<?php
use Tina4\Router;
require_once "vendor/autoload.php";
define("TINA4_SUPPRESS", true);

//Other defines to set here would be
//TINA4_PROJECT_ROOT - where is the project living
//TINA4_DOCUMENT_ROOT - where is the server document root
//TINA4_SUB_FOLDER - is this running under a sub url

$events = [];
$events["Request"] = static function(\Swoole\Http\Request $request, \Swoole\Http\Response $response)
{
    //create config
    $config = new \Tina4\Config(function (\Tina4\Config $config) {
            global $DBA;
            $DBA = new \Tina4\DataSQLite3("test.db");
    });

    //initialize
    \Tina4\Initialize();
    new \Tina4\Tina4Php($config);

    //resolve routes
    $routerResponse = (new Router())->resolveRoute($request->server["request_method"], $request->server["request_uri"], $config, $request->header, $request);

    $content = "";
    if ($routerResponse->content === "") {
        //try give back a response based on the error code - first templates then public
        if (file_exists(TINA4_DOCUMENT_ROOT . "src" . DIRECTORY_SEPARATOR . "templates" . DIRECTORY_SEPARATOR . "errors" . DIRECTORY_SEPARATOR . $routerResponse->httpCode . ".twig")) {
            $content = \Tina4\renderTemplate(TINA4_DOCUMENT_ROOT . "src" . DIRECTORY_SEPARATOR . "templates" . DIRECTORY_SEPARATOR . "errors" . DIRECTORY_SEPARATOR . $routerResponse->httpCode . ".twig");
        }

        if (file_exists(TINA4_DOCUMENT_ROOT . "src" . DIRECTORY_SEPARATOR . "public" . DIRECTORY_SEPARATOR . "errors" . DIRECTORY_SEPARATOR . $routerResponse->httpCode . ".twig")) {
            $content = \Tina4\renderTemplate(TINA4_DOCUMENT_ROOT . "src" . DIRECTORY_SEPARATOR . "public" . DIRECTORY_SEPARATOR . "errors" . DIRECTORY_SEPARATOR . $routerResponse->httpCode . ".twig");
        }
    } else {
        $content = $routerResponse->content;
    }

    //add headers

    foreach ($routerResponse->headers as $hid => $header) {
        $header = explode(":", $header, 2);
        $response->header($header[0], $header[1]);
    }

    $response->end($content);
};

$events["Close"] = static function($server) {
    $server->reload(); //only do this when debugging so you can edit code
};


$swoole = new \Tina4\Swoole(7145, $events, "http");
$swoole->start();