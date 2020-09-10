<?php
/**
 * @description A swagger annotation
 * @tags Testing AUTH
 * @example AUTH
 * @secure
 */
\Tina4\Get::add("/api/auth-example", function (\Tina4\Response $response, \Tina4\Request $request) {

    $json = (object)["hello" => "World!"];

    return $response ($json, HTTP_OK, APPLICATION_JSON);
});
