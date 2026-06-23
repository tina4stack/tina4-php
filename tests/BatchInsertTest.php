<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Regression test for the batch-insert pass (parity with the Python master).
 *
 * `Database::insert($table, [$row1, $row2, $row3])` must insert ALL rows and
 * report a sensible affected-row count and last id. The batch must run in ONE
 * transaction on ONE connection so it is ATOMIC and FAIL-LOUD:
 *
 *   - all 3 rows land (read them back);
 *   - getLastId() returns the last inserted id where the engine exposes it
 *     (SQLite AUTOINCREMENT, PostgreSQL SERIAL);
 *   - a batch with one bad row RAISES and rolls the WHOLE batch back (no
 *     partial, silently-swallowed write) — the bug the Python fix locked in:
 *       (a) the batch must be one transaction on one connection, and
 *       (b) a failing row is never silently dropped.
 *
 * These talk to the REAL database engines — no mocks. SQLite runs always
 * (in-memory). PostgreSQL runs when ext-pgsql is present and the server is
 * reachable (skip reason names "postgres"/"not reachable"). MySQL and MSSQL
 * are not provisioned, so they gated-skip.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Database\DatabaseException;

class BatchInsertTest extends TestCase
{
    // ── SQLite — always runs (in-memory, zero config) ───────────────────────

    public function testSqliteBatchInsertsAllThreeRowsAndReportsLastId(): void
    {
        $db = Database::create('sqlite::memory:', autoCommit: true);
        $db->execute(
            'CREATE TABLE batch_t (id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'name VARCHAR(50) NOT NULL, price REAL)'
        );

        $ok = $db->insert('batch_t', [
            ['name' => 'r1', 'price' => 1.0],
            ['name' => 'r2', 'price' => 2.0],
            ['name' => 'r3', 'price' => 3.0],
        ]);
        $this->assertTrue($ok, 'Batch insert should report success');

        $rows = $db->fetch('SELECT * FROM batch_t ORDER BY id', [], 100);
        $this->assertSame(3, $rows->count, 'All 3 batch rows must be inserted');
        $this->assertSame('r1', $rows->records[0]['name']);
        $this->assertSame('r2', $rows->records[1]['name']);
        $this->assertSame('r3', $rows->records[2]['name']);

        // SQLite exposes AUTOINCREMENT — the last id is the 3rd row.
        $this->assertSame(3, (int) $db->getLastId(), 'Last id must be the 3rd row');

        $db->close();
    }

    public function testSqliteBatchIsAtomicAndFailsLoudOnBadRow(): void
    {
        $db = Database::create('sqlite::memory:', autoCommit: true);
        $db->execute(
            'CREATE TABLE batch_fail (id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'name VARCHAR(50) NOT NULL, price REAL)'
        );
        // Seed one good row so we can prove the failed batch adds nothing.
        $db->insert('batch_fail', ['name' => 'seed', 'price' => 0.0]);
        $before = $db->fetch('SELECT count(*) c FROM batch_fail', [], 1)->records[0]['c'];

        // Middle row violates NOT NULL — the WHOLE batch must roll back + raise.
        $threw = false;
        try {
            $db->insert('batch_fail', [
                ['name' => 'good1', 'price' => 1.0],
                ['name' => null,    'price' => 2.0],   // violates NOT NULL
                ['name' => 'good3', 'price' => 3.0],
            ]);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'A batch with a bad row must RAISE (fail loud), not swallow it');

        $after = $db->fetch('SELECT count(*) c FROM batch_fail', [], 1)->records[0]['c'];
        $this->assertSame(
            (int) $before,
            (int) $after,
            'A failed batch must roll back entirely — no partial rows committed'
        );

        $db->close();
    }

    // ── PostgreSQL — live, gated on reachability ─────────────────────────────

    public function testPostgresBatchInsertsAllThreeRowsAndReportsLastId(): void
    {
        $db = $this->postgresOrSkip();
        $table = '_t4_batch_pg';

        try {
            $db->execute("DROP TABLE IF EXISTS {$table}");
            $db->execute(
                "CREATE TABLE {$table} (id SERIAL PRIMARY KEY, "
                . "name VARCHAR(50) NOT NULL, price DOUBLE PRECISION)"
            );

            $ok = $db->insert($table, [
                ['name' => 'r1', 'price' => 1.0],
                ['name' => 'r2', 'price' => 2.0],
                ['name' => 'r3', 'price' => 3.0],
            ]);
            $this->assertTrue($ok, 'Batch insert should report success');

            $rows = $db->fetch("SELECT * FROM {$table} ORDER BY id", [], 100);
            $this->assertSame(3, $rows->count, 'All 3 batch rows must be inserted');
            $this->assertSame('r1', $rows->records[0]['name']);
            $this->assertSame('r3', $rows->records[2]['name']);

            // PostgreSQL exposes SERIAL via lastval() — last id is the 3rd row's.
            // (Pre-fix this reported 0 / the 1st row because the per-row batch
            // scattered the last-id probe.)
            $this->assertSame(3, (int) $db->getLastId(), 'Last id must be the 3rd row');
        } finally {
            try { $db->execute("DROP TABLE IF EXISTS {$table}"); } catch (\Throwable $e) {}
            $db->close();
        }
    }

    public function testPostgresBatchIsAtomicAndFailsLoudOnBadRow(): void
    {
        $db = $this->postgresOrSkip();
        $table = '_t4_batch_pg_fail';

        try {
            $db->execute("DROP TABLE IF EXISTS {$table}");
            $db->execute(
                "CREATE TABLE {$table} (id SERIAL PRIMARY KEY, "
                . "name VARCHAR(50) NOT NULL, price DOUBLE PRECISION)"
            );
            $db->insert($table, ['name' => 'seed', 'price' => 0.0]);
            $before = $db->fetch("SELECT count(*) c FROM {$table}", [], 1)->records[0]['c'];

            $this->expectException(DatabaseException::class);
            $db->insert($table, [
                ['name' => 'good1', 'price' => 1.0],
                ['name' => null,    'price' => 2.0],   // violates NOT NULL
                ['name' => 'good3', 'price' => 3.0],
            ]);
        } finally {
            // Verify the rollback BEFORE the expected exception propagates out.
            $after = $db->fetch("SELECT count(*) c FROM {$table}", [], 1)->records[0]['c'];
            $this->assertSame(
                (int) $before,
                (int) $after,
                'A failed batch must roll back entirely on PostgreSQL'
            );
            try { $db->execute("DROP TABLE IF EXISTS {$table}"); } catch (\Throwable $e) {}
            $db->close();
        }
    }

    // ── MySQL / MSSQL — not provisioned, gated-skip ─────────────────────────

    public function testMysqlBatchInsertSkippedWhenNotProvisioned(): void
    {
        $url = getenv('TINA4_TEST_MYSQL_URL');
        if (!extension_loaded('mysqli') || !$url) {
            $this->markTestSkipped('MySQL not provisioned (set TINA4_TEST_MYSQL_URL + ext-mysqli to run).');
        }

        $db = Database::create($url, autoCommit: true);
        $table = '_t4_batch_mysql';
        try {
            $db->execute("DROP TABLE IF EXISTS {$table}");
            $db->execute(
                "CREATE TABLE {$table} (id INT AUTO_INCREMENT PRIMARY KEY, "
                . "name VARCHAR(50) NOT NULL, price DOUBLE)"
            );
            $ok = $db->insert($table, [
                ['name' => 'r1', 'price' => 1.0],
                ['name' => 'r2', 'price' => 2.0],
                ['name' => 'r3', 'price' => 3.0],
            ]);
            $this->assertTrue($ok);
            $rows = $db->fetch("SELECT * FROM {$table} ORDER BY id", [], 100);
            $this->assertSame(3, $rows->count);
            $this->assertSame(3, (int) $db->getLastId());
        } finally {
            try { $db->execute("DROP TABLE IF EXISTS {$table}"); } catch (\Throwable $e) {}
            $db->close();
        }
    }

    public function testMssqlBatchInsertSkippedWhenNotProvisioned(): void
    {
        $url = getenv('TINA4_TEST_MSSQL_URL');
        if (!function_exists('sqlsrv_connect') || !$url) {
            $this->markTestSkipped('MSSQL not provisioned (set TINA4_TEST_MSSQL_URL + ext-sqlsrv to run).');
        }

        $db = Database::create($url, autoCommit: true);
        $table = '_t4_batch_mssql';
        try {
            $db->execute("IF OBJECT_ID('{$table}', 'U') IS NOT NULL DROP TABLE {$table}");
            $db->execute(
                "CREATE TABLE {$table} (id INT IDENTITY(1,1) PRIMARY KEY, "
                . "name VARCHAR(50) NOT NULL, price FLOAT)"
            );
            $ok = $db->insert($table, [
                ['name' => 'r1', 'price' => 1.0],
                ['name' => 'r2', 'price' => 2.0],
                ['name' => 'r3', 'price' => 3.0],
            ]);
            $this->assertTrue($ok);
            $rows = $db->fetch("SELECT * FROM {$table} ORDER BY id", [], 100);
            $this->assertSame(3, $rows->count);
        } finally {
            try { $db->execute("IF OBJECT_ID('{$table}', 'U') IS NOT NULL DROP TABLE {$table}"); } catch (\Throwable $e) {}
            $db->close();
        }
    }

    // ── Helpers (plain test helpers, not mocks) ─────────────────────────────

    private function postgresOrSkip(): Database
    {
        if (!function_exists('pg_connect')) {
            $this->markTestSkipped('PostgresAdapter requires the ext-pgsql PHP extension — postgres not reachable.');
        }
        $pg = \PgTestEnv::resolve();
        if (!$pg->reachable()) {
            $this->markTestSkipped(sprintf(
                'PostgreSQL not reachable at %s:%d — skip batch-insert integration test',
                $pg->host,
                $pg->port
            ));
        }

        return Database::create($pg->url('tina4_php'), autoCommit: true, username: $pg->user, password: $pg->pass);
    }
}
