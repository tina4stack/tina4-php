<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 2016/02/15
 * Time: 03:24 PM
 */
namespace Tina4;

/**
 * Class Post Sets the $method variable in Class Route to POST using the definition in Class Tina4Php
 * Post route intercepts post calls made to the web server e.g. Post::add()
 * @package Tina4
 */
class Post extends Route {
    public static $method = TINA4_POST;
}