<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 2016/02/15
 * Time: 03:24 PM
 */
namespace Tina4;

/**
 * Class Delete Sets the $method variable in Class Route to DELETE using the definition in Class Tina4Php
 * Delete route intercepts delete calls made to the web server e.g. Delete::add()
 * @package Tina4
 */
class Delete extends Route {
    public static $method = TINA4_DELETE;
}