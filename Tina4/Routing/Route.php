<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Determines what occurs when a route is called
 * @package Tina4
 */
class Route implements RouteCore
{
    /**
     * @var string Type of method e.g. ANY, POST, DELETE, etc
     */
    public static $method;


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

    /**
     * Add a route to be called on the web server
     *
     * The routePath can contain variables or in-line parameters encapsulated with {} e.g. "/test/{number}"
     *
     * @param string $routePath A valid html route
     * @param \Closure|Mixed $function An anonymous function to handle the route called, has params based on inline params and $response, $request params by default
     * @param bool $inlineParamsToRequest
     * @param bool $secure
     * @return Route
     * @example "api/tests.php"
     */
    public static function add(string $routePath, $function, bool $inlineParamsToRequest = false, bool $secure = false): Route
    {
        global $arrRoutes;
        $originalRoute = $routePath;
//pipe is an or operator for the routing which will allow multiple routes for one anonymous function
        $routePath .= "|";
        $routes = explode("|", $routePath);
        foreach ($routes as $rid => $routePathLoop) {
            if ($routePathLoop !== "") {
                //@todo refine caching of the routes

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

        return new static;
    }

    //These methods are used for mostly CRUD and dynamic routes not for code readability, the inline params are passed into the request

    /**
     * Clean URL by splitting string at "?" to get actual URL
     * @param string $url URL to be cleaned that may contain "?"
     * @return mixed Part of the URL before the "?" if it existed
     */
    public static function cleanURL(string $url): string
    {
        $url = explode("?", $url, 2);
        $url[0] = str_replace(TINA4_SUB_FOLDER, "/", $url[0]);
        return str_replace("//", "/", $url[0]);
    }

    /**
     * PUT route
     * @param string $routePath
     * @param $function
     * @return Route
     */
    public static function put(string $routePath, $function): Route
    {
        self::$method = TINA4_PUT;
        return self::add($routePath, $function, true);
    }

    /**
     * POST route
     * @param string $routePath
     * @param $function
     * @return Route
     */
    public static function post(string $routePath, $function): Route
    {
        self::$method = TINA4_POST;
        return self::add($routePath, $function, true);
    }

    /**
     * PATCH route
     * @param string $routePath
     * @param $function
     * @return Route
     */
    public static function patch(string $routePath, $function): Route
    {
        self::$method = TINA4_PATCH;
        return self::add($routePath, $function, true);
    }

    /**
     * DELETE route
     * @param string $routePath
     * @param $function
     * @return Route
     */
    public static function delete(string $routePath, $function): Route
    {
        self::$method = TINA4_DELETE;
        return self::add($routePath, $function, true);
    }

    /**
     * ANY route - All methods
     * @param string $routePath
     * @param $function
     * @return Route
     */
    public static function any(string $routePath, $function): Route
    {
        self::$method = TINA4_ANY;
        return self::add($routePath, $function, true);
    }

    /***
     * Adds a method to the global routing table for middleware to be run
     * @param array $functionNames
     * @return array
     */
    public static function middleware(array $functionNames): void
    {
        global $arrRoutes;
        $arrRoutes[sizeof($arrRoutes)-1]["middleware"] = $functionNames;
    }
}
