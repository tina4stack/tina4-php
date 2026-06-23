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
use Tina4\ORM;

// ── Test models for issue #256 (one typed property per column) ───────────────

// UUID primary key with a DB-side DEFAULT — the caller never sets `id`, so the
// framework must read back the value PostgreSQL generated (a 36-char UUID
// string), not 0 / '' / a stale wrong integer.
class T4Issue256UuidModel extends ORM
{
    public string $tableName = 't4_issue256_uuid';
    public string $primaryKey = 'id';

    public string $id = '';
    public string $name = '';
}

// SERIAL integer auto-increment — the framework must keep returning the
// incrementing integer id (the path that must NOT regress).
class T4Issue256SerialModel extends ORM
{
    public string $tableName = 't4_issue256_serial';
    public string $primaryKey = 'id';

    public int $id = 0;
    public string $name = '';
}

class PostgresUuidPkTest extends TestCase
{
    private const PG_DB = 'tina4';

    private ?DatabaseAdapter $db = null;

    protected function setUp(): void
    {
        if (!function_exists('pg_connect')) {
            $this->markTestSkipped('PostgresAdapter requires the ext-pgsql PHP extension.');
        }
        $pg = \PgTestEnv::resolve();
        if (!$pg->reachable()) {
            $this->markTestSkipped(
                sprintf('PostgreSQL not reachable at %s:%d — skip integration test',
                    $pg->host, $pg->port)
            );
        }

        $this->db = Database::create($pg->url(self::PG_DB), username: $pg->user, password: $pg->pass);

        $this->db->execute('DROP TABLE IF EXISTS t4_issue38_uuid');
        $this->db->execute(
            'CREATE TABLE t4_issue38_uuid ('
            . '  id uuid PRIMARY KEY DEFAULT gen_random_uuid(), '
            . '  name text'
            . ')'
        );

        // Issue #256 tables: a DB-default UUID PK and a SERIAL integer PK.
        $this->db->execute('DROP TABLE IF EXISTS t4_issue256_uuid');
        $this->db->execute(
            'CREATE TABLE t4_issue256_uuid ('
            . '  id uuid PRIMARY KEY DEFAULT gen_random_uuid(), '
            . '  name text'
            . ')'
        );
        $this->db->execute('DROP TABLE IF EXISTS t4_issue256_serial');
        $this->db->execute(
            'CREATE TABLE t4_issue256_serial ('
            . '  id SERIAL PRIMARY KEY, '
            . '  name text'
            . ')'
        );
        $this->db->commit();

        // Point the #256 ORM test models at this live connection.
        ORM::bindDatabase($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            try {
                $this->db->execute('DROP TABLE IF EXISTS t4_issue38_uuid');
                $this->db->execute('DROP TABLE IF EXISTS t4_issue256_uuid');
                $this->db->execute('DROP TABLE IF EXISTS t4_issue256_serial');
                $this->db->commit();
            } finally {
                $this->db->close();
            }
            $this->db = null;
        }
    }

    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

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

    // ── Issue #256: a DB-generated PK (UUID) must be surfaced after save() ──
    //
    // Before the fix, an ORM model with `id uuid PRIMARY KEY DEFAULT
    // gen_random_uuid()` saved a row but the PK property stayed '' / 0 — the
    // INSERT emitted no RETURNING and PostgreSQL's lastval() probe gives nothing
    // useful for a non-SERIAL PK, so the generated UUID was never read back.

    public function testModelSaveSurfacesGeneratedUuidPk(): void
    {
        $model = new T4Issue256UuidModel();
        $model->name = 'gamma';

        $saved = $model->save();

        // save() returns the model on success, false on failure.
        $this->assertNotFalse($saved, 'save() failed: ' . ($model->getError() ?? ''));

        // The PK property must now hold the ACTUAL generated UUID — NOT 0/''.
        $this->assertIsString($model->id);
        $this->assertNotSame('', $model->id, 'UUID PK was not surfaced (stayed empty)');
        $this->assertNotSame('0', (string)$model->id, 'UUID PK was a stale 0');
        $this->assertSame(36, strlen($model->id), 'surfaced id is not a 36-char UUID');
        $this->assertMatchesRegularExpression(self::UUID_RE, $model->id);

        // And it must match the value actually written to the row.
        $row = $this->db->fetchOne(
            'SELECT id FROM t4_issue256_uuid WHERE name = $1',
            ['gamma']
        );
        $this->assertNotNull($row, 'row was not persisted');
        $this->assertSame($model->id, $row['id'],
            'surfaced PK does not match the persisted row id');
    }

    public function testAdapterInsertSurfacesGeneratedUuidPk(): void
    {
        // The documented db->insert() / getLastId() path must also surface the
        // generated UUID (the adapter insert() uses RETURNING *).
        $ok = $this->db->insert('t4_issue256_uuid', ['name' => 'delta']);
        $this->assertTrue($ok, 'adapter insert() failed: ' . ($this->db->getError() ?? ''));

        $lastId = $this->db->getLastId();
        $this->assertIsString($lastId);
        $this->assertSame(36, strlen($lastId), "getLastId() was not a UUID: " . var_export($lastId, true));
        $this->assertMatchesRegularExpression(self::UUID_RE, $lastId);

        $row = $this->db->fetchOne('SELECT id FROM t4_issue256_uuid WHERE name = $1', ['delta']);
        $this->assertSame($lastId, $row['id']);
    }

    public function testModelSaveStillReturnsSerialIntegerPk(): void
    {
        // The SERIAL integer auto-increment path MUST keep returning the
        // incrementing integer id (must not regress to a UUID/string/0).
        $first = new T4Issue256SerialModel();
        $first->name = 'one';
        $this->assertNotFalse($first->save(), 'first SERIAL save() failed: ' . ($first->getError() ?? ''));

        $second = new T4Issue256SerialModel();
        $second->name = 'two';
        $this->assertNotFalse($second->save(), 'second SERIAL save() failed: ' . ($second->getError() ?? ''));

        // Integers, surfaced, and monotonically increasing.
        $this->assertIsInt($first->id);
        $this->assertIsInt($second->id);
        $this->assertGreaterThan(0, $first->id, 'first SERIAL id was not a positive integer');
        $this->assertGreaterThan($first->id, $second->id,
            'SERIAL id did not increment between inserts');

        // And they match the persisted rows.
        $rowOne = $this->db->fetchOne('SELECT id FROM t4_issue256_serial WHERE name = $1', ['one']);
        $this->assertSame($first->id, (int)$rowOne['id']);
    }
}
