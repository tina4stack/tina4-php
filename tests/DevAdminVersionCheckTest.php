<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * The dev-admin version check must not report "up to date" for a check it never
 * made.
 *
 * It used to answer HTTP 200 with latest == current whenever the call to
 * Packagist failed, and the toolbar renders that as a green
 * "Latest: vX — You are up to date!". A developer several releases behind, on a
 * machine with no route out, was told the opposite of the truth — and the
 * toolbar's own "Could not check for updates" branch could never fire, because
 * the failure arrived as a success.
 *
 * NO MOCKS. REAL dispatch through the front controller, as everywhere else in
 * this suite, and the registry is exercised FOR REAL:
 *
 *   - "unreachable" is a REAL closed port — a socket bound to 127.0.0.1:0 and
 *     then closed, so nothing is listening when the route dials it.
 *   - "reachable" is a REAL local HTTP server (`php -S` via TestServer) serving
 *     a canned Packagist p2 body; the request path selects which body.
 *
 * The registry URL is chosen with TINA4_VERSION_CHECK_URL — the same seam an
 * operator points at a mirror with — so no stream wrapper is replaced and no
 * double stands in for the network.
 */

require_once __DIR__ . '/../Tina4/DevAdmin.php';

use PHPUnit\Framework\TestCase;
use Tina4\App;
use Tina4\DevAdmin;
use Tina4\ErrorTracker;
use Tina4\McpServer;
use Tina4\Middleware;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

class DevAdminVersionCheckTest extends TestCase
{
    /** A REAL local registry server, reused across the reachable cases. */
    private static ?TestServer $registry = null;

    /** @var array<string,string|false> */
    private array $savedEnv = [];
    private const KEYS = ['TINA4_DEBUG', 'TINA4_VERSION_CHECK_URL'];

    public static function setUpBeforeClass(): void
    {
        self::$registry = TestServer::start(__DIR__ . '/fixtures/version_check_registry.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$registry?->stop();
        self::$registry = null;
    }

    protected function setUp(): void
    {
        Router::clear();
        Middleware::reset();
        McpServer::resetDefaultServer();
        foreach (self::KEYS as $k) {
            $this->savedEnv[$k] = getenv($k);
            putenv($k);
            unset($_ENV[$k]);
        }
        $this->env('TINA4_DEBUG', 'true');
        DevAdmin::register();
    }

    protected function tearDown(): void
    {
        Router::clear();
        Middleware::reset();
        McpServer::resetDefaultServer();
        // DevAdmin::register() installs error/exception handlers; the rest of
        // the suite tears them down the same way.
        ErrorTracker::reset();
        foreach (self::KEYS as $k) {
            unset($_ENV[$k]);
            $saved = $this->savedEnv[$k] ?? false;
            $saved === false ? putenv($k) : putenv("{$k}={$saved}");
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Set an env var the framework reads via BOTH getenv() and $_ENV. */
    private function env(string $key, string $value): void
    {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
    }

    /**
     * Run the REAL version-check route with the registry pointed at $url.
     *
     * @return array<string,mixed>
     */
    private function check(string $url): array
    {
        $this->env('TINA4_VERSION_CHECK_URL', $url);
        $request = Request::create(method: 'GET', path: '/__dev/api/version-check', remoteIp: '127.0.0.1');
        $response = Router::dispatch($request, new Response(true));
        return json_decode($response->getBody(), true);
    }

    /** A real address with nothing listening — bind then close to free the port. */
    private function closedPortUrl(): string
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($sock, "could not bind a probe socket: {$errstr}");
        $name = stream_socket_get_name($sock, false);   // "127.0.0.1:PORT"
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($sock);
        return "http://127.0.0.1:{$port}/";
    }

    // ── the check that did not happen ─────────────────────────────────────────

    public function testAnUnreachableRegistryIsNotReportedAsUpToDate(): void
    {
        $payload = $this->check($this->closedPortUrl());

        $this->assertNull($payload['latest'], 'a check that did not happen must not answer with a version');
        $this->assertNotSame($payload['current'], $payload['latest'], 'the toolbar reads latest == current as "you are up to date"');
        $this->assertNotEmpty($payload['error'] ?? '', 'the reason has to reach the client');
        $this->assertSame(App::$VERSION, $payload['current']);
    }

    public function testAnAnswerWithNoStableVersionIsNotReportedAsUpToDate(): void
    {
        // Reaching Packagist is not the same as learning the version.
        $payload = $this->check(self::$registry->base() . '/no-stable-version');

        $this->assertNull($payload['latest']);
        $this->assertNotEmpty($payload['error'] ?? '');
    }

    public function testAReachableRegistryReportsTheHighestStableVersion(): void
    {
        $payload = $this->check(self::$registry->base() . '/with-version');

        $this->assertSame('3.13.131', $payload['latest']);
        $this->assertArrayNotHasKey('error', $payload);
    }

    public function testTheToolbarActsOnAMissingLatestBeforeComparingVersions(): void
    {
        $request = Request::create(method: 'GET', path: '/__dev/toolbar.js', remoteIp: '127.0.0.1');
        $js = Router::dispatch($request, new Response(true))->getBody();

        $this->assertStringContainsString('couldNotCheck', $js, 'no branch for a check that did not happen');
        $this->assertStringContainsString('if (!latest) { couldNotCheck', $js);
        $this->assertLessThan(
            strpos($js, 'if (latest === current)'),
            strpos($js, 'if (!latest)'),
            'the up-to-date branch must not run first — a null would fall into it'
        );
    }
}
