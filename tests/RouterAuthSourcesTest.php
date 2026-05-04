<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Tests for the three-source auth check in Router::dispatchInner():
 *   Priority 1: Authorization Bearer header
 *   Priority 2: formToken in request body
 *   Priority 3: Session token
 *
 * Also tests FreshToken response header on body-token validation and
 * the add_header() alias on Response.
 *
 * Run: ./vendor/bin/phpunit tests/RouterAuthSourcesTest.php --verbose
 */

use PHPUnit\Framework\TestCase;
use Tina4\Auth;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;
use Tina4\Session;

class RouterAuthSourcesTest extends TestCase
{
    private string $secret = 'test-auth-sources-secret';

    protected function setUp(): void
    {
        Router::clear();
        putenv("TINA4_SECRET={$this->secret}");
    }

    protected function tearDown(): void
    {
        Router::clear();
        putenv('TINA4_SECRET');
    }

    // ── Bearer header ────────────────────────────────────────────

    public function testValidBearerHeaderPasses(): void
    {
        Router::post('/api/items', function ($request, $response) {
            return $response->json(['ok' => true]);
        });

        $token = Auth::getToken(['sub' => 'user-1'], $this->secret);
        $request = $this->createRequest('POST', '/api/items', authHeader: "Bearer $token");
        $response = $this->dispatchInner($request, new Response(true));

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testInvalidBearerHeaderReturns401(): void
    {
        Router::post('/api/items', function ($request, $response) {
            return $response->json(['ok' => true]);
        });

        $request = $this->createRequest('POST', '/api/items', authHeader: 'Bearer garbage.token.here');
        $response = $this->dispatchInner($request, new Response(true));

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testMissingBearerHeaderAndNoOtherSourceReturns401(): void
    {
        Router::post('/api/items', function ($request, $response) {
            return $response->json(['ok' => true]);
        });

        $request = $this->createRequest('POST', '/api/items');
        $response = $this->dispatchInner($request, new Response(true));

        $this->assertEquals(401, $response->getStatusCode());
    }

    // ── Body formToken ───────────────────────────────────────────

    public function testValidFormTokenInBodyPasses(): void
    {
        Router::post('/api/items', function ($request, $response) {
            return $response->json(['ok' => true]);
        });

        $token = Auth::getToken(['type' => 'form'], $this->secret);
        $request = $this->createRequest('POST', '/api/items', body: ['formToken' => $token]);
        $response = $this->dispatchInner($request, new Response(true));

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testInvalidFormTokenInBodyReturns401(): void
    {
        Router::post('/api/items', function ($request, $response) {
            return $response->json(['ok' => true]);
        });

        $request = $this->createRequest('POST', '/api/items', body: ['formToken' => 'bad.token.value']);
        $response = $this->dispatchInner($request, new Response(true));

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testFreshTokenHeaderReturnedWhenBodyTokenValidates(): void
    {
        Router::post('/api/items', function ($request, $response) {
            return $response->json(['ok' => true]);
        });

        $token = Auth::getToken(['type' => 'form'], $this->secret);
        $request = $this->createRequest('POST', '/api/items', body: ['formToken' => $token]);
        $response = $this->dispatchInner($request, new Response(true));

        $this->assertEquals(200, $response->getStatusCode());

        $freshToken = $response->getHeader('FreshToken');
        $this->assertNotNull($freshToken, 'FreshToken header should be set when body token validates');
        $this->assertTrue(Auth::validToken($freshToken, $this->secret), 'FreshToken should be a valid JWT');
    }

    public function testNoFreshTokenHeaderWhenBearerHeaderUsed(): void
    {
        Router::post('/api/items', function ($request, $response) {
            return $response->json(['ok' => true]);
        });

        $token = Auth::getToken(['sub' => 'user-1'], $this->secret);
        $request = $this->createRequest('POST', '/api/items', authHeader: "Bearer $token");
        $response = $this->dispatchInner($request, new Response(true));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertNull($response->getHeader('FreshToken'), 'FreshToken should NOT be set for Bearer header auth');
    }

    // ── Session token ────────────────────────────────────────────

    public function testValidSessionTokenPasses(): void
    {
        Router::get('/admin/dashboard', function ($request, $response) {
            return $response->json(['ok' => true]);
        })->secure();

        $token = Auth::getToken(['sub' => 'admin'], $this->secret);
        $session = new Session('file', ['path' => sys_get_temp_dir() . '/tina4-test-sessions-' . uniqid()]);
        $session->start();
        $session->set('token', $token);

        $request = $this->createRequest('GET', '/admin/dashboard');
        $request->session = $session;

        $response = $this->dispatchInner($request, new Response(true));

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testInvalidSessionTokenReturns401(): void
    {
        Router::get('/admin/dashboard', function ($request, $response) {
            return $response->json(['ok' => true]);
        })->secure();

        $session = new Session('file', ['path' => sys_get_temp_dir() . '/tina4-test-sessions-' . uniqid()]);
        $session->start();
        $session->set('token', 'invalid.session.token');

        $request = $this->createRequest('GET', '/admin/dashboard');
        $request->session = $session;

        $response = $this->dispatchInner($request, new Response(true));

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testNoSessionTokenReturns401(): void
    {
        Router::get('/admin/dashboard', function ($request, $response) {
            return $response->json(['ok' => true]);
        })->secure();

        // No session set at all on the request
        $request = $this->createRequest('GET', '/admin/dashboard');
        $response = $this->dispatchInner($request, new Response(true));

        $this->assertEquals(401, $response->getStatusCode());
    }

    // ── Priority chain (header > body > session) ─────────────────

    public function testBearerHeaderTakesPriorityOverBodyToken(): void
    {
        Router::post('/api/items', function ($request, $response) {
            $sub = is_array($request->user) ? ($request->user['sub'] ?? 'MISSING') : 'NOT_ARRAY';
            return $response->json(['sub' => $sub]);
        });

        $headerToken = Auth::getToken(['sub' => 'from-header'], $this->secret);
        $bodyToken = Auth::getToken(['sub' => 'from-body'], $this->secret);

        $request = $this->createRequest(
            'POST',
            '/api/items',
            authHeader: "Bearer $headerToken",
            body: ['formToken' => $bodyToken]
        );
        $response = $this->dispatchInner($request, new Response(true));

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        $this->assertEquals('from-header', $body['sub'], 'Bearer header should take priority over body formToken');

        // No FreshToken header because header source was used, not body
        $this->assertNull($response->getHeader('FreshToken'));
    }

    public function testBodyTokenTakesPriorityOverSessionToken(): void
    {
        Router::post('/api/items', function ($request, $response) {
            $sub = is_array($request->user) ? ($request->user['sub'] ?? 'MISSING') : 'NOT_ARRAY';
            return $response->json(['sub' => $sub]);
        });

        $bodyToken = Auth::getToken(['sub' => 'from-body'], $this->secret);
        $sessionToken = Auth::getToken(['sub' => 'from-session'], $this->secret);

        $session = new Session('file', ['path' => sys_get_temp_dir() . '/tina4-test-sessions-' . uniqid()]);
        $session->start();
        $session->set('token', $sessionToken);

        $request = $this->createRequest('POST', '/api/items', body: ['formToken' => $bodyToken]);
        $request->session = $session;

        $response = $this->dispatchInner($request, new Response(true));

        $this->assertEquals(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        $this->assertEquals('from-body', $body['sub'], 'Body formToken should take priority over session token');

        // FreshToken header should be set because body source was used
        $this->assertNotNull($response->getHeader('FreshToken'));
    }

    // ── Response::add_header() ──────────────────────────────────

    public function testAddHeaderAliasWorks(): void
    {
        $response = new Response(true);
        $response->add_header('X-Custom', 'test-value');

        $this->assertEquals('test-value', $response->getHeader('X-Custom'));
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function createRequest(
        string $method,
        string $path,
        string $authHeader = '',
        array $body = [],
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
