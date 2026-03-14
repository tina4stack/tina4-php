<?php
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Auth;
use Tina4\Config;
use Tina4\Router;
use Tina4\Response;
use Tina4\Request;
use Tina4\RouterResponse;

class RouterSecurityTest extends TestCase
{
    private Router $router;
    private Config $config;
    private Auth $auth;

    protected function setUp(): void
    {
        global $arrRoutes;
        $arrRoutes = [];

        $this->router = new Router();
        $this->config = new Config();
        $this->auth = new Auth();
        $this->config->setAuthentication($this->auth);
    }

    protected function tearDown(): void
    {
        global $arrRoutes;
        $arrRoutes = [];
    }

    /**
     * Tests that the secure flag is stored correctly when using ->secure(true)
     */
    public function testSecureFlagIsStoredOnRoute(): void
    {
        global $arrRoutes;

        \Tina4\Get::add("/api/flag-test", function (Response $response) {
            return $response("OK", 200);
        })->secure(true);

        $route = end($arrRoutes);
        $this->assertTrue($route["secure"], "Route should have secure flag set to true");
    }

    /**
     * Tests that secure flag defaults to false
     */
    public function testSecureFlagDefaultsFalse(): void
    {
        global $arrRoutes;

        \Tina4\Get::add("/api/open-test", function (Response $response) {
            return $response("OK", 200);
        });

        $route = end($arrRoutes);
        $this->assertFalse($route["secure"], "Route should default to secure=false");
    }

    /**
     * Tests that a GET route WITHOUT @secure annotation and without ->secure()
     * is accessible without auth
     */
    public function testUnsecuredGetRouteIsAccessible(): void
    {
        \Tina4\Get::add("/api/public", function (Response $response) {
            return $response("public data", HTTP_OK, TEXT_HTML);
        });

        $result = $this->router->resolveRoute(TINA4_GET, "/api/public", $this->config);

        $this->assertNotNull($result);
        $this->assertEquals(HTTP_OK, $result->httpCode);
        $this->assertEquals("public data", $result->content);
    }

    /**
     * Tests that a GET route with @secure annotation returns 403 without auth
     */
    public function testSecureAnnotatedGetRouteReturns403WithoutAuth(): void
    {
        /**
         * @secure admin
         */
        \Tina4\Get::add("/api/secret-annotated", function (Response $response) {
            return $response("secret data", HTTP_OK, TEXT_HTML);
        });

        $result = $this->router->resolveRoute(TINA4_GET, "/api/secret-annotated", $this->config);

        $this->assertNotNull($result);
        $this->assertEquals(HTTP_FORBIDDEN, $result->httpCode);
    }

    /**
     * Tests that a GET route with @secure annotation is accessible WITH a valid Bearer token
     */
    public function testSecureAnnotatedGetRouteAccessibleWithBearerToken(): void
    {
        /**
         * @secure admin
         */
        \Tina4\Get::add("/api/secret-bearer", function (Response $response) {
            return $response("secret data", HTTP_OK, TEXT_HTML);
        });

        $token = $this->auth->getToken(["payload" => "/api/secret-bearer"]);
        $headers = ["Authorization" => "Bearer " . $token];

        $result = $this->router->resolveRoute(TINA4_GET, "/api/secret-bearer", $this->config, $headers);

        $this->assertNotNull($result);
        $this->assertEquals(HTTP_OK, $result->httpCode);
        $this->assertEquals("secret data", $result->content);
    }

    /**
     * DOCUMENTS THE BUG: Route with ->secure(true) but NO @secure annotation
     * should return 403 but currently does NOT because Router only checks annotations.
     * After Phase 1 fix, this test should pass.
     */
    public function testSecureFlagRouteReturns403WithoutAuth(): void
    {
        \Tina4\Get::add("/api/flag-secured", function (Response $response) {
            return $response("should be protected", HTTP_OK, TEXT_HTML);
        })->secure(true);

        $result = $this->router->resolveRoute(TINA4_GET, "/api/flag-secured", $this->config);

        $this->assertNotNull($result);
        // After Phase 1 fix, this should be HTTP_FORBIDDEN
        $this->assertEquals(HTTP_FORBIDDEN, $result->httpCode,
            "Route with ->secure(true) should return 403 without auth token");
    }

    /**
     * Tests that a route with ->secure(true) is accessible with Bearer token
     * After Phase 1 fix, this should work.
     */
    public function testSecureFlagRouteAccessibleWithBearerToken(): void
    {
        \Tina4\Get::add("/api/flag-secured-auth", function (Response $response) {
            return $response("protected data", HTTP_OK, TEXT_HTML);
        })->secure(true);

        $token = $this->auth->getToken(["payload" => "/api/flag-secured-auth"]);
        $headers = ["Authorization" => "Bearer " . $token];

        $result = $this->router->resolveRoute(TINA4_GET, "/api/flag-secured-auth", $this->config, $headers);

        $this->assertNotNull($result);
        $this->assertEquals(HTTP_OK, $result->httpCode);
        $this->assertEquals("protected data", $result->content);
    }

    /**
     * Tests CRUD route with secure=true returns 403 without auth
     * After Phase 1 fix, this should pass.
     */
    public function testSecureCrudGetRouteReturns403WithoutAuth(): void
    {
        $obj = new class extends \Tina4\ORM {
            public $tableName = "test_table";
            public $id;
        };

        \Tina4\Crud::route("/api/secure-crud", $obj, function ($action, $obj, $filter, $request) {
            if ($action === "read") {
                return ["data" => [], "recordsFiltered" => 0, "recordsTotal" => 0];
            }
            return null;
        }, secure: true);

        $result = $this->router->resolveRoute(TINA4_GET, "/api/secure-crud", $this->config);

        $this->assertNotNull($result);
        $this->assertEquals(HTTP_FORBIDDEN, $result->httpCode,
            "Secured CRUD GET route should return 403 without auth");
    }

    /**
     * Tests CRUD route with secure=true is accessible with Bearer token
     */
    public function testSecureCrudGetRouteAccessibleWithBearerToken(): void
    {
        $obj = new class extends \Tina4\ORM {
            public $tableName = "test_table";
            public $id;
        };

        \Tina4\Crud::route("/api/secure-crud-auth", $obj, function ($action, $obj, $filter, $request) {
            if ($action === "read") {
                return ["data" => [], "recordsFiltered" => 0, "recordsTotal" => 0];
            }
            return null;
        }, secure: true);

        $token = $this->auth->getToken(["payload" => "/api/secure-crud-auth"]);
        $headers = ["Authorization" => "Bearer " . $token];

        $result = $this->router->resolveRoute(TINA4_GET, "/api/secure-crud-auth", $this->config, $headers);

        $this->assertNotNull($result);
        $this->assertEquals(HTTP_OK, $result->httpCode);
    }

    /**
     * Tests that an unsecured CRUD route remains accessible without auth
     * This is the backward compatibility check.
     */
    public function testUnsecuredCrudRouteRemainsAccessible(): void
    {
        $obj = new class extends \Tina4\ORM {
            public $tableName = "test_table";
            public $id;
        };

        \Tina4\Crud::route("/api/open-crud", $obj, function ($action, $obj, $filter, $request) {
            if ($action === "read") {
                return ["data" => [], "recordsFiltered" => 0, "recordsTotal" => 0];
            }
            return null;
        });

        $result = $this->router->resolveRoute(TINA4_GET, "/api/open-crud", $this->config);

        $this->assertNotNull($result);
        $this->assertEquals(HTTP_OK, $result->httpCode,
            "Unsecured CRUD route should remain accessible without auth");
    }

    /**
     * Tests that an invalid Bearer token returns 403 on a secure route
     */
    public function testInvalidBearerTokenReturns403(): void
    {
        /**
         * @secure admin
         */
        \Tina4\Get::add("/api/bad-token", function (Response $response) {
            return $response("secret", HTTP_OK, TEXT_HTML);
        });

        $headers = ["Authorization" => "Bearer invalid.token.garbage"];

        $result = $this->router->resolveRoute(TINA4_GET, "/api/bad-token", $this->config, $headers);

        $this->assertNotNull($result);
        $this->assertEquals(HTTP_FORBIDDEN, $result->httpCode);
    }

    /**
     * Tests that a formToken for one route cannot be reused on a different route
     * (Phase 2: payload-to-route binding)
     */
    public function testFormTokenCannotBeReusedAcrossRoutes(): void
    {
        /**
         * @secure admin
         */
        \Tina4\Get::add("/api/route-a", function (Response $response) {
            return $response("route A", HTTP_OK, TEXT_HTML);
        });

        // Generate token for a different route
        $token = $this->auth->getToken(["payload" => "/api/route-b"]);
        $_REQUEST["formToken"] = $token;

        $result = $this->router->resolveRoute(TINA4_GET, "/api/route-a", $this->config);

        $this->assertNotNull($result);
        $this->assertEquals(HTTP_FORBIDDEN, $result->httpCode,
            "formToken for /api/route-b should NOT grant access to /api/route-a");

        unset($_REQUEST["formToken"]);
    }

    /**
     * Tests that a formToken WITH matching payload grants access
     */
    public function testFormTokenWithMatchingPayloadGrantsAccess(): void
    {
        /**
         * @secure admin
         */
        \Tina4\Get::add("/api/form-route", function (Response $response) {
            return $response("form data", HTTP_OK, TEXT_HTML);
        });

        $token = $this->auth->getToken(["payload" => "/api/form-route"]);
        $_REQUEST["formToken"] = $token;

        $result = $this->router->resolveRoute(TINA4_GET, "/api/form-route", $this->config);

        $this->assertNotNull($result);
        $this->assertEquals(HTTP_OK, $result->httpCode,
            "formToken with matching payload should grant access");

        unset($_REQUEST["formToken"]);
    }

    /**
     * Tests that a formToken with empty payload still works (backward compatibility)
     */
    public function testFormTokenWithEmptyPayloadStillWorks(): void
    {
        /**
         * @secure admin
         */
        \Tina4\Get::add("/api/empty-payload", function (Response $response) {
            return $response("data", HTTP_OK, TEXT_HTML);
        });

        // Token without explicit payload - backward compat
        $token = $this->auth->getToken([]);
        $_REQUEST["formToken"] = $token;

        $result = $this->router->resolveRoute(TINA4_GET, "/api/empty-payload", $this->config);

        $this->assertNotNull($result);
        $this->assertEquals(HTTP_OK, $result->httpCode,
            "formToken with empty payload should still grant access for backward compatibility");

        unset($_REQUEST["formToken"]);
    }

    /**
     * Tests POST to a secured CRUD route without any auth returns 403
     * (Phase 3: POST/PUT/PATCH/DELETE tightening)
     */
    public function testSecuredCrudPostReturns403WithoutAuth(): void
    {
        $obj = new class extends \Tina4\ORM {
            public $tableName = "test_table";
            public $id;
        };

        \Tina4\Crud::route("/api/post-secured", $obj, function ($action, $obj, $filter, $request) {
            return (object)["httpCode" => 200, "message" => "created"];
        }, secure: true);

        $result = $this->router->resolveRoute(TINA4_POST, "/api/post-secured", $this->config);

        $this->assertNotNull($result);
        $this->assertEquals(HTTP_FORBIDDEN, $result->httpCode,
            "Secured CRUD POST should return 403 without auth");
    }

    /**
     * Tests POST to an unsecured CRUD route still works without auth
     */
    public function testUnsecuredCrudPostRemainsAccessible(): void
    {
        $obj = new class extends \Tina4\ORM {
            public $tableName = "test_table";
            public $id;
        };

        \Tina4\Crud::route("/api/post-open", $obj, function ($action, $obj, $filter, $request) {
            if ($action === "afterCreate") {
                return (object)["httpCode" => 200, "message" => "created"];
            }
            return null;
        });

        // POST without auth should work for unsecured routes
        $result = $this->router->resolveRoute(TINA4_POST, "/api/post-open", $this->config);

        // Unsecured POST routes should still be accessible
        $this->assertNotNull($result);
    }
}
