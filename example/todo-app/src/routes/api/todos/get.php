<?php

use Tina4\Router;
use const Tina4\HTTP_OK;

Router::get("/api/todos", function (\Tina4\Request $request, \Tina4\Response $response) {
    $todos = (new Todo())->select("*", 100)->asArray();
    return $response($todos, HTTP_OK);
});
