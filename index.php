<?php
/**
 * Created by PhpStorm.
 * User: andrevanzuydam
 * Date: 2019-08-18
 * Time: 10:50
 */

use Tina4\ORM;

require "vendor/autoload.php";
//Add an alias to ORM for legacy code
class_alias("\Tina4\ORM", "Tina4Object");
//define ("TINA4_DEBUG", true);

//global $DBA;

//$DBA = new \Tina4\DataMySQL("test", "root", "Pass1234!");




echo new \Tina4\Tina4Php();
