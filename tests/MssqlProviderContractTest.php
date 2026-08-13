<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * MSSQL provider contract — feature 11 (mssqlprovider_contract.json), parity with
 * tina4-python/tests/test_mssqlprovider_contract.py.
 *
 * MSSQL-DEC-01 + MSSQL-DEC-02 (OWNER-DECISIONS.md Batch 5, feature doc
 * 011-mssql-provider.md). Every case drives the lab's REAL SQL Server :1433
 * (sa -> tina4_test) through the public Database facade -> MSSQLAdapter. No mocks.
 * Durability is read back on a SECOND, FRESH connection.
 *
 * MSSQL-DEC-01 (safe parameter handling): a binary parameter round-trips intact.
 * FreeTDS (pdo_dblib) cannot bind raw binary through a ? placeholder (a NUL byte
 * breaks the TDS stream — MEASURED against real SQL Server), so the adapter
 * inlines a binary param as a 0x varbinary literal, which round-trips
 * byte-for-byte; ordinary text keeps the bound path.
 *
 * MSSQL-DEC-02 (one pagination strategy): OFFSET/FETCH, proven by the window.
 *
 * PHP/Ruby/Node do NOT emulate MSSQL RETURNING (only the Python adapter does);
 * a non-`id`-PK insert surfaces the correct generated id through SCOPE_IDENTITY,
 * which is column-name-independent (invariant mssql-nonid-pk-generated-id).
 *
 * Mutation-proof: drop the inlineBinaryParams call -> "a binary parameter round
 * trips intact" goes RED (a NUL byte errors / truncates the bound value).
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;

class MssqlProviderContractTest extends TestCase
{
    private const NONID = 'mssqlprov_nonid';   // a table whose PK is deliberately NOT `id`
    private const PARAMS = 'mssqlprov_params';
    private const PAGE = 'mssqlprov_page';

    private ?Database $db = null;

    /** A payload with a NUL byte and high bytes — corrupted by a text bind. */
    private static function binPayload(): string
    {
        return "\x00\x01\xff\x02\x10\xc8\x00\x7f";
    }

    private static function mssqlUrl(): string
    {
        $host = getenv('TINA4_TEST_MSSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TINA4_TEST_MSSQL_PORT') ?: 1433);
        $db   = getenv('TINA4_TEST_MSSQL_DB') ?: 'tina4_test';
        return "mssql://{$host}:{$port}/{$db}";
    }

    private static function mssqlUser(): string
    {
        return getenv('TINA4_TEST_MSSQL_USERNAME') ?: 'sa';
    }

    private static function mssqlPass(): string
    {
        $p = getenv('TINA4_TEST_MSSQL_PASSWORD');
        return ($p === false || $p === '') ? 'TinaSQL123!Secure' : $p;
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
        // MSSQL needs either ext-sqlsrv OR ext-pdo_dblib (FreeTDS 'dblib' PDO
        // driver). The reason names "mssql" + "not installed" so the gate flags it.
        if (!function_exists('sqlsrv_connect') && !in_array('dblib', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped(
                'MSSQL client not installed — neither ext-sqlsrv nor ext-pdo_dblib (FreeTDS) is available'
            );
        }
        $host = getenv('TINA4_TEST_MSSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TINA4_TEST_MSSQL_PORT') ?: 1433);
        if (!self::tcpReachable($host, $port)) {
            $this->markTestSkipped("MSSQL not reachable at {$host}:{$port} (set TINA4_TEST_MSSQL_*)");
        }
        $this->db = Database::create(self::mssqlUrl(), username: self::mssqlUser(), password: self::mssqlPass());
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            foreach ([self::NONID, self::PARAMS, self::PAGE] as $t) {
                try {
                    $this->db->execute("IF OBJECT_ID('{$t}', 'U') IS NOT NULL DROP TABLE {$t}");
                } catch (\Throwable) {
                    // best effort
                }
            }
            $this->db->close();
            $this->db = null;
        }
    }

    private function drop(string $table): void
    {
        $this->db->execute("IF OBJECT_ID('{$table}', 'U') IS NOT NULL DROP TABLE {$table}");
    }

    /** A fresh IDENTITY table with a NON-`id` PK so its identity restarts at 1. */
    private function freshNonid(): void
    {
        $this->drop(self::NONID);
        $this->db->execute(
            'CREATE TABLE ' . self::NONID . ' ('
            . 'person_key INT IDENTITY(1,1) PRIMARY KEY, '
            . 'code VARCHAR(40) NOT NULL, '
            . 'qty INT)'
        );
    }

    private function freshParams(): void
    {
        $this->drop(self::PARAMS);
        // Explicit NULL: FreeTDS / pdo_dblib runs ANSI_NULL_DFLT_OFF, so an
        // unspecified column is NOT NULL there — mark the optional columns
        // nullable so a single-column insert does not trip the other's default.
        $this->db->execute('CREATE TABLE ' . self::PARAMS . ' (k INT PRIMARY KEY, txt VARCHAR(100) NULL, blob VARBINARY(100) NULL)');
    }

    private function freshPage(): void
    {
        $this->drop(self::PAGE);
        $this->db->execute('CREATE TABLE ' . self::PAGE . ' (id INT PRIMARY KEY, val VARCHAR(20))');
        foreach (['a', 'b', 'c', 'd', 'e'] as $i => $v) {
            $this->db->execute('INSERT INTO ' . self::PAGE . ' (id, val) VALUES (?, ?)', [$i + 1, $v]);
        }
    }

    /** Every row on a SECOND connection — the durability witness. */
    private function rowsOnFresh(string $table, string $orderCol): array
    {
        $other = Database::create(self::mssqlUrl(), username: self::mssqlUser(), password: self::mssqlPass());
        try {
            return $other->fetch('SELECT * FROM ' . $table . ' ORDER BY ' . $orderCol, [], 1000)->records;
        } finally {
            $other->close();
        }
    }

    // ── mssql-nonid-pk-generated-id ─────────────────────────────────────────

    public function testANonIdPrimaryKeyInsertReturnsTheGeneratedLastId(): void
    {
        $this->freshNonid();
        $result = $this->db->insert(self::NONID, ['code' => 'a', 'qty' => 10]);
        $this->assertEquals(1, $result->lastId, 'a non-`id`-PK insert must return the generated key 1 (SCOPE_IDENTITY)');
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

    // ── mssql-safe-params ───────────────────────────────────────────────────

    public function testABinaryParameterRoundTripsIntact(): void
    {
        $this->freshParams();
        $bin = self::binPayload();
        $this->db->execute('INSERT INTO ' . self::PARAMS . ' (k, blob) VALUES (?, ?)', [1, $bin]);
        $other = Database::create(self::mssqlUrl(), username: self::mssqlUser(), password: self::mssqlPass());
        try {
            $row = $other->fetchOne('SELECT blob FROM ' . self::PARAMS . ' WHERE k = ?', [1]);
        } finally {
            $other->close();
        }
        $this->assertNotNull($row, 'the binary row must be readable on a fresh connection');
        $this->assertSame(
            bin2hex($bin),
            bin2hex((string) $row['blob']),
            'binary must round-trip byte-for-byte (0x literal inline)'
        );
    }

    public function testATextParameterRoundTripsIntact(): void
    {
        $this->freshParams();
        $text = "it's a \"quoted\" O'Brien value";
        // Ordinary UTF-8 text stays on the bound path (never mis-routed to 0x).
        $this->db->execute('INSERT INTO ' . self::PARAMS . ' (k, txt) VALUES (?, ?)', [2, $text]);
        $other = Database::create(self::mssqlUrl(), username: self::mssqlUser(), password: self::mssqlPass());
        try {
            $row = $other->fetchOne('SELECT txt FROM ' . self::PARAMS . ' WHERE k = ?', [2]);
        } finally {
            $other->close();
        }
        $this->assertNotNull($row);
        $this->assertSame($text, $row['txt'], 'text must round-trip intact with quote-escaping');
    }

    // ── mssql-offset-fetch-pagination ───────────────────────────────────────

    public function testAPaginatedQueryReturnsTheFirstPageWindow(): void
    {
        $this->freshPage();
        $result = $this->db->fetch('SELECT id, val FROM ' . self::PAGE . ' ORDER BY id', [], 2, 0);
        $ids = array_map(static fn(array $r) => (int) $r['id'], $result->records);
        $this->assertSame([1, 2], $ids, 'first page (limit 2, offset 0) must be [1, 2]');
    }

    public function testAPaginatedQueryReturnsALaterPageWindowWithOffset(): void
    {
        $this->freshPage();
        $result = $this->db->fetch('SELECT id, val FROM ' . self::PAGE . ' ORDER BY id', [], 2, 2);
        $ids = array_map(static fn(array $r) => (int) $r['id'], $result->records);
        // OFFSET/FETCH; a TOP-only strategy that ignores the offset returns [1, 2].
        $this->assertSame([3, 4], $ids, 'the offset window (limit 2, offset 2) must be [3, 4] via OFFSET/FETCH');
        $vals = array_map(static fn(array $r) => $r['val'], $result->records);
        $this->assertSame(['c', 'd'], $vals);
    }
}
