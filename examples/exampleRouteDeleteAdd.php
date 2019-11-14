<?php

use Tina4\Delete;

Delete::add("/example-delete-call/{parsedURLVariable}", function ($parsedURLVariable, $response, $request) {

    if ($parsedURLVariable = 1) {
        //Do something
    } else {
        //Do something else
    }
});