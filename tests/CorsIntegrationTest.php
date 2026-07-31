<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Regression test for issue #106 / #107: CORS headers must survive auth rejection.
 *
 * When CORS middleware is registered globally and an auth middleware rejects a request
 * (returns 401), the CORS headers must still be present on the response.  Without this,
 * browsers see a CORS error instead of the intended 401 — masking the real problem.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Router;
use Tina4\Middleware;
use Tina4\Middleware\CorsMiddleware;
use Tina4\Request;
use Tina4\Response;

/**
 * Minimal auth middleware that returns 401 when no X-Auth header is present.
 */
class FakeAuthMiddleware
{
    public static function beforeAuth(Request $request, Response $response): array
    {
        $headers = $request->headers ?? [];
        $authHeader = $headers['x-auth'] ?? $headers['X-Auth'] ?? null;

        if (empty($authHeader)) {
            return [$request, $response->json(['error' => 'Unauthorized'], 401)];
        }

        return [$request, $response];
    }
}

class CorsIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        // ADR-0018 made the CORS default deny. This suite is about CORS POLICY
        // headers, so it now declares the policy it used to inherit from the old
        // permissive default. No assertion below was changed.
        putenv('TINA4_CORS_ORIGINS=*');
        $_ENV['TINA4_CORS_ORIGINS'] = '*';
        \Tina4\Middleware\CorsMiddleware::resetWarnings();
        Router::clear();
        Middleware::reset();
    }

    protected function tearDown(): void
    {
        putenv('TINA4_CORS_ORIGINS');
        unset($_ENV['TINA4_CORS_ORIGINS']);
        Router::clear();
        Middleware::reset();
    }

    /**
     * CORS headers must be present even when auth middleware rejects the request.
     *
     * Issue: When CorsMiddleware and an auth middleware are both registered globally,
     * the CORS headers should appear on 401 responses so that browsers can read the
     * error instead of reporting a CORS error.
     */
    public function testCorsHeadersSetEvenWhenAuthRejects(): void
    {
        // Register CORS middleware globally — must run before auth
        Middleware::use(CorsMiddleware::class);
        // Register the fake auth middleware globally — returns 401 if no X-Auth header
        Middleware::use(FakeAuthMiddleware::class);

        // Register a route that would require auth
        Router::get('/protected', fn($req, $res) => $res->json(['data' => 'secret']));

        // Dispatch a GET /protected request without an X-Auth header
        $request  = Request::create(method: 'GET', path: '/protected');
        $response = new Response(testing: true);
        $result   = Router::dispatch($request, $response);

        // Auth middleware must have rejected the request
        $this->assertSame(401, $result->getStatusCode(), 'Response status should be 401 (auth rejected)');

        // CORS headers must still be present so the browser can read the 401
        $headers = $result->getHeaders();
        $this->assertArrayHasKey(
            'Access-Control-Allow-Origin',
            $headers,
            'Access-Control-Allow-Origin header must be present even on 401 responses'
        );
    }

    /**
     * CORS headers must be present on OPTIONS preflight even when auth rejects.
     */
    public function testCorsHeadersSetOnPreflightWithAuthRejection(): void
    {
        Middleware::use(CorsMiddleware::class);
        Middleware::use(FakeAuthMiddleware::class);

        Router::get('/protected', fn($req, $res) => $res->json(['ok' => true]));

        $request  = Request::create(method: 'OPTIONS', path: '/protected');
        $response = new Response(testing: true);
        $result   = Router::dispatch($request, $response);

        $headers = $result->getHeaders();
        $this->assertArrayHasKey(
            'Access-Control-Allow-Origin',
            $headers,
            'Access-Control-Allow-Origin header must be present on OPTIONS preflight'
        );
    }

    /**
     * A request with the X-Auth header must reach the route handler and return 200.
     */
    public function testAuthenticatedRequestReachesHandler(): void
    {
        Middleware::use(CorsMiddleware::class);
        Middleware::use(FakeAuthMiddleware::class);

        Router::get('/protected', fn($req, $res) => $res->json(['ok' => true]));

        $request  = Request::create(method: 'GET', path: '/protected', headers: ['x-auth' => 'token123']);
        $response = new Response(testing: true);
        $result   = Router::dispatch($request, $response);

        $this->assertSame(200, $result->getStatusCode(), 'Authenticated request should reach the handler');

        $headers = $result->getHeaders();
        $this->assertArrayHasKey(
            'Access-Control-Allow-Origin',
            $headers,
            'Access-Control-Allow-Origin must also be present on successful responses'
        );
    }
}
