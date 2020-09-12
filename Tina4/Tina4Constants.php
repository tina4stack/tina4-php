<?php

namespace Tina4;
if (!defined("TINA4_SUPPRESS")) define("TINA4_SUPPRESS", false);
//CSFR
if (!defined("TINA4_SECURE")) define("TINA4_SECURE", true); //turn off on localhost for development, defaults to secure on webserver

if (!defined("TINA4_DATABASE_TYPES")) define("TINA4_DATABASE_TYPES", ["Tina4\DataMySQL", "Tina4\DataFirebird", "Tina4\DataSQLite3"]);

//Debug Types
if (!defined("DEBUG_CONSOLE")) define("DEBUG_CONSOLE", 9001);
if (!defined("DEBUG_SCREEN")) define("DEBUG_SCREEN", 9002);
if (!defined("DEBUG_ALL")) define("DEBUG_ALL", 9003);
if (!defined("DEBUG_NONE")) define("DEBUG_NONE", 9004);

if (!defined("HTTP_OK")) define("HTTP_OK", 200);
if (!defined("HTTP_CREATED")) define("HTTP_CREATED", 201);
if (!defined("HTTP_NO_CONTENT")) define("HTTP_NO_CONTENT", 204);
if (!defined("HTTP_BAD_REQUEST")) define("HTTP_BAD_REQUEST", 400);
if (!defined("HTTP_UNAUTHORIZED")) define("HTTP_UNAUTHORIZED", 401);
if (!defined("HTTP_FORBIDDEN")) define("HTTP_FORBIDDEN", 403);
if (!defined("HTTP_NOT_FOUND")) define("HTTP_NOT_FOUND", 404);
if (!defined("HTTP_METHOD_NOT_ALLOWED")) define("HTTP_METHOD_NOT_ALLOWED", 405);
if (!defined("HTTP_RESOURCE_CONFLICT")) define("HTTP_RESOURCE_CONFLICT", 409);
if (!defined("HTTP_MOVED_PERMANENTLY")) define("HTTP_MOVED_PERMANENTLY", 301);
if (!defined("HTTP_INTERNAL_SERVER_ERROR")) define("HTTP_INTERNAL_SERVER_ERROR", 500);
if (!defined("HTTP_NOT_IMPLEMENTED")) define("HTTP_NOT_IMPLEMENTED", 501);


if (!defined("TINA4_POST")) define("TINA4_POST", "POST");
if (!defined("TINA4_GET")) define("TINA4_GET", "GET");
if (!defined("TINA4_ANY")) define("TINA4_ANY", "ANY");
if (!defined("TINA4_PUT")) define("TINA4_PUT", "PUT");
if (!defined("TINA4_PATCH")) define("TINA4_PATCH", "PATCH");
if (!defined("TINA4_DELETE")) define("TINA4_DELETE", "DELETE");
if (!defined("TINA4_DOCUMENT_ROOT")) define("TINA4_DOCUMENT_ROOT", null);


if (!defined("TEXT_HTML")) define("TEXT_HTML", "text/html");
if (!defined("TEXT_PLAIN")) define("TEXT_PLAIN", "text/plain");
if (!defined("APPLICATION_JSON")) define("APPLICATION_JSON", "application/json");
if (!defined("APPLICATION_XML")) define("APPLICATION_XML", "application/xml");

if (!defined("DATA_ARRAY")) define("DATA_ARRAY", 0);
if (!defined("DATA_OBJECT")) define("DATA_OBJECT", 1);
if (!defined("DATA_NUMERIC")) define("DATA_NUMERIC", 2);
if (!defined("DATA_TYPE_TEXT")) define("DATA_TYPE_TEXT", 0);
if (!defined("DATA_TYPE_NUMERIC")) define("DATA_TYPE_NUMERIC", 1);
if (!defined("DATA_TYPE_BINARY")) define("DATA_TYPE_BINARY", 2);
if (!defined("DATA_ALIGN_LEFT")) define("DATA_ALIGN_LEFT", 0);
if (!defined("DATA_ALIGN_RIGHT")) define("DATA_ALIGN_RIGHT", 1);
if (!defined("DATA_CASE_UPPER")) define("DATA_CASE_UPPER", 1);
if (!defined("DATA_NO_SQL")) define("DATA_NO_SQL", "ERR001");

//Initialize the ENV
(new Env());

/**
 * Redirect
 * @param string $url The URL to be redirected to
 * @param integer $statusCode Code of status
 * @example examples\exampleTina4PHPRedirect.php
 */
function redirect($url, $statusCode = 303)
{
    //Define URL to test from parsed string
    $testURL = parse_url($url);

    //Check if test URL contains a scheme (http or https) or if it contains a host name
    if ((!isset($testURL["scheme"]) || $testURL["scheme"] == "") && (!isset($testURL["host"]) || $testURL["host"] == "")) {

        //Check if the current page uses an alias and if the parsed URL string is an absolute URL
        if (isset($_SERVER["CONTEXT_PREFIX"]) && $_SERVER["CONTEXT_PREFIX"] != "" && substr($url, 0, 1) === '/') {

            //Append the prefix to the absolute path
            header('Location: ' . $_SERVER["CONTEXT_PREFIX"] . $url, true, $statusCode);
            die();
        } else {
            header('Location: ' . $url, true, $statusCode);
            die();
        }

    } else {
        header('Location: ' . $url, true, $statusCode);
        die();
    }

}

/**
 * Autoloader
 * @param $class
 */
function tina4_autoloader($class)
{
    $root = dirname(__FILE__);

    $class = explode("\\", $class);
    $class = $class[count($class) - 1];

    $fileName = "{$root}" . DIRECTORY_SEPARATOR . str_replace("_", DIRECTORY_SEPARATOR, $class) . ".php";

    if (file_exists($fileName)) {
        require_once $fileName;
    } else {
        if (defined("TINA4_INCLUDE_LOCATIONS") && is_array(TINA4_INCLUDE_LOCATIONS)) {
            foreach (TINA4_INCLUDE_LOCATIONS as $lid => $location) {
                if (file_exists($_SERVER["DOCUMENT_ROOT"] . DIRECTORY_SEPARATOR . "{$location}" . DIRECTORY_SEPARATOR . "{$class}.php")) {
                    require_once $_SERVER["DOCUMENT_ROOT"] . DIRECTORY_SEPARATOR . "{$location}" . DIRECTORY_SEPARATOR . "{$class}.php";
                    break;
                }
            }
        }
    }

}

spl_autoload_register('Tina4\tina4_autoloader');

/**
 * @param null $config
 * @return bool
 * @throws \Phpfastcache\Exceptions\PhpfastcacheDriverCheckException
 * @throws \Phpfastcache\Exceptions\PhpfastcacheDriverException
 * @throws \Phpfastcache\Exceptions\PhpfastcacheDriverNotFoundException
 * @throws \Phpfastcache\Exceptions\PhpfastcacheInvalidArgumentException
 * @throws \Phpfastcache\Exceptions\PhpfastcacheInvalidConfigurationException
 * @throws \ReflectionException
 * @throws \Twig\Error\LoaderError
 */
function runTina4($config = null)
{
    echo new Tina4Php($config);
    return true;
}