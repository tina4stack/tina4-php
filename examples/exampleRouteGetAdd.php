<?php

use Tina4\Get;

Get::add("/example-url", function ($response, $request) {
    return $response ("OK" . $request, 200);
});