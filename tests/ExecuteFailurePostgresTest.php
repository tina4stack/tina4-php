<?php

/**
 * Database::execute() must FAIL LOUD — RAISE on a SQL error, not return false
 * (and definitely not silently return true).
 *
 * For a plain write/DDL (no RETURNING/CALL/EXEC/SELECT) the facade originally
 * `return true` unconditionally, discarding the adapter's boolean result.
 * tina4-php#114 changed it to return false on a failed statement. The v3
 * FAIL-LOUD contract (parity with the Python master and with this framework's
 * own fetch()/fetchOne()) now makes execute() THROW a DatabaseException — the
 * cause is still captured on getError(). Callers that want a bool must
 * try/catch.
 *
 * PG-gated: skipped when ext-pgsql is missing or PostgreSQL is unreachable
 * (postgres://tina4:tina4@localhost:5432/tina4_php), matching
 * CreateTablePostgresTest.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Database\DatabaseException;

class ExecuteFailurePostgresTest extends TestCase
{
    private const PG_DB = 'tina4_php';

    private ?Database $db = null;

    protected function setUp(): void
    {
        if (!function_exists('pg_connect')) {
            $this->markTestSkipped('PostgresAdapter requires ext-pgsql.');
        }
        $pg = \PgTestEnv::resolve();
        if (!$pg->reachable()) {
            $this->markTestSkipped(sprintf('PostgreSQL not reachable at %s:%d — skip', $pg->host, $pg->port));
        }
        $this->db = Database::create($pg->url(self::PG_DB), username: $pg->user, password: $pg->pass);
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

    public function testExecuteThrowsOnFailingDdl(): void
    {
        // Duplicate column name → CREATE fails. Must THROW, not return false/true.
        try {
            $this->db->execute('CREATE TABLE t4_exec_fail (id INTEGER, id INTEGER)');
            $this->fail('execute() must raise when the DDL fails');
        } catch (\Throwable $e) {
            $this->assertStringContainsStringIgnoringCase('more than once', $e->getMessage());
        }
        // The cause is still captured on getError() after the throw.
        $this->assertNotNull($this->db->getError());
        $this->assertStringContainsStringIgnoringCase('more than once', (string) $this->db->getError());
    }

    public function testExecuteThrowsOnFailingInsert(): void
    {
        $this->db->execute('CREATE TABLE t4_exec_fail (id SERIAL PRIMARY KEY, name VARCHAR(50) NOT NULL)');
        // NOT NULL violation → INSERT fails → execute() must raise.
        $this->expectException(\Throwable::class);
        try {
            $this->db->execute('INSERT INTO t4_exec_fail (name) VALUES (NULL)');
        } catch (\Throwable $e) {
            $this->assertNotNull($this->db->getError());
            throw $e;
        }
    }

    public function testExecuteThrowsOnUnknownTable(): void
    {
        try {
            $this->db->execute('INSERT INTO t4_does_not_exist (x) VALUES (1)');
            $this->fail('execute() must raise on an unknown table');
        } catch (\Throwable $e) {
            $this->assertNotNull($this->db->getError());
        }
    }
}
