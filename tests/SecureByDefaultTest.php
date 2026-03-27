<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Tests for secure-by-default auth enforcement.
 *
 * Tina4 PHP v3 auth convention:
 *   - Write routes (POST/PUT/PATCH/DELETE) require Bearer JWT by default
 *   - Use ->noAuth() to opt out of auth on write routes
 *   - GET/HEAD/OPTIONS are open by default
 *   - Use ->secure() to require auth on read routes
 *
 * These tests validate route flag registration and auth enforcement
 * logic via Router::match() and dispatchInner (via reflection) to
 * avoid the Session::save() visibility issue in Router::dispatch().
 */

use PHPUnit\Framework\TestCase;
use Tina4\Auth;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

class SecureByDefaultTest extends TestCase
{
    private string $secret = 'test-secure-default-secret';

    protected function setUp(): void
    {
        Router::reset();
        putenv("SECRET={$this->secret}");
    }

    protected function tearDown(): void
    {
        Router::reset();
        putenv('SECRET');
    }

    // ── POST without Bearer returns 401 ──────────────────────────

    public function testPostWithoutBearerReturns401(): void
    {
        Router::post('/api/items', function ($request, $response) {
            return $response->json(['created' => true], 201);
        });

        // POST routes are secure by default — no noAuth, no secure chain
        $match = Router::match('POST', '/api/items');
        $this->assertNotNull($match);
        $this->assertFalse($match['route']['noAuth'], 'POST should NOT have noAuth by default');

        // Dispatch via reflection to bypass Session::save() issue
        $request = $this->createRequest('POST', '/api/items');
        $response = $this->dispatchInner($request, new Response(true));

        $this->assertEquals(401, $response->getStatusCode());
    }

    // ── POST with valid Bearer returns 200 ───────────────────────

    public function testPostWithValidBearerReturns200(): void
    {
        Router::post('/api/items', function ($request, $response) {
            return $response->json(['created' => true], 201);
        });

        $token = Auth::getToken(['sub' => 'user-1'], $this->secret);
        $request = $this->createRequest('POST', '/api/items', "Bearer $token");
        $response = $this->dispatchInner($request, new Response(true));

        $this->assertEquals(201, $response->getStatusCode());
    }

    // ── POST with noAuth() returns 200 without token ─────────────

    public function testPostWithNoAuthReturns200WithoutToken(): void
    {
        Router::post('/api/webhook', function ($request, $response) {
            return $response->json(['ok' => true]);
        })->noAuth();

        $match = Router::match('POST', '/api/webhook');
        $this->assertNotNull($match);
        $this->assertTrue($match['route']['noAuth'], 'noAuth() should set flag to true');

        $request = $this->createRequest('POST', '/api/webhook');
        $response = $this->dispatchInner($request, new Response(true));

        $this->assertEquals(200, $response->getStatusCode());
    }

    // ── GET with ->secure() returns 401 without token ────────────

    public function testGetWithSecureReturns401WithoutToken(): void
    {
        Router::get('/api/admin/stats', function ($request, $response) {
            return $response->json(['secret' => true]);
        })->secure();

        $match = Router::match('GET', '/api/admin/stats');
        $this->assertNotNull($match);
        $this->assertTrue($match['route']['secure'], 'secure() should set flag to true');

        $request = $this->createRequest('GET', '/api/admin/stats');
        $response = $this->dispatchInner($request, new Response(true));

        $this->assertEquals(401, $response->getStatusCode());
    }

    // ── GET without ->secure() returns 200 ───────────────────────

    public function testGetWithoutSecureReturns200(): void
    {
        Router::get('/api/items', function ($request, $response) {
            return $response->json(['items' => []]);
        });

        $match = Router::match('GET', '/api/items');
        $this->assertNotNull($match);
        $this->assertFalse($match['route']['secure'], 'GET should NOT be secure by default');

        $request = $this->createRequest('GET', '/api/items');
        $response = $this->dispatchInner($request, new Response(true));

        $this->assertEquals(200, $response->getStatusCode());
    }

    // ── noAuth() annotation works on write routes ────────────────

    public function testNoAuthDocblockAnnotationWorks(): void
    {
        Router::post('/api/public', function ($request, $response) {
            return $response->json(['public' => true]);
        })->noAuth();

        // Confirm route flags
        $match = Router::match('POST', '/api/public');
        $this->assertNotNull($match);
        $this->assertTrue($match['route']['noAuth']);

        // Dispatch without a token — should succeed because noAuth is set
        $request = $this->createRequest('POST', '/api/public');
        $response = $this->dispatchInner($request, new Response(true));

        $this->assertEquals(200, $response->getStatusCode());
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function createRequest(string $method, string $path, string $authHeader = ''): Request
    {
        $headers = ['content-type' => 'application/json'];
        if ($authHeader !== '') {
            $headers['authorization'] = $authHeader;
        }

        return new Request(
            method: $method,
            path: $path,
            query: [],
            body: [],
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
