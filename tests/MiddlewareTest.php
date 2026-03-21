<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Router;
use Tina4\Request;
use Tina4\Response;
use Tina4\Middleware\CorsMiddleware;
use Tina4\Middleware\RateLimiter;
use Tina4\Middleware\ResponseCache;

class MiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        Router::reset();
    }

    protected function tearDown(): void
    {
        Router::reset();
    }

    // --- Middleware Chain Execution Order ---

    public function testMiddlewareRunsInOrder(): void
    {
        $order = [];

        Router::get('/ordered', fn($req, $res) => $res->json(['ok' => true]))->middleware([
            function () use (&$order) { $order[] = 'first'; return true; },
            function () use (&$order) { $order[] = 'second'; return true; },
            function () use (&$order) { $order[] = 'third'; return true; },
        ]);

        $request = Request::create(method: 'GET', path: '/ordered');
        $response = new Response(testing: true);
        Router::dispatch($request, $response);

        $this->assertSame(['first', 'second', 'third'], $order);
    }

    public function testMiddlewareStopsOnFalse(): void
    {
        $order = [];

        Router::get('/stop', fn($req, $res) => $res->json(['ok' => true]))->middleware([
            function () use (&$order) { $order[] = 'first'; return true; },
            function () use (&$order) { $order[] = 'blocker'; return false; },
            function () use (&$order) { $order[] = 'never'; return true; },
        ]);

        $request = Request::create(method: 'GET', path: '/stop');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(['first', 'blocker'], $order);
        $this->assertSame(403, $result->getStatusCode());
    }

    public function testMiddlewareCanReturnCustomResponse(): void
    {
        Router::get('/custom', fn($req, $res) => $res->json(['ok' => true]))->middleware([
            function ($req, $res) {
                return $res->json(['error' => 'unauthorized'], 401);
            },
        ]);

        $request = Request::create(method: 'GET', path: '/custom');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(401, $result->getStatusCode());
        $this->assertSame('unauthorized', $result->getJsonBody()['error']);
    }

    public function testHandlerRunsAfterAllMiddleware(): void
    {
        $handlerCalled = false;

        Router::get('/after', function ($req, $res) use (&$handlerCalled) {
            $handlerCalled = true;
            return $res->json(['done' => true]);
        })->middleware([
            function () { return true; },
            function () { return true; },
        ]);

        $request = Request::create(method: 'GET', path: '/after');
        $response = new Response(testing: true);
        Router::dispatch($request, $response);

        $this->assertTrue($handlerCalled);
    }

    public function testHandlerNotCalledWhenMiddlewareBlocks(): void
    {
        $handlerCalled = false;

        Router::get('/blocked', function ($req, $res) use (&$handlerCalled) {
            $handlerCalled = true;
            return $res->json(['done' => true]);
        })->middleware([
            function () { return false; },
        ]);

        $request = Request::create(method: 'GET', path: '/blocked');
        $response = new Response(testing: true);
        Router::dispatch($request, $response);

        $this->assertFalse($handlerCalled);
    }

    // --- Group Middleware ---

    public function testGroupMiddlewareRunsBeforeRouteMiddleware(): void
    {
        $order = [];

        Router::group('/api', function () use (&$order) {
            Router::get('/data', fn($req, $res) => $res->json(['ok' => true]))->middleware([
                function () use (&$order) { $order[] = 'route_mw'; return true; },
            ]);
        }, [
            function () use (&$order) { $order[] = 'group_mw'; return true; },
        ]);

        $request = Request::create(method: 'GET', path: '/api/data');
        $response = new Response(testing: true);
        Router::dispatch($request, $response);

        $this->assertSame(['group_mw', 'route_mw'], $order);
    }

    public function testGroupMiddlewareBlocksPreventsRouteMiddleware(): void
    {
        $routeMwCalled = false;

        Router::group('/api', function () use (&$routeMwCalled) {
            Router::get('/data', fn($req, $res) => $res->json(['ok' => true]))->middleware([
                function () use (&$routeMwCalled) { $routeMwCalled = true; return true; },
            ]);
        }, [
            function () { return false; },
        ]);

        $request = Request::create(method: 'GET', path: '/api/data');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertFalse($routeMwCalled);
        $this->assertSame(403, $result->getStatusCode());
    }

    public function testNestedGroupMiddleware(): void
    {
        $order = [];

        Router::group('/api', function () use (&$order) {
            Router::group('/v1', function () use (&$order) {
                Router::get('/users', fn($req, $res) => $res->json(['ok' => true]))->middleware([
                    function () use (&$order) { $order[] = 'route'; return true; },
                ]);
            }, [
                function () use (&$order) { $order[] = 'inner_group'; return true; },
            ]);
        }, [
            function () use (&$order) { $order[] = 'outer_group'; return true; },
        ]);

        $request = Request::create(method: 'GET', path: '/api/v1/users');
        $response = new Response(testing: true);
        Router::dispatch($request, $response);

        $this->assertSame(['outer_group', 'inner_group', 'route'], $order);
    }

    // --- Middleware with Request/Response ---

    public function testMiddlewareReceivesRequestAndResponse(): void
    {
        $receivedRequest = null;
        $receivedResponse = null;

        Router::get('/inspect', fn($req, $res) => $res->json(['ok' => true]))->middleware([
            function ($req, $res) use (&$receivedRequest, &$receivedResponse) {
                $receivedRequest = $req;
                $receivedResponse = $res;
                return true;
            },
        ]);

        $request = Request::create(method: 'GET', path: '/inspect');
        $response = new Response(testing: true);
        Router::dispatch($request, $response);

        $this->assertInstanceOf(Request::class, $receivedRequest);
        $this->assertInstanceOf(Response::class, $receivedResponse);
    }

    // --- CORS Middleware Integration ---

    public function testCorsMiddlewareWildcardWithRouter(): void
    {
        $cors = new CorsMiddleware();

        Router::get('/api/data', fn($req, $res) => $res->json(['data' => 'test']))->middleware([
            function ($req, $res) use ($cors) {
                $result = $cors->handle($req->method, 'http://example.com');
                // CORS should not block — just adds headers
                return true;
            },
        ]);

        $request = Request::create(method: 'GET', path: '/api/data');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(200, $result->getStatusCode());
    }

    public function testCorsMiddlewareBlocksDisallowedOrigin(): void
    {
        $cors = new CorsMiddleware(origins: 'http://allowed.com');

        Router::get('/api/data', fn($req, $res) => $res->json(['data' => 'test']))->middleware([
            function ($req, $res) use ($cors) {
                $result = $cors->handle($req->method, 'http://evil.com');
                $headers = $result['headers'];
                if (!isset($headers['Access-Control-Allow-Origin'])) {
                    return $res->json(['error' => 'Origin not allowed'], 403);
                }
                return true;
            },
        ]);

        $request = Request::create(method: 'GET', path: '/api/data');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(403, $result->getStatusCode());
    }

    // --- RateLimiter Middleware Integration ---

    public function testRateLimiterMiddlewareAllows(): void
    {
        $limiter = new RateLimiter(limit: 10, window: 60);

        Router::get('/api/limited', fn($req, $res) => $res->json(['ok' => true]))->middleware([
            function ($req, $res) use ($limiter) {
                $result = $limiter->check('127.0.0.1');
                if (!$result['allowed']) {
                    return $res->json(['error' => 'Rate limit exceeded'], 429);
                }
                return true;
            },
        ]);

        $request = Request::create(method: 'GET', path: '/api/limited');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(200, $result->getStatusCode());
    }

    public function testRateLimiterMiddlewareBlocks(): void
    {
        $limiter = new RateLimiter(limit: 2, window: 60);

        Router::get('/api/limited', fn($req, $res) => $res->json(['ok' => true]))->middleware([
            function ($req, $res) use ($limiter) {
                $result = $limiter->check('192.168.1.1');
                if (!$result['allowed']) {
                    return $res->json(['error' => 'Rate limit exceeded'], 429);
                }
                return true;
            },
        ]);

        $request = Request::create(method: 'GET', path: '/api/limited');
        $response = new Response(testing: true);

        // Exhaust the limit
        Router::dispatch($request, $response);
        Router::dispatch($request, $response);

        // Third request should be blocked
        $result = Router::dispatch($request, $response);
        $this->assertSame(429, $result->getStatusCode());
    }

    // --- Combined Middleware Chain ---

    public function testCorsAndRateLimiterChain(): void
    {
        $cors = new CorsMiddleware();
        $limiter = new RateLimiter(limit: 100, window: 60);
        $order = [];

        Router::get('/api/combined', fn($req, $res) => $res->json(['ok' => true]))->middleware([
            function ($req, $res) use ($cors, &$order) {
                $order[] = 'cors';
                $cors->handle($req->method, 'http://example.com');
                return true;
            },
            function ($req, $res) use ($limiter, &$order) {
                $order[] = 'rate_limiter';
                $result = $limiter->check('127.0.0.1');
                return $result['allowed'];
            },
        ]);

        $request = Request::create(method: 'GET', path: '/api/combined');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(200, $result->getStatusCode());
        $this->assertSame(['cors', 'rate_limiter'], $order);
    }

    // --- Empty / No Middleware ---

    public function testRouteWithNoMiddleware(): void
    {
        Router::get('/plain', fn($req, $res) => $res->json(['ok' => true]));

        $request = Request::create(method: 'GET', path: '/plain');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(200, $result->getStatusCode());
    }

    public function testRouteWithEmptyMiddlewareArray(): void
    {
        Router::get('/empty-mw', fn($req, $res) => $res->json(['ok' => true]))->middleware([]);

        $request = Request::create(method: 'GET', path: '/empty-mw');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(200, $result->getStatusCode());
    }

    // --- Middleware on Different HTTP Methods ---

    public function testMiddlewareOnPostRoute(): void
    {
        $mwCalled = false;

        Router::post('/api/create', fn($req, $res) => $res->json(['created' => true]))->middleware([
            function () use (&$mwCalled) { $mwCalled = true; return true; },
        ]);

        $request = Request::create(method: 'POST', path: '/api/create');
        $response = new Response(testing: true);
        Router::dispatch($request, $response);

        $this->assertTrue($mwCalled);
    }

    public function testMiddlewareOnDeleteRoute(): void
    {
        $mwCalled = false;

        Router::delete('/api/items/{id}', fn($req, $res) => $res->json(['deleted' => true]))->middleware([
            function () use (&$mwCalled) { $mwCalled = true; return true; },
        ]);

        $request = Request::create(method: 'DELETE', path: '/api/items/42');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertTrue($mwCalled);
        $this->assertSame(200, $result->getStatusCode());
    }

    // --- ResponseCache Middleware Integration ---

    public function testResponseCacheMiddlewareStoreAndLookup(): void
    {
        $cache = new ResponseCache(['ttl' => 300]);
        $cache->clearCache();

        $handlerCallCount = 0;

        Router::get('/api/cached', function ($req, $res) use ($cache, &$handlerCallCount) {
            $hit = $cache->lookup('GET', '/api/cached');
            if ($hit) {
                return $res->json(json_decode($hit['body'], true), $hit['statusCode']);
            }
            $handlerCallCount++;
            $body = json_encode(['data' => 'fresh']);
            $cache->store('GET', '/api/cached', $body, 'application/json', 200);
            return $res->json(['data' => 'fresh']);
        });

        $request = Request::create(method: 'GET', path: '/api/cached');
        $response = new Response(testing: true);

        // First call — cache miss
        $result1 = Router::dispatch($request, $response);
        $this->assertSame(200, $result1->getStatusCode());
        $this->assertSame(1, $handlerCallCount);

        // Second call — cache hit
        $result2 = Router::dispatch($request, $response);
        $this->assertSame(200, $result2->getStatusCode());
        // Handler should still be called once because our test logic runs in handler
        // but cache lookup returns the stored body
        $stats = $cache->cacheStats();
        $this->assertSame(1, $stats['size']);

        $cache->clearCache();
    }
}
