<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Parity lock-in for global middleware dispatch (cross-framework — mirrors
 * tina4-python tests/test_middleware_parity.py TestGlobalMiddlewareDispatched,
 * tina4-nodejs test/global-middleware.test.ts, tina4-ruby
 * spec/middleware_parity_spec.rb). Guards the contract behind tina4-python#55:
 * a middleware registered globally via Router::use / Middleware::use lands in
 * the ONE global registry the dispatcher consults, and its before / after
 * hooks actually run.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Middleware;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

/** Test-only global middleware that records when its hooks fire. */
class GlobalDispatchMarker
{
    public static bool $beforeRan = false;
    public static bool $afterRan = false;

    public static function beforeMark(Request $request, Response $response): array
    {
        self::$beforeRan = true;
        return [$request, $response];
    }

    public static function afterMark(Request $request, Response $response): array
    {
        self::$afterRan = true;
        return [$request, $response];
    }
}

class MiddlewareGlobalDispatchTest extends TestCase
{
    protected function setUp(): void
    {
        Middleware::reset();
        GlobalDispatchMarker::$beforeRan = false;
        GlobalDispatchMarker::$afterRan = false;
    }

    protected function tearDown(): void
    {
        Middleware::reset();
    }

    public function testRouterUseRegistersIntoTheGlobalRegistry(): void
    {
        // Router::use must land in the SAME registry the dispatcher reads
        // (delegates to Middleware::use), not a private list nothing consults.
        Router::use(GlobalDispatchMarker::class);
        $this->assertContains(
            GlobalDispatchMarker::class,
            Middleware::getGlobal(),
            'Router::use did not register into the Middleware global registry'
        );
    }

    public function testGlobalMiddlewareBeforeAndAfterRun(): void
    {
        Router::use(GlobalDispatchMarker::class);

        $request = new Request(method: 'GET', path: '/');
        $response = new Response(testing: true);

        // The dispatcher folds Middleware::getGlobal() into the per-route run.
        [$request, $response] = Middleware::runBefore(Middleware::getGlobal(), $request, $response);
        [$request, $response] = Middleware::runAfter(Middleware::getGlobal(), $request, $response);

        $this->assertTrue(GlobalDispatchMarker::$beforeRan, 'global before* middleware did not run');
        $this->assertTrue(GlobalDispatchMarker::$afterRan, 'global after* middleware did not run');
    }

    public function testGlobalRegistryDedupes(): void
    {
        Router::use(GlobalDispatchMarker::class);
        Router::use(GlobalDispatchMarker::class);
        $count = count(array_filter(
            Middleware::getGlobal(),
            static fn ($c) => $c === GlobalDispatchMarker::class
        ));
        $this->assertSame(1, $count, 'global middleware registered twice was not deduped');
    }
}
