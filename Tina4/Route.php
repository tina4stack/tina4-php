<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 2016/02/15
 * Time: 03:23 PM
 */

/**
 * Class Route
 * The main router class which handles the routes that are registered with it
 */

namespace Tina4;

use http\Params;

/**
 * Class Route Determines what occurs when a route is called
 * @package Tina4
 */
class Route
{
    /**
     * @var string Type of method e.g. ANY, POST, DELETE, etc
     */
    public static $method;

    /**
     * Add a route to be called on the web server
     *
     * The routePath can contain variables or in-line parameters encapsulated with {} e.g. "/test/{number}"
     *
     * @param string $routePath A valid html route
     * @param \Closure $function An anonymous function to handle the route called, has params based on inline params and $response, $request params by default
     * @example "api/tests.php"
     *
     */
    public static function add($routePath, $function, $inlineParamsToRequest=false)
    {
        global $arrRoutes;
        $originalRoute = $routePath;
        //pipe is an or operator for the routing which will allow multiple routes for one anonymous function
        $routePath .= "|";
        $routes = explode("|", $routePath);
        foreach ($routes as $rid => $routePath) {
            if ($routePath !== "") {
                if (substr($routePath, 0, 1) !== "/") $routePath = "/" . $routePath;
                $arrRoutes[] = ["routePath" => $routePath, "method" => static::$method, "function" => $function, "originalRoute" => $originalRoute, "inlineParamsToRequest" => $inlineParamsToRequest];
            }
        }
    }

    //These methods are used for mostly CRUD and dynamic routes not for code readability, the inline params are passed into the request
    public static function get ($routePath, $function) {
        self::$method = TINA4_GET;
        self::add($routePath, $function, true);
    }

    public static function put ($routePath, $function) {
        self::$method = TINA4_PUT;
        self::add($routePath, $function, true);
    }

    public static function post ($routePath, $function) {
        self::$method = TINA4_POST;
        self::add($routePath, $function, true);
    }

    public static function patch ($routePath, $function) {
        self::$method = TINA4_PATCH;
        self::add($routePath, $function, true);
    }

    public static function delete ($routePath, $function) {
        self::$method = TINA4_DELETE;
        self::add($routePath, $function, true);
    }

    public static function any ($routePath, $function) {
        self::$method = TINA4_ANY;
        self::add($routePath, $function, true);
    }




}