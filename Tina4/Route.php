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
     * @param resource $function An anonymous function to handle the route called, has params based on inline params and $response, $request params by default
     * @example "api/tests.php"
     *
     */
    public static function add($routePath, $function)
    {
        global $arrRoutes;
        $originalRoute = $routePath;
        //pipe is an or operator for the routing which will allow multiple routes for one anonymous function
        $routePath .= "|";
        $routes = explode("|", $routePath);
        foreach ($routes as $rid => $routePath) {
            if ($routePath !== "") {
                if (substr($routePath, 0, 1) !== "/") $routePath = "/" . $routePath;
                $arrRoutes[] = ["routePath" => $routePath, "method" => static::$method, "function" => $function, "originalRoute" => $originalRoute];
            }
        }
    }
}
