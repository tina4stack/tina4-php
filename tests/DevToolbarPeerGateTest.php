<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tina4\DevAdmin;
use Tina4\Middleware;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

/**
 * The dev toolbar goes only to a caller that could use it.
 *
 * Injection used to turn on TINA4_DEBUG and the path alone, while every /__dev
 * endpoint ran the full ADR-0082 gate. A peer that gate refuses — a container's
 * app browsed from the Docker host, a box on the LAN — therefore got the
 * toolbar on every page and 403 from every one of its buttons.
 *
 * Both decisions now come from the same place: DevAdmin::devSurfaceReachable().
 *
 * REAL dispatch through Router::dispatch() with a controllable raw socket peer,
 * no mocks. Each case sends the Host a browser dialling that address would
 * really send, because guardRequest() runs the Host allow-list BEFORE the peer
 * check and a fixed Host cannot tell the two refusals apart.
 */
final class DevToolbarPeerGateTest extends TestCase
{
    private const KEYS = ['TINA4_DEBUG', 'TINA4_HOST', 'TINA4_MCP_TOKEN', 'TINA4_API_KEY', 'TINA4_LOG_LEVEL'];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        Router::clear();
        Middleware::reset();
        foreach (self::KEYS as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key]);
        }
        $this->env('TINA4_DEBUG', 'true');
        $this->env('TINA4_LOG_LEVEL', 'NONE');

        // The /__dev routes, which App::start() registers in development. Without
        // them the surface answers 404 and the test could not tell a refusal from
        // a route that was never there.
        DevAdmin::register();

        Router::get('/page', fn(Request $request, Response $response) => $response->html('<html><body>hi</body></html>', 200))
            ->noAuth();
        Router::get('/boom', function (Request $request, Response $response) {
            throw new \RuntimeException('boom from a route');
        })->noAuth();
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
        Router::clear();
        Middleware::reset();
        $this->releaseErrorTrackerHandlers();
    }

    /**
     * DevAdmin::register() cascades into ErrorTracker::register(), which installs
     * a process-global error/exception handler pair. PHPUnit flags a test that
     * leaves one behind as risky, and the next test inherits it. Same unwind as
     * AppTestSupport's own (that copy is private to it).
     */
    private function releaseErrorTrackerHandlers(): void
    {
        if (!class_exists(\Tina4\ErrorTracker::class, false)) {
            return;
        }
        $depthProperty = new \ReflectionProperty(\Tina4\ErrorTracker::class, 'handlerDepth');
        $depth = $depthProperty->getValue(null);
        if ($depth <= 0) {
            return;
        }
        while ($depth > 0) {
            restore_error_handler();
            restore_exception_handler();
            $depth--;
        }
        $depthProperty->setValue(null, 0);
        (new \ReflectionProperty(\Tina4\ErrorTracker::class, 'registered'))->setValue(null, false);
    }

    private function env(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
    }

    /** @param array<string, string> $headers */
    private function dispatch(string $path, string $peer, string $host, array $headers = []): Response
    {
        $request = Request::create(
            method: 'GET',
            path: $path,
            headers: array_merge(['host' => $host], $headers),
            remoteIp: $peer
        );

        return Router::dispatch($request, new Response(true));
    }

    /**
     * Whether the dev toolbar element is in the page. The toolbar's own id, not a
     * `/__dev` substring: an error page quotes source lines and other links that
     * mention /__dev without being the toolbar.
     */
    private function pageHasToolbar(string $peer, string $host, array $headers = [], string $path = '/page'): bool
    {
        return str_contains((string) $this->dispatch($path, $peer, $host, $headers)->getBody(), 'id="tina4-dev-toolbar"');
    }

    private function devSurfaceStatus(string $peer, string $host, array $headers = []): int
    {
        return $this->dispatch('/__dev/api/routes', $peer, $host, $headers)->getStatusCode() ?? 200;
    }

    /**
     * The contract in one assertion: the toolbar is in the page exactly when the
     * dev surface answers that same caller.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function peerProvider(): array
    {
        return [
            'loopback' => ['127.0.0.1', '127.0.0.1:7145'],
            'IPv6 loopback' => ['::1', '[::1]:7145'],
            'docker gateway' => ['172.17.0.1', '172.17.0.1:7145'],
            'LAN peer' => ['192.168.0.50', '192.168.0.50:7145'],
            'public peer' => ['203.0.113.7', '203.0.113.7:7145'],
            // The peer is loopback but the Host is not one the allow-list admits
            // (DNS rebinding, or a reverse proxy that forwards the public Host):
            // the Host gate refuses before the peer is looked at.
            'loopback peer, foreign Host' => ['127.0.0.1', 'evil.example:7145'],
            // The reported shape: the browser dials a port published on the
            // host's loopback, so it sends a Host the allow-list admits, and the
            // app sees the Docker gateway (or a LAN box) as the socket peer. Only
            // the PEER gate refuses here.
            'published port, docker gateway peer' => ['172.17.0.1', 'localhost:8797'],
            'published port, LAN peer' => ['192.168.0.50', 'localhost:8797'],
        ];
    }

    /**
     * Every caller above on every page below.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function peerAndPageProvider(): array
    {
        $cases = [];
        foreach (self::peerProvider() as $peerLabel => [$peer, $host]) {
            foreach (self::pageProvider() as $pageLabel => [$path]) {
                $cases["{$peerLabel}, {$pageLabel}"] = [$peer, $host, $path];
            }
        }
        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('peerAndPageProvider')]
    public function testTheToolbarIsInThePageExactlyWhenTheSurfaceAnswers(string $peer, string $host, string $path): void
    {
        $allowed = $this->devSurfaceStatus($peer, $host) !== 403;
        $this->assertSame(
            $allowed,
            $this->pageHasToolbar($peer, $host, [], $path),
            $allowed
                ? "{$peer} via {$host} may use the dev surface but got no toolbar on {$path}"
                : "{$peer} via {$host} is refused by /__dev but was still given the toolbar on {$path}"
        );
    }

    /**
     * Every page the framework renders, not just a route's own HTML: the 404 page
     * and the 500 error page (ErrorOverlay renders its own copy of the toolbar,
     * separately from Router::injectDevToolbar()). A refused peer gets none of them.
     *
     * @return array<string, array{0: string}>
     */
    public static function pageProvider(): array
    {
        return ['a route\'s own page' => ['/page'], 'the 404 page' => ['/missing'], 'the 500 error page' => ['/boom']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pageProvider')]
    public function testARefusedPeerGetsNoToolbarOnAnyPage(string $path): void
    {
        $this->assertFalse(
            $this->pageHasToolbar('172.17.0.1', '172.17.0.1:7145', [], $path),
            "{$path}: a peer every /__dev endpoint refuses must get no toolbar"
        );
    }

    /**
     * The reported shape on every page: a Host the allow-list admits, from a peer
     * only the peer gate refuses.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pageProvider')]
    public function testAnAdmittedHostFromARefusedPeerGetsNoToolbarOnAnyPage(string $path): void
    {
        $this->assertSame(403, $this->devSurfaceStatus('172.17.0.1', 'localhost:8797'));
        $this->assertFalse(
            $this->pageHasToolbar('172.17.0.1', 'localhost:8797', [], $path),
            "{$path}: the peer gate refuses this caller, so it must get no toolbar"
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pageProvider')]
    public function testLoopbackGetsTheToolbarOnEveryPage(string $path): void
    {
        $this->assertTrue(
            $this->pageHasToolbar('127.0.0.1', '127.0.0.1:7145', [], $path),
            "{$path}: the ordinary local developer must keep the toolbar"
        );
    }

    public function testLoopbackStillGetsTheToolbar(): void
    {
        $this->assertTrue(
            $this->pageHasToolbar('127.0.0.1', '127.0.0.1:7145'),
            'the ordinary local developer must be unaffected'
        );
    }

    public function testARefusedPeerGetsNoToolbar(): void
    {
        $this->assertSame(403, $this->devSurfaceStatus('172.17.0.1', '172.17.0.1:7145'));
        $this->assertFalse(
            $this->pageHasToolbar('172.17.0.1', '172.17.0.1:7145'),
            'the reported symptom: a toolbar on every page whose every button answers 403'
        );
    }

    /**
     * The opt-in that DOES admit a non-loopback caller (ADR-0082): TINA4_HOST
     * plus a dedicated TINA4_MCP_TOKEN carried in a header. Such a caller may
     * use the surface, so it keeps the toolbar.
     */
    public function testAnOptedInRemoteCallerKeepsBoth(): void
    {
        $this->env('TINA4_HOST', '172.17.0.1');
        $this->env('TINA4_MCP_TOKEN', 'dev-toolbar-token');
        $headers = ['authorization' => 'Bearer dev-toolbar-token'];

        $this->assertNotSame(403, $this->devSurfaceStatus('172.17.0.1', '172.17.0.1:7145', $headers));
        $this->assertTrue($this->pageHasToolbar('172.17.0.1', '172.17.0.1:7145', $headers));
    }

    /**
     * TINA4_HOST alone opens the Host gate but not the peer gate, so the surface
     * still refuses — and so the page must still carry no toolbar.
     */
    public function testTheHostAllowListAloneIsNotEnough(): void
    {
        $this->env('TINA4_HOST', '172.17.0.1');

        $this->assertSame(403, $this->devSurfaceStatus('172.17.0.1', '172.17.0.1:7145'));
        $this->assertFalse($this->pageHasToolbar('172.17.0.1', '172.17.0.1:7145'));
    }

    /**
     * ErrorOverlay::renderErrorOverlay() is public, and its docblock shows it
     * called with $_SERVER, which carries no word from the router. It then
     * decides from those same server fields; a caller that names no peer gets no
     * toolbar.
     */
    public function testADirectOverlayCallDecidesFromTheServerFields(): void
    {
        $exception = new \RuntimeException('boom');
        $hasToolbar = fn (?array $server): bool => str_contains(
            \Tina4\ErrorOverlay::renderErrorOverlay($exception, $server),
            'id="tina4-dev-toolbar"'
        );

        $this->assertTrue($hasToolbar(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost:8797']), 'loopback lost the toolbar');
        $this->assertFalse($hasToolbar(['REMOTE_ADDR' => '172.17.0.1', 'HTTP_HOST' => 'localhost:8797']), 'a refused peer got the toolbar');
        $this->assertFalse($hasToolbar(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'evil.example']), 'a refused Host got the toolbar');
        $this->assertFalse($hasToolbar(null), 'a caller that names no peer got the toolbar');

        // The opt-in travels in the same server fields.
        $this->env('TINA4_HOST', '172.17.0.1');
        $this->env('TINA4_MCP_TOKEN', 'dev-toolbar-token');
        $optedIn = ['REMOTE_ADDR' => '172.17.0.1', 'HTTP_HOST' => '172.17.0.1:7145'];
        $this->assertTrue($hasToolbar($optedIn + ['HTTP_AUTHORIZATION' => 'Bearer dev-toolbar-token']), 'Bearer opt-in lost the toolbar');
        $this->assertTrue($hasToolbar($optedIn + ['HTTP_X_MCP_TOKEN' => 'dev-toolbar-token']), 'X-MCP-Token opt-in lost the toolbar');
        $this->assertFalse($hasToolbar($optedIn), 'TINA4_HOST alone opened the toolbar');
    }

    /** The predicate itself, so a caller of it can rely on the same answer. */
    public function testReachabilityPredicateMatchesTheGate(): void
    {
        foreach (self::peerProvider() as $label => [$peer, $host]) {
            $request = Request::create(method: 'GET', path: '/page', headers: ['host' => $host], remoteIp: $peer);
            $this->assertSame(
                $this->devSurfaceStatus($peer, $host) !== 403,
                DevAdmin::devSurfaceReachable($request),
                "{$label}: the predicate and the endpoint gate disagree"
            );
        }
    }
}
