<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;

/**
 * The write-path contract (feature 3/4 of the feature audit).
 *
 * `tests/fixtures/write_path_contract.json` is byte-identical in all four
 * frameworks and is the shared answer key: the same cases, the same seeds, the
 * same expectations, executed identically in python, php, ruby and node.
 *
 * The bug these lock in: update($table, $data) with no explicit filter used to
 * build "UPDATE table SET ..." with NO WHERE clause, so it overwrote EVERY row
 * and reported success. A write with no filter is now an error.
 *
 * The fixture used to be ORPHANED — nothing read it, and the four runners were
 * hand-written independently. They drifted to 17/16/15/14 cases with different
 * names, and the case "a_string_filter_with_params_works_the_same_as_a_hash_filter"
 * was executed by NONE of them while exactly that shape shipped broken in four
 * Node adapters. Every case now runs in every framework, from this one file.
 *
 * RUN IT AGAINST EVERY LIVE ENGINE. SQLite alone proves nothing: the whole
 * reason per-adapter write SQL exists is that placeholder style, RETURNING
 * support and identifier quoting differ exactly where the engine differs.
 *
 *   TINA4_TEST_WRITE_PATH_URL=postgres://host:5432/db \
 *   TINA4_TEST_WRITE_PATH_USERNAME=u TINA4_TEST_WRITE_PATH_PASSWORD=p \
 *     vendor/bin/phpunit tests/WritePathContractTest.php
 *
 * Unset, it falls back to a temp SQLite file so the suite still runs anywhere.
 * No mocks — a database is a real dependency and CI provisions it.
 */
class WritePathContractTest extends TestCase
{
    /** Every op the dispatcher implements. A case naming any other op FAILS. */
    private const IMPLEMENTED_OPS = [
        'insert', 'insert_batch', 'update', 'delete', 'truncate', 'primary_key',
        'transaction_rollback', 'transaction_commit', 'execute_raw',
    ];

    /** @var array<string, mixed> */
    private static array $contract;
    private static Database $db;
    private static string $dir;
    private static string $url;
    private static string $username;
    private static string $password;
    private static string $tableName;
    private static string $compositeTableName;

    /** @return array<string, mixed> */
    private static function loadContract(): array
    {
        return json_decode(
            file_get_contents(__DIR__ . '/fixtures/write_path_contract.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    public static function setUpBeforeClass(): void
    {
        self::$contract = self::loadContract();
        self::$tableName = self::$contract['table']['name'];
        self::$compositeTableName = self::$contract['composite_table']['name'];

        self::$dir = sys_get_temp_dir() . '/tina4-writepath-' . bin2hex(random_bytes(6));
        mkdir(self::$dir, 0777, true);

        $url = trim((string) (getenv('TINA4_TEST_WRITE_PATH_URL') ?: ''));
        self::$url = $url === '' ? 'sqlite:///' . self::$dir . '/contract.db' : $url;
        self::$username = (string) (getenv('TINA4_TEST_WRITE_PATH_USERNAME') ?: '');
        self::$password = (string) (getenv('TINA4_TEST_WRITE_PATH_PASSWORD') ?: '');

        self::$db = self::connect();

        foreach ([self::$tableName, self::$compositeTableName] as $table) {
            try {
                self::$db->execute("DROP TABLE {$table}");
            } catch (\Throwable) {
                // first run against this engine
            }
        }
        // The DDL lives in the shared fixture so all four frameworks create
        // literally the same table. Ids come from the case data, not a
        // sequence — an identity column would fight the explicit ids the
        // cases name.
        self::$db->execute(self::$contract['table']['ddl']);
        self::$db->execute(self::$contract['composite_table']['ddl']);
        self::$db->commit();
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$tableName, self::$compositeTableName] as $table) {
            try {
                self::$db->execute("DROP TABLE {$table}");
                self::$db->commit();
            } catch (\Throwable) {
                // teardown must never mask a failure
            }
        }
        foreach (glob(self::$dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(self::$dir);
    }

    private static function connect(): Database
    {
        return Database::create(self::$url, username: self::$username, password: self::$password);
    }

    /** @return array<string, array{0: string}> */
    public static function caseProvider(): array
    {
        $contract = self::loadContract();
        $out = [];
        foreach (array_merge($contract['cases'], $contract['errors']) as $case) {
            $out[$case['name']] = [$case['name']];
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    private function findCase(string $name): ?array
    {
        foreach (array_merge(self::$contract['cases'], self::$contract['errors']) as $case) {
            if ($case['name'] === $name) {
                return $case;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $case */
    private function tableFor(array $case): string
    {
        return ($case['table'] ?? '') === 'composite' ? self::$compositeTableName : self::$tableName;
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(Database $db, string $table): array
    {
        return $db->fetch("SELECT * FROM {$table}", [], 1000)->toArray();
    }

    /**
     * Engine-tolerant value comparison. Engines disagree on the PHP type of an
     * INTEGER column; this contract is about WHICH rows a write touched, not
     * about type mapping, which the adapter contract owns.
     */
    private function same(mixed $actual, mixed $expected): bool
    {
        return $actual === $expected || (string) $actual === (string) $expected;
    }

    /** Both tables empty. Raw DELETE, never truncate() — that is under test. */
    private function resetTables(): void
    {
        foreach ([self::$tableName, self::$compositeTableName] as $table) {
            self::$db->execute("DELETE FROM {$table}");
        }
        self::$db->commit();
    }

    /** @param array<string, mixed> $case */
    private function seed(array $case, string $table): void
    {
        foreach ($case['seed'] ?? [] as $row) {
            self::$db->insert($table, $row);
        }
        self::$db->commit();
    }

    /** @param array<string, mixed> $case */
    private function runOp(array $case, string $table): mixed
    {
        $data = $case['data'] ?? null;

        switch ($case['op']) {
            case 'insert':
            case 'insert_batch':
                return self::$db->insert($table, $data);

            case 'update':
                if (array_key_exists('filter_sql', $case)) {
                    return self::$db->update($table, $data, $case['filter_sql'], $case['filter_params']);
                }
                if (array_key_exists('filter', $case)) {
                    return self::$db->update($table, $data, $case['filter']);
                }
                return self::$db->update($table, $data);

            case 'delete':
                if (array_key_exists('filter_sql', $case)) {
                    return self::$db->delete($table, $case['filter_sql'], $case['filter_params']);
                }
                if (array_key_exists('filter', $case)) {
                    return self::$db->delete($table, $case['filter']);
                }
                return self::$db->delete($table);

            case 'truncate':
                return self::$db->truncate($table);

            case 'primary_key':
                return self::$db->primaryKey($table);

            case 'transaction_rollback':
            case 'transaction_commit':
                self::$db->startTransaction();
                self::$db->insert($table, $data);
                if ($case['op'] === 'transaction_commit') {
                    self::$db->commit();
                } else {
                    self::$db->rollback();
                }
                return null;

            case 'execute_raw':
                return self::$db->execute($case['sql']);
        }

        $this->fail("unimplemented op {$case['op']} in case {$case['name']}");
    }

    /**
     * Every expectation the fixture declares, checked by name.
     *
     * @param array<string, mixed> $case
     */
    private function checkExpectations(array $case, string $table, mixed $result): void
    {
        $name = $case['name'];
        $expect = $case['expect'] ?? [];

        if (array_key_exists('affected_rows', $expect)) {
            $this->assertSame($expect['affected_rows'], $result->affectedRows, "{$name}: wrong affectedRows");
        }

        $rows = (array_key_exists('rows_after', $expect) || array_key_exists('unchanged', $expect))
            ? $this->rows(self::$db, $table)
            : [];

        if (array_key_exists('rows_after', $expect)) {
            $this->assertCount(
                $expect['rows_after'],
                $rows,
                "{$name}: expected {$expect['rows_after']} row(s) after, got " . json_encode($rows)
            );
        }

        foreach ($expect['unchanged'] ?? [] as $matcher) {
            $matched = 0;
            foreach ($rows as $row) {
                $all = true;
                foreach ($matcher as $key => $value) {
                    if (!$this->same($row[$key] ?? null, $value)) {
                        $all = false;
                        break;
                    }
                }
                if ($all) {
                    $matched++;
                }
            }
            $this->assertSame(
                1,
                $matched,
                "{$name}: expected exactly one row matching " . json_encode($matcher)
                . ", found {$matched} in " . json_encode($rows)
            );
        }

        if (array_key_exists('primary_key', $expect)) {
            $this->assertSame($expect['primary_key'], $result, "{$name}: wrong primary key");
        }

        if ($expect['last_id_is_null'] ?? false) {
            $this->assertNull(
                $result->lastId,
                "{$name}: lastId is insert-only, but this write reported " . var_export($result->lastId, true)
            );
        }

        if (array_key_exists('last_id_is_not_stale', $expect)) {
            $reported = $result->lastId;
            // null / 0 / "" all mean "this engine cannot report one", which the
            // contract allows. A stale value is the failure being pinned.
            if ($reported !== null && $reported !== 0 && $reported !== '') {
                $this->assertFalse(
                    $this->same($reported, $expect['last_id_is_not_stale']),
                    "{$name}: lastId came back as the EARLIER row's id — the adapter is "
                    . "reporting a stale id rather than null"
                );
            }
        }

        if ($expect['visible_after_reconnect'] ?? false) {
            // A write only visible on the connection that made it is not
            // durable, and reading it back on the same handle cannot tell.
            $other = self::connect();
            $this->assertCount(
                $expect['rows_after'] ?? 1,
                $this->rows($other, $table),
                "{$name}: the write is not visible on a second connection — it was never durable"
            );
        }
    }

    /**
     * Not a gate — the record. A reader must be able to see whether this
     * contract was checked against a real engine or only against the one that
     * shares nothing with them.
     */
    public function testTheRunRecordsWhichEngineItCovered(): void
    {
        fwrite(STDERR, "\nwrite-path contract covered: " . self::$db->getAdapter()->getDatabaseType() . "\n");
        $this->assertNotSame('', self::$url);
    }

    /**
     * The orphan guard. A case naming an op the dispatcher does not implement
     * must FAIL, never be quietly skipped — silent skipping is how the fixture
     * went unread for its whole life while the runners drifted apart.
     */
    public function testTheRunnerImplementsEveryOpTheSharedFixtureUses(): void
    {
        $declared = [];
        foreach (array_merge(self::$contract['cases'], self::$contract['errors']) as $case) {
            $declared[$case['op']] = true;
        }
        $this->assertSame(
            [],
            array_values(array_diff(array_keys($declared), self::IMPLEMENTED_OPS)),
            'the shared fixture declares op(s) this runner cannot execute'
        );
    }

    /**
     * Every case in the shared fixture, checked against the same answer key.
     *
     * @dataProvider caseProvider
     */
    public function testCaseFromTheSharedFixture(string $name): void
    {
        $case = $this->findCase($name);
        $this->assertNotNull($case, "case {$name} vanished from the fixture");

        $table = $this->tableFor($case);
        $this->resetTables();
        $this->seed($case, $table);

        if ($case['expect_raises'] ?? false) {
            $threw = false;
            try {
                $this->runOp($case, $table);
            } catch (\Throwable) {
                $threw = true;
            }
            $this->assertTrue($threw, "{$name}: the op did not raise");
            // The write must not have landed. This is the data-loss property.
            $this->checkExpectations($case, $table, null);
            if ($case['expect_error_recorded'] ?? false) {
                $this->assertNotSame(
                    '',
                    (string) self::$db->getError(),
                    "{$name}: the statement raised but getError() was empty — "
                    . "the cause has to stay readable after the raise"
                );
            }
            return;
        }

        $this->checkExpectations($case, $table, $this->runOp($case, $table));
    }
}
