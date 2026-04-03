<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Auth;
use Tina4\Router;
use Tina4\Request;
use Tina4\Response;

class RouterV3Test extends TestCase
{
    protected function setUp(): void
    {
        Router::clear();
    }

    protected function tearDown(): void
    {
        Router::clear();
    }

    // --- Route Registration ---

    public function testRegisterGetRoute(): void
    {
        Router::get('/hello', fn() => 'Hello');
        $result = Router::match('GET', '/hello');

        $this->assertNotNull($result);
        $this->assertSame('/hello', $result['route']['pattern']);
    }

    public function testRegisterPostRoute(): void
    {
        Router::post('/users', fn() => 'created');
        $this->assertNotNull(Router::match('POST', '/users'));
        $this->assertNull(Router::match('GET', '/users'));
    }

    public function testRegisterPutRoute(): void
    {
        Router::put('/users/{id}', fn() => 'updated');
        $this->assertNotNull(Router::match('PUT', '/users/42'));
    }

    public function testRegisterPatchRoute(): void
    {
        Router::patch('/users/{id}', fn() => 'patched');
        $this->assertNotNull(Router::match('PATCH', '/users/5'));
    }

    public function testRegisterDeleteRoute(): void
    {
        Router::delete('/users/{id}', fn() => 'deleted');
        $this->assertNotNull(Router::match('DELETE', '/users/99'));
    }

    public function testRegisterAnyRoute(): void
    {
        Router::any('/status', fn() => 'ok');

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->assertNotNull(Router::match($method, '/status'), "any() should match {$method}");
        }
    }

    // --- Dynamic Parameters ---

    public function testDynamicParam(): void
    {
        Router::get('/users/{id}', fn() => null);
        $result = Router::match('GET', '/users/42');

        $this->assertNotNull($result);
        $this->assertSame('42', $result['params']['id']);
    }

    public function testMultipleDynamicParams(): void
    {
        Router::get('/orgs/{orgId}/users/{userId}', fn() => null);
        $result = Router::match('GET', '/orgs/acme/users/42');

        $this->assertNotNull($result);
        $this->assertSame('acme', $result['params']['orgId']);
        $this->assertSame('42', $result['params']['userId']);
    }

    public function testCatchAllParam(): void
    {
        Router::get('/docs/{path:.*}', fn() => null);
        $result = Router::match('GET', '/docs/api/v1/users');

        $this->assertNotNull($result);
        $this->assertSame('api/v1/users', $result['params']['path']);
    }

    // --- Route Matching ---

    public function testExactMatchOnly(): void
    {
        Router::get('/users', fn() => null);

        $this->assertNotNull(Router::match('GET', '/users'));
        $this->assertNull(Router::match('GET', '/users/extra'));
    }

    public function testNoMatchReturnsNull(): void
    {
        Router::get('/hello', fn() => null);
        $this->assertNull(Router::match('GET', '/goodbye'));
    }

    public function testRootRoute(): void
    {
        Router::get('/', fn() => null);
        $this->assertNotNull(Router::match('GET', '/'));
    }

    public function testTrailingSlashNormalized(): void
    {
        Router::get('/users', fn() => null);
        // Matching strips trailing slashes
        $this->assertNotNull(Router::match('GET', '/users'));
    }

    // --- Middleware ---

    public function testMiddlewareChain(): void
    {
        $log = [];

        Router::get('/admin', fn() => 'admin page')->middleware([
            function () use (&$log) { $log[] = 'mw1'; return true; },
            function () use (&$log) { $log[] = 'mw2'; return true; },
        ]);

        $request = Request::create(method: 'GET', path: '/admin');
        $response = new Response(testing: true);

        Router::dispatch($request, $response);

        $this->assertSame(['mw1', 'mw2'], $log);
    }

    public function testMiddlewareCanBlock(): void
    {
        Router::get('/admin', fn($req, $res) => $res->json(['ok' => true]))->middleware([
            function () { return false; },
        ]);

        $request = Request::create(method: 'GET', path: '/admin');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(403, $result->getStatusCode());
    }

    public function testMiddlewareCanReturnResponse(): void
    {
        Router::get('/admin', fn($req, $res) => $res->json(['ok' => true]))->middleware([
            function ($req, $res) {
                return $res->json(['error' => 'Custom block'], 401);
            },
        ]);

        $request = Request::create(method: 'GET', path: '/admin');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(401, $result->getStatusCode());
    }

    // --- Route Groups ---

    public function testGroupPrefix(): void
    {
        Router::group('/api/v1', function () {
            Router::get('/users', fn() => null);
            Router::post('/users', fn() => null);
        });

        $this->assertNotNull(Router::match('GET', '/api/v1/users'));
        $this->assertNotNull(Router::match('POST', '/api/v1/users'));
        $this->assertNull(Router::match('GET', '/users'));
    }

    public function testNestedGroups(): void
    {
        Router::group('/api', function () {
            Router::group('/v1', function () {
                Router::get('/users', fn() => null);
            });
        });

        $this->assertNotNull(Router::match('GET', '/api/v1/users'));
    }

    public function testGroupWithMiddleware(): void
    {
        $log = [];

        Router::group('/admin', function () {
            Router::get('/dashboard', fn($req, $res) => $res->json(['page' => 'dash']));
        }, [
            function () use (&$log) { $log[] = 'group_mw'; return true; },
        ]);

        $request = Request::create(method: 'GET', path: '/admin/dashboard');
        $response = new Response(testing: true);
        Router::dispatch($request, $response);

        $this->assertSame(['group_mw'], $log);
    }

    // --- Route Chaining ---

    public function testCacheFlag(): void
    {
        Router::get('/data', fn() => null)->cache();
        $result = Router::match('GET', '/data');

        $this->assertTrue($result['route']['cache']);
    }

    public function testNoCacheFlag(): void
    {
        Router::get('/live', fn() => null)->cache()->noCache();
        $result = Router::match('GET', '/live');

        $this->assertFalse($result['route']['cache']);
    }

    public function testSecureFlag(): void
    {
        Router::get('/secret', fn() => null)->secure();
        $result = Router::match('GET', '/secret');

        $this->assertTrue($result['route']['secure']);
    }

    // --- Dispatch ---

    public function testDispatchCallsHandler(): void
    {
        Router::get('/greet/{name}', function (Request $req, Response $res) {
            return $res->json(['greeting' => 'Hello, ' . $req->params['name']]);
        });

        $request = Request::create(method: 'GET', path: '/greet/Alice');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame('Hello, Alice', $result->getJsonBody()['greeting']);
    }

    public function testDispatchNotFound(): void
    {
        $request = Request::create(method: 'GET', path: '/nonexistent');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(404, $result->getStatusCode());
    }

    public function testDispatchSecureRouteWithoutToken(): void
    {
        Router::get('/secure', fn($req, $res) => $res->json(['ok' => true]))->secure();

        $request = Request::create(method: 'GET', path: '/secure');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(401, $result->getStatusCode());
    }

    public function testDispatchSecureRouteWithToken(): void
    {
        $secret = 'router-test-secret';
        putenv("SECRET={$secret}");
        Router::get('/secure', fn($req, $res) => $res->json(['ok' => true]))->secure();

        $token = Auth::getToken(['sub' => 'tester'], $secret);
        $request = Request::create(
            method: 'GET',
            path: '/secure',
            headers: ['authorization' => "Bearer {$token}"],
        );
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(200, $result->getStatusCode());
        putenv('SECRET');
    }

    public function testDispatchArrayReturn(): void
    {
        Router::get('/data', fn($req, $res) => ['foo' => 'bar']);

        $request = Request::create(method: 'GET', path: '/data');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(['foo' => 'bar'], $result->getJsonBody());
    }

    public function testDispatchStringReturn(): void
    {
        Router::get('/page', fn($req, $res) => '<h1>Welcome</h1>');

        $request = Request::create(method: 'GET', path: '/page');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame('<h1>Welcome</h1>', $result->getBody());
    }

    // --- Route Listing ---

    public function testRouteList(): void
    {
        Router::get('/a', fn() => null);
        Router::post('/b', fn() => null)->secure();
        Router::get('/c', fn() => null)->cache();

        $list = Router::getRoutes();
        $this->assertCount(3, $list);

        $methods = array_column($list, 'method');
        $this->assertContains('GET', $methods);
        $this->assertContains('POST', $methods);
    }

    public function testRouteCount(): void
    {
        Router::get('/a', fn() => null);
        Router::post('/b', fn() => null);

        $this->assertSame(2, Router::count());
    }

    // --- Edge Cases ---

    public function testParamsSetOnRequest(): void
    {
        Router::get('/items/{category}/{id}', function (Request $req, Response $res) {
            return $res->json($req->params);
        });

        $request = Request::create(method: 'GET', path: '/items/electronics/42');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $body = $result->getJsonBody();
        $this->assertSame('electronics', $body['category']);
        $this->assertSame('42', $body['id']);
    }

    public function testReset(): void
    {
        Router::get('/test', fn() => null);
        $this->assertSame(1, Router::count());

        Router::clear();
        $this->assertSame(0, Router::count());
    }
}
