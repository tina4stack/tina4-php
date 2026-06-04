<?php

/**
 * Cross-framework middleware + header parity tests for tina4-php.
 *
 * Mirrors tina4-python/tests/test_middleware_parity.py — every assertion
 * here corresponds to one shipped on the Python side under
 * tina4-book#141. The three bugs covered:
 *
 *   - PY-10-01: function-style middleware with $next continuation.
 *               Pre-fix, PHP invoked Closures as `$mw($req, $resp)`
 *               with only two args, so chapter-10 examples whose body
 *               called `$next` were dead code.
 *   - PY-10-02: ->middleware() mutated noAuth on POST/PUT/PATCH/DELETE,
 *               silently opening write routes. Now middleware is purely
 *               additive — ->noAuth()/->secure() drive the gate.
 *   - PY-10-03: $request->headers used lowercase-only keys, so
 *               `['Content-Type']` (mixed-case) returned null. Now the
 *               headers bag is case-insensitive (CaseInsensitiveArray).
 */

use PHPUnit\Framework\TestCase;
use Tina4\CaseInsensitiveArray;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

class ParityClassMiddleware
{
    public static $beforeCalls = 0;
    public static $afterCalls = 0;

    public static function beforeAuth($request, $response): array
    {
        self::$beforeCalls++;
        return [$request, $response];
    }
}

class MiddlewareParityTest extends TestCase
{
    protected function setUp(): void
    {
        Router::clear();
        ParityClassMiddleware::$beforeCalls = 0;
        ParityClassMiddleware::$afterCalls = 0;
    }

    protected function tearDown(): void
    {
        Router::clear();
    }

    // ── PY-10-02: middleware does not auto-disable auth gate ────────

    /**
     * @dataProvider writeMethodProvider
     */
    public function testWriteMethodWithMiddlewareKeepsAuthRequired(string $method): void
    {
        Router::{strtolower($method)}('/api/widgets', fn($rq, $rs) => $rs('ok'))
            ->middleware([ParityClassMiddleware::class]);

        $match = Router::match($method, '/api/widgets');
        $this->assertNotNull($match);
        $this->assertTrue(
            empty($match['route']['noAuth']),
            "{$method} with middleware must keep auth required (PY-10-02)"
        );
    }

    public static function writeMethodProvider(): array
    {
        return [
            'POST'   => ['POST'],
            'PUT'    => ['PUT'],
            'PATCH'  => ['PATCH'],
            'DELETE' => ['DELETE'],
        ];
    }

    public function testNoAuthAboveMiddlewareStillOpensRoute(): void
    {
        Router::post('/api/public-webhook', fn($rq, $rs) => $rs('ok'))
            ->noAuth()
            ->middleware([ParityClassMiddleware::class]);

        $match = Router::match('POST', '/api/public-webhook');
        $this->assertNotNull($match);
        $this->assertTrue(!empty($match['route']['noAuth']), '->noAuth() must keep the route open');

        $request = Request::create(method: 'POST', path: '/api/public-webhook');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertNotSame(401, $result->getStatusCode(), 'noAuth route must not return 401');
    }

    public function testSecureAboveMiddlewareLocksGetRoute(): void
    {
        Router::get('/api/protected', fn($rq, $rs) => $rs('ok'))
            ->secure()
            ->middleware([ParityClassMiddleware::class]);

        $request = Request::create(method: 'GET', path: '/api/protected');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(401, $result->getStatusCode(), '->secure() GET without token must return 401');
    }

    // ── PY-10-03: $request->headers is case-insensitive ─────────────

    public function testHeadersAreCaseInsensitive(): void
    {
        $request = Request::create(
            method: 'POST',
            path: '/echo',
            headers: ['Content-Type' => 'application/json'],
        );

        $this->assertSame('application/json', $request->headers['Content-Type']);
        $this->assertSame('application/json', $request->headers['content-type']);
        $this->assertSame('application/json', $request->headers['CONTENT-TYPE']);
    }

    public function testHeadersLowercaseSourceIsCaseInsensitiveToo(): void
    {
        // parseHeaders() and many existing call sites hand in already-
        // lowercased keys. The wrapper must still accept mixed-case
        // lookups against them.
        $request = Request::create(
            method: 'GET',
            path: '/echo',
            headers: ['authorization' => 'Bearer xyz'],
        );

        $this->assertSame('Bearer xyz', $request->headers['Authorization']);
        $this->assertSame('Bearer xyz', $request->headers['authorization']);
    }

    public function testHeadersIsArrayAccessAndCountable(): void
    {
        $request = Request::create(
            method: 'GET',
            path: '/echo',
            headers: ['X-One' => 'a', 'X-Two' => 'b'],
        );

        $this->assertInstanceOf(CaseInsensitiveArray::class, $request->headers);
        $this->assertCount(2, $request->headers);
        $this->assertTrue(isset($request->headers['x-one']));
        $this->assertFalse(isset($request->headers['x-three']));

        $iterated = [];
        foreach ($request->headers as $name => $value) {
            $iterated[$name] = $value;
        }
        $this->assertSame(['x-one' => 'a', 'x-two' => 'b'], $iterated);
    }

    // ── PY-10-01: function-style middleware with $next continuation ─

    public function testFunctionMiddlewareReceivesNextAndChainCompletes(): void
    {
        $order = [];

        Router::get('/api/fn-mw', function ($req, $resp) use (&$order) {
            $order[] = 'handler';
            return $resp->json(['ok' => true]);
        })->noAuth()->middleware([
            function ($req, $resp, $next) use (&$order) {
                $order[] = 'before';
                $result = $next($req, $resp);
                $order[] = 'after';
                return $result;
            },
        ]);

        $request = Request::create(method: 'GET', path: '/api/fn-mw');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(['before', 'handler', 'after'], $order);
        $this->assertSame(200, $result->getStatusCode());
    }

    public function testFunctionMiddlewareCanShortCircuitWithoutCallingNext(): void
    {
        $handlerCalls = 0;

        Router::get('/api/blocked', function ($req, $resp) use (&$handlerCalls) {
            $handlerCalls++;
            return $resp->json(['should-not' => 'reach']);
        })->middleware([
            // Note: no $next() call — middleware returns its own response.
            function ($req, $resp, $next) {
                return $resp->json(['blocked' => true], 418);
            },
        ]);

        $request = Request::create(method: 'GET', path: '/api/blocked');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(0, $handlerCalls, 'route handler must not run when middleware skips $next');
        $this->assertSame(418, $result->getStatusCode());
        $this->assertSame(['blocked' => true], $result->getJsonBody());
    }

    public function testFunctionMiddlewareChainsInDeclaredOrder(): void
    {
        $order = [];

        Router::get('/api/chain', function ($req, $resp) use (&$order) {
            $order[] = 'handler';
            return $resp->json(['ok' => true]);
        })->middleware([
            function ($req, $resp, $next) use (&$order) {
                $order[] = 'outer-before';
                $result = $next($req, $resp);
                $order[] = 'outer-after';
                return $result;
            },
            function ($req, $resp, $next) use (&$order) {
                $order[] = 'inner-before';
                $result = $next($req, $resp);
                $order[] = 'inner-after';
                return $result;
            },
        ]);

        $request = Request::create(method: 'GET', path: '/api/chain');
        $response = new Response(testing: true);
        Router::dispatch($request, $response);

        $this->assertSame(
            ['outer-before', 'inner-before', 'handler', 'inner-after', 'outer-after'],
            $order,
        );
    }

    // ── Regression guard: class-based before_* still runs ───────────

    public function testClassBasedBeforeMiddlewareStillRuns(): void
    {
        Router::get('/api/class-mw', fn($rq, $rs) => $rs->json(['ok' => true]))
            ->middleware([ParityClassMiddleware::class]);

        $request = Request::create(method: 'GET', path: '/api/class-mw');
        $response = new Response(testing: true);
        Router::dispatch($request, $response);

        $this->assertSame(1, ParityClassMiddleware::$beforeCalls, 'beforeAuth must still be invoked');
    }

    public function testTwoArgClosureMiddlewareStillRunsAsFilter(): void
    {
        // Two-arg closures kept the legacy "filter" semantics —
        // they return false / a Response to short-circuit.
        Router::get('/api/two-arg', fn($rq, $rs) => $rs->json(['ok' => true]))
            ->middleware([
                function ($req, $resp) {
                    return $resp->json(['filtered' => true], 401);
                },
            ]);

        $request = Request::create(method: 'GET', path: '/api/two-arg');
        $response = new Response(testing: true);
        $result = Router::dispatch($request, $response);

        $this->assertSame(401, $result->getStatusCode());
    }
}
