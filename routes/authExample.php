<?php

/**
 * @tags examples
 * @description Test
 *
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
    $test = $auth->getPayLoad('eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJkYXRhIjp7Im5hbWUiOiJ0ZXN0In0sInN1YiI6IjEyMzQ1Njc4OTAiLCJpc3MiOiJodHRwczpcL1wvdGluYTRwaHAuY29tIn0.aE44H6puEwYYxGG6W4ZYExbzQ1uouRYvho456dGSpVorNAK1BHb0PJMUmI5d2RS65gKN6zeq-D8mwLVcrOmd2QyKshiiOdrAcUpkOHaUN5NtfkgyfcCwnyUUHmWI2bkeCLlSqzYe8oolNUaAnIUOrVv01kylLbMQ4u3uwwgNWGadPAQVlhMv9xOy-fNyUYvQcqZpYZ3HfABbwiVkAD0_T3ZhNufsL4PyEvxQH7AhSkV-SG-mOUqb1iQbDmp92m1oYytNoL2tsr6Q6UcZCxi3WbD-aQf7_GcHUDhBgRyUyAmJ125E4B6zfaXwaByHIa0FMFFYIQvwq1Q7NcedH2j6iZaV8APDRGZpTE_AI7C3QucOwkOJhjCxbueKKuNeZN5HHO2ihi77Cu8viFf68_QCWiaCK45mLekrc3dauUuD9BzhBteftj4Q1yf0cdQ90cvL1rwtcGOjBfjxf--KrlZFU2v6Qo3YqFkGNqEewaRVcyBcoIXRE6h9n541Gansj0NVxRNVvQ6P0dSxSBA4KZspAIb70hXaQl9DNeKsvNAPSe7GL-bLFwc1Uz61IX0QqYTNY2T2o779gQfqQ7_ojNrlGb-UzJhGQGtfRhWGjJwWA5-2IUyaF604lNOE8_DcmBYGiH98t6Cd7n-20UgTPuJRlh2PgpuHpqUxJ51SYinJ_Eo');
    return $response (["test" => $test, "token" => $auth->getToken(["data" => ["name" => "test"], "sub" => "1234567890", "iss" => "https://tina4php.com"])], HTTP_OK);
});


/**
 * @tags Testing API
 * @description Test
 *
 */
\Tina4\Get::add("/test/philip", function (\Tina4\Response $response) {
    $html = \Tina4\renderTemplate("example.twig", ["publicKey" => str_replace("\r", "", str_replace("\n", "", file_get_contents("./secrets/public.pub")))]);

    return $response ($html, HTTP_OK);
});
