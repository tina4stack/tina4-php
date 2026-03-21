<?php

use Tina4\Route;

/**
 * GET / — Render the welcome page using a Twig template.
 */
Route::get("/", function (\Tina4\Request $request, \Tina4\Response $response) {
    return $response->render("index.twig", ["title" => "Tina4 PHP Example"]);
});
