<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * RBAC role/permission guards — Feature 138 / ADR-0058.
 * Contract answer key: tina4-documentation/plan/v3/fixtures/rbac_contract.json.
 *
 * Every case drives a REAL request through Router::dispatchInner() with REAL
 * HS256 tokens minted by Auth::getToken. NO MOCKS. role()/can() read the
 * VERIFIED payload only; a guard implies auth (no token -> 401, valid-but-
 * unauthorised -> 403).
 *
 * Run: ./vendor/bin/phpunit tests/RbacTest.php
 */

use PHPUnit\Framework\TestCase;
use Tina4\Auth;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

class RbacTest extends TestCase
{
    private string $secret = 'rbac-contract-secret';

    protected function setUp(): void
    {
        Router::clear();
        putenv("TINA4_SECRET={$this->secret}");
        $ok = fn ($request, $response) => $response->json(['ok' => true]);
        // GET routes (public by default) so the guard is what makes them require auth.
        Router::get('/rbac/role_admin', $ok)->role('admin');
        Router::get('/rbac/role_any', $ok)->role('admin', 'editor');
        Router::get('/rbac/role_stacked', $ok)->role('admin')->role('editor');
        Router::get('/rbac/can_delete', $ok)->can('posts.delete');
        Router::get('/rbac/can_users', $ok)->can('users.delete');
    }

    protected function tearDown(): void
    {
        Router::clear();
        putenv('TINA4_SECRET');
    }

    private function get(string $path, ?array $payload = null, array $extraHeaders = []): Response
    {
        $headers = ['content-type' => 'application/json'] + $extraHeaders;
        if ($payload !== null) {
            $headers['authorization'] = 'Bearer ' . Auth::getToken($payload, $this->secret);
        }
        $request = new Request(
            method: 'GET',
            path: $path,
            query: [],
            body: [],
            headers: $headers,
            ip: '127.0.0.1',
        );
        $method = new \ReflectionMethod(Router::class, 'dispatchInner');
        return $method->invoke(null, $request, new Response(true));
    }

    // ── rbac-role-allows ───────────────────────────────────────
    public function testRoleClaimAllowsTheRoute(): void
    {
        $this->assertEquals(200, $this->get('/rbac/role_admin', ['sub' => 'u', 'roles' => ['admin']])->getStatusCode());
    }

    // ── rbac-role-denies-403 ───────────────────────────────────
    public function testMissingRoleIsForbidden403(): void
    {
        $this->assertEquals(403, $this->get('/rbac/role_admin', ['sub' => 'u', 'roles' => ['viewer']])->getStatusCode());
    }

    // ── rbac-unauthenticated-401 ───────────────────────────────
    public function testUnauthenticatedGuardIs401(): void
    {
        // No token -> 401 (unauthenticated), NOT 403. A guard implies auth.
        $this->assertEquals(401, $this->get('/rbac/role_admin')->getStatusCode());
    }

    // ── rbac-role-or-and ───────────────────────────────────────
    public function testRoleListIsAnyOf(): void
    {
        $this->assertEquals(200, $this->get('/rbac/role_any', ['sub' => 'u', 'roles' => ['editor']])->getStatusCode());
        $this->assertEquals(200, $this->get('/rbac/role_any', ['sub' => 'u', 'roles' => ['admin']])->getStatusCode());
        $this->assertEquals(403, $this->get('/rbac/role_any', ['sub' => 'u', 'roles' => ['viewer']])->getStatusCode());
    }

    public function testStackedGuardsAreAllOf(): void
    {
        $this->assertEquals(200, $this->get('/rbac/role_stacked', ['sub' => 'u', 'roles' => ['admin', 'editor']])->getStatusCode());
        $this->assertEquals(403, $this->get('/rbac/role_stacked', ['sub' => 'u', 'roles' => ['admin']])->getStatusCode());
    }

    // ── rbac-can-permission ────────────────────────────────────
    public function testPermissionGrantsTheRoute(): void
    {
        $this->assertEquals(200, $this->get('/rbac/can_delete', ['sub' => 'u', 'permissions' => ['posts.delete']])->getStatusCode());
    }

    public function testMissingPermissionIsForbidden403(): void
    {
        $this->assertEquals(403, $this->get('/rbac/can_delete', ['sub' => 'u', 'permissions' => ['posts.read']])->getStatusCode());
    }

    public function testRoleAloneDoesNotSatisfyAPermissionGuard(): void
    {
        $this->assertEquals(403, $this->get('/rbac/can_delete', ['sub' => 'u', 'roles' => ['admin']])->getStatusCode());
    }

    // ── rbac-wildcard-grant ────────────────────────────────────
    public function testWildcardPermissionGrantsWithinScope(): void
    {
        $this->assertEquals(200, $this->get('/rbac/can_delete', ['sub' => 'u', 'permissions' => ['posts.*']])->getStatusCode());
    }

    public function testSuperuserStarGrantsEverything(): void
    {
        $this->assertEquals(200, $this->get('/rbac/can_delete', ['sub' => 'u', 'permissions' => ['*']])->getStatusCode());
    }

    public function testWildcardDoesNotCrossScope(): void
    {
        $this->assertEquals(403, $this->get('/rbac/can_users', ['sub' => 'u', 'permissions' => ['posts.*']])->getStatusCode());
    }

    // ── rbac-verified-payload-only ─────────────────────────────
    public function testSpoofedRoleHeaderIsIgnored(): void
    {
        // A viewer token with a spoofed X-Role: admin header is still forbidden.
        $this->assertEquals(403, $this->get('/rbac/role_admin', ['sub' => 'u', 'roles' => ['viewer']], ['x-role' => 'admin'])->getStatusCode());
    }

    // ── rbac-legacy-singular-role ──────────────────────────────
    public function testLegacySingularRoleIsCoerced(): void
    {
        // A legacy singular role: "admin" is read as roles: ["admin"].
        $this->assertEquals(200, $this->get('/rbac/role_admin', ['sub' => 'u', 'role' => 'admin'])->getStatusCode());
    }
}
