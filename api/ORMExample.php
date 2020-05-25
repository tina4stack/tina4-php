<?php


/**
 * @description A swagger annotation
 * @tags ORM Example,Testing
 * @example ORMExample
 */
\Tina4\Post::add("/api/orm-example", function (\Tina4\Response $response, \Tina4\Request $request) {


    return $response ($request->data, HTTP_OK, APPLICATION_JSON);
});