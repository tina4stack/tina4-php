<?php

\Tina4\Get::add("/auth/example", function(\Tina4\Response $response) {
    $html = \Tina4\renderTemplate("example.twig", ["publicKey" => str_replace("\r", "", str_replace("\n", "", file_get_contents("./secrets/public.pub")))]);

    return $response ($html, HTTP_OK);
});

\Tina4\Post::add("/auth/example/sign", function(\Tina4\Response $response, \Tina4\Request $request) {
    global $auth;
    return $response ($auth->getToken("ME!"), HTTP_OK);
});