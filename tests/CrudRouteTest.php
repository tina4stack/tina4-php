<?php

use PHPUnit\Framework\TestCase;
use Tina4\Crud;
use Tina4\DataSQLite3;
use Tina4\ORM;
use Tina4\Request;
use Tina4\Response;

/**
 * Covers the routes Crud::route() registers.
 *
 * Two things are fixed in place here:
 *
 *   - update is reachable by PUT as well as POST, so a client that follows the
 *     usual REST verbs is not forced onto POST /{id},
 *   - the "read" handler filters against $filterObject when one is given. Read
 *     handlers commonly query a view rather than the table $object maps to, and
 *     getDataTablesFilter() drops any column the ORM it was handed does not
 *     know, so filtering on a view-only column silently returned everything.
 */
class CrudRouteTest extends TestCase
{
    protected function setUp(): void
    {
        global $arrRoutes;
        $arrRoutes = [];
    }

    protected function tearDown(): void
    {
        global $arrRoutes;
        $arrRoutes = [];
        $_REQUEST = [];
    }

    private function registerRoute(?ORM $filterObject, &$captured = null): void
    {
        $object = new CrudRouteTestModel();
        $object->DBA = new DataSQLite3(":memory:");

        Crud::route(
            "/crud-route-test",
            $object,
            function ($action, $object, $filter, $request) use (&$captured) {
                if ($action === "read") {
                    $captured = $filter;
                }

                return [];
            },
            false,
            false,
            [],
            $filterObject
        );
    }

    private function callRoute(string $method, string $path): void
    {
        global $arrRoutes;

        foreach ($arrRoutes as $route) {
            if ($route["method"] === $method && $route["routePath"] === $path) {
                $request = new Request("");
                $request->params = [];
                call_user_func($route["function"], new Response(), $request);

                return;
            }
        }

        $this->fail("No {$method} route registered for {$path}");
    }

    public function testUpdateIsReachableByPutAsWellAsPost(): void
    {
        global $arrRoutes;

        $this->registerRoute(null);

        $registered = [];
        foreach ($arrRoutes as $route) {
            $registered[] = $route["method"] . " " . $route["routePath"];
        }

        $this->assertContains("PUT /crud-route-test/{id}", $registered);
        $this->assertContains("POST /crud-route-test/{id}", $registered);
    }

    public function testTheReadFilterUsesTheFilterObjectWhenOneIsGiven(): void
    {
        $filterObject = new CrudRouteTestView();
        $filterObject->DBA = new DataSQLite3(":memory:");

        $this->registerRoute($filterObject, $captured);

        $_REQUEST = [
            "columns" => [["data" => "siteName", "searchable" => "true"]],
            "search" => ["value" => "acme"],
        ];

        $this->callRoute("GET", "/crud-route-test");

        // site_Name belongs to the view, not to the table $object maps to.
        $this->assertStringContainsString("site_Name", $captured["where"]);
        $this->assertStringContainsString("like '%ACME%'", $captured["where"]);
    }

    public function testTheReadFilterFallsBackToTheObjectWhenNoFilterObjectIsGiven(): void
    {
        $this->registerRoute(null, $captured);

        $_REQUEST = [
            "columns" => [["data" => "siteName", "searchable" => "true"]],
            "search" => ["value" => "acme"],
        ];

        $this->callRoute("GET", "/crud-route-test");

        // The base model has no siteName, so the column is ignored as before.
        $this->assertSame("", $captured["where"]);
    }
}

class CrudRouteTestModel extends ORM
{
    public $id;
    public $firstName;
}

class CrudRouteTestView extends ORM
{
    public $id;
    public $firstName;
    public $siteName;
}
