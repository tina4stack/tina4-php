<?php

use Tina4\Post;

Post::add("/example-url-redirect", function ($response, $request) {
    //Do something

    Tina4\redirect("/redirected-url");
});