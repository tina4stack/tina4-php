<?php

use PHPUnit\Framework\TestCase;
use Tina4\Response;
use Tina4\Request;

class RoutingTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear any existing routes if possible; assuming Tina4 has a way to reset routes for testing
        // If not, tests might need to run in isolation or mock the router
    }

    public function testSimpleGetRoute()
    {
        // Define a simple GET route
        \Tina4\Get::add('/test', function (Response $response) {
            return $response('Test content', 200, 'text/html');
        });

        // Simulate a GET request
        $request = new Request("");
        $request->params = [];

        // Assuming Tina4 has a method to dispatch or match routes, e.g., a central dispatch function
        // Replace with actual dispatch logic; for example:
        // $responseData = \Tina4\Dispatch($request); // Hypothetical

        // For testing, manually find and invoke the route (adapt based on Tina4's internals)
        // Assuming routes are stored in a global or accessible router
        global $arrRoutes;
        $routes = $arrRoutes; // Hypothetical access to stored routes
        $router = new \Tina4\Router();
        foreach ($routes as $route) {
            if ($router->matchPath('/test', $route["routePath"], [])) {
                $result = call_user_func($route['function'], new Response(), $request);
                $this->assertEquals('Test content', $result['content']);
                $this->assertEquals(200, $result['httpCode']);
                $this->assertEquals('text/html', $result['contentType']);
                return;
            }
        }
        $this->fail('Route not found');
    }

    public function testRouteWithParams()
    {
        // Define a GET route with parameters
        \Tina4\Get::add('/test-user/{id}', function ($id, Response $response, Request $request) {
            return $response("User ID: " . $request->params['id'], 200, 'text/html');
        });

        // Simulate a GET request with param
        $request = new Request("");


        // Hypothetical param parsing; in real Tina4, params might be auto-populated
        // Assume a helper to parse params
        global $arrRoutes;
        $routes = $arrRoutes;
        $router = new \Tina4\Router();
        foreach ($routes as $route) {
            if ($router->matchPath('/test-user/123', $route["routePath"], [])) {
                $request->params['id'] = 123;
                $result = call_user_func($route['function'], $request->params['id'], new Response(), $request);
                $this->assertEquals('User ID: 123', $result['content']);
                $this->assertEquals(200, $result['httpCode']);
                return;
            }
        }
        $this->fail('Route not found');
    }

    public function testPostRoute()
    {
        // Define a POST route
        \Tina4\Post::add('/submit', function (Response $response, Request $request) {
            return $response("Posted data: " . json_encode($request->data), 201, 'application/json');
        });

        // Simulate a POST request
        $request = new Request("");
        $request->method = 'POST';
        $request->url = '/submit';
        $request->data = ['key' => 'value'];

        global $arrRoutes;
        $routes = $arrRoutes;
        foreach ($routes as $route) {
            if ($route['routePath'] === '/submit') {
                $result = call_user_func($route['function'], new Response(), $request);
                $this->assertEquals('Posted data: {"key":"value"}', $result['content']);
                $this->assertEquals(201, $result['httpCode']);
                $this->assertEquals('application/json', $result['contentType']);
                return;
            }
        }
        $this->fail('Route not found');
    }

    public function testRouteNotFound()
    {
        $request = new Request("");
        $request->method = 'GET';
        $request->url = '/nonexistent';

        // Hypothetical: Check if no route matches
        $matched = false;
        global $arrRoutes;
        $routes = $arrRoutes;
        foreach ($routes as $route) {
            if (preg_match($this->pathToRegex($route['routePath']), $request->url)) {
                $matched = true;
                break;
            }
        }
        $this->assertFalse($matched, 'No route should match');
    }

    // Helper to convert Tina4 path to regex (e.g., '/user/{id}' to '#/user/([^/]+)#')
    private function pathToRegex(string $path): string
    {
        $path = preg_replace('/\{[^}]+\}/', '([^/]+)', $path);
        return '#^' . $path . '$#';
    }

    // Add more tests as needed
}