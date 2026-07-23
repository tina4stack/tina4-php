<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * D6: TestClient dispatches through the REAL front controller.
 *
 * The in-process TestClient is the surface developers test routes with. It
 * MUST produce what the live server produces, or a green test proves nothing
 * about production. Python's old TestClient called Router.match directly and
 * invoked the handler itself, so on a miss it FABRICATED {"error":"Not found"}
 * and skipped middleware, static files, and the real 404 page. Python has been
 * fixed (dispatch through core.server.app); this test LOCKS IN that PHP's
 * TestClient::request() already dispatches through Router::dispatch() — the
 * identical entry the built-in Server, Swoole, and PHP-FPM all funnel through
 * (Server.php, App.php) — so it never fabricates and always runs the pipeline.
 *
 * The discriminator: an unmatched path returns the framework's REAL HTML 404
 * error page (renderError -> Frond template), NOT an invented JSON body. If a
 * future change reverts TestClient to a match-and-run shortcut, the
 * assertStringContainsString('<!DOCTYPE', ...) below fails — that is the bite.
 *
 * NOT a mock: real Router registration, the real Router::dispatch front
 * controller, real rendered 404.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Router;
use Tina4\TestClient;

class TestClientFrontControllerTest extends TestCase
{
    protected function setUp(): void
    {
        Router::clear();
        putenv('TINA4_SECRET=d6-front-controller-secret');
        $_ENV['TINA4_SECRET'] = 'd6-front-controller-secret';
        Router::get('/d6-open', function ($request, $response) {
            return $response(['ok' => true]);
        });
    }

    protected function tearDown(): void
    {
        Router::clear();
        putenv('TINA4_SECRET');
        unset($_ENV['TINA4_SECRET']);
    }

    // ── THE discriminator: a miss is the real 404, never a fabricated body ──
    public function testUnmatchedPathIsTheRealNotFoundNotAFabricatedBody(): void
    {
        $res = (new TestClient())->get('/d6-definitely-not-a-route');
        $this->assertEquals(404, $res->status);

        $body = trim($res->text());
        // The real front controller renders an HTML error page. The pre-fix
        // Python client invented {"error":"Not found"} — assert PHP does NOT.
        $this->assertNotSame('{"error":"Not found"}', $body);
        $this->assertNotSame('{"error": "Not found"}', $body);
        // And that it IS the real rendered page (this is the assertion that
        // bites if TestClient ever regresses to a shortcut).
        $this->assertStringContainsStringIgnoringCase('<!DOCTYPE', $body);
        $this->assertStringContainsStringIgnoringCase('Not Found', $body);
    }

    // ── A matched route runs the full pipeline ──────────────────────────────
    public function testMatchedRouteRunsThroughThePipeline(): void
    {
        $res = (new TestClient())->get('/d6-open');
        $this->assertEquals(200, $res->status);
        $this->assertTrue($res->json()['ok']);
    }

    // ── The parity accessor is `status` (Ruby/PHP/Node), never `status_code` ─
    public function testResponseExposesStatusForCrossFrameworkParity(): void
    {
        $res = (new TestClient())->get('/d6-open');
        $this->assertObjectHasProperty('status', $res);
        $this->assertFalse(
            property_exists($res, 'status_code'),
            'status_code came back — PHP has diverged from the other three again',
        );
    }
}
