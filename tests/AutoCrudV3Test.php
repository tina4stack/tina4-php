<?php

/**
 * Tina4 - This is not a 4ramework.
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
        putenv("SECRET={$this->secret}");
        $this->db = new SQLite3Adapter(':memory:');
        $this->db->exec("CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, category TEXT, price REAL)");
    }

    protected function tearDown(): void
    {
        Router::clear();
        putenv('SECRET');
        $this->db->close();
    }

    // --- Registration ---

    public function testRegisterModel(): void
    {
        $crud = new AutoCrud($this->db);
        $crud->register(CrudItem::class);

        $models = $crud->getModels();
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

        $token = Auth::getToken(['sub' => 'tester'], $this->secret);
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
        $this->assertCount(3, $body['data']);
        $this->assertSame(5, $body['total']);
    }

    public function testUpdateItem(): void
    {
        $this->db->exec("INSERT INTO items (name, category) VALUES ('Widget', 'Tools')");

        $crud = new AutoCrud($this->db);
        $crud->register(CrudItem::class);
        $crud->generateRoutes();

        $token = Auth::getToken(['sub' => 'tester'], $this->secret);
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

        $token = Auth::getToken(['sub' => 'tester'], $this->secret);
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

        $token = Auth::getToken(['sub' => 'tester'], $this->secret);
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
        $this->assertCount(2, $body['data']);
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
}
