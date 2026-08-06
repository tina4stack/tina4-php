<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * RowCapDetectorTest — the fetch() row cap, measured end to end on REAL
 * SQLite files (no doubles anywhere: real adapter, real driver, real disk).
 *
 * fetch() caps a read at 100 rows unless the caller supplied their own cap.
 * That decision has TWO halves and each one failed on its own:
 *
 *  (a) THE DETECTOR — "does this statement already end with its own LIMIT?"
 *      It must SCRUB string literals and BOTH comment styles first, and it must
 *      be ANCHORED to the end of the statement. MEASURED 2026-08-01 against a
 *      150-row table with the cap at 100:
 *          SELECT * FROM t WHERE label != 'LIMIT' ORDER BY id  -> 150 (want 100)
 *          SELECT * FROM t ORDER BY id -- LIMIT 5              -> 150 (want 100)
 *          SELECT * FROM t ORDER BY id (block comment: LIMIT 5)-> 150 (want 100)
 *          SELECT id, label AS rate_limit FROM t               -> 150 (want 100)
 *      A column named rate_limit silently returning a whole table is the
 *      production incident the cap exists to prevent.
 *
 *  (b) THE APPEND SITE — the cap and the COUNT(*) wrapper must land on a NEW
 *      LINE. MEASURED in tina4-php specifically:
 *          SELECT * FROM items LIMIT 3 -- c    RAISED "incomplete input"
 *              (the trailing `--` commented out the COUNT wrapper's `)`)
 *          SELECT * FROM items LIMIT 3 (block comment)  RAISED
 *              "near LIMIT: syntax error" (the block comment hid the real
 *              trailing LIMIT from the detector, so a SECOND one was appended)
 *
 * The NEGATIVE half is what stops "fix the detector" from degrading into
 * "always append": a real trailing LIMIT must still be honoured, and a LIMIT
 * that only lives in a SUBQUERY must still leave the outer statement capped.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tina4\Database\DatabaseAdapter;
use Tina4\Database\PdoSqliteAdapter;
use Tina4\Database\SQLite3Adapter;

class RowCapDetectorTest extends TestCase
{
    /** Rows seeded into the fixture table — 50 more than the 100-row cap. */
    private const ROW_COUNT = 150;

    /** The framework's default fetch() cap. */
    private const CAP = 100;

    /** @var array<int,string> Temp database files to unlink. */
    private array $dbFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->dbFiles as $file) {
            @unlink($file);
        }
        $this->dbFiles = [];
    }

    // ── the real fixture ────────────────────────────────────────────────────

    /**
     * A real SQLite file holding {@see ROW_COUNT} rows.
     *
     * $adapterClass lets every end-to-end case run against BOTH SQLite
     * adapters: SQLite3Adapter (ext-sqlite3, its own fetch()) and
     * PdoSqliteAdapter (pdo_sqlite, fetch() from PdoAdapterTrait). They are
     * separate implementations of the same contract, so a fix applied to one
     * proves nothing about the other.
     *
     * @param class-string<DatabaseAdapter> $adapterClass
     */
    private function seededAdapter(string $adapterClass): DatabaseAdapter
    {
        $file = \TempPath::file('tina4_rowcap_', '.db');
        $this->dbFiles[] = $file;

        /** @var DatabaseAdapter $adapter */
        $adapter = new $adapterClass($file);
        $adapter->execute('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT)');
        $adapter->startTransaction();
        for ($i = 1; $i <= self::ROW_COUNT; $i++) {
            $adapter->execute('INSERT INTO items (label) VALUES (?)', ["item-{$i}"]);
        }
        $adapter->commit();

        return $adapter;
    }

    /** Both real SQLite adapters — they do NOT share a fetch() implementation. */
    public static function sqliteAdapters(): array
    {
        return [
            'ext-sqlite3' => [SQLite3Adapter::class],
            'pdo_sqlite' => [PdoSqliteAdapter::class],
        ];
    }

    /** Reflection into the protected static detectors — pure functions, no double. */
    private function callNormalizer(string $method, string $sql): mixed
    {
        $ref = new ReflectionClass(SQLite3Adapter::class);
        return $ref->getMethod($method)->invoke(null, $sql);
    }

    private function hasTrailingLimit(string $sql): bool
    {
        return (bool) $this->callNormalizer('hasTrailingLimit', $sql);
    }

    // ── (a) DETECTOR — POSITIVE: the cap must still apply ───────────────────
    // "Positive" = the row cap is applied, i.e. the detector answers FALSE
    // because the caller did NOT supply a cap of their own.

    public function testDetectorIgnoresLimitInsideStringLiteral(): void
    {
        // The word LIMIT lives in a value, not in a clause.
        $this->assertFalse(
            $this->hasTrailingLimit("SELECT * FROM items WHERE label != 'LIMIT' ORDER BY id"),
            'a LIMIT inside a string literal is not a LIMIT clause'
        );
    }

    public function testDetectorIgnoresLimitInsideLineComment(): void
    {
        $this->assertFalse(
            $this->hasTrailingLimit('SELECT * FROM items ORDER BY id -- LIMIT 5'),
            'a LIMIT inside a -- comment is not a LIMIT clause'
        );
    }

    public function testDetectorIgnoresLimitInsideBlockComment(): void
    {
        $this->assertFalse(
            $this->hasTrailingLimit('SELECT * FROM items ORDER BY id /* LIMIT 5 */'),
            'a LIMIT inside a block comment is not a LIMIT clause'
        );
    }

    public function testDetectorIgnoresColumnNamedRateLimit(): void
    {
        // The anchoring half: an identifier that merely CONTAINS "limit".
        $this->assertFalse(
            $this->hasTrailingLimit('SELECT id, label AS rate_limit FROM items'),
            'a column named rate_limit must not disarm the row cap'
        );
    }

    public function testDetectorIgnoresLimitOnlyInSubquery(): void
    {
        $this->assertFalse(
            $this->hasTrailingLimit(
                'SELECT * FROM (SELECT * FROM items LIMIT 5) AS q WHERE q.id > 0'
            ),
            'a subquery LIMIT does not terminate the OUTER statement'
        );
    }

    // ── (a) DETECTOR — NEGATIVE: a real cap must still be seen ──────────────
    // Without this half, "fixing" the detector could just mean always
    // appending, which is a syntax error on every one of these.

    public function testDetectorSeesRealTrailingLimitBehindLineComment(): void
    {
        // MEASURED: this shape raised "near LIMIT: syntax error" pre-fix once a
        // comment followed the real clause.
        $this->assertTrue(
            $this->hasTrailingLimit('SELECT * FROM items LIMIT 3 -- trailing note'),
            'a real trailing LIMIT is still a LIMIT when a comment follows it'
        );
    }

    public function testDetectorSeesRealTrailingLimitBehindBlockComment(): void
    {
        $this->assertTrue(
            $this->hasTrailingLimit('SELECT * FROM items LIMIT 3 /* trailing note */'),
            'a real trailing LIMIT is still a LIMIT when a block comment follows it'
        );
    }

    public function testDetectorSeesTrailingLimitWithSemicolon(): void
    {
        $this->assertTrue(
            $this->hasTrailingLimit('SELECT * FROM items LIMIT 3;'),
            'a trailing semicolon does not hide the LIMIT'
        );
    }

    public function testDetectorSeesPlaceholderLimits(): void
    {
        // Every placeholder dialect the four frameworks bind with.
        $this->assertTrue($this->hasTrailingLimit('SELECT * FROM items LIMIT ?'));
        $this->assertTrue($this->hasTrailingLimit('SELECT * FROM items LIMIT $1 OFFSET $2'));
        $this->assertTrue($this->hasTrailingLimit('SELECT * FROM items LIMIT :max'));
        $this->assertTrue($this->hasTrailingLimit('SELECT * FROM items LIMIT %s'));
        $this->assertTrue($this->hasTrailingLimit('SELECT * FROM items LIMIT 10, 20'));
    }

    // ── the scrubber itself ─────────────────────────────────────────────────

    public function testScrubKeepsLengthAndNewlines(): void
    {
        // Same-length blanking keeps offsets and line structure usable by any
        // caller that searches the scrubbed copy and edits the original.
        $sql = "SELECT 'a''b' -- note\nFROM t /* two\nlines */ WHERE x = 1";
        $scrubbed = (string) $this->callNormalizer('scrubSqlText', $sql);

        $this->assertSame(strlen($sql), strlen($scrubbed), 'scrub must preserve length');
        $this->assertSame(
            substr_count($sql, "\n"),
            substr_count($scrubbed, "\n"),
            'scrub must preserve newlines'
        );
        $this->assertStringNotContainsString('note', $scrubbed);
        $this->assertStringNotContainsString('lines', $scrubbed);
        $this->assertStringContainsString('FROM t', $scrubbed);
        $this->assertStringContainsString('WHERE x = 1', $scrubbed);
    }

    public function testTrailingFetchDetectorIsAnchoredAndScrubbed(): void
    {
        // The MSSQL/ODBC spelling of the same contract.
        $this->assertTrue(
            (bool) $this->callNormalizer(
                'hasTrailingFetch',
                'SELECT * FROM items ORDER BY id OFFSET 0 ROWS FETCH NEXT 5 ROWS ONLY'
            )
        );
        $this->assertFalse(
            (bool) $this->callNormalizer(
                'hasTrailingFetch',
                'SELECT * FROM items ORDER BY id -- FETCH NEXT 5 ROWS ONLY'
            ),
            'a FETCH inside a comment must not disarm the row cap'
        );
    }

    // ── (a) end to end — the cap is APPLIED ─────────────────────────────────

    #[DataProvider('sqliteAdapters')]
    public function testRowCapAppliedWhenLimitOnlyInStringLiteral(string $adapterClass): void
    {
        $db = $this->seededAdapter($adapterClass);
        $result = $db->fetch("SELECT * FROM items WHERE label != 'LIMIT' ORDER BY id");

        $this->assertCount(self::CAP, $result['data'], 'literal LIMIT must not disarm the cap');
        $this->assertSame(self::ROW_COUNT, (int) $result['total']);
    }

    #[DataProvider('sqliteAdapters')]
    public function testRowCapAppliedWhenLimitOnlyInLineComment(string $adapterClass): void
    {
        $db = $this->seededAdapter($adapterClass);
        $result = $db->fetch('SELECT * FROM items ORDER BY id -- LIMIT 5');

        $this->assertCount(self::CAP, $result['data'], 'commented LIMIT must not disarm the cap');
        $this->assertSame(self::ROW_COUNT, (int) $result['total']);
    }

    #[DataProvider('sqliteAdapters')]
    public function testRowCapAppliedWhenLimitOnlyInBlockComment(string $adapterClass): void
    {
        $db = $this->seededAdapter($adapterClass);
        $result = $db->fetch('SELECT * FROM items ORDER BY id /* LIMIT 5 */');

        $this->assertCount(self::CAP, $result['data'], 'block-commented LIMIT must not disarm the cap');
        $this->assertSame(self::ROW_COUNT, (int) $result['total']);
    }

    #[DataProvider('sqliteAdapters')]
    public function testRowCapAppliedWhenColumnIsNamedRateLimit(string $adapterClass): void
    {
        $db = $this->seededAdapter($adapterClass);
        $result = $db->fetch('SELECT id, label AS rate_limit FROM items ORDER BY id');

        $this->assertCount(self::CAP, $result['data'], 'a rate_limit column must not disarm the cap');
        $this->assertSame(self::ROW_COUNT, (int) $result['total']);
    }

    #[DataProvider('sqliteAdapters')]
    public function testRowCapAppliedWhenLimitOnlyInSubquery(string $adapterClass): void
    {
        // The subquery caps its own 120 rows; the OUTER statement is still
        // uncapped and must get the 100-row cap.
        $db = $this->seededAdapter($adapterClass);
        $result = $db->fetch(
            'SELECT * FROM (SELECT * FROM items ORDER BY id LIMIT 120) AS q WHERE q.id > 0'
        );

        $this->assertCount(self::CAP, $result['data'], 'a subquery LIMIT must not cap the outer read');
        $this->assertSame(120, (int) $result['total']);
    }

    // ── (b) end to end — the APPEND SITE ────────────────────────────────────

    #[DataProvider('sqliteAdapters')]
    public function testRowCapSurvivesTrailingLineCommentAtAppendSite(string $adapterClass): void
    {
        // Isolates the append site from the detector: no LIMIT anywhere, so the
        // detector answers FALSE either way. Appended INLINE, the cap lands
        // inside the trailing comment and SQLite silently ignores it — all 150
        // rows come back.
        $db = $this->seededAdapter($adapterClass);
        $result = $db->fetch('SELECT * FROM items ORDER BY id -- every row please');

        $this->assertCount(self::CAP, $result['data'], 'the cap must not land inside a trailing comment');
        $this->assertSame(self::ROW_COUNT, (int) $result['total']);
    }

    #[DataProvider('sqliteAdapters')]
    public function testCountProbeSurvivesTrailingLineComment(string $adapterClass): void
    {
        // MEASURED: the COUNT(*) wrapper closed its paren INLINE, so a trailing
        // `--` commented the `)` out. ext-sqlite3 raised "incomplete input";
        // the PDO path swallowed it and reported total = 0 beside real rows.
        $db = $this->seededAdapter($adapterClass);
        $result = $db->fetch('SELECT * FROM items ORDER BY id -- all of them');

        $this->assertSame(
            self::ROW_COUNT,
            (int) $result['total'],
            'the COUNT probe must not be swallowed by a trailing comment'
        );
    }

    // ── (b) end to end — NEGATIVE: the caller's own cap wins ────────────────

    #[DataProvider('sqliteAdapters')]
    public function testUserTrailingLimitHonouredWithLineComment(string $adapterClass): void
    {
        // MEASURED defect #1: this exact statement raised "incomplete input".
        $db = $this->seededAdapter($adapterClass);
        $result = $db->fetch('SELECT * FROM items ORDER BY id LIMIT 3 -- c');

        $this->assertCount(3, $result['data'], 'the caller LIMIT must be honoured, not replaced');
        $this->assertSame(3, (int) $result['total']);
    }

    #[DataProvider('sqliteAdapters')]
    public function testUserTrailingLimitHonouredWithBlockComment(string $adapterClass): void
    {
        // MEASURED defect #2: this exact statement raised "near LIMIT: syntax
        // error" — the block comment hid the real LIMIT, so a second one was
        // appended.
        $db = $this->seededAdapter($adapterClass);
        $result = $db->fetch('SELECT * FROM items ORDER BY id LIMIT 3 /* c */');

        $this->assertCount(3, $result['data'], 'the caller LIMIT must be honoured, not replaced');
        $this->assertSame(3, (int) $result['total']);
    }

    #[DataProvider('sqliteAdapters')]
    public function testUserTrailingLimitHonouredPlain(string $adapterClass): void
    {
        $db = $this->seededAdapter($adapterClass);
        $result = $db->fetch('SELECT * FROM items ORDER BY id LIMIT 3');

        $this->assertCount(3, $result['data']);
    }

    #[DataProvider('sqliteAdapters')]
    public function testUserTrailingLimitHonouredWithSemicolon(string $adapterClass): void
    {
        $db = $this->seededAdapter($adapterClass);
        $result = $db->fetch('SELECT * FROM items ORDER BY id LIMIT 3;');

        $this->assertCount(3, $result['data']);
    }

    #[DataProvider('sqliteAdapters')]
    public function testExplicitLimitArgumentStillWins(string $adapterClass): void
    {
        // The caller asked for 7 and supplied no cap of their own — the cap
        // machinery must still paginate normally.
        $db = $this->seededAdapter($adapterClass);
        $result = $db->fetch('SELECT * FROM items ORDER BY id -- note', [], 7, 10);

        $this->assertCount(7, $result['data']);
        $this->assertSame(11, (int) $result['data'][0]['id'], 'OFFSET must apply too');
    }
}
