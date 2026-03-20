<?php

use Tina4\Router;
use const Tina4\HTTP_OK;
use const Tina4\HTTP_NOT_FOUND;

Router::delete("/api/todos/{id}", function ($id, \Tina4\Request $request, \Tina4\Response $response) {
    $todo = new Todo();
    $todo->load("id = {$id}");
    if (!empty($todo->id)) {
        $todo->delete();
        return $response(["message" => "Deleted"], HTTP_OK);
    }
    return $response(["error" => "Not found"], HTTP_NOT_FOUND);
});
