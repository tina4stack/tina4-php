<?php
/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tina4\Database\PdoPostgresAdapter;

/**
 * PostgreSQL over pdo_pgsql: a connection the server drops is replaced, not
 * reused forever (parity with the ext-pgsql PostgresAdapter #319 and the Python
 * master #236/#242; issue #245).
 *
 * BUG (pre-fix): PdoPostgresAdapter — the SILENT fallback used when ext-pgsql is
 * absent but pdo_pgsql is present — never re-established a dropped link. Once the
 * server closed the connection (failover, idle reap, pg_terminate_backend) every
 * later statement failed on the dead PDO until the process was restarted, while
 * the ext-pgsql adapter next to it recovered.
 *
 * FIX: query()/execute() reconnect and retry ONCE when a dropped link — not a
 * real SQL error — made the statement fail, outside an explicit transaction. A
 * reconnect that itself fails leaves the link closed, so the next statement
 * reopens and recovers once a server returns. Transaction control stays raw: a
 * drop inside a transaction surfaces and is never silently retried on a fresh link.
 *
 * NO MOCKS — a REAL PostgreSQL over the PDO adapter. The drop is a real
 * pg_terminate_backend issued from a second PDO connection; every assertion reads
 * what the server holds. Skips only when pdo_pgsql or the server is absent.
 */
final class PdoPostgresReconnectTest extends TestCase
{
    private const PG_DB = 'tina4_php';
    private const TABLE = 't4_pdo_pg_reconnect';

    private ?PdoPostgresAdapter $db = null;
    private ?PdoPostgresAdapter $killer = null;

    protected function setUp(): void
    {
        if (!in_array('pgsql', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('[needs:postgres] the pdo_pgsql PDO driver is not available.');
        }
        $pg = \PgTestEnv::resolve();
        if (!$pg->reachable()) {
            $this->markTestSkipped(sprintf(
                '[needs:postgres] PostgreSQL not reachable at %s:%d - skip integration test',
                $pg->host,
                $pg->port
            ));
        }
        $url = $pg->url(self::PG_DB);
        $this->db = new PdoPostgresAdapter($url, username: $pg->user, password: $pg->pass);
        $this->killer = new PdoPostgresAdapter($url, username: $pg->user, password: $pg->pass);
        $this->db->execute('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->db->execute('CREATE TABLE ' . self::TABLE . ' (id serial PRIMARY KEY, note text)');
    }

    protected function tearDown(): void
    {
        try {
            $this->db?->execute('DROP TABLE IF EXISTS ' . self::TABLE);
        } catch (\Throwable) {
            // best effort
        }
    }

    private function backendPid(PdoPostgresAdapter $db): int
    {
        return (int) $db->query('SELECT pg_backend_pid() AS p')[0]['p'];
    }

    private function dropTheConnection(int $pid): void
    {
        $this->killer->query('SELECT pg_terminate_backend(?)', [$pid]);
    }

    public function testADroppedLinkReconnectsOnTheNextStatement(): void
    {
        $before = $this->backendPid($this->db);
        $this->dropTheConnection($before);

        $this->db->execute('INSERT INTO ' . self::TABLE . ' (note) VALUES (?)', ['after-drop']);

        $after = $this->backendPid($this->db);
        $this->assertNotSame($before, $after, 'the adapter reconnected on a fresh backend');

        $rows = $this->db->query('SELECT note FROM ' . self::TABLE . ' ORDER BY id');
        $this->assertCount(1, $rows, 'the write after the drop landed exactly once');
        $this->assertSame('after-drop', $rows[0]['note']);
    }

    public function testEveryReadPathRecoversAfterADrop(): void
    {
        $this->dropTheConnection($this->backendPid($this->db));
        $this->assertSame([], $this->db->query('SELECT note FROM ' . self::TABLE));

        $this->dropTheConnection($this->backendPid($this->db));
        $this->assertSame(1, (int) $this->db->query('SELECT 1 AS one')[0]['one']);
    }

    // NEGATIVE: a real SQL error is NOT a dropped link - it surfaces and must not
    // trigger a reconnect (the backend stays the same). Guards the reconnect
    // against swallowing or masking genuine query errors.
    public function testARealSqlErrorSurfacesAndDoesNotReconnect(): void
    {
        $before = $this->backendPid($this->db);

        $threw = false;
        try {
            $this->db->execute('INSERT INTO a_table_that_does_not_exist_245 (x) VALUES (1)');
        } catch (\Throwable) {
            $threw = true;
        }

        $this->assertTrue($threw, 'a real SQL error must surface, not be swallowed as a drop');
        $this->assertSame($before, $this->backendPid($this->db), 'a SQL error must NOT trigger a reconnect');
    }

    // NEGATIVE: a drop INSIDE an explicit transaction surfaces (never silently
    // retried on a fresh link), and the next statement after rollback recovers.
    // A ROLLBACK that fails on the dead link must still clear the in-transaction
    // state so recovery is not wedged.
    public function testADropInsideATransactionSurfacesThenRecovers(): void
    {
        $this->db->startTransaction();
        $this->dropTheConnection($this->backendPid($this->db));

        $surfaced = false;
        try {
            $this->db->execute('INSERT INTO ' . self::TABLE . ' (note) VALUES (?)', ['in-txn']);
        } catch (\Throwable) {
            $surfaced = true;
        }
        $this->assertTrue($surfaced, 'a drop inside a transaction must surface, not retry on a new link');

        try {
            $this->db->rollback();
        } catch (\Throwable) {
            // ROLLBACK on a dead link may fail - the in-transaction flag must still clear
        }

        $this->db->execute('INSERT INTO ' . self::TABLE . ' (note) VALUES (?)', ['after-txn-drop']);
        $rows = $this->db->query('SELECT note FROM ' . self::TABLE . " WHERE note = 'after-txn-drop'");
        $this->assertCount(1, $rows, 'the next statement after rollback must recover');
    }
}
