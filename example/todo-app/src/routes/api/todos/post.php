<?php

use Tina4\Router;
use const Tina4\HTTP_CREATED;

Router::post("/api/todos", function (\Tina4\Request $request, \Tina4\Response $response) {
    $todo = new Todo($request);
    $todo->save();
    return $response($todo->asArray(), HTTP_CREATED);
});
