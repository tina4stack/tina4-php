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

//Twig template engine
global $twigLoader;
$webRoot =  str_replace ("phar://", "", realpath(dirname(__FILE__)."/../../../../"));

$twigPaths = TINA4_TEMPLATE_LOCATIONS;

error_log("TINA4: Twig Paths".print_r ($twigPaths, 1));
foreach ($twigPaths as $tid => $twigPath) {
    if (!file_exists($webRoot."/".$twigPath)) {
        unset($twigPaths[$tid]);
    }
}

$twigLoader = new Twig_Loader_Filesystem($twigPaths);


global $twig;
$twig = new Twig_Environment($twigLoader, ["debug" => true]);
$twig->addExtension(new Twig_Extension_Debug());

function renderTemplate ($fileName, $data=[]) {
    global $twig;

    return $twig->render($fileName, $data);
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

    if (file_exists("{$root}/".str_replace("_","/",$class). ".php")) {
        include "{$root}/" . str_replace("_", "/", $class) . ".php";
    }  else {
        if (defined("TINA4_INCLUDE_LOCATIONS") && is_array(TINA4_INCLUDE_LOCATIONS)) {
            foreach (TINA4_INCLUDE_LOCATIONS as $lid => $location) {
                if (file_exists($_SERVER["DOCUMENT_ROOT"]."/{$location}/{$class}.php")) {
                    include $_SERVER["DOCUMENT_ROOT"] . "/{$location}/{$class}.php";
                    break;
                }
            }
        }
    }

}


spl_autoload_register('tina4_autoloader');