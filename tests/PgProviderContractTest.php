<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * PostgreSQL provider as the write-path ORACLE — feature 9 (pgprovider_contract.json),
 * parity with tina4-python/tests/test_pgprovider_contract.py.
 *
 * PG-DEC-01 (OWNER-DECISIONS.md Batch 5, feature 009-postgresql-provider.md): make the
 * adapter-contract test assert real write-path BEHAVIOUR — not just method presence —
 * with PostgreSQL as the reference model. PostgreSQL is the oracle for the write
 * contract: native RETURNING gives the true generated last-id, the transaction is real,
 * and the affected count is exact.
 *
 * Every case drives the lab's REAL PostgreSQL :55432 (tina4/tina4 → tina4_php) through
 * the public Database facade → PostgresAdapter. No mocks. Durability is read back on a
 * SECOND, FRESH connection where that is the property under test. The oracle table has a
 * SERIAL key and is dropped + recreated per test, so the RETURNING-generated id is
 * deterministic (first insert → 1, second → 2).
 *
 * Mutation-proof: make Database::executeMany() skip its transaction (execute each row
 * standalone) and "execute many mid batch failure rolls back on a fresh connection"
 * goes RED (the first row survives); drop the filterless-write guard and the guard
 * cases go RED.
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;

class PgProviderContractTest extends TestCase
{
    private const TABLE = 'pgprovider_oracle';

    private ?Database $db = null;

    private static function pgUrl(): string
    {
        $host = getenv('TINA4_TEST_PG_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TINA4_TEST_PG_PORT') ?: 55432);
        $db   = getenv('TINA4_TEST_PG_DB') ?: 'tina4_php';
        $user = getenv('TINA4_TEST_PG_USERNAME') ?: 'tina4';
        $pass = getenv('TINA4_TEST_PG_PASSWORD') ?: 'tina4';
        return "postgres://{$user}:{$pass}@{$host}:{$port}/{$db}";
    }

    private static function tcpReachable(string $host, int $port): bool
    {
        $conn = @fsockopen($host, $port, $errno, $errstr, 2.0);
        if ($conn === false) {
            return false;
        }
        fclose($conn);
        return true;
    }

    protected function setUp(): void
    {
        $host = getenv('TINA4_TEST_PG_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TINA4_TEST_PG_PORT') ?: 55432);
        if (!self::tcpReachable($host, $port)) {
            $this->markTestSkipped("no reachable postgres at {$host}:{$port} (set TINA4_TEST_PG_*)");
        }
        // A fresh, empty oracle table per test so SERIAL restarts at 1 — that is
        // what makes the RETURNING-generated id deterministic to assert.
        $this->db = Database::create(self::pgUrl());
        $this->db->execute('DROP TABLE IF EXISTS ' . self::TABLE);
        $this->db->execute(
            'CREATE TABLE ' . self::TABLE . ' ('
            . 'id SERIAL PRIMARY KEY, '
            . 'code VARCHAR(40) NOT NULL UNIQUE, '
            . 'qty INTEGER)'
        );
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            try {
                $this->db->execute('DROP TABLE IF EXISTS ' . self::TABLE);
            } catch (\Throwable) {
                // best effort
            }
            $this->db->close();
            $this->db = null;
        }
    }

    /**
     * Every row on a SECOND connection — the durability witness. A write only
     * visible on the connection that made it is not durable.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rowsOnFreshConnection(): array
    {
        $other = Database::create(self::pgUrl());
        try {
            return $other->fetch('SELECT * FROM ' . self::TABLE, [], 1000)->records;
        } finally {
            $other->close();
        }
    }

    // ── pg-insert-returning-last-id ─────────────────────────────────────────

    public function testInsertReturnsTheReturningGeneratedLastId(): void
    {
        $result = $this->db->insert(self::TABLE, ['code' => 'a', 'qty' => 10]);
        $this->assertEquals(1, $result->lastId, 'expected the RETURNING-generated serial id 1');
        $rows = $this->rowsOnFreshConnection();
        $this->assertCount(1, $rows);
        $this->assertEquals(1, $rows[0]['id']);
        $this->assertSame('a', $rows[0]['code']);
    }

    public function testInsertReportsAffectedRowsOfOne(): void
    {
        $result = $this->db->insert(self::TABLE, ['code' => 'a', 'qty' => 10]);
        $this->assertSame(1, $result->affectedRows, 'a single insert must report affectedRows 1');
    }

    public function testASecondInsertReturnsTheNextGeneratedIdNotAStaleOne(): void
    {
        $first = $this->db->insert(self::TABLE, ['code' => 'a', 'qty' => 10]);
        $second = $this->db->insert(self::TABLE, ['code' => 'b', 'qty' => 20]);
        $this->assertEquals(1, $first->lastId, 'first generated id should be 1');
        $this->assertEquals(2, $second->lastId, 'second insert must return the NEXT generated id 2, not a stale one');
        $this->assertNotEquals($first->lastId, $second->lastId);
    }

    // ── pg-write-affected-count ─────────────────────────────────────────────

    public function testUpdateReportsTheExactNumberOfRowsChanged(): void
    {
        $this->db->insert(self::TABLE, ['code' => 'a', 'qty' => 10]);
        $this->db->insert(self::TABLE, ['code' => 'b', 'qty' => 20]);
        $this->db->insert(self::TABLE, ['code' => 'c', 'qty' => 40]);
        $result = $this->db->update(self::TABLE, ['qty' => 99], 'qty < ?', [30]);
        $this->assertSame(2, $result->affectedRows, 'the filter matches exactly two of three rows');
    }

    public function testUpdateReportsANullLastId(): void
    {
        $this->db->insert(self::TABLE, ['code' => 'a', 'qty' => 10]);
        $result = $this->db->update(self::TABLE, ['qty' => 99], 'code = ?', ['a']);
        $this->assertNull($result->lastId, 'last_id is insert-only; an update must report null');
    }

    public function testDeleteReportsTheExactNumberOfRowsRemoved(): void
    {
        $this->db->insert(self::TABLE, ['code' => 'a', 'qty' => 10]);
        $this->db->insert(self::TABLE, ['code' => 'b', 'qty' => 20]);
        $result = $this->db->delete(self::TABLE, ['code' => 'a']);
        $this->assertSame(1, $result->affectedRows, 'deleting one matching row must report affectedRows 1');
        $rows = $this->rowsOnFreshConnection();
        $this->assertCount(1, $rows);
        $this->assertSame('b', $rows[0]['code']);
    }

    // ── pg-filterless-write-guard ───────────────────────────────────────────

    public function testUpdateWithoutAFilterOrPrimaryKeyRaisesAndChangesNothing(): void
    {
        $this->db->insert(self::TABLE, ['code' => 'a', 'qty' => 10]);
        $this->db->insert(self::TABLE, ['code' => 'b', 'qty' => 20]);
        $raised = false;
        try {
            // No filter and no primary key in the data → a full-table rewrite that
            // must be refused, not performed.
            $this->db->update(self::TABLE, ['qty' => 1]);
        } catch (\Throwable) {
            $raised = true;
        }
        $this->assertTrue($raised, 'a filterless update must raise, not rewrite every row');
        $byCode = [];
        foreach ($this->rowsOnFreshConnection() as $row) {
            $byCode[$row['code']] = (int) $row['qty'];
        }
        $this->assertSame(['a' => 10, 'b' => 20], $byCode, 'a refused update must change nothing');
    }

    public function testDeleteWithoutAFilterRaisesAndRemovesNothing(): void
    {
        $this->db->insert(self::TABLE, ['code' => 'a', 'qty' => 10]);
        $this->db->insert(self::TABLE, ['code' => 'b', 'qty' => 20]);
        $raised = false;
        try {
            $this->db->delete(self::TABLE); // truncate() is the explicit whole-table empty
        } catch (\Throwable) {
            $raised = true;
        }
        $this->assertTrue($raised, 'a filterless delete must raise, not empty the table');
        $this->assertCount(2, $this->rowsOnFreshConnection(), 'a refused delete must remove nothing');
    }

    // ── pg-executemany-atomicity ────────────────────────────────────────────

    public function testExecuteManyMidBatchFailureRollsBackOnAFreshConnection(): void
    {
        // RETURNING forces the row-at-a-time loop (the batch collapser rejects it),
        // so the first set really executes inside the owned transaction before the
        // second set violates UNIQUE(code). One owned transaction → the failure
        // rolls the whole batch back and a fresh connection sees ZERO rows.
        $raised = false;
        try {
            $this->db->executeMany(
                'INSERT INTO ' . self::TABLE . ' (code, qty) VALUES (?, ?) RETURNING *',
                [['dup', 1], ['dup', 2]]
            );
        } catch (\Throwable) {
            $raised = true;
        }
        $this->assertTrue($raised, 'a mid-batch UNIQUE violation must raise');
        $this->assertSame(
            [],
            $this->rowsOnFreshConnection(),
            'a mid-batch failure must roll the whole batch back — the first row surviving on a '
            . 'fresh connection means executeMany committed per row instead of owning one transaction'
        );
    }

    public function testExecuteManyEmptyInputIsAZeroRowNoOp(): void
    {
        $affected = $this->db->executeMany(
            'INSERT INTO ' . self::TABLE . ' (code, qty) VALUES (?, ?)',
            []
        );
        $this->assertSame(0, $affected, 'empty input is a no-op with a zero affected count');
        $this->assertSame([], $this->rowsOnFreshConnection(), 'empty input must write nothing');
    }

    // ── pg-fetch-shape ──────────────────────────────────────────────────────

    public function testFetchReturnsANativeListOfRowsWithNativeTypes(): void
    {
        $this->db->insert(self::TABLE, ['code' => 'x', 'qty' => 42]);
        $result = $this->db->fetch('SELECT id, code, qty FROM ' . self::TABLE . ' ORDER BY id');
        $this->assertIsArray($result->records);
        $this->assertCount(1, $result->records);
        $row = $result->records[0];
        // ext-pgsql returns column values as strings — native for that driver — so the
        // gate is value-correctness + the list-of-maps shape, not a Python-style int.
        $this->assertArrayHasKey('qty', $row);
        $this->assertEquals(42, $row['qty']);
        $this->assertSame('x', $row['code']);
    }

    public function testFetchOneReturnsASingleNativeRecord(): void
    {
        $this->db->insert(self::TABLE, ['code' => 'y', 'qty' => 7]);
        $row = $this->db->fetchOne('SELECT code, qty FROM ' . self::TABLE . ' WHERE code = ?', ['y']);
        $this->assertIsArray($row, 'fetchOne must return one native record');
        $this->assertSame('y', $row['code']);
        $this->assertEquals(7, $row['qty']);
    }

    public function testFetchOneWithNoMatchReturnsNull(): void
    {
        $row = $this->db->fetchOne('SELECT * FROM ' . self::TABLE . ' WHERE code = ?', ['nope']);
        $this->assertNull($row, 'fetchOne with no match must return null');
    }
}
