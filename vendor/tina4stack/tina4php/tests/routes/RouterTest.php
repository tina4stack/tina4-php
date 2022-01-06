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

}