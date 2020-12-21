<?php

/**
 * Example of a get route - access http://localhost:8080/hello-world
 * Assuming you have run php -S localhost:8080 index.php
 * @secure
 */
\Tina4\Get::add("/hello-world", function(\Tina4\Response $response){

    return $response ("Hello World!");
});


/**
 * Example of a get route with inline params - access http://localhost:8080/hello-world/test
 * Assuming you have run php -S localhost:8080 index.php
 */
\Tina4\Get::add("/hello-world/{name}", function($name, \Tina4\Response $response){

    return $response ("Hello World {$name}!");
});