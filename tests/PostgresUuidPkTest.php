<?php

/**
 * Regression test for issue #38 — PG adapter's lastval() probe must not
 * abort the outer transaction on UUID PK tables.
 *
 * Mirrors tina4-python's tests/test_postgres_uuid_pk.py.
 *
 * Before the savepoint wrap in PostgresAdapter, this sequence:
 *
 *     INSERT INTO uuid_table VALUES (...)
 *     SELECT * FROM uuid_table
 *
 * failed on the SELECT with
 * "current transaction is aborted, commands ignored until end of transaction
 *  block", because the post-INSERT SELECT lastval() probe raised on the
 * missing sequence and PostgreSQL marked the whole transaction as aborted.
 *
 * The test boots a real PostgreSQL (Docker container at localhost:55432),
 * runs the exact reproduction from the issue, and asserts the SELECT
 * succeeds. The test is skipped automatically when the container isn't
 * reachable so CI without postgres just no-ops.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Database\DatabaseAdapter;

class PostgresUuidPkTest extends TestCase
{
    private const PG_HOST = 'localhost';
    private const PG_PORT = 55432;
    private const PG_USER = 'tina4';
    private const PG_PASS = 'tina4';
    private const PG_DB = 'tina4';

    private ?DatabaseAdapter $db = null;

    protected function setUp(): void
    {
        if (!function_exists('pg_connect')) {
            $this->markTestSkipped('PostgresAdapter requires the ext-pgsql PHP extension.');
        }
        if (!self::pgReachable()) {
            $this->markTestSkipped(
                sprintf('PostgreSQL not reachable at %s:%d — skip integration test',
                    self::PG_HOST, self::PG_PORT)
            );
        }

        $url = sprintf(
            'postgres://%s:%d/%s',
            self::PG_HOST, self::PG_PORT, self::PG_DB
        );
        $this->db = Database::create($url, username: self::PG_USER, password: self::PG_PASS);

        $this->db->execute('DROP TABLE IF EXISTS t4_issue38_uuid');
        $this->db->execute(
            'CREATE TABLE t4_issue38_uuid ('
            . '  id uuid PRIMARY KEY DEFAULT gen_random_uuid(), '
            . '  name text'
            . ')'
        );
        $this->db->commit();
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            try {
                $this->db->execute('DROP TABLE IF EXISTS t4_issue38_uuid');
                $this->db->commit();
            } finally {
                $this->db->close();
            }
            $this->db = null;
        }
    }

    private static function pgReachable(): bool
    {
        $sock = @fsockopen(self::PG_HOST, self::PG_PORT, $errno, $errstr, 1.0);
        if ($sock === false) {
            return false;
        }
        fclose($sock);
        return true;
    }

    public function testInsertThenSelectDoesNotRaise(): void
    {
        // The exact reproduction from the issue. INSERT then SELECT must
        // both succeed; before the savepoint fix, the SELECT raised
        // "current transaction is aborted".
        $result = $this->db->execute('INSERT INTO t4_issue38_uuid (name) VALUES ($1)', ['alice']);
        $this->assertNotFalse($result, 'INSERT failed: ' . ($this->db->error() ?? ''));
        $this->assertNull($this->db->error(), 'INSERT should not record an error');

        // The smoking gun — this used to fail with "transaction is aborted".
        $row = $this->db->fetchOne('SELECT name FROM t4_issue38_uuid WHERE name = $1', ['alice']);
        $this->assertNotNull($row);
        $this->assertEquals('alice', $row['name']);
    }

    public function testInsertThenMultipleSelects(): void
    {
        // Pattern: insert one row, do several selects on the same connection.
        $this->db->execute('INSERT INTO t4_issue38_uuid (name) VALUES ($1)', ['bob']);
        $this->db->execute('INSERT INTO t4_issue38_uuid (name) VALUES ($1)', ['carol']);

        $rows = $this->db->fetch('SELECT name FROM t4_issue38_uuid ORDER BY name');
        $names = array_column($rows->records, 'name');
        $this->assertEquals(['bob', 'carol'], $names);
    }

    public function testInsertThenUpdateThenSelect(): void
    {
        // The savepoint pattern must work for INSERT-UPDATE-SELECT chains.
        $this->db->execute('INSERT INTO t4_issue38_uuid (name) VALUES ($1)', ['dave']);
        $this->db->execute(
            'UPDATE t4_issue38_uuid SET name = $1 WHERE name = $2',
            ['dave2', 'dave']
        );
        $row = $this->db->fetchOne('SELECT name FROM t4_issue38_uuid');
        $this->assertEquals('dave2', $row['name']);
    }

    public function testExplicitTransactionWithUuidInserts(): void
    {
        // Explicit startTransaction → multiple UUID INSERTs → commit must
        // persist all rows (and not silently abort the txn).
        $this->db->startTransaction();
        $this->db->execute('INSERT INTO t4_issue38_uuid (name) VALUES ($1)', ['e1']);
        $this->db->execute('INSERT INTO t4_issue38_uuid (name) VALUES ($1)', ['e2']);
        $this->db->execute('INSERT INTO t4_issue38_uuid (name) VALUES ($1)', ['e3']);
        $this->db->commit();

        $row = $this->db->fetchOne('SELECT count(*) AS n FROM t4_issue38_uuid');
        $this->assertEquals(3, (int)$row['n'],
            "expected 3 rows after commit, got {$row['n']}");
    }

    public function testExplicitTransactionWithUuidThenRollback(): void
    {
        // Rollback must drop everything — proves we're really inside one txn.
        $this->db->startTransaction();
        $this->db->execute('INSERT INTO t4_issue38_uuid (name) VALUES ($1)', ['x1']);
        $this->db->execute('INSERT INTO t4_issue38_uuid (name) VALUES ($1)', ['x2']);
        $this->db->rollback();

        $row = $this->db->fetchOne('SELECT count(*) AS n FROM t4_issue38_uuid');
        $this->assertEquals(0, (int)$row['n'], 'rollback did not undo the UUID inserts');
    }

    public function testInsertReturningIdStillWorks(): void
    {
        // The RETURNING path skips the lastval probe entirely; verify it
        // still works on a UUID PK.
        $result = $this->db->execute(
            'INSERT INTO t4_issue38_uuid (name) VALUES ($1) RETURNING id',
            ['frank']
        );
        $this->assertNotFalse($result);
        // After RETURNING, lastInsertId should hold a uuid-like string
        $lastId = $this->db->lastInsertId();
        $this->assertNotEmpty($lastId);
    }
}
