<?php

/**
 * Regression tests for middleware-vs-auth semantics.
 *
 * Pre-3.13.2 attaching ->middleware([SomeClass::class]) to a write route
 * silently set noAuth=true, which let any POST/PUT/PATCH/DELETE serve
 * unauthenticated traffic the moment a logging or CORS middleware
 * landed on it — a security footgun documented in tina4-book#141 as
 * PY-10-02. The fix makes middleware purely additive: it never relaxes
 * the auth gate. Developers explicitly open routes with ->noAuth() and
 * lock GET routes with ->secure().
 *
 * This file is the regression guard. Each test below pins the new
 * semantic — the historical "middleware implies noAuth" behaviour must
 * stay buried.
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

    // ── Write routes with middleware STILL require auth ──────────────

    public function testPostWithMiddlewareStillRequiresAuth(): void
    {
        Router::post("/api/tasks", fn($rq, $rs) => $rs("ok"))
            ->middleware([DummyOAuthMiddleware::class]);

        $match = Router::match('POST', '/api/tasks');
        $this->assertNotNull($match);
        $this->assertTrue(empty($match['route']['noAuth']), 'POST + middleware must NOT auto-set noAuth (PY-10-02)');
    }

    public function testPutWithMiddlewareStillRequiresAuth(): void
    {
        Router::put("/api/tasks/{id}", fn($rq, $rs) => $rs("ok"))
            ->middleware([DummyOAuthMiddleware::class]);

        $match = Router::match('PUT', '/api/tasks/1');
        $this->assertNotNull($match);
        $this->assertTrue(empty($match['route']['noAuth']), 'PUT + middleware must NOT auto-set noAuth (PY-10-02)');
    }

    public function testPatchWithMiddlewareStillRequiresAuth(): void
    {
        Router::patch("/api/tasks/{id}", fn($rq, $rs) => $rs("ok"))
            ->middleware([DummyOAuthMiddleware::class]);

        $match = Router::match('PATCH', '/api/tasks/1');
        $this->assertNotNull($match);
        $this->assertTrue(empty($match['route']['noAuth']), 'PATCH + middleware must NOT auto-set noAuth (PY-10-02)');
    }

    public function testDeleteWithMiddlewareStillRequiresAuth(): void
    {
        Router::delete("/api/tasks/{id}", fn($rq, $rs) => $rs("ok"))
            ->middleware([DummyOAuthMiddleware::class]);

        $match = Router::match('DELETE', '/api/tasks/1');
        $this->assertNotNull($match);
        $this->assertTrue(empty($match['route']['noAuth']), 'DELETE + middleware must NOT auto-set noAuth (PY-10-02)');
    }

    // ── Write routes WITHOUT middleware still require auth ───────────

    public function testPostWithoutMiddlewareRequiresAuth(): void
    {
        Router::post("/api/items", fn($rq, $rs) => $rs("ok"));

        $match = Router::match('POST', '/api/items');
        $this->assertNotNull($match);
        $this->assertTrue(empty($match['route']['noAuth']), 'POST without middleware should NOT have noAuth');
    }

    // ── ->secure() still enables auth on GET routes ──────────────────

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
