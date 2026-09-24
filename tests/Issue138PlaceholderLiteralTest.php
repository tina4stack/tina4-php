<?php declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * tina4-python#138 parity: turning `?` into the driver's placeholder was a plain
 * text replace, so a `?` inside a string literal became a placeholder, and on
 * psycopg every literal `%` crashed once parameters were passed - and fetch()
 * always passes LIMIT/OFFSET, so `LIKE 'abc%'` failed everywhere.
 *
 * The contract, in all four frameworks: translating `?` skips string literals
 * ('...', PostgreSQL E'...' and $$...$$), quoted identifiers ("..." and
 * `...`) and comments (-- and slash-star); a literal `%` works with or without
 * parameters. Each case runs on the REAL engine through Database::create();
 * the SQLTranslator helpers, pure functions, are checked directly.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\SQLTranslator;

class Issue138PlaceholderLiteralTest extends TestCase
{
    private const TABLE = 'issue138_php_note';

    private ?Database $db = null;

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            try {
                $this->db->execute('DROP TABLE ' . self::TABLE);
            } catch (\Throwable) {
            }
            $this->db->close();
            $this->db = null;
        }
    }

    // ── the translators (pure functions: no dependency, no double) ────────

    public function testPlaceholderStyleSkipsLiteralsIdentifiersAndComments(): void
    {
        $sql = "SELECT 'why?' AS \"n?\", `m?`, E'it\\'s?', \$\$a?\$\$, \$t\$b?\$t\$ -- c?\n/* d? */ FROM t WHERE a = ? AND b = ?";
        $this->assertSame(
            "SELECT 'why?' AS \"n?\", `m?`, E'it\\'s?', \$\$a?\$\$, \$t\$b?\$t\$ -- c?\n/* d? */ FROM t WHERE a = :1 AND b = :2",
            SQLTranslator::placeholderStyle($sql, ':')
        );
    }

    public function testSprintfStyleDoublesEveryLiteralPercent(): void
    {
        $this->assertSame(
            "SELECT 'a%%?' FROM t WHERE x LIKE 'b%%' AND y = %s",
            SQLTranslator::placeholderStyle("SELECT 'a%?' FROM t WHERE x LIKE 'b%' AND y = ?", '%s')
        );
    }

    public function testNamedToPositionalSkipsDollarQuotesAndQuotedIdentifiers(): void
    {
        [$sql, $params] = SQLTranslator::namedToPositional(
            "SELECT \$\$:a\$\$, \"col:a\", `x:a`, E'y:a', v::text FROM t WHERE id = :a -- :a\n/* :a */",
            ['a' => 5]
        );
        $this->assertSame(
            "SELECT \$\$:a\$\$, \"col:a\", `x:a`, E'y:a', v::text FROM t WHERE id = ? -- :a\n/* :a */",
            $sql
        );
        $this->assertSame([5], $params);
    }

    // ── the real engines ───────────────────────────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function engines(): array
    {
        return [
            'PostgreSQL (ext-pgsql)' => ['postgres'],
            'SQLite' => ['sqlite'],
            'MySQL' => ['mysql'],
            'SQL Server' => ['mssql'],
            'Firebird (ext-interbase)' => ['firebird'],
            'Firebird (PDO)' => ['firebird-pdo'],
            'ODBC (PostgreSQL driver)' => ['odbc'],
        ];
    }

    private function connect(string $engine): Database
    {
        $env = static fn(string $name): string => (string) (getenv($name) ?: '');
        $url = match ($engine) {
            'postgres' => (static function (): string {
                $pg = PgTestEnv::resolve();
                return $pg->reachable() ? "postgres://{$pg->user}:{$pg->pass}@{$pg->host}:{$pg->port}/tina4_php" : '';
            })(),
            'sqlite' => 'sqlite:///' . sys_get_temp_dir() . '/issue138_php_' . getmypid() . '.db',
            'mysql' => $env('TINA4_TEST_MYSQL_URL'),
            'mssql' => $env('TINA4_TEST_MSSQL_URL'),
            'firebird' => $env('TINA4_TEST_FIREBIRD_URL') === '' ? '' : $env('TINA4_TEST_FIREBIRD_URL') . '?driver=interbase',
            'firebird-pdo' => $env('TINA4_TEST_FIREBIRD_URL') === '' ? '' : $env('TINA4_TEST_FIREBIRD_URL') . '?driver=pdo',
            'odbc' => $env('TINA4_TEST_ODBC_DSN') === '' ? '' : 'odbc:///' . $env('TINA4_TEST_ODBC_DSN'),
        };
        if ($url === '') {
            $need = str_starts_with($engine, 'firebird') ? 'firebird' : ($engine === 'odbc' ? 'runtime=odbc' : $engine);
            $this->markTestSkipped("[needs:{$need}] {$engine} not reachable here — set its TINA4_TEST_* variable");
        }
        return $this->db = Database::create($url);
    }

    /** A FROM clause for a table-less SELECT, where the dialect needs one. */
    private static function from(string $engine): string
    {
        return str_starts_with($engine, 'firebird') ? ' FROM RDB$DATABASE' : '';
    }

    /** An integer parameter, cast so every engine can type it (MySQL spells INTEGER as SIGNED). */
    private static function intParam(string $engine): string
    {
        return $engine === 'mysql' ? 'CAST(? AS SIGNED)' : 'CAST(? AS INTEGER)';
    }

    /** A quoted identifier in the dialect's own spelling. */
    private static function quoted(string $engine, string $name): string
    {
        return $engine === 'mysql' ? "`{$name}`" : "\"{$name}\"";
    }

    #[DataProvider('engines')]
    public function testALiteralPercentWithNoParameters(string $engine): void
    {
        $db = $this->connect($engine);
        $rows = $db->fetch("SELECT 'a%' AS v" . self::from($engine), [], 5)->records;
        $this->assertSame('a%', trim((string) $rows[0]['v']));
    }

    #[DataProvider('engines')]
    public function testALikePatternLiteralBesideAParameter(string $engine): void
    {
        $db = $this->connect($engine);
        $rows = $db->fetch("SELECT 'abc' AS v" . self::from($engine) . " WHERE 'abc' LIKE 'a%' AND 1 = ?", [1], 5)->records;
        $this->assertCount(1, $rows);
        $this->assertSame('abc', trim((string) $rows[0]['v']));
    }

    #[DataProvider('engines')]
    public function testAQuestionMarkInsideAStringIsNotAPlaceholder(string $engine): void
    {
        $db = $this->connect($engine);
        $row = $db->fetchOne("SELECT 'why?' AS v, " . self::intParam($engine) . ' AS n' . self::from($engine), [7]);
        $this->assertSame('why?', trim((string) $row['v']));
        $this->assertEquals(7, $row['n']);
    }

    #[DataProvider('engines')]
    public function testExecuteWithALiteralPercentAndAParameter(string $engine): void
    {
        $db = $this->connect($engine);
        try {
            $db->execute('DROP TABLE ' . self::TABLE);
        } catch (\Throwable) {
        }
        $db->execute('CREATE TABLE ' . self::TABLE . ' (id integer NOT NULL PRIMARY KEY, txt varchar(20) NOT NULL)');

        $db->execute('INSERT INTO ' . self::TABLE . " (id, txt) VALUES (?, 'a%?')", [1]);

        $this->assertSame('a%?', trim((string) $db->fetchOne('SELECT txt FROM ' . self::TABLE . ' WHERE id = ?', [1])['txt']));
    }

    #[DataProvider('engines')]
    public function testAPatternPassedAsAParameter(string $engine): void
    {
        $db = $this->connect($engine);
        $rows = $db->fetch("SELECT 'abc' AS v" . self::from($engine) . " WHERE 'abc' LIKE ?", ['a%'], 5)->records;
        $this->assertCount(1, $rows);
    }

    #[DataProvider('engines')]
    public function testAQuestionMarkInsideALineCommentIsNotAPlaceholder(string $engine): void
    {
        $db = $this->connect($engine);
        $row = $db->fetchOne("SELECT -- is this a placeholder?\n " . self::intParam($engine) . ' AS n' . self::from($engine), [8]);
        $this->assertEquals(8, $row['n']);
    }

    #[DataProvider('engines')]
    public function testAQuestionMarkInsideABlockCommentIsNotAPlaceholder(string $engine): void
    {
        $db = $this->connect($engine);
        $row = $db->fetchOne("SELECT /* is this a placeholder? */ " . self::intParam($engine) . ' AS n' . self::from($engine), [9]);
        $this->assertEquals(9, $row['n']);
    }

    #[DataProvider('engines')]
    public function testAQuestionMarkInsideAQuotedIdentifierIsNotAPlaceholder(string $engine): void
    {
        $db = $this->connect($engine);
        $row = $db->fetchOne('SELECT ' . self::intParam($engine) . ' AS ' . self::quoted($engine, 'n?') . self::from($engine), [10]);
        $this->assertEquals(10, $row['n?'] ?? $row['N?'] ?? null, 'the ? in the alias stays part of the name: ' . json_encode($row));
    }

    /** Firebird rewrites a NULL parameter to a literal NULL: it must count only real placeholders. */
    public function testFirebirdNullParameterBesideACommentedQuestionMark(): void
    {
        $db = $this->connect('firebird');
        $row = $db->fetchOne(
            "SELECT /* why? */ CAST(? AS INTEGER) AS n, CAST(? AS VARCHAR(5)) AS s -- and?\n FROM RDB\$DATABASE",
            [3, null]
        );
        $this->assertEquals(3, $row['n']);
        $this->assertNull($row['s']);
    }

    /** SQL Server splices binary values as 0x literals: only at real placeholders. */
    public function testSqlServerBinaryParameterBesideACommentedQuestionMark(): void
    {
        $db = $this->connect('mssql');
        $row = $db->fetchOne('SELECT /* why? */ DATALENGTH(?) AS size, CAST(? AS INTEGER) AS n', ["\x00\xff\x01", 4]);
        $this->assertEquals(3, $row['size']);
        $this->assertEquals(4, $row['n']);
    }

    public function testAQuestionMarkInsidePostgresDollarQuotesIsNotAPlaceholder(): void
    {
        $db = $this->connect('postgres');
        $row = $db->fetchOne('SELECT $$why? it\'s$$ AS v, $tag$and?$tag$ AS w, CAST(? AS INTEGER) AS n', [11]);
        $this->assertSame("why? it's", $row['v']);
        $this->assertSame('and?', $row['w']);
        $this->assertEquals(11, $row['n']);
    }

    public function testAQuestionMarkInsideAPostgresEscapeStringIsNotAPlaceholder(): void
    {
        $db = $this->connect('postgres');
        $row = $db->fetchOne("SELECT E'it\\'s why?' AS v, CAST(? AS INTEGER) AS n", [12]);
        $this->assertSame("it's why?", $row['v']);
        $this->assertEquals(12, $row['n']);
    }

    /** The jsonb ? operator works in a statement that has no parameters. */
    public function testThePostgresJsonbQuestionOperatorWithoutParameters(): void
    {
        $db = $this->connect('postgres');
        $row = $db->fetchOne("SELECT '{\"a\": 1}'::jsonb ? 'a' AS has");
        $this->assertTrue((bool) $row['has']);
    }
}
