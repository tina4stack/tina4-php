<?php
/**
 * Created by PhpStorm.
 * User: andrevanzuydam
 * Date: 2019-03-24
 * Time: 23:37
 */
use Tina4\Get;
use Tina4\Post;

Get::add("/test",
    function ($response, $request)
    {
        return $response ("Testing", 200);
    }
);

Get::add( "/test/object",
    function ($response, $request)
    {
        $person = new Person();
        $person->firstName = "Test";
        return $response ($person->getTableData(), 200);
    }
);

Post::add("/test/object",
    function ($response, $request) {
        try {
            $object = new Person($request);
            $result = $object->getTableData();
            $errorCode = 200; //Successful
        } catch (Exception $exception) {
            $result = (object)["error" => $exception->getMessage()];
            $errorCode = 400; //Bad Request
        }

        return $response ($result, $errorCode);
    }
);
