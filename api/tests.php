<?php
/**
 * Created by PhpStorm.
 * User: andrevanzuydam
 * Date: 2019-08-18
 * Time: 10:55
 */
use Tina4\Get;
use Tina4\Routing;
//A swagger endpoint for annotating your API end points
Get::add('/swagger/json.json', function($response) {
    return $response ( (new Routing())->getSwagger("Tina4","Use the entries below to test the frame work","1.0.0"));
});


/**
 * Hello world get end point
 * @description Runs a Hello world test
 * @tags Hello World
 * @summary Get a hello world test
 */
Get::add("/tests/routing", function($response){

    ob_start();
    passthru("./codecept run unit");
    $variable = ob_get_contents();
    ob_get_clean();


    return $response ($variable, 200, "text/html");

});
