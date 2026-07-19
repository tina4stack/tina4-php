<?php

/**
 * Tina4 PHP — v3.13.12 SQL normalization (strip trailing ;).
 *
 * The framework wraps user SQL with COUNT(*) subqueries in fetch()
 * and appends LIMIT/OFFSET (or engine-specific equivalent). A
 * trailing `;` breaks both wrappers. This test pins the
 * normalisation behaviour at two levels:
 *
 *   1. Unit: SqlNormalizerTrait::stripTrailingSemicolons
 *   2. Integration: SQLite3Adapter::fetch / fetchOne survive a
 *      user-supplied trailing `;`
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\SQLite3Adapter;

class SqlNormalizerTest extends TestCase
{
    /** Use reflection so the protected static method is testable directly. */
    private function strip(string $sql): string
    {
        $ref = new ReflectionClass(SQLite3Adapter::class);
        $method = $ref->getMethod('stripTrailingSemicolons');
        return $method->invoke(null, $sql);
    }

    public function testStripsSingleTrailingSemicolon(): void
    {
        $this->assertSame('SELECT 1', $this->strip('SELECT 1;'));
    }

    public function testStripsTrailingWhitespaceAndSemicolons(): void
    {
        $this->assertSame('SELECT 1', $this->strip('SELECT 1   ;  '));
    }

    public function testStripsMultipleTrailingSemicolons(): void
    {
        $this->assertSame('SELECT 1', $this->strip('SELECT 1;;;'));
    }

    public function testStripsNewlineSemicolon(): void
    {
        $this->assertSame('SELECT 1', $this->strip("SELECT 1\n;\n"));
    }

    public function testEmptyStringPassthrough(): void
    {
        $this->assertSame('', $this->strip(''));
    }

    public function testAllSemicolons(): void
    {
        $this->assertSame('', $this->strip(';;;'));
    }

    public function testNoTrailingSemicolonUnchanged(): void
    {
        $this->assertSame('SELECT 1', $this->strip('SELECT 1'));
    }

    // ── hasTrailingLimit (Bug 4 — double-LIMIT detection) ──────────────

    /** Reflection helper for the protected static hasTrailingLimit(). */
    private function hasTrailingLimit(string $sql): bool
    {
        $ref = new ReflectionClass(SQLite3Adapter::class);
        $method = $ref->getMethod('hasTrailingLimit');
        return $method->invoke(null, $sql);
    }

    public function testHasTrailingLimitSimple(): void
    {
        $this->assertTrue($this->hasTrailingLimit('SELECT * FROM t LIMIT 1'));
    }

    public function testHasTrailingLimitWithOffset(): void
    {
        $this->assertTrue($this->hasTrailingLimit('SELECT * FROM t LIMIT 5 OFFSET 10'));
    }

    public function testHasTrailingLimitWithOrderBy(): void
    {
        $this->assertTrue($this->hasTrailingLimit('SELECT * FROM t ORDER BY id DESC LIMIT 1'));
    }

    public function testHasTrailingLimitPlaceholder(): void
    {
        $this->assertTrue($this->hasTrailingLimit('SELECT * FROM t LIMIT ?'));
        $this->assertTrue($this->hasTrailingLimit('SELECT * FROM t LIMIT $1 OFFSET $2'));
        $this->assertTrue($this->hasTrailingLimit('SELECT * FROM t LIMIT :max'));
    }

    public function testHasTrailingLimitMysqlCommaForm(): void
    {
        $this->assertTrue($this->hasTrailingLimit('SELECT * FROM t LIMIT 10, 20'));
    }

    public function testHasTrailingLimitTrailingWhitespace(): void
    {
        $this->assertTrue($this->hasTrailingLimit('SELECT * FROM t LIMIT 1   '));
    }

    public function testNoTrailingLimitWhenAbsent(): void
    {
        $this->assertFalse($this->hasTrailingLimit('SELECT * FROM t ORDER BY id'));
        $this->assertFalse($this->hasTrailingLimit('SELECT * FROM t'));
    }

    public function testNoTrailingLimitWhenLimitNotAtEnd(): void
    {
        // LIMIT inside a subquery, not terminating the outer statement —
        // appending pagination is still safe here.
        $this->assertFalse(
            $this->hasTrailingLimit('SELECT * FROM (SELECT * FROM t LIMIT 5) AS q WHERE q.id > 0')
        );
    }

    /**
     * Integration: SQLite3Adapter::fetch must NOT double-append LIMIT when the
     * user SQL already ends with its own LIMIT clause (Bug 4).
     */
    public function testFetchDoesNotDoubleAppendLimit(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'tina4_dbl_limit_') . '.db';
        $adapter = new SQLite3Adapter($tmpFile);
        $adapter->open();
        try {
            $adapter->execute('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
            for ($i = 1; $i <= 3; $i++) {
                $adapter->execute('INSERT INTO widgets (name) VALUES (?)', ["w{$i}"]);
            }

            // Pre-fix: "... LIMIT 1 LIMIT 100 OFFSET 0" is a syntax error and
            // the adapter swallows it into an empty result.
            $result = $adapter->fetch('SELECT * FROM widgets ORDER BY id DESC LIMIT 1');
            $this->assertCount(1, $result['data'], 'fetch must honour the user LIMIT, not error out');
            $this->assertSame('w3', $result['data'][0]['name']);
        } finally {
            $adapter->close();
            unlink($tmpFile);
        }
    }

    public function testInternalSemicolonUntouched(): void
    {
        // Trailing `;` after the literal — should still strip
        $this->assertSame("SELECT ';' AS x", $this->strip("SELECT ';' AS x;"));
    }

    // ── Integration test against SQLite ────────────────────────────────

    public function testFetchSurvivesTrailingSemicolon(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'tina4_sql_norm_') . '.db';
        $adapter = new SQLite3Adapter($tmpFile);
        $adapter->open();
        try {
            $adapter->execute('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
            for ($i = 0; $i < 5; $i++) {
                $adapter->execute('INSERT INTO widgets (name) VALUES (?)', ["widget-{$i}"]);
            }

            // Pre-v3.13.12: would fail with "near \";\": syntax error" because
            // the framework wraps in COUNT(*) FROM (SELECT * FROM widgets;) AS …
            $result = $adapter->fetch('SELECT * FROM widgets;');
            $this->assertCount(5, $result['data'], 'fetch must return rows when user SQL ends with ;');
            $this->assertSame(5, $result['total'], 'COUNT(*) probe must survive trailing ;');
        } finally {
            $adapter->close();
            unlink($tmpFile);
        }
    }

    public function testFetchOneSurvivesTrailingSemicolon(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'tina4_sql_norm_') . '.db';
        $adapter = new SQLite3Adapter($tmpFile);
        $adapter->open();
        try {
            $adapter->execute('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
            $adapter->execute('INSERT INTO widgets (name) VALUES (?)', ['alpha']);

            $row = $adapter->fetchOne('SELECT * FROM widgets WHERE id = ?;', [1]);
            $this->assertNotNull($row);
            $this->assertSame('alpha', $row['name']);
        } finally {
            $adapter->close();
            unlink($tmpFile);
        }
    }

    // ── v3.13.12: fetchAll returns ALL rows by default ────────────────

    /**
     * Seed a 150-row SQLite DB through Database::create and return [Database, tmpFile].
     *
     * @return array{0: \Tina4\Database\Database, 1: string}
     */
    private function seedBigDb(): array
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'tina4_fetch_all_') . '.db';
        $db = \Tina4\Database\Database::create('sqlite:///' . $tmpFile);
        $db->execute('CREATE TABLE rows (id INTEGER PRIMARY KEY AUTOINCREMENT, n INTEGER)');
        for ($i = 0; $i < 150; $i++) {
            $db->execute('INSERT INTO rows (n) VALUES (?)', [$i]);
        }
        return [$db, $tmpFile];
    }

    public function testFetchAllReturnsAllRowsByDefault(): void
    {
        [$db, $tmpFile] = $this->seedBigDb();
        try {
            // Pre-v3.13.12: silently truncated to 100. v3.13.12: returns all 150.
            $rows = $db->fetchAll('SELECT * FROM rows ORDER BY n');
            $this->assertCount(
                150,
                $rows,
                'fetchAll must return ALL rows by default (was silently truncated to 100 pre-v3.13.12)'
            );
        } finally {
            unlink($tmpFile);
        }
    }

    public function testFetchAllExplicitLimitStillCaps(): void
    {
        [$db, $tmpFile] = $this->seedBigDb();
        try {
            $rows = $db->fetchAll('SELECT * FROM rows ORDER BY n', [], 10);
            $this->assertCount(10, $rows, 'Explicit limit must still cap');
        } finally {
            unlink($tmpFile);
        }
    }

    public function testFetchDefaultStillPaginatesTo100(): void
    {
        [$db, $tmpFile] = $this->seedBigDb();
        try {
            // fetch() (paginated sibling) keeps its 100-row default — only fetchAll changed.
            $result = $db->fetch('SELECT * FROM rows ORDER BY n');
            $this->assertCount(100, $result->records, 'fetch() default page size unchanged');
            $this->assertSame(150, $result->count, 'count still reflects the whole set');
        } finally {
            unlink($tmpFile);
        }
    }

    // ── #262: stripTrailingOrderBy — pure (engine-agnostic) unit coverage ──
    //
    // SQL Server (error 20018) rejects ``SELECT COUNT(*) FROM (<inner> ORDER BY
    // x) t`` when the inner SELECT ends in an ORDER BY without TOP/OFFSET/FETCH,
    // so the MSSQL count probe silently returned 0. This helper strips a
    // trailing top-level ORDER BY before the COUNT wrap. The regex logic runs
    // everywhere (no DB needed); the live behaviour is locked in separately by
    // MySQLMSSQLLiveTest::testMSSQLCountWithTrailingOrderBy.

    /** Reflection helper for the protected static stripTrailingOrderBy(). */
    private function stripOrderBy(string $sql): string
    {
        $ref = new ReflectionClass(\Tina4\Database\MSSQLAdapter::class);
        $method = $ref->getMethod('stripTrailingOrderBy');
        return $method->invoke(null, $sql);
    }

    public function testStripTrailingOrderBySimple(): void
    {
        $this->assertSame('SELECT id FROM t', $this->stripOrderBy('SELECT id FROM t ORDER BY id'));
        $this->assertSame('SELECT id FROM t', $this->stripOrderBy('SELECT id FROM t ORDER BY id DESC'));
        $this->assertSame('SELECT a,b FROM t', $this->stripOrderBy('SELECT a,b FROM t ORDER BY a, b'));
    }

    public function testStripTrailingOrderByNoOrderByUnchanged(): void
    {
        $this->assertSame('SELECT id FROM t', $this->stripOrderBy('SELECT id FROM t'));
        $this->assertSame('SELECT id FROM t WHERE x=1', $this->stripOrderBy('SELECT id FROM t WHERE x=1'));
    }

    public function testStripTrailingOrderByKeepsLegalisedOffsetFetch(): void
    {
        // OFFSET/FETCH makes the ORDER BY valid inside a subquery on SQL Server —
        // leave it so the COUNT wrap stays correct.
        $sql = 'SELECT id FROM t ORDER BY id OFFSET 0 ROWS FETCH NEXT 10 ROWS ONLY';
        $this->assertSame($sql, $this->stripOrderBy($sql));
    }

    public function testStripTrailingOrderByLeavesSubqueryOrderBy(): void
    {
        // ORDER BY lives inside a derived table (legalised by its own OFFSET);
        // the OUTER query has none — must not strip the inner one.
        $sql = 'SELECT * FROM (SELECT id FROM t ORDER BY id OFFSET 0 ROWS) z';
        $this->assertSame($sql, $this->stripOrderBy($sql));

        // SELECT TOP inside a subquery legalises its ORDER BY too; no outer order.
        $top = 'SELECT * FROM (SELECT TOP 5 id FROM t ORDER BY id) z';
        $this->assertSame($top, $this->stripOrderBy($top));
    }

    public function testStripTrailingOrderByStripsOnlyOuter(): void
    {
        // A subquery keeps its (legalised) ORDER BY; only the trailing outer
        // ORDER BY is removed.
        $this->assertSame(
            'SELECT * FROM (SELECT id FROM t ORDER BY id OFFSET 0 ROWS) z',
            $this->stripOrderBy('SELECT * FROM (SELECT id FROM t ORDER BY id OFFSET 0 ROWS) z ORDER BY z.id')
        );
    }
}
