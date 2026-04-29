<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;

/**
 * Regression tests for the pool round-robin transaction bug.
 *
 * Before the adapter-pin fix, every Database method call rotated to a
 * different pooled connection. Result: startTransaction() pinned a flag
 * on adapter A, the executes autocommitted on adapters B and C, and the
 * final commit/rollback landed on adapter D — meaningless. Rollbacks
 * were silently no-op'd and writes leaked through.
 *
 * These tests fail (3 rows leaking despite rollback) before the
 * pinnedAdapter property in Database::getNextAdapter() and pass after it.
 *
 * Mirrors tests/test_database.py::TestPoolTransactionAtomicity in tina4-python.
 */
class PoolTransactionAtomicityTest extends TestCase
{
    private string $tmpDb = '';

    protected function setUp(): void
    {
        // Pool tests need a shared on-disk SQLite file because :memory: is
        // private per connection — pooled adapters would each get their own
        // empty database and the test would be meaningless.
        $this->tmpDb = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'tina4_pool_atomicity_' . uniqid('', true) . '.db';
    }

    protected function tearDown(): void
    {
        if ($this->tmpDb !== '' && file_exists($this->tmpDb)) {
            @unlink($this->tmpDb);
        }
    }

    private function pooledDb(int $pool = 4): Database
    {
        $db = Database::create('sqlite:///' . $this->tmpDb, pool: $pool);
        $db->execute('CREATE TABLE IF NOT EXISTS t (id INTEGER PRIMARY KEY, val TEXT)');
        $db->execute('DELETE FROM t');
        return $db;
    }

    public function testRollbackUndoesInsertsUnderPool(): void
    {
        $db = $this->pooledDb(4);

        $db->startTransaction();
        $db->execute("INSERT INTO t (id, val) VALUES (1, 'a')");
        $db->execute("INSERT INTO t (id, val) VALUES (2, 'b')");
        $db->execute("INSERT INTO t (id, val) VALUES (3, 'c')");
        $db->rollback();

        $row = $db->fetchOne('SELECT count(*) AS n FROM t');
        $n = (int) ($row['n'] ?? -1);
        $db->close();

        $this->assertSame(
            0,
            $n,
            "rollback() leaked {$n} of 3 rows under pool=4 — adapter pin not honoured"
        );
    }

    public function testCommitPersistsInsertsUnderPool(): void
    {
        $db = $this->pooledDb(4);

        $db->startTransaction();
        $db->execute("INSERT INTO t (id, val) VALUES (10, 'x')");
        $db->execute("INSERT INTO t (id, val) VALUES (20, 'y')");
        $db->execute("INSERT INTO t (id, val) VALUES (30, 'z')");
        $db->commit();

        $row = $db->fetchOne('SELECT count(*) AS n FROM t');
        $n = (int) ($row['n'] ?? -1);
        $db->close();

        $this->assertSame(
            3,
            $n,
            "commit() persisted only {$n} of 3 rows under pool=4"
        );
    }

    public function testPinReleasesAfterCommit(): void
    {
        $db = $this->pooledDb(4);

        $db->startTransaction();
        $db->commit();

        // After commit(), the next call should NOT be pinned to the same
        // adapter — round-robin should resume.
        $seen = [];
        for ($i = 0; $i < 8; $i++) {
            $seen[spl_object_id($db->getAdapter())] = true;
        }
        $db->close();

        $this->assertGreaterThan(
            1,
            count($seen),
            'after commit() the pin was not released — getAdapter() never rotated'
        );
    }

    public function testPinReleasesAfterRollback(): void
    {
        $db = $this->pooledDb(4);

        $db->startTransaction();
        $db->rollback();

        $seen = [];
        for ($i = 0; $i < 8; $i++) {
            $seen[spl_object_id($db->getAdapter())] = true;
        }
        $db->close();

        $this->assertGreaterThan(
            1,
            count($seen),
            'after rollback() the pin was not released — getAdapter() never rotated'
        );
    }

    public function testNoPoolNoRegression(): void
    {
        // Single-connection mode (pool=0) must still work correctly — the
        // pin code path should be a no-op when there is no pool to fight.
        $db = Database::create('sqlite:///' . $this->tmpDb, pool: 0);
        $db->execute('CREATE TABLE IF NOT EXISTS t (id INTEGER PRIMARY KEY, val TEXT)');
        $db->execute('DELETE FROM t');

        $db->startTransaction();
        $db->execute("INSERT INTO t (id, val) VALUES (1, 'r')");
        $db->rollback();

        $row = $db->fetchOne('SELECT count(*) AS n FROM t');
        $n = (int) ($row['n'] ?? -1);
        $db->close();

        $this->assertSame(
            0,
            $n,
            "pool=0 broke after the pin fix: {$n} rows leaked"
        );
    }

    public function testPinHonouredAcrossExecutesUnderPool(): void
    {
        // While pinned, every call to getAdapter() must return the same
        // adapter. This is the load-bearing invariant the production fix
        // relies on.
        $db = $this->pooledDb(4);

        $db->startTransaction();
        $a1 = $db->getAdapter();
        $a2 = $db->getAdapter();
        $a3 = $db->getAdapter();
        $db->commit();
        $db->close();

        $this->assertSame(
            spl_object_id($a1),
            spl_object_id($a2),
            'pinned adapter rotated mid-transaction'
        );
        $this->assertSame(
            spl_object_id($a2),
            spl_object_id($a3),
            'pinned adapter rotated mid-transaction'
        );
    }
}
