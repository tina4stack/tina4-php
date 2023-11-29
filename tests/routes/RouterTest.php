<?php declare(strict_types=1);
use PHPUnit\Framework\TestCase;



final class RouterTest extends TestCase
{

    public function testRoutingAdd() : void
    {
        global $arrRoutes;

        \Tina4\Route::get("/test", static function(\Tina4\Response $response) {
            return $response("OK");
        });

        $this->assertCount(1, $arrRoutes, "Check number of routes");
        foreach ($arrRoutes as $id => $route) {
            $this->assertContains("/test", $route, "Testing for route path in arrRoutes");
            $this->assertContains(TINA4_GET, $route, "Testing for TINA4_GET in route");
            $result = $route["function"](new \Tina4\Response());

            $this->assertEquals("Content-Type: ".TEXT_HTML, $result["contentType"], "Testing for correct content type");
            $this->assertEquals("OK", $result["content"], "Testing for OK");
            $this->assertEquals(200, $result["httpCode"], "Testing for 200 response");
        }

    }

    public function testGetRouting(): void
    {
        //test get route application/json

        //test get route text/html

        //test get route application/xml

        //test get route with @secure

        //test get route with @content-type

        //test get route with @cache

        //test get route with @no-cache

    }

    public function testPostRouting(): void
    {
        //test post route application/json

        //test post route text/html

        //test post route application/xml

        //test post route with @secure

        //test post route with @content-type

        //test post route with @cache

        //test post route with @no-cache

    }

}