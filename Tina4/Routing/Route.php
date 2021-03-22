<?php

namespace Tina4;
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4 (Andre van Zuydam)
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Class Route Determines what occurs when a route is called
 * @package Tina4
 */
class Route implements RouteCore
{
    /**
     * @var string Type of method e.g. ANY, POST, DELETE, etc
     */
    public static string $method;

    /**
     * Get route
     * @param string $routePath
     * @param $function
     */
    public static function get(string $routePath, $function): void
    {
        self::$method = TINA4_GET;
        self::add($routePath, $function, true);
    }

    //These methods are used for mostly CRUD and dynamic routes not for code readability, the inline params are passed into the request

    /**
     * Add a route to be called on the web server
     *
     * The routePath can contain variables or in-line parameters encapsulated with {} e.g. "/test/{number}"
     *
     * @param string $routePath A valid html route
     * @param \Closure|Mixed $function An anonymous function to handle the route called, has params based on inline params and $response, $request params by default
     * @param bool $inlineParamsToRequest
     * @param bool $secure
     * @example "api/tests.php"
     */
    public static function add(string $routePath, $function, bool $inlineParamsToRequest = false, bool $secure = false): void
    {
        global $arrRoutes;

        $originalRoute = $routePath;
        //pipe is an or operator for the routing which will allow multiple routes for one anonymous function
        $routePath .= "|";
        $routes = explode("|", $routePath);

        foreach ($routes as $rid => $routePathLoop) {
            if ($routePathLoop !== "") {
                if (isset($_SERVER["REQUEST_URI"]) && substr($routePathLoop, 0,2) !== substr($_SERVER["REQUEST_URI"], 0,2)) continue;

                if ($routePathLoop[0] !== "/") {
                    $routePathLoop = "/" . $routePathLoop;
                }

                $class = null;
                $method = $function;

                if (is_array($function) && class_exists($function[0]) && method_exists($function[0], $function[1])) {
                    $class = $function[0];
                    $method = $function[1];
                }

                $arrRoutes[] = ["routePath" => $routePathLoop, "method" => static::$method, "function" => $method, "class" => $class, "originalRoute" => $originalRoute, "inlineParamsToRequest" => $inlineParamsToRequest];
            }
        }

    }

    /**
     * PUT route
     * @param $routePath
     * @param $function
     */
    public static function put(string $routePath, $function): void
    {
        self::$method = TINA4_PUT;
        self::add($routePath, $function, true);
    }

    /**
     * POST route
     * @param $routePath
     * @param $function
     */
    public static function post(string $routePath, $function): void
    {
        self::$method = TINA4_POST;
        self::add($routePath, $function, true);
    }

    /**
     * PATCH route
     * @param $routePath
     * @param $function
     */
    public static function patch(string $routePath, $function): void
    {
        self::$method = TINA4_PATCH;
        self::add($routePath, $function, true);
    }

    /**
     * DELETE route
     * @param $routePath
     * @param $function
     */
    public static function delete(string $routePath, $function): void
    {
        self::$method = TINA4_DELETE;
        self::add($routePath, $function, true);
    }

    /**
     * ANY route - All methods
     * @param $routePath
     * @param $function
     */
    public static function any(string $routePath, $function): void
    {
        self::$method = TINA4_ANY;
        self::add($routePath, $function, true);
    }

}