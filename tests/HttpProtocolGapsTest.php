<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * RFC 9110 conformance — HTTP method handling gaps.
 *
 * Reported against 24stack v3.11.36: `Router::get(...)` returns 404 on HEAD.
 * RFC 9110 §9.3.2: "The HEAD method is identical to GET except that the
 * server MUST NOT send content in the response." §15.5.6: 405 (not 404)
 * when the path exists but the method doesn't. §10.2.1: 405 MUST include
 * an Allow header listing valid methods.
 *
 * These tests are written BEFORE the fix lands. Expect them to fail on
 * 3.12.7 — that's the gate they're meant to close.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Router;
use Tina4\Request;
use Tina4\Response;

class HttpProtocolGapsTest extends TestCase
{
    protected function setUp(): void
    {
        Router::clear();
        // Tests assert raw HTTP semantics — keep the dev toolbar out of the way.
        putenv('TINA4_DEBUG=false');
        unset($_ENV['TINA4_DEBUG']);
        \Tina4\DotEnv::resetEnv();
    }

    protected function tearDown(): void
    {
        Router::clear();
    }

    private function dispatch(string $method, string $path, array $headers = []): Response
    {
        $request = Request::create(
            method: $method,
            path: $path,
            headers: $headers,
        );
        return Router::dispatch($request, new Response(testing: true));
    }

    // ── Group 1: HEAD auto-fallback to GET ───────────────────────────────

    public function testHeadOnGetRouteReturns200(): void
    {
        Router::get('/welcome', fn($req, $res) => $res->json(['ok' => true]));

        $resp = $this->dispatch('HEAD', '/welcome');
        $this->assertSame(
            200,
            $resp->getStatusCode(),
            'HEAD MUST succeed on every route registered as GET (RFC 9110 §9.3.2)'
        );
    }

    public function testHeadResponseBodyIsEmpty(): void
    {
        Router::get('/welcome', fn($req, $res) => $res->json(['heavy' => str_repeat('x', 5000)]));

        $resp = $this->dispatch('HEAD', '/welcome');
        $this->assertSame(
            '',
            $resp->getBody(),
            'RFC 9110 §9.3.2: server MUST NOT send content in a HEAD response'
        );
    }

    public function testHeadCarriesSameContentTypeAsGet(): void
    {
        Router::get('/welcome', fn($req, $res) => $res->json(['ok' => true]));

        $resp = $this->dispatch('HEAD', '/welcome');
        $this->assertStringContainsString(
            'application/json',
            (string) $resp->getContentType(),
            'HEAD SHOULD send the same headers the GET would have sent'
        );
    }

    public function testHeadOnNonexistentPathStill404(): void
    {
        Router::get('/welcome', fn($req, $res) => $res->json(['ok' => true]));

        $resp = $this->dispatch('HEAD', '/does/not/exist');
        $this->assertSame(404, $resp->getStatusCode());
    }

    public function testHeadOnPostOnlyPathReturns405(): void
    {
        // Path exists only for POST — HEAD does NOT auto-fall back to POST
        Router::post('/submit', fn($req, $res) => $res->json(['created' => true]));

        $resp = $this->dispatch('HEAD', '/submit');
        $this->assertSame(
            405,
            $resp->getStatusCode(),
            'HEAD only auto-falls back to GET, never to other methods'
        );
        $allow = $resp->getHeaders()['Allow'] ?? '';
        $this->assertStringContainsString('POST', $allow, '405 MUST include Allow listing real methods');
    }

    // ── Group 2: 405 Method Not Allowed + Allow header ──────────────────

    public function testWrongMethodOnExistingPathReturns405NotFound(): void
    {
        Router::post('/submit', fn($req, $res) => $res->json(['ok' => true]));

        $resp = $this->dispatch('GET', '/submit');
        $this->assertSame(
            405,
            $resp->getStatusCode(),
            'RFC 9110 §15.5.6: wrong method on existing path is 405, not 404'
        );
    }

    public function testFiveOhFiveIncludesAllowHeaderListingValidMethods(): void
    {
        Router::get('/x', fn($req, $res) => $res->json([]));
        Router::post('/x', fn($req, $res) => $res->json([]));

        $resp = $this->dispatch('PUT', '/x');
        $this->assertSame(405, $resp->getStatusCode());

        $allow = $resp->getHeaders()['Allow'] ?? '';
        $allowed = array_map('trim', explode(',', $allow));
        $this->assertContains('GET', $allowed, 'Allow MUST list every supported method (RFC 9110 §10.2.1)');
        $this->assertContains('POST', $allowed);
        $this->assertNotContains('PUT', $allowed, 'PUT is what was asked — must NOT be in Allow');
    }

    public function testAllowHeaderIncludesHeadAndOptionsWhenGetIsRegistered(): void
    {
        // GET implies HEAD (auto-fallback) and OPTIONS (auto-handle) work too.
        Router::get('/page', fn($req, $res) => $res->json([]));

        $resp = $this->dispatch('PUT', '/page');
        $this->assertSame(405, $resp->getStatusCode());

        $allowed = array_map('trim', explode(',', $resp->getHeaders()['Allow'] ?? ''));
        $this->assertContains('GET', $allowed);
        $this->assertContains('HEAD', $allowed, 'HEAD MUST appear in Allow whenever GET is registered');
        $this->assertContains('OPTIONS', $allowed, 'OPTIONS MUST appear in Allow for any path the router knows about');
    }

    // ── Group 3: Generic OPTIONS handler ─────────────────────────────────

    public function testOptionsOnExistingPathReturns204(): void
    {
        Router::get('/foo', fn($req, $res) => $res->json([]));

        $resp = $this->dispatch('OPTIONS', '/foo');
        $this->assertSame(
            204,
            $resp->getStatusCode(),
            'RFC 9110 §9.3.7: OPTIONS on an existing resource returns 204 No Content with Allow'
        );
    }

    public function testOptionsAllowIncludesAllRegisteredMethods(): void
    {
        Router::get('/r', fn($req, $res) => $res->json([]));
        Router::post('/r', fn($req, $res) => $res->json([]));
        Router::delete('/r', fn($req, $res) => $res->json([]));

        $resp = $this->dispatch('OPTIONS', '/r');
        $allowed = array_map('trim', explode(',', $resp->getHeaders()['Allow'] ?? ''));

        foreach (['GET', 'POST', 'DELETE', 'HEAD', 'OPTIONS'] as $expected) {
            $this->assertContains($expected, $allowed, "OPTIONS Allow header missing $expected");
        }
        $this->assertNotContains('PUT', $allowed);
        $this->assertNotContains('PATCH', $allowed);
    }

    public function testOptionsOnNonexistentPathReturns404(): void
    {
        Router::get('/exists', fn($req, $res) => $res->json([]));

        $resp = $this->dispatch('OPTIONS', '/does/not/exist');
        $this->assertSame(404, $resp->getStatusCode());
    }

    // ── Group 4: TRACE / CONNECT explicit rejection ──────────────────────

    public function testTraceReturns405OnExistingPath(): void
    {
        Router::get('/x', fn($req, $res) => $res->json([]));

        $resp = $this->dispatch('TRACE', '/x');
        $this->assertSame(
            405,
            $resp->getStatusCode(),
            'TRACE on an existing path must be 405; we never support TRACE (security)'
        );
        $allowed = array_map('trim', explode(',', $resp->getHeaders()['Allow'] ?? ''));
        $this->assertNotContains('TRACE', $allowed);
    }

    public function testConnectReturns405OnExistingPath(): void
    {
        Router::get('/x', fn($req, $res) => $res->json([]));

        $resp = $this->dispatch('CONNECT', '/x');
        $this->assertSame(
            405,
            $resp->getStatusCode(),
            'CONNECT is for proxies; an origin server must reject it'
        );
        $allowed = array_map('trim', explode(',', $resp->getHeaders()['Allow'] ?? ''));
        $this->assertNotContains('CONNECT', $allowed);
    }

    // ── Group 5: Explicit head() / options() registration ───────────────

    public function testExplicitHeadRouteIsHonoured(): void
    {
        Router::get('/probe', fn($req, $res) => $res->json(['from' => 'get']));
        // Custom HEAD with a specific header — the framework must invoke
        // this handler, not the GET fallback.
        Router::head('/probe', function ($req, $res) {
            $res->header('X-Probe-Source', 'custom-head');
            return $res->json([]);
        });

        $resp = $this->dispatch('HEAD', '/probe');
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame(
            'custom-head',
            $resp->getHeaders()['X-Probe-Source'] ?? '',
            'Explicit Router::head() registration must override the GET auto-fallback'
        );
    }

    public function testExplicitOptionsRouteIsHonoured(): void
    {
        Router::get('/cfg', fn($req, $res) => $res->json([]));
        Router::options('/cfg', function ($req, $res) {
            $res->header('X-Custom-Options', 'yes');
            return $res->json(['custom' => true]);
        });

        $resp = $this->dispatch('OPTIONS', '/cfg');
        $this->assertSame(
            'yes',
            $resp->getHeaders()['X-Custom-Options'] ?? '',
            'Explicit Router::options() registration must override the generic 204 handler'
        );
    }

    // ── Group 6: HEAD body strip is unconditional ───────────────────────

    public function testExplicitHeadHandlerBodyAlsoStripped(): void
    {
        // Even if the developer's HEAD handler returns a body, the framework
        // strips it before sending. RFC 9110 §9.3.2: MUST NOT send content.
        Router::head('/x', fn($req, $res) => $res->json(['accidentally' => 'returned a body']));

        $resp = $this->dispatch('HEAD', '/x');
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame(
            '',
            $resp->getBody(),
            'HEAD body strip is unconditional — RFC 9110 §9.3.2 is a MUST, not a SHOULD'
        );
    }

    public function testHeadResponseStillHasContentLengthOfTheBodyItWouldHaveSent(): void
    {
        // RFC 9110 §9.3.2 SHOULD: HEAD should send the headers it would have
        // sent for GET, including Content-Length pointing at the would-be
        // body's byte count. The body itself is stripped; the Content-Length
        // is informative for clients (cache validation, size estimation).
        Router::get('/sized', fn($req, $res) => $res->json(['msg' => 'hi'])); // ~14 bytes

        $resp = $this->dispatch('HEAD', '/sized');
        $headers = $resp->getHeaders();
        $this->assertArrayHasKey(
            'Content-Length',
            $headers,
            'HEAD SHOULD set Content-Length to what GET would have sent'
        );
        $this->assertGreaterThan(
            0,
            (int)$headers['Content-Length'],
            'Content-Length must reflect the actual body size, not 0'
        );
    }
}
