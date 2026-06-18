<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * DB-contract A + B + C (v3.13.37) — parity with the Python master's
 * tina4-python/tests/test_db_contract_abc.py.
 *
 *   A — fetch() / fetchOne() / fetchAll() FAIL LOUD on a SQL error and
 *       populate getError(); a failed read is never cached.
 *   B — getNextId() is ATOMIC (no duplicate primary keys under concurrency).
 *   C — commit() failure re-raises + retains the pin; rollback() always
 *       clears the pin; nested startTransaction() is guarded.
 *
 * Engine-agnostic: SQLite (in-memory or on-disk temp file) so the suite runs
 * with no DB server.
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Database\CachedDatabase;
use Tina4\Database\Database;
use Tina4\Database\DatabaseAdapter;
use Tina4\Database\DatabaseException;
use Tina4\Database\DatabaseResult;

class DbContractAbcTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        // Cache OFF for the contract tests (default). The cache-specific test
        // builds its own connection with TINA4_AUTO_CACHING=true.
        $this->db = Database::create('sqlite::memory:');
        $this->db->execute(
            'CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT NOT NULL)'
        );
        $this->db->execute("INSERT INTO widgets (id, name) VALUES (1, 'alpha')");
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    // ── THEME A — fetch / fetchOne / fetchAll FAIL LOUD ────────────────────

    public function testFetchRaisesOnBadSqlAndPopulatesGetError(): void
    {
        try {
            $this->db->fetch('SELECT * FROM no_such_table');
            $this->fail('fetch() must raise on a bad statement, not return empty');
        } catch (\Throwable $e) {
            $this->assertNotNull(
                $this->db->getError(),
                'getError() must carry the cause after a failed fetch()'
            );
        }
    }

    public function testFetchOneRaisesOnBadSqlAndPopulatesGetError(): void
    {
        try {
            $this->db->fetchOne('SELECT * FROM no_such_table');
            $this->fail('fetchOne() must raise on a bad statement, not return null');
        } catch (\Throwable $e) {
            $this->assertNotNull(
                $this->db->getError(),
                'getError() must carry the cause after a failed fetchOne()'
            );
        }
    }

    public function testFetchAllRaisesOnBadSql(): void
    {
        $this->expectException(\Throwable::class);
        $this->db->fetchAll('SELECT * FROM no_such_table');
    }

    public function testFetchOneBadColumnRaisesNotEmpty(): void
    {
        // A typo'd column must RAISE — not look like "no matching rows" (null).
        try {
            $this->db->fetchOne('SELECT nonexistent_col FROM widgets');
            $this->fail('a bad column must raise, not return null');
        } catch (\Throwable $e) {
            $this->assertNotNull($this->db->getError());
        }
    }

    public function testCountProbeFailureDoesNotMaskMainQuerySuccess(): void
    {
        // The MAIN query is valid and returns a row. (The COUNT probe wrapping
        // is best-effort; a valid main query must still succeed and the error
        // must be cleared.) This proves the probe path doesn't sabotage a good
        // read.
        $result = $this->db->fetch('SELECT * FROM widgets WHERE id = 1');
        $this->assertCount(1, $result->records);
        $this->assertSame('alpha', $result->records[0]['name']);
        $this->assertNull($this->db->getError());
    }

    public function testSuccessfulReadClearsLastError(): void
    {
        // Seed an error.
        try {
            $this->db->fetchOne('SELECT * FROM no_such_table');
        } catch (\Throwable) {
            // ignore — we just want getError() populated
        }
        $this->assertNotNull($this->db->getError());

        // A later successful read clears it.
        $row = $this->db->fetchOne('SELECT name FROM widgets WHERE id = 1');
        $this->assertSame('alpha', $row['name']);
        $this->assertNull($this->db->getError());
    }

    public function testFailedReadIsNotCached(): void
    {
        // Build a connection with the request-scoped query cache ENABLED.
        // DotEnv::getEnv() consults its internal store, then $_ENV, then
        // getenv() — set $_ENV + putenv() so the CachedDatabase constructor
        // reads the flag.
        \Tina4\DotEnv::resetEnv();
        $_ENV['TINA4_AUTO_CACHING'] = 'true';
        putenv('TINA4_AUTO_CACHING=true');
        try {
            $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR
                . 'tina4_cache_fail_' . uniqid('', true) . '.db';
            $db = Database::create('sqlite:///' . $tmp);

            // Confirm the request cache is actually live, else the test is moot.
            $this->assertTrue($db->cacheStats()['enabled'], 'request cache must be on');

            // Query a MISSING table under caching — must RAISE (not cache a null).
            try {
                $db->fetchOne('SELECT * FROM late_table WHERE id = 1');
                $this->fail('a read against a missing table must raise');
            } catch (\Throwable) {
                // expected
            }

            // Create the table + row, then the SAME query must return the real
            // row — proving the earlier failure was never cached as "null".
            $db->execute('CREATE TABLE late_table (id INTEGER PRIMARY KEY, v TEXT)');
            $db->execute("INSERT INTO late_table (id, v) VALUES (1, 'real')");
            $row = $db->fetchOne('SELECT * FROM late_table WHERE id = 1');
            $this->assertNotNull($row, 'a buried failure must not have been cached');
            $this->assertSame('real', $row['v']);

            $db->close();
            @unlink($tmp);
            @unlink($tmp . '-wal');
            @unlink($tmp . '-shm');
        } finally {
            unset($_ENV['TINA4_AUTO_CACHING']);
            putenv('TINA4_AUTO_CACHING');
            \Tina4\DotEnv::resetEnv();
        }
    }

    // ── THEME B — atomic getNextId (no duplicate PKs) ─────────────────────

    public function testGetNextIdSequential(): void
    {
        // widgets already has id=1 → seed from MAX(pk) gives 2, 3, 4 ...
        $this->assertSame(2, $this->db->getNextId('widgets'));
        $this->assertSame(3, $this->db->getNextId('widgets'));
        $this->assertSame(4, $this->db->getNextId('widgets'));
    }

    public function testGetNextIdConcurrentNoDuplicates(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork is required for the concurrency test');
        }

        // Concurrency needs a shared on-disk SQLite file — :memory: is private
        // per connection, so forked children would each get an empty database.
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'tina4_seq_concurrent_' . uniqid('', true) . '.db';
        $outDir = $tmp . '.ids';
        @mkdir($outDir);

        // Seed the table + sequence row from the parent first, so every child
        // hits the atomic increment (not a concurrent CREATE TABLE race).
        $seed = Database::create('sqlite:///' . $tmp);
        $seed->execute('CREATE TABLE items (id INTEGER PRIMARY KEY, v TEXT)');
        $seed->getNextId('items'); // creates tina4_sequences + the row, returns 1
        $seed->close();

        $n = 60;
        $pids = [];
        for ($i = 0; $i < $n; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork failed');
            }
            if ($pid === 0) {
                // Child — its own connection to the shared file.
                $child = Database::create('sqlite:///' . $tmp);
                try {
                    $id = $child->getNextId('items');
                    file_put_contents($outDir . '/' . $i, (string) $id);
                } catch (\Throwable $e) {
                    file_put_contents($outDir . '/' . $i, 'ERR:' . $e->getMessage());
                }
                $child->close();
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $ids = [];
        $errors = [];
        foreach (glob($outDir . '/*') as $file) {
            $val = trim((string) file_get_contents($file));
            if (str_starts_with($val, 'ERR:')) {
                $errors[] = $val;
            } else {
                $ids[] = (int) $val;
            }
            @unlink($file);
        }
        @rmdir($outDir);
        @unlink($tmp);
        @unlink($tmp . '-wal');
        @unlink($tmp . '-shm');

        $this->assertSame([], $errors, 'no getNextId() call may error: ' . implode('; ', $errors));
        $this->assertCount($n, $ids, "expected {$n} ids");
        $this->assertSame(
            count($ids),
            count(array_unique($ids)),
            'getNextId() returned DUPLICATE ids under concurrency: '
                . implode(',', $ids)
        );
    }

    // ── THEME C — commit failure + pin retention + nested guard ───────────

    public function testCommitFailureRaisesRetainsPinThenRollbackCleansUp(): void
    {
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'tina4_commit_fail_' . uniqid('', true) . '.db';
        $base = Database::create('sqlite:///' . $tmp);
        $base->execute('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');

        // Wrap the live SQLite adapter so its commit() raises ONCE.
        $flaky = new FlakyCommitAdapter($base->getAdapter());
        $db = $this->databaseWithAdapter($flaky);

        $db->startTransaction();
        $db->execute("INSERT INTO t (id, v) VALUES (1, 'x')");

        // commit() must RE-RAISE and populate getError().
        try {
            $db->commit();
            $this->fail('commit() must re-raise when the underlying commit fails');
        } catch (\Throwable $e) {
            $this->assertNotNull($db->getError(), 'getError() populated on commit failure');
        }

        // The pin must be RETAINED so rollback lands on the same connection.
        $this->assertTrue(
            $this->pinIsRetained($db),
            'the transaction pin must be retained after a failed commit'
        );

        // A follow-up rollback on the SAME connection cleans up + clears the pin.
        $db->rollback();
        $this->assertFalse(
            $this->pinIsRetained($db),
            'rollback() must clear the pin (terminal cleanup)'
        );

        $base->close();
        @unlink($tmp);
        @unlink($tmp . '-wal');
        @unlink($tmp . '-shm');
    }

    public function testSuccessfulCommitClearsPin(): void
    {
        $this->db->startTransaction();
        $this->db->execute("INSERT INTO widgets (id, name) VALUES (99, 'committed')");
        $this->db->commit();

        $this->assertFalse(
            $this->pinIsRetained($this->db),
            'a successful commit must release the pin'
        );
        $row = $this->db->fetchOne('SELECT name FROM widgets WHERE id = 99');
        $this->assertSame('committed', $row['name']);
    }

    public function testRollbackAlwaysClearsPin(): void
    {
        $this->db->startTransaction();
        $this->db->execute("INSERT INTO widgets (id, name) VALUES (50, 'rolled')");
        $this->db->rollback();

        $this->assertFalse(
            $this->pinIsRetained($this->db),
            'rollback() must clear the pin'
        );
        // The insert must NOT have persisted.
        $row = $this->db->fetchOne('SELECT name FROM widgets WHERE id = 50');
        $this->assertNull($row, 'rolled-back insert must not persist');
    }

    public function testNestedStartTransactionIsGuarded(): void
    {
        $this->db->startTransaction();          // depth 1
        $this->db->startTransaction();          // nested → depth 2, warns + no-op

        $this->db->execute("INSERT INTO widgets (id, name) VALUES (200, 'nested')");

        // Inner commit unwinds depth 2 → 1, keeping the pin + the open txn.
        $this->db->commit();
        $this->assertTrue(
            $this->pinIsRetained($this->db),
            'inner commit must keep the pin (only unwinds the depth)'
        );

        // Outer commit is the real one → releases the pin + persists the row.
        $this->db->commit();
        $this->assertFalse(
            $this->pinIsRetained($this->db),
            'outer commit must release the pin'
        );
        $row = $this->db->fetchOne('SELECT name FROM widgets WHERE id = 200');
        $this->assertSame(
            'nested',
            $row['name'],
            'the single real transaction must have persisted exactly once'
        );
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** Build a Database whose single connection is the given adapter. */
    private function databaseWithAdapter(DatabaseAdapter $adapter): Database
    {
        $db = Database::create('sqlite::memory:');
        $ref = new \ReflectionClass($db);
        $prop = $ref->getProperty('adapter');
        $prop->setValue($db, $adapter);
        return $db;
    }

    /** Read the private pinnedAdapter to assert pin state. */
    private function pinIsRetained(Database $db): bool
    {
        $ref = new \ReflectionClass($db);
        $prop = $ref->getProperty('pinnedAdapter');
        return $prop->getValue($db) !== null;
    }
}

/**
 * Decorator whose commit() throws ONCE (then delegates) — the PHP analogue of
 * the Python test's monkeypatched adapter.commit. Everything else delegates to
 * the wrapped live adapter so the transaction really runs on a real connection.
 */
class FlakyCommitAdapter implements DatabaseAdapter
{
    private DatabaseAdapter $inner;
    private bool $failed = false;

    public function __construct(DatabaseAdapter $inner)
    {
        $this->inner = $inner;
    }

    public function commit(): void
    {
        if (!$this->failed) {
            $this->failed = true;
            throw new DatabaseException('simulated commit failure');
        }
        $this->inner->commit();
    }

    public function open(): void { $this->inner->open(); }
    public function close(): void { $this->inner->close(); }
    public function query(string $sql, array $params = []): array { return $this->inner->query($sql, $params); }
    public function fetch(string $sql, array $params = [], int $limit = 100, int $offset = 0): array { return $this->inner->fetch($sql, $params, $limit, $offset); }
    public function fetchOne(string $sql, array $params = []): ?array { return $this->inner->fetchOne($sql, $params); }
    public function execute(string $sql, array $params = []): bool|DatabaseResult { return $this->inner->execute($sql, $params); }
    public function executeMany(string $sql, array $paramsList = []): int { return $this->inner->executeMany($sql, $paramsList); }
    public function insert(string $table, array $data): bool { return $this->inner->insert($table, $data); }
    public function update(string $table, array $data, string $where = '', array $whereParams = []): bool { return $this->inner->update($table, $data, $where, $whereParams); }
    public function delete(string $table, string|array $filter = '', array $whereParams = []): bool { return $this->inner->delete($table, $filter, $whereParams); }
    public function startTransaction(): void { $this->inner->startTransaction(); }
    public function rollback(): void { $this->inner->rollback(); }
    public function tableExists(string $table): bool { return $this->inner->tableExists($table); }
    public function getColumns(string $table): array { return $this->inner->getColumns($table); }
    public function getTables(): array { return $this->inner->getTables(); }
    public function lastInsertId(): int|string { return $this->inner->lastInsertId(); }
    public function error(): ?string { return $this->inner->error(); }
}
