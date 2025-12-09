<?php


use PHPUnit\Framework\TestCase;
use Tina4\Router;
use Tina4\Request;
use Tina4\Response;

class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router(); // Assuming Data constructor or whatever is needed; might need mocking if dependencies
    }

    /**
     * @throws ReflectionException
     */
    public function testCallHandlerNoParams(): void
    {
        $handler = function () {
            return 'no params';
        };

        $request = new Request('', []);
        $response = new Response();

        $result = $this->router->callHandler(null, $handler, [], $request, $response);

        $this->assertEquals('no params', $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testCallHandlerOnlyInlineParams(): void
    {
        $handler = function ($a, $b) {
            return $a . $b;
        };

        $request = new Request('', []);
        $response = new Response();

        $result = $this->router->callHandler(null, $handler, ['x', 'y'], $request, $response);

        $this->assertEquals('xy', $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testCallHandlerRequestByType(): void
    {
        $handler = function (\Tina4\Request $req) {
            return get_class($req);
        };

        $request = new Request('', []);
        $response = new Response();

        $result = $this->router->callHandler(null, $handler, [], $request, $response);

        $this->assertEquals('Tina4\Request', $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testCallHandlerRequestByName(): void
    {
        $handler = function ($request) {
            return get_class($request);
        };

        $request = new Request('', []);
        $response = new Response();

        $result = $this->router->callHandler(null, $handler, [], $request, $response);

        $this->assertEquals('Tina4\Request', $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testCallHandlerResponseByType(): void
    {
        $handler = function (\Tina4\Response $res) {
            return get_class($res);
        };

        $request = new Request('', []);
        $response = new Response();

        $result = $this->router->callHandler(null, $handler, [], $request, $response);

        $this->assertEquals('Tina4\Response', $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testCallHandlerResponseByName(): void
    {
        $handler = function ($response) {
            return get_class($response);
        };

        $request = new Request('', []);
        $response = new Response();

        $result = $this->router->callHandler(null, $handler, [], $request, $response);

        $this->assertEquals('Tina4\Response', $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testCallHandlerInlineBeforeRequest(): void
    {
        $handler = function ($a, \Tina4\Request $req) {
            return $a . ' ' . get_class($req);
        };

        $request = new Request('', []);
        $response = new Response();

        $result = $this->router->callHandler(null, $handler, ['test'], $request, $response);

        $this->assertEquals('test Tina4\Request', $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testCallHandlerInlineAfterRequest(): void
    {
        $handler = function (\Tina4\Request $req, $a) {
            return get_class($req) . ' ' . $a;
        };

        $request = new Request('', []);
        $response = new Response();

        $result = $this->router->callHandler(null, $handler, ['test'], $request, $response);

        $this->assertEquals('Tina4\Request test', $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testCallHandlerInlineBetweenRequestAndResponse(): void
    {
        $handler = function (\Tina4\Request $req, $a, \Tina4\Response $res) {
            return get_class($req) . ' ' . $a . ' ' . get_class($res);
        };

        $request = new Request('', []);
        $response = new Response();

        $result = $this->router->callHandler(null, $handler, ['test'], $request, $response);

        $this->assertEquals('Tina4\Request test Tina4\Response', $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testCallHandlerMultipleInlinesWithRequestResponseInVariousOrders(): void
    {
        // Permutation 1: request, response, a, b
        $handler1 = function (\Tina4\Request $req, \Tina4\Response $res, $a, $b) {
            return get_class($req) . ' ' . get_class($res) . ' ' . $a . ' ' . $b;
        };

        $request = new Request('', []);
        $response = new Response();

        $result1 = $this->router->callHandler(null, $handler1, ['x', 'y'], $request, $response);
        $this->assertEquals('Tina4\Request Tina4\Response x y', $result1);

        // Permutation 2: a, request, b, response
        $handler2 = function ($a, \Tina4\Request $req, $b, \Tina4\Response $res) {
            return $a . ' ' . get_class($req) . ' ' . $b . ' ' . get_class($res);
        };

        $result2 = $this->router->callHandler(null, $handler2, ['x', 'y'], $request, $response);
        $this->assertEquals('x Tina4\Request y Tina4\Response', $result2);

        // Permutation 3: response, a, request, b
        $handler3 = function (\Tina4\Response $res, $a, \Tina4\Request $req, $b) {
            return get_class($res) . ' ' . $a . ' ' . get_class($req) . ' ' . $b;
        };

        $result3 = $this->router->callHandler(null, $handler3, ['x', 'y'], $request, $response);
        $this->assertEquals('Tina4\Response x Tina4\Request y', $result3);

        // Permutation 4: b, response, a, request
        $handler4 = function ($b, \Tina4\Response $res, $a, \Tina4\Request $req) {
            return $b . ' ' . get_class($res) . ' ' . $a . ' ' . get_class($req);
        };

        $result4 = $this->router->callHandler(null, $handler4, ['x', 'y'], $request, $response);
        $this->assertEquals('x Tina4\Response y Tina4\Request', $result4); // Note: inlines assigned sequentially: first non-special is $b='x', then $a='y'

        // Add more permutations as needed...
    }

    /**
     * @throws ReflectionException
     */
    public function testCallHandlerByNamesMixed(): void
    {
        $handler = function ($response, $a, $request, $b) {
            return get_class($response) . ' ' . $a . ' ' . get_class($request) . ' ' . $b;
        };

        $request = new Request('', []);
        $response = new Response();

        $result = $this->router->callHandler(null, $handler, ['x', 'y'], $request, $response);

        $this->assertEquals('Tina4\Response x Tina4\Request y', $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testCallHandlerOptionalParams(): void
    {
        $handler = function ($a = 'default') {
            return $a;
        };

        $request = new Request('', []);
        $response = new Response();

        $result = $this->router->callHandler(null, $handler, [], $request, $response);

        $this->assertEquals('default', $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testCallHandlerMissingRequiredParam(): void
    {
        $handler = function ($a) {
            return $a;
        };

        $request = new Request('', []);
        $response = new Response();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Missing required argument for parameter 'a'");

        $this->router->callHandler(null, $handler, [], $request, $response);
    }

    /**
     * @throws ReflectionException
     */
    public function testCallHandlerInstanceMethod(): void
    {
        $className = TestClass::class;
        $method = 'handle';

        $request = new Request('', []);
        $response = new Response();

        $result = $this->router->callHandler($className, $method, ['test'], $request, $response);

        $this->assertEquals('Tina4\Request test Tina4\Response', $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testCallHandlerStaticMethod(): void
    {
        $className = TestClass::class;
        $method = 'staticHandle';

        $request = new Request('', []);
        $response = new Response();

        $result = $this->router->callHandler($className, $method, ['test'], $request, $response);

        $this->assertEquals('Tina4\Request test Tina4\Response', $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testCallHandlerRequestByCustomNameWithType(): void
    {
        $handler = function (\Tina4\Request $req) {
            return get_class($req);
        };

        $request = new Request('', []);
        $response = new Response();

        $result = $this->router->callHandler(null, $handler, [], $request, $response);

        $this->assertEquals('Tina4\Request', $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testCallHandlerResponseByCustomNameWithType(): void
    {
        $handler = function (\Tina4\Response $res) {
            return get_class($res);
        };

        $request = new Request('', []);
        $response = new Response();

        $result = $this->router->callHandler(null, $handler, [], $request, $response);

        $this->assertEquals('Tina4\Response', $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testCallHandlerMixedCustomNamesWithTypes(): void
    {
        $handler = function ($a, \Tina4\Response $res, \Tina4\Request $req, $b) {
            return $a . ' ' . get_class($res) . ' ' . get_class($req) . ' ' . $b;
        };

        $request = new Request('', []);
        $response = new Response();

        $result = $this->router->callHandler(null, $handler, ['x', 'y'], $request, $response);

        $this->assertEquals('x Tina4\Response Tina4\Request y', $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testCallHandlerShortTypeHint(): void
    {
        // Assuming use Tina4\Request; use Tina4\Response; in context
        $handler = function (Request $anyName, $inline, Response $anotherName) {
            return get_class($anyName) . ' ' . $inline . ' ' . get_class($anotherName);
        };

        $request = new Request('', []);
        $response = new Response();

        $result = $this->router->callHandler(null, $handler, ['test'], $request, $response);

        $this->assertEquals('Tina4\Request test Tina4\Response', $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testCallHandlerNoTypeHintCustomName(): void
    {
        // This should not assign to Request if name not 'request'
        $handler = function ($req) {
            return is_object($req) ? get_class($req) : $req;
        };

        $request = new Request('', []);
        $response = new Response();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Missing required argument for parameter 'req'");

        $this->router->callHandler(null, $handler, [], $request, $response);
    }

    /**
     * @throws ReflectionException
     */
    public function testCallHandlerMultipleRequests(): void
    {
        // Test if multiple Request types, but probably only one
        $handler = function (\Tina4\Request $req1, \Tina4\Request $req2) {
            return get_class($req1) . ' ' . get_class($req2);
        };

        $request = new Request('', []);
        $response = new Response();

        $result = $this->router->callHandler(null, $handler, [], $request, $response);

        $this->assertEquals('Tina4\Request Tina4\Request', $result); // Assigns the same $request to both
    }
}

// Helper class for testing instance and static methods
class TestClass
{
    public function handle(\Tina4\Request $req, $a, \Tina4\Response $res): string
    {
        return get_class($req) . ' ' . $a . ' ' . get_class($res);
    }

    public static function staticHandle(\Tina4\Request $req, $a, \Tina4\Response $res): string
    {
        return get_class($req) . ' ' . $a . ' ' . get_class($res);
    }
}