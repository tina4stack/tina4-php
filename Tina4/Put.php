<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 2016/02/15
 * Time: 03:24 PM
 */
namespace Tina4;

/**
 * Class Put Sets the $method variable in Class Route to PUT using the definition in Class Tina4Php
 * Put route intercepts put calls made to the web server e.g. Put::add()
 * @package Tina4
 */
class Put extends Route {
    /**
     * @var string Sets $method to PUT
     */
    public static $method = TINA4_PUT;
}