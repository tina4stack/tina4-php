<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * TestClient routes through the REAL auth gate (parity with Python #PY2).
 *
 * The in-process TestClient is the surface developers use to test routes
 * without a live server. It MUST enforce the same auth the live server does,
 * or a green test hides a live 401 — the verification layer would lie.
 *
 * PHP's TestClient::request() dispatches through Router::dispatch(), which
 * enforces auth (write routes secure by default; ->secure() locks a GET;
 * ->noAuth() opens a write). So PHP already meets the contract — unlike
 * Python, whose TestClient used to match-and-run the handler directly and
 * skip the gate. This test LOCKS IN that contract through the TestClient
 * surface (the existing SecureByDefaultTest / PostProtectionTest only ever
 * exercised Router::dispatch / dispatchInner directly, never the client).
 *
 * NOT a mock: real Router registration, real JWTs via Auth::getToken /
 * Auth::validToken signed with a real TINA4_SECRET, real Router::dispatch.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Auth;
use Tina4\Router;
use Tina4\TestClient;

class TestClientAuthTest extends TestCase
{
    private string $secret = 'php-testclient-auth-secret';
    private ?string $priorApiKey = null;

    protected function setUp(): void
    {
        Router::clear();
        // Set in BOTH so Auth::getToken() and Auth::validToken() resolve the
        // same secret ($_ENV first, then getenv) — the pattern PostProtectionTest uses.
        putenv("TINA4_SECRET={$this->secret}");
        $_ENV['TINA4_SECRET'] = $this->secret;

        // A stray API key would authorise every write regardless of the JWT —
        // stash and clear it so the token path is what's actually under test.
        $this->priorApiKey = getenv('TINA4_API_KEY') ?: null;
        putenv('TINA4_API_KEY');
        unset($_ENV['TINA4_API_KEY']);

        // Fresh routes for every test — immune to Router::clear() in setUp.
        Router::post('/tc-secure-write', function ($request, $response) {
            return $response->json(['created' => true], 201);
        });
        Router::post('/tc-public-write', function ($request, $response) {
            return $response->json(['created' => true], 201);
        })->noAuth();
        Router::get('/tc-public-read', function ($request, $response) {
            return $response->json(['ok' => true]);
        });
        Router::get('/tc-secured-read', function ($request, $response) {
            return $response->json(['secret' => true]);
        })->secure();
    }

    protected function tearDown(): void
    {
        Router::clear();
        putenv('TINA4_SECRET');
        unset($_ENV['TINA4_SECRET']);
        if ($this->priorApiKey !== null) {
            putenv("TINA4_API_KEY={$this->priorApiKey}");
            $_ENV['TINA4_API_KEY'] = $this->priorApiKey;
        }
    }

    // ── The core contract: a tokenless write is rejected ─────────────
    public function testTokenlessWriteIsRejected(): void
    {
        $res = (new TestClient())->post('/tc-secure-write', json: ['name' => 'Alice']);
        $this->assertEquals(401, $res->status, 'tokenless write must 401, not silently succeed');
        $this->assertEquals('Unauthorized', $res->json()['error'] ?? null);
    }

    // ── A valid Bearer token passes ──────────────────────────────────
    public function testWriteWithValidBearerTokenPasses(): void
    {
        $token = Auth::getToken(['sub' => 'user-1']);
        $res = (new TestClient())->post(
            '/tc-secure-write',
            json: ['name' => 'Alice'],
            headers: ['Authorization' => "Bearer $token"],
        );
        $this->assertEquals(201, $res->status);
        $this->assertTrue($res->json()['created']);
    }

    // ── A formToken in the body passes (frond.js posts the token there) ─
    public function testWriteWithFormTokenPasses(): void
    {
        $token = Auth::getToken(['sub' => 'user-1']);
        $res = (new TestClient())->post('/tc-secure-write', json: ['name' => 'Bob', 'formToken' => $token]);
        $this->assertEquals(201, $res->status);
    }

    // ── An invalid token is rejected ─────────────────────────────────
    public function testWriteWithInvalidTokenIsRejected(): void
    {
        $res = (new TestClient())->post(
            '/tc-secure-write',
            json: ['name' => 'Mallory'],
            headers: ['Authorization' => 'Bearer not-a-real-token'],
        );
        $this->assertEquals(401, $res->status);
    }

    // ── A write opened with ->noAuth() needs no token ────────────────
    public function testNoAuthWriteIsPublic(): void
    {
        $res = (new TestClient())->post('/tc-public-write', json: ['name' => 'Anon']);
        $this->assertEquals(201, $res->status);
    }

    // ── A plain GET needs no token ───────────────────────────────────
    public function testPublicGetNeedsNoToken(): void
    {
        $res = (new TestClient())->get('/tc-public-read');
        $this->assertEquals(200, $res->status);
        $this->assertTrue($res->json()['ok']);
    }

    // ── A ->secure() GET without a token is rejected ─────────────────
    public function testSecuredGetWithoutTokenIsRejected(): void
    {
        $res = (new TestClient())->get('/tc-secured-read');
        $this->assertEquals(401, $res->status);
    }

    // ── A ->secure() GET with a valid token passes ───────────────────
    public function testSecuredGetWithTokenPasses(): void
    {
        $token = Auth::getToken(['sub' => 'user-1']);
        $res = (new TestClient())->get('/tc-secured-read', headers: ['Authorization' => "Bearer $token"]);
        $this->assertEquals(200, $res->status);
        $this->assertTrue($res->json()['secret']);
    }
}
