<?php
/**
 * Created by PhpStorm.
 * User: andrevanzuydam
 * Date: 2019-03-24
 * Time: 23:36
 */

//A swagger endpoint for annotating your API end points
\Tina4\Route::get('/swagger/json.json', function (\Tina4\Response $response) {
    return $response ((new Tina4\Routing())->getSwagger("Some Name for the Swagger", "Some other description for swagger", "1.0.0"));
});



