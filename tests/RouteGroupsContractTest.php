<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Router;
use Tina4\TestClient;

/**
 * Feature 32 (route groups) - the prefix+path JOIN grammar (RG-DEC-01).
 *
 * The audit found the join grammar diverges and is UNGATED: PHP fully
 * normalizes (Tina4/Router.php addRoute - the REFERENCE: one separator, no
 * double slash, a single leading slash); Python bare-concatenated, Ruby's
 * chomp stripped only one trailing slash, and Node did no normalization at
 * all - so group("/api") + get("users") silently mis-registered at
 * "/apiusers" in three of four languages. PHP needs NO code change here
 * (it was already correct) - this suite locks that in and proves the SAME
 * shared contract Python/Ruby/Node now also satisfy.
 *
 * Real registration through the real Router, real dispatch through
 * TestClient -> Router::dispatch (the SAME front controller a live request
 * hits) - no mocks. Positive AND negative: a mis-registered path must 404.
 *
 * Same case names in all four:
 *   tina4-python/tests/test_route_groups_contract.py
 *   tina4-ruby/spec/route_groups_contract_spec.rb
 *   tina4-nodejs/test/routeGroupsContract.test.ts
 *
 * See tina4-documentation/plan/v3/fixtures/route_groups_contract.json.
 */
class RouteGroupsContractTest extends TestCase
{
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

    private static function ok(): callable
    {
        return static fn($req, $res) => $res->json(['ok' => true]);
    }

    /** An ordinary group middleware. It does NOT do auth. */
    private static function countingMiddleware(): callable
    {
        return static function () {
            self::$middlewareRuns++;
            return true;
        };
    }

    public function testGroupPrefixJoinsToASingleSlashPath(): void
    {
        // group("/api") + get("users") - the route's own path has NO
        // leading slash. Already correct in PHP (the reference).
        Router::group('/__rg32/c1', static function (): void {
            Router::get('users', RouteGroupsContractTest::ok());
        });

        $client = new TestClient();
        $this->assertSame(200, $client->get('/__rg32/c1/users')->status,
            'the normalized join must resolve at /__rg32/c1/users');
        // Negative: the bare-concat mis-registration must not exist.
        $this->assertSame(404, $client->get('/__rg32/c1users')->status,
            'the bare-concat mis-registration (/__rg32/c1users) must not resolve');
    }

    public function testLeadingSlashOnTheRouteIsNormalized(): void
    {
        // group("/api") + get("/users") - the route's own path already
        // carries its leading slash. Must land on the SAME canonical path,
        // never a double slash.
        Router::group('/__rg32/c2', static function (): void {
            Router::get('/users', RouteGroupsContractTest::ok());
        });

        $client = new TestClient();
        $this->assertSame(200, $client->get('/__rg32/c2/users')->status);

        $paths = array_column(Router::getRoutes(), 'path');
        $this->assertContains('/__rg32/c2/users', $paths);
        $this->assertNotContains('/__rg32/c2//users', $paths,
            'a route path must never carry a doubled separator');
    }

    public function testTrailingSlashOnThePrefixIsNormalized(): void
    {
        // group("/api/") + get("/users") - the GROUP prefix carries a
        // trailing slash. Must ALSO land on the same canonical path.
        Router::group('/__rg32/c3/', static function (): void {
            Router::get('/users', RouteGroupsContractTest::ok());
        });

        $client = new TestClient();
        $this->assertSame(200, $client->get('/__rg32/c3/users')->status);

        $paths = array_column(Router::getRoutes(), 'path');
        $this->assertContains('/__rg32/c3/users', $paths);
        $this->assertNotContains('/__rg32/c3//users', $paths,
            'a trailing slash on the group prefix must not leave a doubled separator');
    }

    public function testNestedGroupsConcatenateThePrefix(): void
    {
        // group("/api") > group("/v1") + get("users") -> "/api/v1/users".
        Router::group('/__rg32/c4', static function (): void {
            Router::group('/v1', static function (): void {
                Router::get('users', RouteGroupsContractTest::ok());
            });
        });

        $client = new TestClient();
        $this->assertSame(200, $client->get('/__rg32/c4/v1/users')->status,
            'nested groups must concatenate to /__rg32/c4/v1/users');
        // Negative: the missing-separator mis-registration must not exist.
        $this->assertSame(404, $client->get('/__rg32/c4/v1users')->status,
            'the nested bare-concat mis-registration (/__rg32/c4/v1users) must not resolve');
    }

    public function testGroupMiddlewareNeverOpensTheWriteAuthGate(): void
    {
        // LOCK: group middleware must never disable the secure-by-default
        // write-auth gate - including under the normalized prefix join.
        Router::group('/__rg32/c5', static function (): void {
            Router::post('/thing', RouteGroupsContractTest::ok());
        }, [self::countingMiddleware()]);

        $response = (new TestClient())->post('/__rg32/c5/thing', json: ['x' => 1]);
        $this->assertSame(401, $response->status,
            'group middleware silently opened a secured write route under the normalized join');
    }
}
