<?php

// MessageLog and RequestInspector are defined in DevAdmin.php alongside
// DevAdmin but PSR-4 cannot autoload them individually, so we force-include
// the file — same as DevAdminTest.
require_once __DIR__ . '/../Tina4/DevAdmin.php';

use PHPUnit\Framework\TestCase;
use Tina4\DevAdmin;
use Tina4\ErrorTracker;
use Tina4\MessageLog;
use Tina4\Request;
use Tina4\RequestInspector;
use Tina4\Response;
use Tina4\Router;

/**
 * Regression: GET /__dev/js/tina4-dev-admin.min.js must SERVE the bundle.
 *
 * DevAdmin built the path as `__DIR__ . '/../src/public/js/…'`.
 * Response::file() refuses any path carrying a '..' segment — the check that
 * closes the `file('downloads/' . $name)` traversal, so it fires before
 * realpath() — and the framework 403'd its own asset. Measured on 3.13.103
 * and 3.13.104: status 403, body 'Forbidden'. Every /__dev dashboard loaded
 * the shell and then rendered blank.
 *
 * DevAdminBundleDedupTest already asserts the bundle exists ON DISK, and it
 * passed throughout — which is precisely how this shipped. Nothing asserted
 * the ROUTE could hand it to a browser. That gap is what this closes.
 *
 * Drives the real registered route handler against the real shipped bundle.
 * No mocks, no fixtures.
 */
class DevAdminBundleServeTest extends TestCase
{
    private const BUNDLE_ROUTE = '/__dev/js/tina4-dev-admin.min.js';

    protected function setUp(): void
    {
        Router::clear();
        MessageLog::reset();
        RequestInspector::reset();
    }

    protected function tearDown(): void
    {
        ErrorTracker::reset();
        Router::clear();
        MessageLog::reset();
        RequestInspector::reset();
    }

    /**
     * Register DevAdmin and invoke the bundle route exactly as the router
     * would, returning the response it produced.
     */
    private function serveBundle(): Response
    {
        DevAdmin::register();

        $callback = null;

        foreach (Router::getRoutes() as $route) {
            if (
                $route['method'] === 'GET'
                && $route['pattern'] === self::BUNDLE_ROUTE
            ) {
                $callback = $route['callback'];
                break;
            }
        }

        $this->assertNotNull(
            $callback,
            'Bundle route should be registered'
        );

        $request = Request::create('GET', self::BUNDLE_ROUTE);

        return $callback($request, new Response(true));
    }

    /**
     * The discriminator. A vulnerable build answers 403 here. Asserting
     * merely "not 404" would pass against the bug, so pin 200 exactly.
     */
    public function testBundleRouteServesTheBundle(): void
    {
        $response = $this->serveBundle();

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Substance, not just status: a stub, an empty body or an error page
     * would all still be 200. Mirrors DevAdminBundleDedupTest's on-disk
     * assertions, but on the bytes that actually reach the browser.
     */
    public function testBundleRouteServesTheRealBundleBytes(): void
    {
        $response = $this->serveBundle();
        $body = $response->getBody();

        $this->assertGreaterThan(100_000, strlen($body));
        $this->assertStringContainsString('db-table-list', $body);
    }

    /**
     * NEGATIVE CONTROL — the 403 was a path-guard refusal, never a missing
     * file. If the bundle really were absent the route owes a 404, and a
     * "fix" that reintroduced Forbidden must not slip through as one.
     */
    public function testBundleRouteNeverAnswersForbidden(): void
    {
        $response = $this->serveBundle();

        $this->assertNotSame(403, $response->getStatusCode());
        $this->assertNotSame('Forbidden', $response->getBody());
    }
}
