<?php declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Database statement semantics - the runner for statement_semantics_contract.json
 * (ADR-0065).
 *
 * tests/fixtures/statement_semantics_contract.json is a byte-for-byte copy of
 * tina4-documentation/plan/v3/fixtures/statement_semantics_contract.json. The
 * same file drives the Python, Ruby and Node runners, so a vector added there is
 * a vector all four frameworks must answer identically.
 *
 *  - write detection and placeholder translation walk the fixture's vectors
 *    through SqlStatement::isWrite() and SQLTranslator::placeholderStyle($sql, ':');
 *  - fetch of a write, execute rows and the query cache run against a REAL
 *    SQLite file, with durability read back on a SECOND, fresh connection;
 *  - OUTPUT and EXEC run against a REAL SQL Server, and the no-parameters rule
 *    against a REAL PostgreSQL. Under TINA4_REQUIRE_SERVICES a missing service
 *    fails the run (RequireServicesGate turns the skip into a failure).
 *
 * NO MOCKS.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Database\DatabaseResult;
use Tina4\Database\SqlStatement;
use Tina4\SQLTranslator;

class StatementSemanticsContractTest extends TestCase
{
    private const JSONB = "'{\"a\":1}'::jsonb";

    /** @var list<string> */
    private array $sqliteFiles = [];

    private static function contract(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/fixtures/statement_semantics_contract.json'), true);
    }

    protected function tearDown(): void
    {
        putenv('TINA4_DB_CACHE');
        foreach ($this->sqliteFiles as $file) {
            @unlink($file);
        }
    }

    /** A fresh SQLite file with an empty `note` table. */
    private function sqliteUrl(): string
    {
        $file = sys_get_temp_dir() . '/tina4_statement_semantics_' . getmypid() . '_' . count($this->sqliteFiles) . '.db';
        @unlink($file);
        $this->sqliteFiles[] = $file;
        $url = 'sqlite:///' . $file;
        $setup = Database::create($url);
        $setup->execute('CREATE TABLE note (id INTEGER PRIMARY KEY AUTOINCREMENT, text VARCHAR(40))');
        $setup->close();
        return $url;
    }

    private function rowsSeenByAFreshConnection(string $url): int
    {
        $fresh = Database::create($url);
        try {
            return (int) $fresh->fetchOne('SELECT COUNT(*) AS n FROM note', [], true)['n'];
        } finally {
            $fresh->close();
        }
    }

    // ── Vectors: the same data through every framework's own function ──────

    public function testWriteDetectionAnswersEveryFixtureVector(): void
    {
        $wrong = [];
        foreach (self::contract()['write_detection_vectors'] as $vector) {
            if (SqlStatement::isWrite($vector['sql']) !== $vector['write']) {
                $wrong[] = json_encode($vector['sql']) . ' expected write=' . var_export($vector['write'], true);
            }
        }
        $this->assertSame([], $wrong, 'write detection disagrees with the shared fixture');
    }

    public function testPlaceholderTranslationAnswersEveryFixtureVector(): void
    {
        $wrong = [];
        foreach (self::contract()['placeholder_vectors'] as $vector) {
            $translated = SQLTranslator::placeholderStyle($vector['sql'], ':');
            if ($translated !== $vector['numbered']) {
                $wrong[] = json_encode($vector['sql']) . ' -> ' . json_encode($translated) . ', expected ' . json_encode($vector['numbered']);
            }
        }
        $this->assertSame([], $wrong, 'placeholder translation disagrees with the shared fixture');
    }

    // ── SQLite: fetch of a write, execute rows, the query cache ─────────────

    public function testFetchOfAnInsertReturningRunsOnceAndCommits(): void
    {
        $url = $this->sqliteUrl();
        $writer = Database::create($url);
        $result = $writer->fetch('INSERT INTO note (text) VALUES (?) RETURNING id', ['via fetch']);
        $writer->close();
        $this->assertSame([1], array_map(static fn(array $row): int => (int) $row['id'], $result->records));
        $this->assertSame(1, $this->rowsSeenByAFreshConnection($url), 'fetch() of a write did not land exactly once');
    }

    public function testFetchOneOfAnInsertReturningRunsOnceAndCommits(): void
    {
        $url = $this->sqliteUrl();
        $writer = Database::create($url);
        $row = $writer->fetchOne('INSERT INTO note (text) VALUES (?) RETURNING id', ['via fetchOne']);
        $writer->close();
        $this->assertSame(1, (int) ($row['id'] ?? 0));
        $this->assertSame(1, $this->rowsSeenByAFreshConnection($url), 'fetchOne() of a write did not land exactly once');
    }

    public function testAFetchedWriteIsNeverCachedAndFlushesTheCache(): void
    {
        $url = $this->sqliteUrl();
        putenv('TINA4_DB_CACHE=true');
        $database = Database::create($url);
        putenv('TINA4_DB_CACHE');
        $countSql = 'SELECT COUNT(*) AS n FROM note';
        $this->assertSame(0, (int) $database->fetchOne($countSql)['n']); // a cached read
        $first = $database->fetchOne('INSERT INTO note (text) VALUES (?) RETURNING id', ['same']);
        $second = $database->fetchOne('INSERT INTO note (text) VALUES (?) RETURNING id', ['same']);
        $this->assertNotSame((int) $first['id'], (int) $second['id'], 'the second fetched write was served from the cache');
        $this->assertSame(2, (int) $database->fetchOne($countSql)['n'], 'the cached count survived a fetched write');
        $database->close();
        $this->assertSame(2, $this->rowsSeenByAFreshConnection($url));
    }

    public function testExecuteReturnsRowsForSelectWithSelectAndReturning(): void
    {
        $url = $this->sqliteUrl();
        $database = Database::create($url);
        $database->execute('INSERT INTO note (text) VALUES (?)', ['one']);
        $cases = [
            ['SELECT id, text FROM note WHERE id = ?', [1], [['id' => '1', 'text' => 'one']]],
            ['WITH later AS (SELECT id FROM note WHERE id >= ?) SELECT id FROM later', [1], [['id' => '1']]],
            ['INSERT INTO note (text) VALUES (?) RETURNING id', ['two'], [['id' => '2']]],
        ];
        foreach ($cases as [$sql, $params, $expected]) {
            $result = $database->execute($sql, $params);
            $this->assertInstanceOf(DatabaseResult::class, $result, "execute({$sql}) did not return the fetch() type");
            $rows = array_map(static fn(array $row): array => array_map('strval', $row), $result->records);
            $this->assertSame($expected, $rows, "execute({$sql}) rows");
        }
        $database->close();
        $this->assertSame(2, $this->rowsSeenByAFreshConnection($url));
    }

    public function testExecuteOfAPlainWriteKeepsItsReturnValue(): void
    {
        $url = $this->sqliteUrl();
        $database = Database::create($url);
        $this->assertTrue($database->execute('INSERT INTO note (text) VALUES (?)', ['plain']));
        $database->close();
        $this->assertSame(1, $this->rowsSeenByAFreshConnection($url));
    }

    // ── SQL Server: OUTPUT and EXEC ─────────────────────────────────────────

    public function testExecuteReturnsRowsForOutputAndExecOnSqlServer(): void
    {
        $url = (string) (getenv('TINA4_TEST_MSSQL_URL') ?: '');
        if ($url === '') {
            $this->markTestSkipped('[needs:mssql] mssql not reachable - TINA4_TEST_MSSQL_URL not set');
        }
        $database = Database::create($url);
        $dropAll = static function () use ($database): void {
            $database->execute("IF OBJECT_ID('contract_php_notes', 'P') IS NOT NULL DROP PROCEDURE contract_php_notes");
            $database->execute("IF OBJECT_ID('contract_php_note', 'U') IS NOT NULL DROP TABLE contract_php_note");
        };
        try {
            $dropAll();
            $database->execute('CREATE TABLE contract_php_note (id INT IDENTITY(1,1) PRIMARY KEY, text VARCHAR(40))');
            $database->execute('CREATE PROCEDURE contract_php_notes AS SELECT id, text FROM contract_php_note ORDER BY id');
            $inserted = $database->execute('INSERT INTO contract_php_note (text) OUTPUT inserted.id VALUES (?)', ['out']);
            $this->assertInstanceOf(DatabaseResult::class, $inserted, 'execute(INSERT ... OUTPUT) did not return rows');
            $this->assertSame([['id' => '1']], array_map(static fn(array $row): array => array_map('strval', $row), $inserted->records));
            $listed = $database->execute('EXEC contract_php_notes');
            $this->assertInstanceOf(DatabaseResult::class, $listed, 'execute(EXEC) did not return rows');
            $this->assertSame([['id' => '1', 'text' => 'out']], array_map(static fn(array $row): array => array_map('strval', $row), $listed->records));
        } finally {
            $dropAll();
            $database->close();
        }
    }

    // ── PostgreSQL: no parameters, no rewrite ───────────────────────────────

    private function postgres(): Database
    {
        $pg = PgTestEnv::resolve();
        if (!$pg->reachable()) {
            $this->markTestSkipped("[needs:postgres] postgres not reachable at {$pg->host}:{$pg->port}");
        }
        return Database::create("postgres://{$pg->user}:{$pg->pass}@{$pg->host}:{$pg->port}/tina4_php");
    }

    public function testSqlWithNoParametersIsSentExactlyAsWrittenOnPostgresql(): void
    {
        $database = $this->postgres();
        try {
            $jsonb = self::JSONB;
            $row = $database->fetchOne(
                "SELECT {$jsonb} ? 'a' AS has_key, {$jsonb} ?| array['a','z'] AS any_key, {$jsonb} ?& array['a'] AS all_keys, 'a%' AS percent",
                [],
                true
            );
            $this->assertSame(['has_key' => true, 'any_key' => true, 'all_keys' => true, 'percent' => 'a%'], self::booleans($row));
        } finally {
            $database->close();
        }
    }

    public function testJsonbExistsFunctionsAndLiteralPercentWorkWithParametersOnPostgresql(): void
    {
        $database = $this->postgres();
        try {
            $jsonb = self::JSONB;
            $row = $database->fetchOne("SELECT jsonb_exists({$jsonb}, ?) AS has_key, 'a%' || ? AS joined", ['a', 'b'], true);
            $this->assertSame(['has_key' => true, 'joined' => 'a%b'], self::booleans($row));
        } finally {
            $database->close();
        }
    }

    /** ext-pgsql answers a boolean as 't'/'f'; PDO as a bool. Compare the meaning. */
    private static function booleans(?array $row): array
    {
        return array_map(static fn($value) => $value === 't' ? true : ($value === 'f' ? false : $value), $row ?? []);
    }
}
