<?php

use PHPUnit\Framework\TestCase;
use Tina4\Crud;
use Tina4\ORM;
use Tina4\Route;

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
    }

    private function createTestORM(): ORM
    {
        return new class extends ORM {
            public $tableName = "test_table";
            public $id;
            public $firstName;
        };
    }

    public function testCrudRouteGeneratesSixRoutes(): void
    {
        global $arrRoutes;

        Crud::route("/api/crud-test", $this->createTestORM(), function ($action, $obj, $filter, $request) {
            return null;
        });

        // GET /form, POST /, GET /, GET /{id}, POST /{id}, DELETE /{id}
        $this->assertCount(6, $arrRoutes);
    }

    public function testCrudRouteGeneratesCorrectPaths(): void
    {
        global $arrRoutes;

        Crud::route("/api/crud-test", $this->createTestORM(), function ($action, $obj, $filter, $request) {
            return null;
        });

        $paths = array_column($arrRoutes, "routePath");
        $this->assertContains("/api/crud-test/form", $paths, "Missing GET /form route");
        $this->assertContains("/api/crud-test", $paths, "Missing base route");
        $this->assertContains("/api/crud-test/{id}", $paths, "Missing /{id} route");
    }

    public function testCrudRouteGeneratesCorrectMethods(): void
    {
        global $arrRoutes;

        Crud::route("/api/crud-test", $this->createTestORM(), function ($action, $obj, $filter, $request) {
            return null;
        });

        $methods = array_column($arrRoutes, "method");
        $this->assertContains(TINA4_GET, $methods);
        $this->assertContains(TINA4_POST, $methods);
        $this->assertContains(TINA4_DELETE, $methods);
    }

    public function testCrudRouteSecureFlagPropagates(): void
    {
        global $arrRoutes;

        Crud::route("/api/secure-test", $this->createTestORM(), function ($action, $obj, $filter, $request) {
            return null;
        }, secure: true);

        foreach ($arrRoutes as $route) {
            $this->assertTrue(
                $route["secure"],
                "Route {$route['routePath']} ({$route['method']}) should have secure=true"
            );
        }
    }

    public function testCrudRouteDefaultsToNotSecure(): void
    {
        global $arrRoutes;

        Crud::route("/api/open-test", $this->createTestORM(), function ($action, $obj, $filter, $request) {
            return null;
        });

        foreach ($arrRoutes as $route) {
            $this->assertFalse(
                $route["secure"],
                "Route {$route['routePath']} should have secure=false by default"
            );
        }
    }

    public function testCrudRouteMiddlewarePropagates(): void
    {
        global $arrRoutes;

        Crud::route("/api/mw-test", $this->createTestORM(), function ($action, $obj, $filter, $request) {
            return null;
        }, middleware: ["authenticate", "checkRole"]);

        foreach ($arrRoutes as $route) {
            $this->assertEquals(
                ["authenticate", "checkRole"],
                $route["middleware"],
                "Route {$route['routePath']} should have middleware propagated"
            );
        }
    }

    public function testCrudRouteCachedFlagPropagates(): void
    {
        global $arrRoutes;

        Crud::route("/api/cached-test", $this->createTestORM(), function ($action, $obj, $filter, $request) {
            return null;
        }, cached: true);

        foreach ($arrRoutes as $route) {
            $this->assertTrue(
                $route["cached"],
                "Route {$route['routePath']} should have cached=true"
            );
        }
    }
}
