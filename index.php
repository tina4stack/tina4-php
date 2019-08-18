<?php
/**
 * Created by PhpStorm.
 * User: andrevanzuydam
 * Date: 2019-08-18
 * Time: 10:50
 */
require "vendor/autoload.php";
//Add an alias to ORM for legacy code
class_alias("\Tina4\ORM", "Tina4Object");

echo new \Tina4\Tina4Php();
