<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

/**
 * Feature 79 (route groups) - a group must not weaken the auth gate.
 *
 * ADR-0019. Python shipped a hole here: attaching middleware to a group set
 * auth_required = False, so an ordinary logging middleware made every write
 * route inside the group PUBLIC. PHP has always been correct, because its auth
 * decision keys off the method and the explicit ->secure()/->noAuth() flags
 * independent of middleware.
 *
 * These tests exist so PHP CANNOT acquire the bug. That is the point: the hole
 * survived in Python precisely because nothing asserted the opposite. Case
 * names match tina4-python/tests/test_route_groups.py.
 *
 * Real dispatch through Router::dispatch. No doubles.
 */
class RouteGroupAuthTest extends TestCase
{
    /** @var int How many times the group middleware ran during a request. */
    public static int $middlewareRuns = 0;

    protected function setUp(): void
    {
        Router::clear();
        self::$middlewareRuns = 0;
    }

    protected function tearDown(): void
    {
        Router::clear();
    }

    /** An ordinary group middleware. It does NOT do auth. */
    public static function countingMiddleware(): callable
    {
        return static function () {
            self::$middlewareRuns++;
            return true;
        };
    }

    private function dispatch(string $method, string $path): int
    {
        $request = Request::create(method: $method, path: $path);
        $response = new Response(testing: true);
        Router::dispatch($request, $response);
        return $response->getStatusCode();
    }

    public function testUngroupedWriteRouteIsSecuredByDefault(): void
    {
        // The control. If this ever goes public the test below proves nothing.
        Router::post('/rg/plain', fn($req, $res) => $res->json(['ok' => true]));
        $this->assertSame(401, $this->dispatch('POST', '/rg/plain'));
    }

    public function testGroupMiddlewareDoesNotOpenASecuredWriteRoute(): void
    {
        Router::group('/rg/secured', static function (): void {
            Router::post('/thing', fn($req, $res) => $res->json(['ok' => true]));
        }, [self::countingMiddleware()]);

        $this->assertSame(
            401,
            $this->dispatch('POST', '/rg/secured/thing'),
            'group middleware silently opened a secured write route'
        );
    }

    public function testGroupMiddlewareDoesNotOpenANestedWriteRoute(): void
    {
        Router::group('/rg/outer', static function (): void {
            Router::group('/inner', static function (): void {
                Router::post('/thing', fn($req, $res) => $res->json(['ok' => true]));
            });
        }, [self::countingMiddleware()]);

        $this->assertSame(401, $this->dispatch('POST', '/rg/outer/inner/thing'));
    }

    public function testGroupRouteCanStillBeOpenedExplicitly(): void
    {
        // The positive twin: ->noAuth() is the explicit spelling and must work,
        // or there would be no way to declare a public write route in a group.
        Router::group('/rg/public', static function (): void {
            Router::post('/thing', fn($req, $res) => $res->json(['ok' => true]))->noAuth();
        }, [self::countingMiddleware()]);

        $this->assertSame(200, $this->dispatch('POST', '/rg/public/thing'));
    }

    public function testGroupMiddlewareRunsOncePerRequest(): void
    {
        // Python merged group middleware twice, so it ran twice and a counter
        // or a rate-limit bucket double-counted every request.
        Router::group('/rg/once', static function (): void {
            Router::get('/thing', fn($req, $res) => $res->json(['ok' => true]));
        }, [self::countingMiddleware()]);

        self::$middlewareRuns = 0;
        $this->assertSame(200, $this->dispatch('GET', '/rg/once/thing'));
        $this->assertSame(1, self::$middlewareRuns, 'group middleware must run exactly once');
    }

    public function testNestedGroupPrefixComposes(): void
    {
        Router::group('/rg/api', static function (): void {
            Router::group('/admin', static function (): void {
                Router::get('/stats', fn($req, $res) => $res->json(['ok' => true]));
            });
        });

        $paths = array_column(Router::getRoutes(), 'path');
        $this->assertContains('/rg/api/admin/stats', $paths);
        $this->assertNotContains('/rg/api/stats', $paths);
    }
}
