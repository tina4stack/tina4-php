<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 2016/02/15
 * Time: 03:24 PM
 */
namespace Tina4;

/**
 * Class Patch Sets the $method variable in Class Route to PATCH using the definition in Class Tina4Php
 * Patch route intercepts patch calls made to the web server e.g. Patch::add()
 * @package Tina4
 */
class Patch extends Route {
    public static $method = TINA4_PATCH;
}