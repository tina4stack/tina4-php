<?php

\Tina4\Router::get("/api/categories", function ($request, $response) {
    $categories = (new Category())->all(100, 0);
    return $response(array_map(fn($c) => $c->toDict(), $categories));
})->noAuth();
