<?php

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Database\DatabaseAdapter;

/**
 * Default model — no $_db, resolves from the global default bound via
 * ORM::bindDatabase($db).
 */
class BindDbDefaultWidget extends \Tina4\ORM
{
    public string $tableName = 't4_bind_default';
    public string $primaryKey = 'id';
    public int $id = 0;
    public string $name = '';
}

/**
 * Named-connection model. The $_db property is a string naming a connection
 * registered via ORM::bindDatabase($db, name: 'analytics'). PHP property types
 * are invariant, so the override must repeat the base type
 * (DatabaseAdapter|string|null) rather than a bare `string`.
 */
class BindDbAnalyticsWidget extends \Tina4\ORM
{
    public string $tableName = 't4_bind_analytics';
    public string $primaryKey = 'id';
    public DatabaseAdapter|string|null $_db = 'analytics';
    public int $id = 0;
    public string $name = '';
}

/** Model referencing a connection name that was never registered. */
class BindDbMissingWidget extends \Tina4\ORM
{
    public string $tableName = 't4_bind_missing';
    public string $primaryKey = 'id';
    public DatabaseAdapter|string|null $_db = 'missing';
    public int $id = 0;
}

/**
 * ORM::bindDatabase() — the renamed binder + named-connection registry.
 *
 * Mirrors the tina4-python master, where bind_database(db, name=None) sets the
 * global default with no name, and registers a named connection with a name. A
 * model whose _db is a string resolves its connection from that registry.
 */
class OrmBindDatabaseTest extends TestCase
{
    private const PG_HOST = 'localhost';
    private const PG_PORT = 5432;
    private const PG_USER = 'tina4';
    private const PG_PASS = 'tina4';

    private static ?DatabaseAdapter $savedGlobalDb = null;

    protected function setUp(): void
    {
        // Preserve and clear the cross-test global so each test starts clean.
        self::$savedGlobalDb = \Tina4\ORM::getGlobalDb();
        self::resetBindings();
    }

    protected function tearDown(): void
    {
        self::resetBindings();
        if (self::$savedGlobalDb !== null) {
            \Tina4\ORM::bindDatabase(self::$savedGlobalDb);
        }
    }

    /** Reset the private global + protected named registry via reflection. */
    private static function resetBindings(): void
    {
        $ref = new \ReflectionClass(\Tina4\ORM::class);
        $ref->getProperty('_globalDb')->setValue(null, null);
        $ref->getProperty('_namedDbs')->setValue(null, []);
    }

    // ── Pure unit tests (in-memory SQLite, no external DB) ───────────────────

    public function testBindDatabaseSetsGlobalDefault(): void
    {
        $db = Database::create('sqlite::memory:');
        \Tina4\ORM::bindDatabase($db);

        $this->assertSame($db, \Tina4\ORM::getGlobalDb());

        // A model with no $_db resolves to the global default.
        $resolved = $this->resolveDbFor(new BindDbDefaultWidget());
        $this->assertSame($db, $resolved);
    }

    public function testNamedConnectionResolvesForModelWithStringDb(): void
    {
        $main      = Database::create('sqlite::memory:');
        $analytics = Database::create('sqlite::memory:');

        \Tina4\ORM::bindDatabase($main);                       // global default
        \Tina4\ORM::bindDatabase($analytics, name: 'analytics'); // named connection

        // The analytics model ($_db = 'analytics') must resolve to $analytics,
        // NOT the global default.
        $resolved = $this->resolveDbFor(new BindDbAnalyticsWidget());
        $this->assertSame($analytics, $resolved);
        $this->assertNotSame($main, $resolved);

        // The default model still resolves to the global default.
        $this->assertSame($main, $this->resolveDbFor(new BindDbDefaultWidget()));
    }

    public function testMissingNamedConnectionThrowsClearError(): void
    {
        \Tina4\ORM::bindDatabase(Database::create('sqlite::memory:'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Named database 'missing' not found");
        $this->expectExceptionMessage("ORM::bindDatabase(\$db, name: 'missing')");

        $this->resolveDbFor(new BindDbMissingWidget());
    }

    public function testDirectAdapterOnDbIsUsedAsIs(): void
    {
        // A model carrying a live adapter on $_db uses it directly, with no
        // registry or global lookup.
        $db = Database::create('sqlite::memory:');
        $w = new BindDbDefaultWidget();
        $w->_db = $db;

        $this->assertSame($db, $this->resolveDbFor($w));
    }

    // ── Live two-database integration test (PostgreSQL, auto-skipped) ────────

    /**
     * Bind tina4_php as the default and tina4_rb as name:'analytics'. A default
     * model and an analytics model each create a table + insert in their OWN
     * database. Assert via SELECT current_database() that they resolve to
     * different DBs and the analytics table does not exist in the default DB.
     */
    public function testLiveTwoDatabaseRouting(): void
    {
        if (!function_exists('pg_connect')) {
            $this->markTestSkipped('PostgresAdapter requires the ext-pgsql PHP extension.');
        }
        if (!self::pgReachable()) {
            $this->markTestSkipped(sprintf(
                'PostgreSQL not reachable at %s:%d — skip integration test',
                self::PG_HOST, self::PG_PORT
            ));
        }

        $mainDb = Database::create(
            sprintf('postgres://%s:%d/%s', self::PG_HOST, self::PG_PORT, 'tina4_php'),
            username: self::PG_USER, password: self::PG_PASS
        );
        $analyticsDb = Database::create(
            sprintf('postgres://%s:%d/%s', self::PG_HOST, self::PG_PORT, 'tina4_rb'),
            username: self::PG_USER, password: self::PG_PASS
        );

        \Tina4\ORM::bindDatabase($mainDb);                          // default
        \Tina4\ORM::bindDatabase($analyticsDb, name: 'analytics');  // named

        $this->dropTwo($mainDb, $analyticsDb);

        try {
            // Each model writes to its own database.
            $main = new BindDbDefaultWidget(['id' => 1, 'name' => 'in-main']);
            $this->assertTrue($main->createTable(), 'default model createTable() in tina4_php');
            $main->save();

            $analytics = new BindDbAnalyticsWidget(['id' => 1, 'name' => 'in-analytics']);
            $this->assertTrue($analytics->createTable(), 'analytics model createTable() in tina4_rb');
            $analytics->save();

            // Each model's resolved connection points at the expected database.
            $mainCurrent = $this->resolveDbFor($main)
                ->fetchOne('SELECT current_database() AS db')['db'];
            $analyticsCurrent = $this->resolveDbFor($analytics)
                ->fetchOne('SELECT current_database() AS db')['db'];

            $this->assertSame('tina4_php', $mainCurrent);
            $this->assertSame('tina4_rb', $analyticsCurrent);
            $this->assertNotSame($mainCurrent, $analyticsCurrent);

            // The analytics table lives in tina4_rb only — it must NOT exist in
            // the default (tina4_php) database.
            $this->assertTrue($mainDb->tableExists('t4_bind_default'));
            $this->assertFalse(
                $mainDb->tableExists('t4_bind_analytics'),
                'analytics table must not exist in the default database'
            );
            $this->assertTrue($analyticsDb->tableExists('t4_bind_analytics'));
        } finally {
            $this->dropTwo($mainDb, $analyticsDb);
            $mainDb->close();
            $analyticsDb->close();
        }
    }

    private function dropTwo(DatabaseAdapter $mainDb, DatabaseAdapter $analyticsDb): void
    {
        foreach ([$mainDb, $analyticsDb] as $db) {
            $db->execute('DROP TABLE IF EXISTS t4_bind_default');
            $db->execute('DROP TABLE IF EXISTS t4_bind_analytics');
            $db->commit();
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

    /** Invoke the protected ORM::resolveDbFor() resolver under test. */
    private function resolveDbFor(\Tina4\ORM $instance): DatabaseAdapter
    {
        $m = new \ReflectionMethod(\Tina4\ORM::class, 'resolveDbFor');
        return $m->invoke(null, $instance);
    }
}
