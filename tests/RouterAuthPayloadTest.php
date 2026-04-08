<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Integration tests verifying that after the validToken() → bool refactor
 * the router/middleware correctly populates $request->user with the actual
 * JWT payload dict (not `true`), enforces 401 on missing/invalid tokens,
 * and enforces 403 for CSRF failures.
 *
 * Run: ./vendor/bin/phpunit tests/RouterAuthPayloadTest.php --verbose
 */

use PHPUnit\Framework\TestCase;
use Tina4\Auth;
use Tina4\Middleware\CsrfMiddleware;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

class RouterAuthPayloadTest extends TestCase
{
    private string $secret = 'test-router-auth-secret';

    protected function setUp(): void
    {
        Router::clear();
        putenv("SECRET={$this->secret}");
        putenv('TINA4_CSRF=true');
    }

    protected function tearDown(): void
    {
        Router::clear();
        putenv('SECRET');
        putenv('TINA4_CSRF');
    }

    // ── 1. POST without Bearer returns 401 ───────────────────────

    public function testPostWithoutBearerReturns401(): void
    {
        Router::post('/test/auth', function ($request, $response) {
            return $response->json(['ok' => true], 200);
        });

        $request  = $this->createRequest('POST', '/test/auth');
        $response = $this->dispatchInner($request, new Response(true));

        $this->assertEquals(401, $response->getStatusCode());
    }

    // ── 2. POST with valid Bearer attaches payload (not `true`) ──

    public function testPostWithValidBearerAttachesPayload(): void
    {
        Router::post('/test/auth', function ($request, $response) {
            // If request->user was set to `true` (the bool from old validToken),
            // accessing ['sub'] would fail/return null.
            $sub = is_array($request->user) ? ($request->user['sub'] ?? 'MISSING') : 'NOT_ARRAY';
            return $response->json(['sub' => $sub], 200);
        });

        $token    = Auth::getToken(['sub' => 'test-user']);
        $request  = $this->createRequest('POST', '/test/auth', "Bearer $token");
        $response = $this->dispatchInner($request, new Response(true));

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body, 'Response body should decode to an array');
        $this->assertEquals(
            'test-user',
            $body['sub'],
            'request->user["sub"] should be "test-user", not `true` or null'
        );
    }

    // ── 3. POST with invalid Bearer returns 401 ──────────────────

    public function testInvalidBearerReturns401(): void
    {
        Router::post('/test/auth', function ($request, $response) {
            return $response->json(['ok' => true], 200);
        });

        $request  = $this->createRequest('POST', '/test/auth', 'Bearer garbage.token.here');
        $response = $this->dispatchInner($request, new Response(true));

        $this->assertEquals(401, $response->getStatusCode());
    }

    // ── 4. CSRF middleware rejects POST without formToken → 403 ──

    public function testCsrfInvalidTokenReturns403(): void
    {
        // Call CsrfMiddleware::beforeCsrf() directly (same pattern as CsrfMiddlewareTest).
        // A POST request with no formToken in body or headers should be rejected.
        // We do NOT pass a Bearer token so CSRF is not bypassed by auth.
        $request  = $this->createRequest('POST', '/test/csrf', '', []);
        $response = new Response(true);

        [, $resOut] = CsrfMiddleware::beforeCsrf($request, $response);

        // response->error() returns an array (see CsrfMiddlewareTest::assertCsrfRejected)
        if ($resOut instanceof Response) {
            $this->assertGreaterThanOrEqual(403, $resOut->getStatusCode());
        } else {
            $this->assertIsArray($resOut);
            $this->assertEquals(403, $resOut['status'] ?? 0);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function createRequest(
        string $method,
        string $path,
        string $authHeader = '',
        array  $body = []
    ): Request {
        $headers = ['content-type' => 'application/json'];
        if ($authHeader !== '') {
            $headers['authorization'] = $authHeader;
        }

        return new Request(
            method: $method,
            path: $path,
            query: [],
            body: $body,
            headers: $headers,
            ip: '127.0.0.1',
        );
    }

    /**
     * Call Router::dispatchInner() via reflection to bypass the
     * Session::save() visibility issue in Router::dispatch().
     */
    private function dispatchInner(Request $request, Response $response): Response
    {
        $method = new \ReflectionMethod(Router::class, 'dispatchInner');
        return $method->invoke(null, $request, $response);
    }
}
