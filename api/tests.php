<?php
/**
 * Created by PhpStorm.
 * User: andrevanzuydam
 * Date: 2019-08-18
 * Time: 10:55
 */
use Tina4\Any;
use Tina4\Get;
use Tina4\Post;
use Tina4\Patch;

use Tina4\Routing;

/**
 * Get a result of the tests
 * @description Runs a Hello world test
 * @tags Hello World
 * @summary Get a hello world test
 */
Get::add("/cest/routing", function($response){

    ob_start();
    passthru("./codecept run");
    $variable = ob_get_contents();
    ob_get_clean();


    return $response ($variable, 200, "text/html");
});

Any::add("/cest/routing/any", function ($response, $request){

    return $response ("OK". $request, 200);
});

