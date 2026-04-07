<?php

/**
 * Tests for middleware-skips-auth behaviour.
 *
 * When a route has custom middleware, the built-in Bearer token auth gate
 * is skipped — the developer's middleware handles auth. This allows
 * OAuth cookie-based sessions and other non-Bearer auth patterns.
 *
 * Use ->secure() to explicitly re-enable the built-in gate on routes
 * that have middleware.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Router;

class DummyOAuthMiddleware
{
    public static function handle($request, $response): array
    {
        return [$request, $response];
    }
}

class MiddlewareAuthBypassTest extends TestCase
{
    protected function setUp(): void
    {
        Router::clear();
    }

    protected function tearDown(): void
    {
        Router::clear();
    }

    // ── Write routes with middleware skip built-in auth ──────────────

    public function testPostWithMiddlewareSkipsAuth(): void
    {
        Router::post("/api/tasks", fn($rq, $rs) => $rs("ok"))
            ->middleware([DummyOAuthMiddleware::class]);

        $match = Router::match('POST', '/api/tasks');
        $this->assertNotNull($match);
        $this->assertTrue(!empty($match['route']['noAuth']), 'POST with middleware should have noAuth=true');
    }

    public function testPutWithMiddlewareSkipsAuth(): void
    {
        Router::put("/api/tasks/{id}", fn($rq, $rs) => $rs("ok"))
            ->middleware([DummyOAuthMiddleware::class]);

        $match = Router::match('PUT', '/api/tasks/1');
        $this->assertNotNull($match);
        $this->assertTrue(!empty($match['route']['noAuth']), 'PUT with middleware should have noAuth=true');
    }

    public function testPatchWithMiddlewareSkipsAuth(): void
    {
        Router::patch("/api/tasks/{id}", fn($rq, $rs) => $rs("ok"))
            ->middleware([DummyOAuthMiddleware::class]);

        $match = Router::match('PATCH', '/api/tasks/1');
        $this->assertNotNull($match);
        $this->assertTrue(!empty($match['route']['noAuth']), 'PATCH with middleware should have noAuth=true');
    }

    public function testDeleteWithMiddlewareSkipsAuth(): void
    {
        Router::delete("/api/tasks/{id}", fn($rq, $rs) => $rs("ok"))
            ->middleware([DummyOAuthMiddleware::class]);

        $match = Router::match('DELETE', '/api/tasks/1');
        $this->assertNotNull($match);
        $this->assertTrue(!empty($match['route']['noAuth']), 'DELETE with middleware should have noAuth=true');
    }

    // ── Write routes WITHOUT middleware still require auth ───────────

    public function testPostWithoutMiddlewareRequiresAuth(): void
    {
        Router::post("/api/items", fn($rq, $rs) => $rs("ok"));

        $match = Router::match('POST', '/api/items');
        $this->assertNotNull($match);
        $this->assertTrue(empty($match['route']['noAuth']), 'POST without middleware should NOT have noAuth');
    }

    // ── ->secure() re-enables auth even with middleware ──────────────

    public function testPostWithMiddlewareAndSecureRequiresAuth(): void
    {
        Router::post("/api/admin/tasks", fn($rq, $rs) => $rs("ok"))
            ->secure()
            ->middleware([DummyOAuthMiddleware::class]);

        $match = Router::match('POST', '/api/admin/tasks');
        $this->assertNotNull($match);
        $this->assertTrue(!empty($match['route']['secure']), 'POST with middleware + secure() should have secure=true');
    }

    // ── ->noAuth() still works on bare routes ───────────────────────

    public function testPostWithNoauthIsPublic(): void
    {
        Router::post("/api/webhook", fn($rq, $rs) => $rs("ok"))
            ->noAuth();

        $match = Router::match('POST', '/api/webhook');
        $this->assertNotNull($match);
        $this->assertTrue(!empty($match['route']['noAuth']), 'POST with noAuth() should have noAuth=true');
    }

    // ── GET routes unaffected ────────────────────────────────────────

    public function testGetWithMiddlewareStaysPublic(): void
    {
        Router::get("/api/tasks", fn($rq, $rs) => $rs("ok"))
            ->middleware([DummyOAuthMiddleware::class]);

        $match = Router::match('GET', '/api/tasks');
        $this->assertNotNull($match);
        $this->assertTrue(empty($match['route']['secure']), 'GET with middleware should not be secure');
    }
}
