<?php

/**
 * @tags examples
 * @description Test
 */
\Tina4\Get::add("/auth/example", function (\Tina4\Response $response) {
    $html = \Tina4\renderTemplate("example.twig", ["publicKey" => str_replace("\r", "", str_replace("\n", "", file_get_contents("./secrets/public.pub")))]);

    return $response ($html, HTTP_OK);
});

/**
 * @tags examples
 * @description Token signing
 * @example ORMExample
 * @secure
 */
\Tina4\Post::add("/auth/example/sign", function (\Tina4\Response $response, \Tina4\Request $request) {
    global $auth;
    return $response ($auth->getToken(["data" => ["name" => "test"], "sub" => "1234567890", "iss" => "https://tina4php.com"]), HTTP_OK);
});