<?php
/**
 * Created by PhpStorm.
 * User: andrevanzuydam
 * Date: 2019-03-24
 * Time: 23:37
 */
use Tina4\Get;
use Tina4\Post;
use Tina4\Any;
use Tina4\Response;
use Tina4\Request;

Any::add("/test",
    function (Response $response, Request $request)
    {
        ob_start();
        phpinfo();

        $phpInfo = ob_get_contents();
        ob_get_clean();

        $phpInfo = "";
        $phpInfo .= $request;

        $testTable = new Tina4\ORM('{"name": "Andre", "email": "test@test.com"}', "user_table", ["name" => "user_name", "email" => "email_address"]);

        print_r($testTable->getTableData());

        $testTable = new Tina4\ORM('{"name": "Andre", "email": "test@test.com"}', "user_table");

        print_r($testTable->getTableData());

        return $response ($phpInfo, HTTP_OK, TEXT_HTML);
    }
);

Get::add( "/test/object",
    function (Response $response, Request $request)
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
