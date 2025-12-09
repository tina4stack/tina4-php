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
    public static string $method;

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
     * Add a route to be called on the web server
     *
     * The routePath can contain variables or in-line parameters encapsulated with {} e.g. "/test/{number}"
     *
     * @param string $routePath A valid html route
     * @param \Closure|Mixed $function An anonymous function to handle the route called, has params based on inline params and $response, $request params by default
     * @param bool $inlineParamsToRequest
     * @param bool $secure
     * @param bool $cached
     * @param null $caller
     * @return Route
     */
    public static function add(string $routePath, $function, bool $inlineParamsToRequest = false, bool $secure = false, bool $cached = false, $caller=null): Route
    {
        global $arrRoutes;
        $originalRoute = $routePath;
        //pipe is an or operator for the routing which will allow multiple routes for one anonymous function
        //find the ignore routes
        $ignoreRoutes = explode("~~", $routePath."~~");

        $ignoreRoutePaths = [];
        if (count($ignoreRoutes) > 1)
        {
            $ignorePath = $ignoreRoutes[1]."|";
            $ignoreRoutePaths = explode("|", $ignorePath);
        }
        //find the normal routes
        $routePath = $ignoreRoutes[0];
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

                if (empty($caller)) {
                    list(, $caller) = debug_backtrace(false);
                }

                $arrRoutes[] = ["routePath" => $routePathLoop, "method" => static::$method, "function" => $method, "class" => $class, "originalRoute" => $originalRoute,
                    "inlineParamsToRequest" => $inlineParamsToRequest, "ignoreRoutes" => $ignoreRoutePaths, "secure" => $secure, "cached" => $cached,
                    "caller" => $caller];
            }
        }


        return new static;

    }

    /**
     * Get route
     * @param string $routePath
     * @param $function
     * @return Route
     */
    public static function get(string $routePath, $function): Route
    {
        self::$method = TINA4_GET;
        list(, $caller) = debug_backtrace(false);

        return self::add($routePath, $function, true, false, false, $caller);
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
        list(, $caller) = debug_backtrace(false);

        return self::add($routePath, $function, true, false, false, $caller);
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
        list(, $caller) = debug_backtrace(false);

        return self::add($routePath, $function, true, false, false, $caller);
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
        list(, $caller) = debug_backtrace(false);

        return self::add($routePath, $function, true, false, false, $caller);
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
        list(, $caller) = debug_backtrace(false);

        return self::add($routePath, $function, true, false, false, $caller);
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
        list(, $caller) = debug_backtrace(false);

        return self::add($routePath, $function, true, false, false, $caller);
    }

    /***
     * Adds a method to the global routing table for middleware to be run
     * @param array $functionNames
     * @return Route
     */
    public static function middleware(array $functionNames): Route
    {
        global $arrRoutes;
        $arrRoutes[count($arrRoutes)-1]["middleware"] = $functionNames;

        return new static;
    }


    /**
     * No cached route
     * @param bool $default
     * @return Route
     */
    public static function noCache(bool $default=false): Route
    {
        global $arrRoutes;
        $arrRoutes[count($arrRoutes)-1]["cached"] = $default;

        return new static;
    }

    /**
     * Cache route
     * @param bool $default
     * @return Route
     */
    public static function cache(bool $default=true): Route
    {
        global $arrRoutes;
        $arrRoutes[count($arrRoutes)-1]["cached"] = $default;

        return new static;
    }

    /**
     * Secure route
     * @param bool $default
     * @return Route
     */
    public static function secure(bool $default=true): Route
    {
        global $arrRoutes;
        $arrRoutes[count($arrRoutes)-1]["secure"] = $default;

        return new static;
    }

    /**
     * Add caller
     */
    public static function caller($caller=null): Route
    {
        global $arrRoutes;
        if (!empty($caller)) {
            $arrRoutes[count($arrRoutes) - 1]["caller"] = $caller;
        }

        return new static;
    }
}
