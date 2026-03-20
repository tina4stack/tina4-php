<?php

use Tina4\Router;
use const Tina4\HTTP_OK;
use const Tina4\HTTP_NOT_FOUND;

Router::put("/api/todos/{id}", function ($id, \Tina4\Request $request, \Tina4\Response $response) {
    $todo = new Todo($request);
    $todo->id = $id;
    if ($todo->save()) {
        return $response($todo->asArray(), HTTP_OK);
    }
    return $response(["error" => "Not found"], HTTP_NOT_FOUND);
});
