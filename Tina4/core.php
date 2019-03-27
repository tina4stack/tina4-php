<?php
/**
 * Created by PhpStorm.
 * User: andrevanzuydam
 * Date: 2018/04/12
 * Time: 11:24
 */

//defines
define("TINA4_POST" , "POST");
define("TINA4_GET"  , "GET");
define("TINA4_ANY"  , "ANY");
define("TINA4_PUT"  , "PUT");
define("TINA4_PATCH"  , "PATCH");
define("TINA4_DELETE"  , "DELETE");

global $twig;
//Twig template engine
$webRoot = explode ( "/", realpath( __DIR__));// str_replace ("phar://", "", realpath(dirname(__FILE__)."/../../../../"));
array_pop($webRoot);
$webRoot = join("/", $webRoot);

$twigPaths = TINA4_TEMPLATE_LOCATIONS;

error_log("TINA4: Twig Paths\n".print_r ($twigPaths, 1));
foreach ($twigPaths as $tid => $twigPath) {

    if (!file_exists($webRoot."/".$twigPath)) {
        unset($twigPaths[$tid]);
    }
}

$twigLoader = new \Twig\Loader\FilesystemLoader();
foreach ($twigPaths as $twigPath) {
    $twigLoader->addPath($twigPath, '__main__');
}

$twig = new \Twig\Environment($twigLoader, ["debug" => true]);
$twig->addExtension(new Twig\Extension\DebugExtension());

function renderTemplate ($fileName, $data=[]) {
    try {
        global $twig;

        return $twig->render($fileName, $data);
    } catch (Exception $exception) {
        return $exception->getMessage();
    }
}


function redirect($url, $statusCode = 303)
{
    header('Location: ' . $url, true, $statusCode);
    die();
}

function tina4_autoloader($class) {
    $root = dirname(__FILE__);

    $class = explode("\\", $class);
    $class = $class[count($class)-1];

    $fileName = "{$root}/".str_replace("_","/",$class). ".php";

    if (file_exists($fileName)) {
        include_once $fileName;
    }  else {
        if (defined("TINA4_INCLUDE_LOCATIONS") && is_array(TINA4_INCLUDE_LOCATIONS)) {
            foreach (TINA4_INCLUDE_LOCATIONS as $lid => $location) {
                if (file_exists($_SERVER["DOCUMENT_ROOT"]."/{$location}/{$class}.php")) {
                    require_once $_SERVER["DOCUMENT_ROOT"] . "/{$location}/{$class}.php";
                    break;
                }
            }
        }
    }

}


spl_autoload_register('tina4_autoloader');