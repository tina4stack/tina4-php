<?php

/**
 * Database::execute() must propagate adapter failure (not silently return true).
 *
 * For a plain write/DDL (no RETURNING/CALL/EXEC/SELECT) the facade used to
 * `return true` unconditionally, discarding the adapter's boolean result.
 * PHP adapters return `false` (and record error()) on a failed statement —
 * unlike Python/Ruby/Node whose adapters raise (and are caught) — so the
 * facade reported success even when the driver failed.
 *
 * PG-gated: skipped when ext-pgsql is missing or PostgreSQL is unreachable
 * (postgres://tina4:tina4@localhost:5432/tina4_php), matching
 * CreateTablePostgresTest.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;

class ExecuteFailurePostgresTest extends TestCase
{
    private const PG_HOST = 'localhost';
    private const PG_PORT = 5432;
    private const PG_USER = 'tina4';
    private const PG_PASS = 'tina4';
    private const PG_DB   = 'tina4_php';

    private ?Database $db = null;

    private static function pgReachable(): bool
    {
        $fp = @fsockopen(self::PG_HOST, self::PG_PORT, $errno, $errstr, 1.0);
        if ($fp) { fclose($fp); return true; }
        return false;
    }

    protected function setUp(): void
    {
        if (!function_exists('pg_connect')) {
            $this->markTestSkipped('PostgresAdapter requires ext-pgsql.');
        }
        if (!self::pgReachable()) {
            $this->markTestSkipped(sprintf('PostgreSQL not reachable at %s:%d — skip', self::PG_HOST, self::PG_PORT));
        }
        $url = sprintf('postgres://%s:%d/%s', self::PG_HOST, self::PG_PORT, self::PG_DB);
        $this->db = Database::create($url, username: self::PG_USER, password: self::PG_PASS);
        $this->db->execute('DROP TABLE IF EXISTS t4_exec_fail');
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            $this->db->execute('DROP TABLE IF EXISTS t4_exec_fail');
        }
    }

    public function testExecuteReturnsTrueOnSuccessfulWrite(): void
    {
        $this->assertTrue($this->db->execute('CREATE TABLE t4_exec_fail (id SERIAL PRIMARY KEY, name VARCHAR(50) NOT NULL)'));
        $this->assertTrue($this->db->execute("INSERT INTO t4_exec_fail (name) VALUES ('alpha')"));
        // A write affecting 0 rows is still a success, not a failure.
        $this->assertTrue($this->db->execute("UPDATE t4_exec_fail SET name = 'x' WHERE id = 99999"));
    }

    public function testExecuteReturnsFalseOnFailingDdl(): void
    {
        // Duplicate column name → CREATE fails. Must return false, not true.
        $ok = $this->db->execute('CREATE TABLE t4_exec_fail (id INTEGER, id INTEGER)');
        $this->assertFalse($ok, 'execute() must return false when the DDL fails');
        $this->assertNotNull($this->db->getError());
        $this->assertStringContainsStringIgnoringCase('more than once', (string) $this->db->getError());
    }

    public function testExecuteReturnsFalseOnFailingInsert(): void
    {
        $this->db->execute('CREATE TABLE t4_exec_fail (id SERIAL PRIMARY KEY, name VARCHAR(50) NOT NULL)');
        // NOT NULL violation → INSERT fails.
        $ok = $this->db->execute('INSERT INTO t4_exec_fail (name) VALUES (NULL)');
        $this->assertFalse($ok, 'execute() must return false when the INSERT fails');
        $this->assertNotNull($this->db->getError());
    }

    public function testExecuteReturnsFalseOnUnknownTable(): void
    {
        $ok = $this->db->execute('INSERT INTO t4_does_not_exist (x) VALUES (1)');
        $this->assertFalse($ok);
        $this->assertNotNull($this->db->getError());
    }
}
