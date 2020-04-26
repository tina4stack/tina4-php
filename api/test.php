<?php



\Tina4\Get::add("/test", function (\Tina4\Response $response) {

    $object = new MapTest ();
    $object->firstName = "HELLO";
    $object->save();



    return $response ( $object, HTTP_OK, APPLICATION_JSON);
});