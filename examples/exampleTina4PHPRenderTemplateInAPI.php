<?php

use Tina4\Get;

Get::add("/hello-world-render-example", function ($response, $request) {

    $data = array(
        "message" => "Hello world",
        "number" => "1"
    );

    $data[] = array(
        "message" => "Hello world number 2",
        "number" => "2"
    );

    return $response (\Tina4\renderTemplate("route/to/folder/index.twig", ["data" => $data]));
});