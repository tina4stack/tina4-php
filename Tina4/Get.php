<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 2016/02/15
 * Time: 03:23 PM
 */

namespace Tina4;

/**
 * Class Get Sets the $method variable in Class Route to GET using the definition in Class Tina4Php
 * Get route intercepts get calls made to the web server e.g. Get::add()
 * @package Tina4
 * @example examples\exampleRouteGetAdd.php
 */
class Get extends Route
{
    /**
     * @var string Sets $method to GET
     */
    public static $method = TINA4_GET;

}