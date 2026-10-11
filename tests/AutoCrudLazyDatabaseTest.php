<?php

/**
 * AutoCrud resolves its database LAZILY (ADR-0094 parity).
 *
 * The crud route `generate crud` writes registers AutoCrud at route-DISCOVERY
 * time, which happens with no database bound yet (e.g. `tina4 routes`, or boot
 * before the .env DB is wired). Before this fix the generated route did
 * `new AutoCrud(ORM::database())`, so discovery threw "No database configured"
 * and the crud routes never loaded - while the Python and Ruby crud routes,
 * which register without touching the DB, loaded fine. AutoCrud now takes no DB
 * at construction and resolves ORM::database() only when a request actually
 * queries, so registration needs no DB and the per-request handlers get the
 * bound one.
 *
 * Real SQLite, no mocks.
 */

use PHPUnit\Framework\TestCase;
use Tina4\AutoCrud;
use Tina4\ORM;
use Tina4\Router;
use Tina4\Request;
use Tina4\Response;
use Tina4\Database\SQLite3Adapter;

class LazyCrudItem extends ORM
{
    public string $tableName = 'lazy_items';
    public string $primaryKey = 'id';
    public ?int $id = null;
    public ?string $name = null;
}

final class AutoCrudLazyDatabaseTest extends TestCase
{
    private \ReflectionProperty $globalDbProp;
    private mixed $savedGlobalDb = null;

    protected function setUp(): void
    {
        Router::clear();
        // ReflectionProperty reaches a private static without setAccessible() on
        // PHP 8.1+ (where setAccessible is a deprecated no-op).
        $this->globalDbProp = new \ReflectionProperty(ORM::class, '_globalDb');
        $this->savedGlobalDb = $this->globalDbProp->getValue();
    }

    protected function tearDown(): void
    {
        Router::clear();
        // Restore whatever binding the rest of the suite expects.
        $this->globalDbProp->setValue(null, $this->savedGlobalDb);
    }

    public function testRegistersCrudRoutesWithNoDatabaseConfigured(): void
    {
        // Route-discovery time: NO database bound.
        $this->globalDbProp->setValue(null, null);

        // The regression: this must NOT throw "No database configured".
        $crud = new AutoCrud();              // no DB argument
        $crud->register(LazyCrudItem::class);
        $generated = $crud->generateRoutes();

        self::assertCount(5, $generated, 'all five crud routes register without a DB');
        foreach ([['GET', '/api/lazy_items'], ['GET', '/api/lazy_items/1'],
                  ['POST', '/api/lazy_items'], ['PUT', '/api/lazy_items/1'],
                  ['DELETE', '/api/lazy_items/1']] as [$method, $path]) {
            self::assertNotNull(
                Router::match($method, $path),
                "$method $path must be registered at discovery with no DB"
            );
        }
    }

    public function testLazyDatabaseResolvesTheBoundConnectionAtRequestTime(): void
    {
        // Register with NO DB (as discovery does), then bind a real SQLite DB and
        // hit the list route: the lazy db() must resolve the bound connection.
        $this->globalDbProp->setValue(null, null);
        $crud = new AutoCrud();
        $crud->register(LazyCrudItem::class);
        $crud->generateRoutes();

        $db = new SQLite3Adapter(':memory:');
        $db->exec('CREATE TABLE lazy_items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        $db->exec('INSERT INTO lazy_items (name) VALUES (:n)', [':n' => 'Widget']);
        ORM::bindDatabase($db);

        $req = Request::create(method: 'GET', path: '/api/lazy_items');
        $res = Router::dispatch($req, new Response(true));
        $body = $res->getJsonBody();

        self::assertSame(1, $body['total'], 'the list handler queried the bound DB at request time');
        self::assertSame('Widget', $body['records'][0]['name']);

        $db->close();
    }
}
