<?php
/**
 * Created by PhpStorm.
 * User: andrevanzuydam
 * Date: 2019-03-24
 * Time: 23:36
 */
use Tina4\Get;
use Tina4\Routing;

//A swagger endpoint for annotating your API end points
Get::add('/swagger/json.json', function($response) {
    return $response ( (new Routing())->getSwagger("Some Name for the Swagger","Some other description for swagger","1.0.0"));
});
