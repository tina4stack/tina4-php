<?php
/**
 * Created by PhpStorm.
 * User: Andre van Zuydam
 * Date: 2016/02/15
 * Time: 03:25 PM
 */
namespace Tina4;

/**
 * Class Any Sets the $method variable in Class Route to ANY using the definition in Class Tina4Php
 * The Any route intercepts any type of call that can be made to the web server e.g. Any::add()
 * @package Tina4
 */
class Any extends Route {
    /**
     * @var string Sets $method to ANY
     */
    public static $method = TINA4_ANY;
}
