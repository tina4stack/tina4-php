<?php

/**
 * Engine-aware ORM::createTable() against a live PostgreSQL.
 *
 * Mirrors the tina4-python create_table fix (v3.13.16): on PostgreSQL the
 * generated DDL must use the engine's native types, not SQLite-only syntax:
 *
 *   - auto-increment PK  → SERIAL (via SQLTranslator::autoIncrementSyntax),
 *     not "INTEGER PRIMARY KEY AUTOINCREMENT"
 *   - bool column        → native BOOLEAN, not INTEGER (so PG can accept a
 *     real boolean on insert and `WHERE x = TRUE` works)
 *   - datetime column    → TIMESTAMP, not DATETIME (PG has no DATETIME type)
 *   - boolean DEFAULT    → TRUE/FALSE (not 1/0) on a native BOOLEAN column
 *
 * createTable() must also return false (not true) when the CREATE actually
 * fails — the pre-fix version emitted SQLite DDL, silently failed on PG, and
 * still returned true.
 *
 * The test boots the live PostgreSQL used for doc verification
 * (postgres://tina4:tina4@localhost:5432/tina4_php) and is skipped
 * automatically when ext-pgsql is missing or the server is unreachable, so
 * CI without postgres just no-ops.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Database\DatabaseAdapter;
use Tina4\Database\PostgresAdapter;

// ── Test models (one typed property per column) ─────────────────────────────

class CtPgWidget extends \Tina4\ORM
{
    public string $tableName = 't4_ct_pg_widget';
    public string $primaryKey = 'id';

    public int $id = 0;
    public string $name = '';
    public bool $active = true;       // native BOOLEAN, DEFAULT TRUE
    public string $createdAt = '';    // name heuristic → TIMESTAMP
}

// Two properties collide on the same snake_case column → duplicate-column
// DDL error → createTable() must return false.
class CtPgDupColumn extends \Tina4\ORM
{
    public string $tableName = 't4_ct_pg_dup';
    public string $primaryKey = 'id';

    public int $id = 0;
    public string $userName = '';
    public string $user_name = '';
}

class CreateTablePostgresTest extends TestCase
{
    private const PG_DB = 'tina4_php';

    private ?DatabaseAdapter $db = null;
    private static ?DatabaseAdapter $savedGlobalDb = null;

    protected function setUp(): void
    {
        if (!function_exists('pg_connect')) {
            $this->markTestSkipped('PostgresAdapter requires the ext-pgsql PHP extension.');
        }
        $pg = \PgTestEnv::resolve();
        if (!$pg->reachable()) {
            $this->markTestSkipped(sprintf(
                'PostgreSQL not reachable at %s:%d — skip integration test',
                $pg->host, $pg->port
            ));
        }

        $this->db = Database::create($pg->url(self::PG_DB), username: $pg->user, password: $pg->pass);

        self::$savedGlobalDb = \Tina4\ORM::getGlobalDb();
        \Tina4\ORM::bindDatabase($this->db);

        $this->dropAll();
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            try {
                $this->dropAll();
            } finally {
                if (self::$savedGlobalDb !== null) {
                    \Tina4\ORM::bindDatabase(self::$savedGlobalDb);
                }
                $this->db->close();
            }
            $this->db = null;
        }
    }

    private function dropAll(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS t4_ct_pg_widget');
        $this->db->execute('DROP TABLE IF EXISTS t4_ct_pg_dup');
        $this->db->commit();
    }

    /**
     * The acceptance criterion: createTable() returns true, the table really
     * exists, and the columns use PG-native types.
     */
    public function testCreateTableEmitsEngineNativeTypes(): void
    {
        $widget = new CtPgWidget($this->db);

        $this->assertTrue($widget->createTable(), 'createTable() should return true on success');
        $this->assertTrue($this->db->tableExists('t4_ct_pg_widget'), 'the table must really exist');

        $cols = [];
        foreach ($this->db->getColumns('t4_ct_pg_widget') as $c) {
            $cols[$c['name']] = $c;
        }

        // SERIAL → integer column backed by a sequence default (nextval(...)).
        $this->assertSame('integer', $cols['id']['type']);
        $this->assertStringContainsString('nextval', (string) ($cols['id']['default'] ?? ''),
            'id should be a SERIAL/sequence-backed column, not a plain INTEGER');

        // VARCHAR
        $this->assertSame('character varying', $cols['name']['type']);

        // Native BOOLEAN with DEFAULT TRUE (not INTEGER / DEFAULT 1).
        $this->assertSame('boolean', $cols['active']['type'],
            'bool column must be native BOOLEAN on PostgreSQL');
        $this->assertSame('true', $cols['active']['default'],
            'boolean DEFAULT must be TRUE on a native BOOLEAN column');

        // TIMESTAMP (not DATETIME — PG has no DATETIME type).
        $this->assertSame('timestamp without time zone', $cols['created_at']['type'],
            'datetime column must be TIMESTAMP on PostgreSQL');
    }

    /**
     * The full round-trip: insert a row (real boolean + datetime), select it
     * back, and confirm both round-trip and index access works.
     */
    public function testRoundTripInsertSelectAndIndexAccess(): void
    {
        (new CtPgWidget($this->db))->createTable();

        $widget = new CtPgWidget($this->db);
        $widget->name = 'hello';
        $widget->active = true;
        $widget->createdAt = '2026-06-15 12:34:56';
        $this->assertNotFalse($widget->save(), 'save() should persist the row: ' . ($this->db->error() ?? ''));

        $result = $this->db->fetch(
            'SELECT id, name, active, created_at FROM t4_ct_pg_widget ORDER BY id'
        );
        $this->assertCount(1, $result->records);

        // Index access — Bug 2 (DatabaseResult ArrayAccess).
        $row = $result[0];
        $this->assertSame('hello', $row['name']);

        // Native-type reads: a PG BOOLEAN column comes back as a real PHP
        // bool (proves the column is BOOLEAN, not INTEGER, AND that the
        // adapter coerces 't'/'f' → true/false to match Python/Node/SQLite).
        $this->assertIsBool($row['active'], 'PG boolean must read back as a native PHP bool');
        $this->assertTrue($row['active'], 'inserted true should round-trip as boolean true');

        // SERIAL PK reads back as a native int (not the string "1").
        $this->assertIsInt($row['id'], 'PG integer/serial must read back as a native PHP int');

        // Timestamp round-trips (left as a string — conventional in PHP).
        $this->assertStringStartsWith('2026-06-15 12:34:56', (string) $row['created_at']);
    }

    /**
     * `WHERE active = TRUE` must work — confirming PostgreSQL keeps native
     * boolean literals and the adapter does NOT translate TRUE/FALSE → 1/0.
     */
    public function testWhereBooleanLiteralWorks(): void
    {
        (new CtPgWidget($this->db))->createTable();

        $w = new CtPgWidget($this->db);
        $w->name = 'on';
        $w->active = true;
        $w->createdAt = '2026-06-15 00:00:00';
        $this->assertNotFalse($w->save(), 'save() should persist the row: ' . ($this->db->error() ?? ''));

        $result = $this->db->fetch('SELECT count(*) AS n FROM t4_ct_pg_widget WHERE active = TRUE');
        $this->assertSame(1, (int) $result[0]['n']);
    }

    /**
     * createTable() must return false (not true) when the CREATE genuinely
     * fails — here a model whose two properties collide on one column.
     */
    public function testCreateTableReturnsFalseWhenDdlFails(): void
    {
        $model = new CtPgDupColumn($this->db);

        $this->assertFalse($model->createTable(),
            'createTable() must return false when the DDL fails');
        $this->assertFalse($this->db->tableExists('t4_ct_pg_dup'),
            'no table should exist after a failed CREATE');
    }

    /**
     * createTable() short-circuits to true when the table already exists.
     */
    public function testCreateTableIdempotentWhenTableExists(): void
    {
        $first = new CtPgWidget($this->db);
        $this->assertTrue($first->createTable());

        $second = new CtPgWidget($this->db);
        $this->assertTrue($second->createTable(),
            'createTable() should return true (no-op) when the table already exists');
    }

    /**
     * Native-type reads (v3.13.17): ext-pgsql returns every column as a
     * string, so the adapter must coerce per-column using the PG field type.
     * A Tina4 app built on SQLite (native-ish types) must see the SAME shapes
     * on PostgreSQL, matching the Python (psycopg2) and Node (node-postgres)
     * reference adapters.
     *
     * The must-fix conversions are int + bool (they break `===` and boolean
     * logic). float/numeric come back as float; text/varchar/timestamp stay
     * strings (timestamp-as-string is a conventional, documented minor diff).
     */
    public function testPostgresReadsReturnNativePhpTypes(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS t4_native_types');
        $this->db->execute(
            'CREATE TABLE t4_native_types ('
            . '  id SERIAL PRIMARY KEY,'
            . '  big_id BIGINT,'
            . '  small_id SMALLINT,'
            . '  name VARCHAR(50),'
            . '  active BOOLEAN,'
            . '  score NUMERIC(10,2),'
            . '  ratio DOUBLE PRECISION,'
            . '  created_at TIMESTAMP'
            . ')'
        );
        $this->db->commit();

        $this->db->execute(
            "INSERT INTO t4_native_types"
            . " (big_id, small_id, name, active, score, ratio, created_at)"
            . " VALUES (9000000000, 7, 'alpha', TRUE, 12.50, 3.14, '2026-06-15 12:34:56')"
        );
        $this->db->execute(
            "INSERT INTO t4_native_types"
            . " (big_id, small_id, name, active, score, ratio, created_at)"
            . " VALUES (NULL, NULL, 'beta', FALSE, NULL, NULL, NULL)"
        );
        $this->db->commit();

        $rows = $this->db->fetch('SELECT * FROM t4_native_types ORDER BY id')->records;
        $this->assertCount(2, $rows);

        // Row 1 — every typed column coerced to its native PHP type.
        $row = $rows[0];
        $this->assertIsInt($row['id'],       'SERIAL/integer must be int, not "1"');
        $this->assertSame(1, $row['id']);
        $this->assertIsInt($row['big_id'],   'bigint (int8) must be int');
        $this->assertSame(9000000000, $row['big_id']);
        $this->assertIsInt($row['small_id'], 'smallint (int2) must be int');
        $this->assertSame(7, $row['small_id']);

        $this->assertIsBool($row['active'],  'boolean must be bool, not "t"');
        $this->assertTrue($row['active']);

        $this->assertIsFloat($row['score'],  'numeric must be float for parity');
        $this->assertSame(12.5, $row['score']);
        $this->assertIsFloat($row['ratio'],  'float8 must be float');
        $this->assertSame(3.14, $row['ratio']);

        // varchar + timestamp stay strings (documented minor diff vs Python).
        $this->assertIsString($row['name']);
        $this->assertSame('alpha', $row['name']);
        $this->assertIsString($row['created_at']);
        $this->assertStringStartsWith('2026-06-15 12:34:56', $row['created_at']);

        // Row 2 — boolean false coerces correctly; NULLs stay null (not 0/false).
        $row = $rows[1];
        $this->assertIsBool($row['active']);
        $this->assertFalse($row['active'], "'f' must coerce to boolean false");
        $this->assertNull($row['big_id'],   'NULL int must stay null, not 0');
        $this->assertNull($row['score'],    'NULL numeric must stay null, not 0.0');
        $this->assertNull($row['created_at']);

        // fetchOne() uses the same query() fetch path — assert it coerces too.
        $one = $this->db->fetchOne('SELECT id, active FROM t4_native_types ORDER BY id LIMIT 1');
        $this->assertIsInt($one['id']);
        $this->assertIsBool($one['active']);

        // json_encode shape matches Python: id is 1 (not "1"), active is true.
        $encoded = json_encode($rows[0]);
        $this->assertStringContainsString('"id":1', $encoded);
        $this->assertStringContainsString('"active":true', $encoded);

        $this->db->execute('DROP TABLE IF EXISTS t4_native_types');
        $this->db->commit();
    }

    /**
     * Sanity: the connection really is the PostgreSQL adapter under the facade.
     */
    public function testUnderlyingAdapterIsPostgres(): void
    {
        $adapter = $this->db;
        while (method_exists($adapter, 'getAdapter')) {
            $next = $adapter->getAdapter();
            if ($next === $adapter) {
                break;
            }
            $adapter = $next;
        }
        $this->assertInstanceOf(PostgresAdapter::class, $adapter);
    }
}
