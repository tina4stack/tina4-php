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

class Route {
    public static $method;

    /**
     * @param $routePath
     * @param $function
     */
    public static function add ($routePath, $function) {
        global $arrRoutes;
        $originalRoute = $routePath;
        //pipe is an or operator for the routing which will allow multiple routes for one anonymous function
        $routePath .= "|";
        $routes = explode ("|", $routePath);
        foreach ($routes as $rid => $routePath) {
            if ($routePath !== "") {
                if (substr($routePath, 0, 1) !== "/") $routePath = "/" . $routePath;
                $arrRoutes[] = ["routePath" => $routePath, "method" => static::$method, "function" => $function, "originalRoute" => $originalRoute];
            }
        }
    }
}
