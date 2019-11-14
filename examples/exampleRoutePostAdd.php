<?php

use Tina4\Post;

Post::add("/example-url-to-calculate-parsed-variables", function ($response, $request) {

    $total = $_REQUEST["parsedVariable1"] * $_REQUEST["parsedVariable2"];

});