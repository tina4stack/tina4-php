<?php


/**
 * @description My first API
 * @summary Returns Hello World!
 * @tags My First API
 */
\Tina4\Get::add("/api/test", function (\Tina4\Response $response) {

    return $response ("Hello World!");
});

/**
 * @description Get a token to submit for the post request
 * @tags Authentication
 */
\Tina4\Get::add("/api/token", function (\Tina4\Response $response) {

    return $response ((new \Tina4\Auth())->getToken());
});

/**
 * @description My first API with params
 * @summary Returns Hello World! with a param
 * @tags My First API
 */
\Tina4\Get::add("/api/test/{param}", function ($param, \Tina4\Response $response) {

    return $response ("Hello World with {$param}!");
});

/**
 * @description Example of taking in the body of a post
 * @tags Post Example
 * @secure
 */
\Tina4\Post::add("/api/test/post", function (\Tina4\Response $response, \Tina4\Request $request) {
    $someObject = $request->data;

    return $response ($someObject);
});