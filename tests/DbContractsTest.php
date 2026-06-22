<?php

/**
 * Tina4 - The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Database contract tests - the behaviours apps depend on, locked against drift.
 *
 * Mirrors the Python master spec tina4-python/tests/test_db_contracts.py:
 *
 *   * execute() RAISES on a SQL error (never silently returns false), so a
 *     failing write propagates and a transaction rolls back.
 *   * read-after-write within a request is consistent (the request-scoped cache
 *     is off by default and never serves a pre-write value).
 *   * getNextId() is strictly monotonic + unique across repeated calls (no
 *     duplicate keys from a cached MAX(id)).
 *   * transactions bracket correctly: commit persists, rollback discards.
 *
 * These run against a real SQLite database. Engine-agnostic in intent - the same
 * contracts are mirrored in the Python/Ruby/Node suites.
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;

class DbContractsTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        // Cache OFF (default) so read-after-write is real, not served from cache.
        $this->db = Database::create('sqlite::memory:');
        $this->db->execute(
            'CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, qty INTEGER)'
        );
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    // -- execute() RAISES on a SQL error -----------------------------------

    public function testBadSqlRaisesNotReturnsFalse(): void
    {
        $this->expectException(\Throwable::class);
        $this->db->execute('INSERT INTO does_not_exist (x) VALUES (1)');
    }

    public function testCauseCapturedOnGetError(): void
    {
        try {
            $this->db->execute('THIS IS NOT VALID SQL');
        } catch (\Throwable) {
            // ignore - we just want getError() populated
        }
        $this->assertNotEmpty($this->db->getError());
    }

    public function testConstraintViolationRaises(): void
    {
        $this->db->execute("INSERT INTO items (id, name) VALUES (1, 'a')");
        $this->expectException(\Throwable::class);
        $this->db->execute("INSERT INTO items (id, name) VALUES (1, 'dup')"); // duplicate PK
    }

    public function testSuccessfulWriteIsTruthy(): void
    {
        $result = $this->db->execute("INSERT INTO items (name, qty) VALUES ('a', 1)");
        $this->assertTrue((bool)$result); // never false on success
    }

    // -- read-after-write consistency --------------------------------------

    public function testInsertIsImmediatelyVisible(): void
    {
        $this->db->execute("INSERT INTO items (name, qty) VALUES ('widget', 5)");
        $row = $this->db->fetchOne('SELECT name, qty FROM items WHERE name = ?', ['widget']);
        $this->assertSame(5, (int)$row['qty']);
    }

    public function testUpdateIsImmediatelyVisible(): void
    {
        $this->db->execute("INSERT INTO items (id, name, qty) VALUES (1, 'a', 1)");
        $this->db->execute('UPDATE items SET qty = 99 WHERE id = 1');
        $this->assertSame(99, (int)$this->db->fetchOne('SELECT qty FROM items WHERE id = 1')['qty']);
    }

    public function testMaxIdReflectsLatestWrite(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->db->execute('INSERT INTO items (id, name) VALUES (?, ?)', [$i, "n$i"]);
            $mx = (int)$this->db->fetchOne('SELECT MAX(id) AS m FROM items')['m'];
            $this->assertSame($i, $mx); // no stale pre-write MAX from a request cache
        }
    }

    // -- getNextId() strict monotonicity + uniqueness ----------------------

    public function testGetNextIdStrictlyIncreasingAndUnique(): void
    {
        $ids = [];
        for ($i = 0; $i < 50; $i++) {
            $ids[] = $this->db->getNextId('orders');
        }
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids); // already sorted ascending
        $this->assertSame(count($ids), count(array_unique($ids))); // no duplicates
        // contiguous
        for ($i = 1; $i < count($ids); $i++) {
            $this->assertSame(1, $ids[$i] - $ids[$i - 1]);
        }
    }

    public function testIndependentSequencesPerTable(): void
    {
        $a = $this->db->getNextId('table_a');
        $b = $this->db->getNextId('table_b');
        $this->assertSame(1, $a);
        $this->assertSame(1, $b); // separate counters, both start fresh
    }

    // -- transaction bracketing --------------------------------------------

    public function testCommitPersists(): void
    {
        $this->db->startTransaction();
        $this->db->execute("INSERT INTO items (name) VALUES ('kept')");
        $this->db->commit();
        $this->assertSame(
            1,
            (int)$this->db->fetchOne("SELECT count(*) AS c FROM items WHERE name='kept'")['c']
        );
    }

    public function testRollbackDiscards(): void
    {
        $this->db->execute("INSERT INTO items (name) VALUES ('before')");
        $this->db->startTransaction();
        $this->db->execute("INSERT INTO items (name) VALUES ('rolled')");
        $this->db->rollback();
        $this->assertSame(
            0,
            (int)$this->db->fetchOne("SELECT count(*) AS c FROM items WHERE name='rolled'")['c']
        );
        $this->assertSame(
            1,
            (int)$this->db->fetchOne("SELECT count(*) AS c FROM items WHERE name='before'")['c']
        );
    }

    public function testFailedWriteInTxnCanRollBack(): void
    {
        $this->db->startTransaction();
        $this->db->execute("INSERT INTO items (id, name) VALUES (1, 'a')");
        try {
            $this->db->execute("INSERT INTO items (id, name) VALUES (1, 'dup')"); // raises
        } catch (\Throwable) {
            $this->db->rollback();
        }
        $this->assertSame(
            0,
            (int)$this->db->fetchOne('SELECT count(*) AS c FROM items')['c']
        );
    }
}
