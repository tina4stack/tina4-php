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
use Tina4\Database\Database;

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

    /**
     * A REAL commit failure on a REAL engine — no wrapper, no simulation.
     *
     * SQLite checks DEFERRED foreign keys at COMMIT time
     * (`PRAGMA defer_foreign_keys = ON`, which the engine resets on every
     * COMMIT/ROLLBACK). A child row pointing at a parent that does not exist is
     * therefore accepted by the INSERT and REFUSED by the COMMIT with
     * SQLITE_CONSTRAINT, leaving the transaction open and awaiting a ROLLBACK.
     * That is precisely the state contract C describes, produced by the database
     * itself.
     *
     * Two cheaper-looking routes do NOT work here, and both were measured:
     * chmod on an ALREADY-OPEN sqlite handle does not deny writes (SQLite checks
     * permissions at open), and chmod 0500 on the containing directory does not
     * either; the denial only materialises for a connection opened AFTER the
     * chmod — at which point the INSERT fails and COMMIT is never reached, so it
     * would test the wrong call.
     */
    public function testCommitFailureRaisesRetainsPinThenRollbackCleansUp(): void
    {
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'tina4_commit_fail_' . uniqid('', true) . '.db';
        $db = Database::create('sqlite:///' . $tmp);

        try {
            $db->execute('CREATE TABLE parent (id INTEGER PRIMARY KEY)');
            $db->execute(
                'CREATE TABLE child (id INTEGER PRIMARY KEY, '
                . 'parent_id INTEGER NOT NULL REFERENCES parent(id))'
            );

            $db->startTransaction();
            $db->execute('PRAGMA defer_foreign_keys = ON');
            // Accepted now, refused at COMMIT: parent 999 does not exist.
            $db->execute("INSERT INTO child (id, parent_id) VALUES (1, 999)");

            // commit() must RE-RAISE and populate getError().
            $raised = null;
            try {
                $db->commit();
            } catch (\Throwable $e) {
                $raised = $e;
            }
            $this->assertNotNull(
                $raised,
                'commit() must re-raise when the engine refuses the commit'
            );
            $this->assertStringContainsStringIgnoringCase(
                'foreign key',
                $raised->getMessage(),
                'the raised error must be the ENGINE\'s commit-time constraint failure'
            );
            $this->assertNotNull($db->getError(), 'getError() populated on commit failure');

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

            // ... and the engine really discarded the uncommitted row.
            $this->assertNull(
                $db->fetchOne('SELECT id FROM child WHERE id = 1'),
                'the row from the failed transaction must not have persisted'
            );
        } finally {
            $db->close();
            @unlink($tmp);
            @unlink($tmp . '-wal');
            @unlink($tmp . '-shm');
        }
    }

    /**
     * The POSITIVE control for the case above: the identical deferred-FK setup
     * with a parent that DOES exist commits cleanly and releases the pin. Proves
     * the failure test fails because of the constraint, not because deferring
     * foreign keys breaks commits.
     */
    public function testSatisfiedDeferredForeignKeyCommitsAndReleasesPin(): void
    {
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'tina4_commit_ok_' . uniqid('', true) . '.db';
        $db = Database::create('sqlite:///' . $tmp);

        try {
            $db->execute('CREATE TABLE parent (id INTEGER PRIMARY KEY)');
            $db->execute(
                'CREATE TABLE child (id INTEGER PRIMARY KEY, '
                . 'parent_id INTEGER NOT NULL REFERENCES parent(id))'
            );

            $db->startTransaction();
            $db->execute('PRAGMA defer_foreign_keys = ON');
            // Child first, parent second — legal precisely because the check is
            // deferred to COMMIT, and satisfied by the time it runs.
            $db->execute("INSERT INTO child (id, parent_id) VALUES (1, 7)");
            $db->execute('INSERT INTO parent (id) VALUES (7)');
            $db->commit();

            $this->assertFalse(
                $this->pinIsRetained($db),
                'a successful commit must release the pin'
            );
            $row = $db->fetchOne('SELECT parent_id FROM child WHERE id = 1');
            $this->assertSame(7, (int)$row['parent_id'], 'the committed row persisted');
            $this->assertNull($db->getError());
        } finally {
            $db->close();
            @unlink($tmp);
            @unlink($tmp . '-wal');
            @unlink($tmp . '-shm');
        }
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

    /** Read the private pinnedAdapter to assert pin state. */
    private function pinIsRetained(Database $db): bool
    {
        $ref = new \ReflectionClass($db);
        $prop = $ref->getProperty('pinnedAdapter');
        return $prop->getValue($db) !== null;
    }
}
