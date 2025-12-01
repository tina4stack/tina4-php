<?php

#[Get("/hello/world")]
function helloWorld(\Tina4\Response $response) {
    return $response("Hello from decorator!");
}

#[Get("/hello/world/{id}")]
/**
 * @description Hello
 * @example {"message": "Hello from Tina4PHP!", "timestamp": "2025-11-30T16:42:00Z"}
 * @example_response "OK"
 * @tags MOO
 * @secure
 */
function helloWorldTwo($id, \Tina4\Response $response) {
    return $response("{$id} Hello from decorator!");
}