<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * MySQL provider contract — feature 10 (mysqlprovider_contract.json), parity with
 * tina4-python/tests/test_mysqlprovider_contract.py.
 *
 * MYSQL-DEC-01 + MYSQL-DEC-02 (OWNER-DECISIONS.md Batch 5, feature doc
 * 010-mysql-provider.md). Every case drives the lab's REAL MySQL :3306
 * (tina4/tina4 -> tina4_test) through the public Database facade -> MySQLAdapter.
 * No mocks. Durability is read back on a SECOND, FRESH connection.
 *
 * This adapter does NOT emulate MySQL RETURNING (only the Python adapter does); a
 * non-`id`-PK insert surfaces the correct generated id through mysqli's
 * column-independent last_insert_id (invariant 1). MYSQL-DEC-02 here: the DESCRIBE
 * introspection is STRICT-quoted (MYSQL-DESCRIBE-UNPARAM) and the FIRST->LAST
 * batch-id math is de-duplicated onto SQLTranslator::batchLastId (the prepared and
 * non-prepared write paths used to carry two identical inline blocks).
 *
 * Mutation-proof: revert the strict DESCRIBE quoting -> "describe of an odd table
 * name returns its columns" goes RED (a backtick/special-char name is a syntax
 * error). The batch case is a green regression lock here (PHP loops the batch
 * row-at-a-time, so each single-row INSERT reports its own id).
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;

class MysqlProviderContractTest extends TestCase
{
    private const NONID = 'mysqlprov_nonid';      // a table whose PK is deliberately NOT `id`
    private const BATCH = 'mysqlprov_batch';
    private const SENTINEL = 'mysqlprov_sentinel';
    private const ODD = 'mysqlprov`odd';          // an embedded backtick -> needs strict quoting

    private ?Database $db = null;

    private static function mysqlUrl(): string
    {
        $host = getenv('TINA4_TEST_MYSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TINA4_TEST_MYSQL_PORT') ?: 3306);
        $db   = getenv('TINA4_TEST_MYSQL_DB') ?: 'tina4_test';
        $user = getenv('TINA4_TEST_MYSQL_USERNAME') ?: 'tina4';
        $pass = getenv('TINA4_TEST_MYSQL_PASSWORD') ?: 'tina4';
        return "mysql://{$user}:{$pass}@{$host}:{$port}/{$db}";
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

    /** Strict backtick-quote for building DDL against an odd name in the test. */
    private static function q(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    protected function setUp(): void
    {
        $host = getenv('TINA4_TEST_MYSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TINA4_TEST_MYSQL_PORT') ?: 3306);
        if (!self::tcpReachable($host, $port)) {
            $this->markTestSkipped("no reachable MySQL at {$host}:{$port} (set TINA4_TEST_MYSQL_*)");
        }
        $this->db = Database::create(self::mysqlUrl());
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            foreach ([self::q(self::NONID), self::q(self::BATCH), self::q(self::SENTINEL), self::q(self::ODD)] as $t) {
                try {
                    $this->db->execute('DROP TABLE IF EXISTS ' . $t);
                } catch (\Throwable) {
                    // best effort
                }
            }
            $this->db->close();
            $this->db = null;
        }
    }

    /** A fresh AUTO_INCREMENT table with a NON-`id` PK so its sequence restarts at 1. */
    private function freshNonid(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS ' . self::NONID);
        $this->db->execute(
            'CREATE TABLE ' . self::NONID . ' ('
            . 'person_key INT NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . 'code VARCHAR(40) NOT NULL UNIQUE, '
            . 'qty INTEGER)'
        );
    }

    /** Every row on a SECOND connection — the durability witness. */
    private function rowsOnFresh(string $table, string $orderCol): array
    {
        $other = Database::create(self::mysqlUrl());
        try {
            return $other->fetch('SELECT * FROM ' . self::q($table) . ' ORDER BY ' . self::q($orderCol), [], 1000)->records;
        } finally {
            $other->close();
        }
    }

    // ── mysql-nonid-pk-generated-id ─────────────────────────────────────────

    public function testANonIdPrimaryKeyInsertReturnsTheGeneratedLastId(): void
    {
        $this->freshNonid();
        $result = $this->db->insert(self::NONID, ['code' => 'a', 'qty' => 10]);
        $this->assertEquals(1, $result->lastId, 'a non-`id`-PK insert must return the generated key 1');
        $rows = $this->rowsOnFresh(self::NONID, 'person_key');
        $this->assertCount(1, $rows);
        $this->assertEquals(1, $rows[0]['person_key']);
        $this->assertSame('a', $rows[0]['code']);
    }

    public function testASecondNonIdPrimaryKeyInsertReturnsTheNextGeneratedId(): void
    {
        $this->freshNonid();
        $first = $this->db->insert(self::NONID, ['code' => 'a', 'qty' => 10]);
        $second = $this->db->insert(self::NONID, ['code' => 'b', 'qty' => 20]);
        $this->assertEquals(1, $first->lastId, 'first generated id should be 1');
        $this->assertEquals(2, $second->lastId, 'second insert must return the NEXT generated id 2');
        $this->assertNotEquals($first->lastId, $second->lastId);
    }

    public function testANonIdPrimaryKeyInsertReportsAffectedRowsOfOne(): void
    {
        $this->freshNonid();
        $result = $this->db->insert(self::NONID, ['code' => 'a', 'qty' => 10]);
        $this->assertSame(1, $result->affectedRows, 'a single insert must report affectedRows 1');
    }

    // ── mysql-write-affected-count ──────────────────────────────────────────

    public function testUpdateReportsTheExactNumberOfRowsChanged(): void
    {
        $this->freshNonid();
        $this->db->insert(self::NONID, ['code' => 'a', 'qty' => 10]);
        $this->db->insert(self::NONID, ['code' => 'b', 'qty' => 20]);
        $this->db->insert(self::NONID, ['code' => 'c', 'qty' => 40]);
        $result = $this->db->update(self::NONID, ['qty' => 99], 'qty < ?', [30]);
        $this->assertSame(2, $result->affectedRows, 'the filter matches exactly two of three rows');
    }

    public function testDeleteReportsTheExactNumberOfRowsRemoved(): void
    {
        $this->freshNonid();
        $this->db->insert(self::NONID, ['code' => 'a', 'qty' => 10]);
        $this->db->insert(self::NONID, ['code' => 'b', 'qty' => 20]);
        $result = $this->db->delete(self::NONID, ['code' => 'a']);
        $this->assertSame(1, $result->affectedRows, 'deleting one matching row must report 1');
        $rows = $this->rowsOnFresh(self::NONID, 'person_key');
        $this->assertCount(1, $rows);
        $this->assertSame('b', $rows[0]['code']);
    }

    public function testAnUpdateReportsANullLastId(): void
    {
        $this->freshNonid();
        $this->db->insert(self::NONID, ['code' => 'a', 'qty' => 10]);
        $result = $this->db->update(self::NONID, ['qty' => 99], 'code = ?', ['a']);
        $this->assertNull($result->lastId, 'last_id is insert-only; an update must report null');
    }

    // ── mysql-describe-safe ─────────────────────────────────────────────────

    public function testDescribeOfAnOddTableNameReturnsItsColumns(): void
    {
        $oddQ = self::q(self::ODD);
        $this->db->execute('DROP TABLE IF EXISTS ' . $oddQ);
        $this->db->execute('CREATE TABLE ' . $oddQ . ' (person_key INT NOT NULL AUTO_INCREMENT PRIMARY KEY, val VARCHAR(20))');
        // A raw/naively-quoted DESCRIBE breaks on the embedded backtick; strict
        // quoting introspects the odd name correctly.
        $cols = $this->db->getColumns(self::ODD);
        $names = array_map(static fn(array $c) => $c['name'], $cols);
        $this->assertSame(['person_key', 'val'], $names);
        $pk = array_values(array_filter($cols, static fn(array $c) => $c['primaryKey']));
        $this->assertCount(1, $pk);
        $this->assertSame('person_key', $pk[0]['name']);
    }

    public function testDescribeOfACraftedTableNameDoesNotInject(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS ' . self::SENTINEL);
        $this->db->execute('CREATE TABLE ' . self::SENTINEL . ' (id INT PRIMARY KEY)');
        $crafted = 'x`; DROP TABLE ' . self::SENTINEL . '; --';
        try {
            // The crafted name becomes ONE escaped identifier -> a clean "unknown
            // table" (no runnable SQL). The result is irrelevant; the sentinel must survive.
            $this->db->getColumns($crafted);
        } catch (\Throwable) {
            // strict-quoting a non-existent table raises/returns empty depending on the
            // path — either way nothing was injected.
        }
        $this->assertTrue(
            $this->db->tableExists(self::SENTINEL),
            'a crafted DESCRIBE name must not drop the sentinel table'
        );
    }

    // ── mysql-batch-sequential-ids ──────────────────────────────────────────

    public function testABatchInsertReturnsTheLastSequentialGeneratedId(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS ' . self::BATCH);
        $this->db->execute('CREATE TABLE ' . self::BATCH . ' (seq_key INT NOT NULL AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL, qty INTEGER)');
        $result = $this->db->insert(self::BATCH, [
            ['code' => 'b1', 'qty' => 1],
            ['code' => 'b2', 'qty' => 2],
            ['code' => 'b3', 'qty' => 3],
        ]);
        $this->assertEquals(3, $result->lastId, 'a 3-row batch must return the LAST generated id 3');
    }

    public function testABatchInsertAssignsConsecutiveIdsToEveryRow(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS ' . self::BATCH);
        $this->db->execute('CREATE TABLE ' . self::BATCH . ' (seq_key INT NOT NULL AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL, qty INTEGER)');
        $this->db->insert(self::BATCH, [
            ['code' => 'b1', 'qty' => 1],
            ['code' => 'b2', 'qty' => 2],
            ['code' => 'b3', 'qty' => 3],
        ]);
        $rows = $this->rowsOnFresh(self::BATCH, 'seq_key');
        $keys = array_map(static fn(array $r) => (int) $r['seq_key'], $rows);
        $this->assertSame([1, 2, 3], $keys);
        $codes = array_map(static fn(array $r) => $r['code'], $rows);
        $this->assertSame(['b1', 'b2', 'b3'], $codes);
    }
}
