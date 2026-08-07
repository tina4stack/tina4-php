<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Auth;
use Tina4\AutoCrud;
use Tina4\Database\SQLite3Adapter;
use Tina4\ORM;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

/**
 * Test model for AutoCrud tests.
 */
class CrudItem extends ORM
{
    public string $tableName = 'items';
    public string $primaryKey = 'id';
}

class AutoCrudV3Test extends TestCase
{
    private SQLite3Adapter $db;
    private string $secret = 'autocrud-test-secret';

    protected function setUp(): void
    {
        Router::clear();
        putenv("TINA4_SECRET={$this->secret}");
        $_ENV['TINA4_SECRET'] = $this->secret;
        $this->db = new SQLite3Adapter(':memory:');
        $this->db->exec("CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, category TEXT, price REAL)");
        \Tina4\ORM::bindDatabase($this->db);
    }

    protected function tearDown(): void
    {
        Router::clear();
        putenv('TINA4_SECRET');
        unset($_ENV['TINA4_SECRET']);
        $this->db->close();
    }

    // --- Registration ---

    public function testRegisterModel(): void
    {
        $crud = new AutoCrud($this->db);
        $crud->register(CrudItem::class);

        $models = $crud->models();
        $this->assertArrayHasKey('items', $models);
        $this->assertSame(CrudItem::class, $models['items']);
    }

    // --- Route Generation ---

    public function testGenerateRoutes(): void
    {
        $crud = new AutoCrud($this->db);
        $crud->register(CrudItem::class);
        $routes = $crud->generateRoutes();

        $this->assertCount(5, $routes);

        $methods = array_column($routes, 'method');
        $this->assertContains('GET', $methods);
        $this->assertContains('POST', $methods);
        $this->assertContains('PUT', $methods);
        $this->assertContains('DELETE', $methods);
    }

    // --- Secure-by-default writes (opt-in public) ---

    public function testWritesAreSecureByDefault(): void
    {
        // default (public=false): the write routes must NOT be noAuth, so the
        // framework's secure-by-default write gate applies. Regression guard for
        // the 'AutoCrud silently ships public writes' footgun.
        $crud = new AutoCrud($this->db);
        $crud->register(CrudItem::class);
        $crud->generateRoutes();

        foreach ([['POST', '/api/items'], ['PUT', '/api/items/1'], ['DELETE', '/api/items/1']] as [$method, $path]) {
            $match = Router::match($method, $path);
            $this->assertNotNull($match, "$method $path should be registered");
            $this->assertFalse($match['route']['noAuth'], "$method must be secure-by-default (no noAuth)");
        }
    }

    public function testPublicTrueOpensWrites(): void
    {
        // public=true is the explicit, visible opt-in that opens the writes.
        $crud = new AutoCrud($this->db);
        $crud->register(CrudItem::class, true);
        $crud->generateRoutes();

        foreach ([['POST', '/api/items'], ['PUT', '/api/items/1'], ['DELETE', '/api/items/1']] as [$method, $path]) {
            $match = Router::match($method, $path);
            $this->assertTrue($match['route']['noAuth'], "$method must be noAuth when public=true");
        }
    }

    public function testGeneratedRoutePaths(): void
    {
        $crud = new AutoCrud($this->db);
        $crud->register(CrudItem::class);
        $crud->generateRoutes();

        $this->assertNotNull(Router::match('GET', '/api/items'));
        $this->assertNotNull(Router::match('GET', '/api/items/1'));
        $this->assertNotNull(Router::match('POST', '/api/items'));
        $this->assertNotNull(Router::match('PUT', '/api/items/1'));
        $this->assertNotNull(Router::match('DELETE', '/api/items/1'));
    }

    public function testCustomPrefix(): void
    {
        $crud = new AutoCrud($this->db, '/v2/api');
        $crud->register(CrudItem::class);
        $crud->generateRoutes();

        $this->assertNotNull(Router::match('GET', '/v2/api/items'));
    }

    // --- CRUD Operations via Dispatch ---

    public function testCreateItem(): void
    {
        $crud = new AutoCrud($this->db);
        $crud->register(CrudItem::class);
        $crud->generateRoutes();

        $token = Auth::getToken(['sub' => 'tester']);
        $request = Request::create(
            method: 'POST',
            path: '/api/items',
            body: ['name' => 'Widget', 'category' => 'Tools', 'price' => 9.99],
            headers: ['content-type' => 'application/json', 'authorization' => "Bearer {$token}"],
        );
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(201, $result->getStatusCode());
        $body = $result->getJsonBody();
        $this->assertSame('Widget', $body['name']);
    }

    public function testGetItem(): void
    {
        $this->db->exec("INSERT INTO items (name, category, price) VALUES ('Widget', 'Tools', 9.99)");

        $crud = new AutoCrud($this->db);
        $crud->register(CrudItem::class);
        $crud->generateRoutes();

        $request = Request::create(method: 'GET', path: '/api/items/1');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(200, $result->getStatusCode());
        $body = $result->getJsonBody();
        $this->assertSame('Widget', $body['name']);
    }

    public function testGetItemNotFound(): void
    {
        $crud = new AutoCrud($this->db);
        $crud->register(CrudItem::class);
        $crud->generateRoutes();

        $request = Request::create(method: 'GET', path: '/api/items/999');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(404, $result->getStatusCode());
    }

    public function testListItems(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->db->exec("INSERT INTO items (name, category) VALUES (:name, :cat)", [
                ':name' => "Item{$i}",
                ':cat' => 'General',
            ]);
        }

        $crud = new AutoCrud($this->db);
        $crud->register(CrudItem::class);
        $crud->generateRoutes();

        $request = Request::create(method: 'GET', path: '/api/items?limit=3&offset=0');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(200, $result->getStatusCode());
        $body = $result->getJsonBody();
        $this->assertCount(3, $body['records']);
        $this->assertSame(5, $body['total']);
        $this->assertSame(1, $body['page']);
        $this->assertSame(3, $body['per_page']);
        $this->assertSame(2, $body['total_pages']); // ceil(5 / 3)
    }

    public function testUpdateItem(): void
    {
        $this->db->exec("INSERT INTO items (name, category) VALUES ('Widget', 'Tools')");

        $crud = new AutoCrud($this->db);
        $crud->register(CrudItem::class);
        $crud->generateRoutes();

        $token = Auth::getToken(['sub' => 'tester']);
        $request = Request::create(
            method: 'PUT',
            path: '/api/items/1',
            body: ['name' => 'Widget V2'],
            headers: ['content-type' => 'application/json', 'authorization' => "Bearer {$token}"],
        );
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(200, $result->getStatusCode());
        $body = $result->getJsonBody();
        $this->assertSame('Widget V2', $body['name']);
    }

    public function testDeleteItem(): void
    {
        $this->db->exec("INSERT INTO items (name) VALUES ('Widget')");

        $crud = new AutoCrud($this->db);
        $crud->register(CrudItem::class);
        $crud->generateRoutes();

        $token = Auth::getToken(['sub' => 'tester']);
        $request = Request::create(method: 'DELETE', path: '/api/items/1', headers: ['authorization' => "Bearer {$token}"]);
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(200, $result->getStatusCode());

        // Verify deletion
        $rows = $this->db->query("SELECT * FROM items WHERE id = 1");
        $this->assertCount(0, $rows);
    }

    public function testDeleteItemNotFound(): void
    {
        $crud = new AutoCrud($this->db);
        $crud->register(CrudItem::class);
        $crud->generateRoutes();

        $token = Auth::getToken(['sub' => 'tester']);
        $request = Request::create(method: 'DELETE', path: '/api/items/999', headers: ['authorization' => "Bearer {$token}"]);
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(404, $result->getStatusCode());
    }

    // --- Filtering ---

    public function testListWithFilter(): void
    {
        $this->db->exec("INSERT INTO items (name, category) VALUES ('Hammer', 'Tools')");
        $this->db->exec("INSERT INTO items (name, category) VALUES ('Wrench', 'Tools')");
        $this->db->exec("INSERT INTO items (name, category) VALUES ('Apple', 'Food')");

        $crud = new AutoCrud($this->db);
        $crud->register(CrudItem::class);
        $crud->generateRoutes();

        $request = Request::create(
            method: 'GET',
            path: '/api/items',
            query: ['filter' => ['category' => 'Tools']],
        );
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $body = $result->getJsonBody();
        $this->assertCount(2, $body['records']);
        $this->assertSame(2, $body['total']); // true COUNT over the filter
    }

    // --- Sorting ---

    public function testListWithSort(): void
    {
        $this->db->exec("INSERT INTO items (name, price) VALUES ('B-Item', 20.00)");
        $this->db->exec("INSERT INTO items (name, price) VALUES ('A-Item', 10.00)");
        $this->db->exec("INSERT INTO items (name, price) VALUES ('C-Item', 30.00)");

        $crud = new AutoCrud($this->db);
        $crud->register(CrudItem::class);
        $crud->generateRoutes();

        // Sort by name ascending
        $request = Request::create(
            method: 'GET',
            path: '/api/items',
            query: ['sort' => 'name'],
        );
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        // Note: sort via filter path, needs filter to trigger find()
        // Without filter, all() is used which doesn't support sorting
        $this->assertSame(200, $result->getStatusCode());
    }

    // --- Pagination tests ---

    public function testPaginationDefaultLimit(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $this->db->exec("INSERT INTO items (name) VALUES (:name)", [':name' => "Item{$i}"]);
        }

        $crud = new AutoCrud($this->db);
        $crud->register(CrudItem::class);
        $crud->generateRoutes();

        $request = Request::create(method: 'GET', path: '/api/items?limit=5&offset=0');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(200, $result->getStatusCode());
        $body = $result->getJsonBody();
        $this->assertCount(5, $body['records']);
        $this->assertSame(15, $body['total']);
        $this->assertSame(1, $body['page']);
        $this->assertSame(3, $body['total_pages']); // ceil(15 / 5)
    }

    public function testPaginationSecondPage(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->db->exec("INSERT INTO items (name) VALUES (:name)", [':name' => "Item{$i}"]);
        }

        $crud = new AutoCrud($this->db);
        $crud->register(CrudItem::class);
        $crud->generateRoutes();

        $request = Request::create(method: 'GET', path: '/api/items?limit=5&offset=5');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(200, $result->getStatusCode());
        $body = $result->getJsonBody();
        $this->assertCount(3, $body['records']); // 8 - 5 = 3
    }

    // --- ADR-0043: the list envelope is EXACTLY the seven canonical keys ---

    /**
     * ADR-0043: the AutoCrud REST list endpoint returns EXACTLY the seven
     * canonical snake_case keys - records, total, page, per_page, total_pages,
     * limit, offset - and `total` is the TRUE total for the query (a COUNT
     * probe), NEVER the number of rows returned on the page.
     *
     * Regression guard for the handler's own envelope, which built its OWN dict
     * instead of routing through DatabaseResult::toPaginate(): the filter branch
     * shipped `'total' => count($data)` (rows returned), so a page-2 request
     * reported total = pageSize (20) instead of the real 250, and BOTH branches
     * emitted `data` with no page/per_page/total_pages. Real SQLite, 250 real
     * rows, a real dispatched route, page 2 - so rows-returned (20) and the true
     * total (250) cannot coincide and the wrong-total bug is observable.
     */
    public function testPaginateRestListEnvelopeIsTheSevenKeys(): void
    {
        for ($i = 1; $i <= 250; $i++) {
            $this->db->exec("INSERT INTO items (name, category) VALUES (:name, :cat)", [
                ':name' => "Item{$i}",
                ':cat' => 'bulk',
            ]);
        }

        $crud = new AutoCrud($this->db);
        $crud->register(CrudItem::class);
        $crud->generateRoutes();

        $canonical = ['limit', 'offset', 'page', 'per_page', 'records', 'total', 'total_pages'];

        // --- Filter branch (the one that shipped total = count($data)) ---
        $request = Request::create(
            method: 'GET',
            path: '/api/items',
            query: ['filter' => ['category' => 'bulk'], 'limit' => 20, 'offset' => 20],
        );
        $response = new Response(testing: true);
        $body = Router::dispatch($request, $response)->getJsonBody();

        // EXACTLY the seven keys - no `data`, no `count`, no `totalPages`/`perPage`.
        $keys = array_keys($body);
        sort($keys);
        $this->assertSame($canonical, $keys, 'filter branch must be exactly the seven canonical keys');

        // total is the TRUE COUNT over the filter (250), not rows-returned (20 = pageSize).
        $this->assertSame(250, $body['total'], 'total must be the true COUNT over the filter, not rows returned');
        $this->assertNotSame(20, $body['total'], 'total must not be the page size (rows returned)');

        $this->assertCount(20, $body['records'], 'records carries the full page of rows, verbatim');
        $this->assertSame(2, $body['page'], 'page = floor(offset/limit) + 1 = 2');
        $this->assertSame(20, $body['per_page']);
        $this->assertSame(20, $body['limit']);
        $this->assertSame(20, $body['offset']);
        $this->assertSame(13, $body['total_pages'], 'total_pages = ceil(250 / 20) = 13');

        // --- No-filter branch: same envelope, same true total ---
        $request2 = Request::create(
            method: 'GET',
            path: '/api/items',
            query: ['limit' => 20, 'offset' => 20],
        );
        $response2 = new Response(testing: true);
        $body2 = Router::dispatch($request2, $response2)->getJsonBody();

        $keys2 = array_keys($body2);
        sort($keys2);
        $this->assertSame($canonical, $keys2, 'no-filter branch must also be exactly the seven canonical keys');
        $this->assertSame(250, $body2['total'], 'no-filter total is the true COUNT (250)');
        $this->assertCount(20, $body2['records']);
        $this->assertSame(2, $body2['page']);
        $this->assertSame(13, $body2['total_pages']);
    }

    // --- Multiple filter values ---

    public function testListWithFilterMultipleResults(): void
    {
        $this->db->exec("INSERT INTO items (name, category) VALUES ('Hammer', 'Tools')");
        $this->db->exec("INSERT INTO items (name, category) VALUES ('Wrench', 'Tools')");
        $this->db->exec("INSERT INTO items (name, category) VALUES ('Apple', 'Food')");
        $this->db->exec("INSERT INTO items (name, category) VALUES ('Banana', 'Food')");

        $crud = new AutoCrud($this->db);
        $crud->register(CrudItem::class);
        $crud->generateRoutes();

        $request = Request::create(
            method: 'GET',
            path: '/api/items',
            query: ['filter' => ['category' => 'Food']],
        );
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $body = $result->getJsonBody();
        $this->assertCount(2, $body['records']);
        $this->assertSame(2, $body['total']); // true COUNT over the filter
    }

    // --- Update non-existent item ---

    public function testUpdateNonExistent(): void
    {
        $crud = new AutoCrud($this->db);
        $crud->register(CrudItem::class);
        $crud->generateRoutes();

        $token = Auth::getToken(['sub' => 'tester']);
        $request = Request::create(
            method: 'PUT',
            path: '/api/items/999',
            body: ['name' => 'Ghost'],
            headers: ['content-type' => 'application/json', 'authorization' => "Bearer {$token}"],
        );
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(404, $result->getStatusCode());
    }

    // --- List empty table ---

    public function testListEmptyTable(): void
    {
        $crud = new AutoCrud($this->db);
        $crud->register(CrudItem::class);
        $crud->generateRoutes();

        $request = Request::create(method: 'GET', path: '/api/items');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(200, $result->getStatusCode());
        $body = $result->getJsonBody();
        $this->assertCount(0, $body['records']);
        $this->assertSame(0, $body['total']);
    }

    // --- Create multiple items ---

    public function testCreateMultipleItems(): void
    {
        $crud = new AutoCrud($this->db);
        $crud->register(CrudItem::class);
        $crud->generateRoutes();

        $token = Auth::getToken(['sub' => 'tester']);

        for ($i = 1; $i <= 3; $i++) {
            $request = Request::create(
                method: 'POST',
                path: '/api/items',
                body: ['name' => "Item{$i}", 'category' => 'Test'],
                headers: ['content-type' => 'application/json', 'authorization' => "Bearer {$token}"],
            );
            $response = new Response(testing: true);
            $result = Router::dispatch($request, $response);
            $this->assertSame(201, $result->getStatusCode());
        }

        // Verify all 3 exist
        $request = Request::create(method: 'GET', path: '/api/items');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);
        $body = $result->getJsonBody();
        $this->assertSame(3, $body['total']);
    }
}
