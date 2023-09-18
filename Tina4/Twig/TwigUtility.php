<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;


use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

class TwigUtility
{
    /**
     * Initialize twig engine with passed config
     * @param Config|null $config A config with settings to initialize twig
     * @return Environment A twig instance
     * @throws LoaderError Error if the twig instance cannot be created
     */
    public static function initTwig(?Config $config = null): Environment
    {
        global $twig;

        //Twig initialization
        if (empty($twig)) {

            if (defined("TINA4_TEMPLATE_LOCATIONS_INTERNAL") && !empty(TINA4_TEMPLATE_LOCATIONS_INTERNAL)) {
                $twigPaths = TINA4_TEMPLATE_LOCATIONS_INTERNAL;
            } else {
                $twigPaths = ["cache", "src" . DIRECTORY_SEPARATOR . "templates", "src" . DIRECTORY_SEPARATOR . "public", "src" . DIRECTORY_SEPARATOR . "assets", "src" . DIRECTORY_SEPARATOR . "templates" . DIRECTORY_SEPARATOR . "snippets"];
            }

            Debug::message("TINA4: Twig Paths - " . str_replace("\n", "", print_r($twigPaths, 1)), TINA4_LOG_DEBUG);

            foreach ($twigPaths as $tid => $twigPath) {
                if (!is_array($twigPath) && !file_exists($twigPath) && !file_exists(str_replace(DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR, TINA4_DOCUMENT_ROOT . $twigPath))) {
                    unset($twigPaths[$tid]);
                }
            }

            $twigLoader = new FilesystemLoader();
            foreach ($twigPaths as $twigPath) {
                if (is_array($twigPath)) {
                    if (isset($twigPath["nameSpace"])) {
                        $twigLoader->addPath($twigPath["path"], $twigPath["nameSpace"]);
                        $twigLoader->addPath($twigPath["path"], "__main__");
                    }
                } elseif (file_exists(TINA4_SUB_FOLDER . $twigPath)) {
                    $twigLoader->addPath(TINA4_SUB_FOLDER . $twigPath, '__main__');
                } else {
                    $twigLoader->addPath(str_replace(DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR, TINA4_DOCUMENT_ROOT . DIRECTORY_SEPARATOR . $twigPath), '__main__');
                }
            }

            if (defined("TINA4_CACHE_ON") && TINA4_CACHE_ON === true) {
                $twig = new Environment($twigLoader, ["debug" => TINA4_DEBUG, "cache" => "./cache"]);
            } else {
                $twig = new Environment($twigLoader, ["debug" => TINA4_DEBUG]);
            }

            if (TINA4_DEBUG) {
                $dumpFunction = new TwigFunction( "dump", function($variable) {
                    echo "<pre>";
                    print_r($variable);
                    echo "</pre>";
                });
                $twig->addFunction($dumpFunction);
            }

            $twig->addGlobal('Tina4', new Caller());
            if (isset($_SERVER["HTTP_HOST"])) {
                $twig->addGlobal('url', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]");
            }

            $twig->addGlobal('baseUrl', TINA4_SUB_FOLDER);
            $twig->addGlobal('baseURL', TINA4_SUB_FOLDER);
            $twig->addGlobal('uniqId', uniqid('', true));

            if ($config === null) {
                $config = new Config();
            }

            $auth = $config->getAuthentication();
            if ($auth === null) {
                $auth = new Auth();
                $config->setAuthentication($auth);
            }

            if (defined("TINA4_TWIG_GLOBALS") && TINA4_TWIG_GLOBALS) {
                if (!empty($_COOKIE)) {
                    $twig->addGlobal('cookie', $_COOKIE);
                }

                if (!empty($_SESSION)) {
                    $twig->addGlobal('session', $_SESSION);
                }

                if (!empty($_REQUEST)) {
                    if (isset($_REQUEST["formToken"]) && $auth->validToken($_REQUEST["formToken"]) ) {
                        $twig->addGlobal('request', $_REQUEST);
                    } else {
                        $twig->addGlobal('request', $_GET);
                    }
                }

                if (!empty($_SERVER)) {
                    $twig->addGlobal('server', $_SERVER);
                }
            }

            self::addTwigMethods($config, $twig);

            //Add form Token

            if ($auth !== null) {
                $twig->addGlobal('formToken', $config->getAuthentication()->getToken());

                $function = new TwigFunction("formToken", static function($payload) use ($auth) {
                    return $auth->getToken(compact('payload'));
                });

                $twig->addFunction($function);

                $filter = new TwigFilter("formToken", static function ($payload) use ($auth) {
                    if (!empty($_SERVER) && isset($_SERVER["REMOTE_ADDR"])) {
                        return _input(["type" => "hidden", "name" => "formToken", "value" => $auth->getToken(["formName" => $payload])]) . "";
                    }

                    return "";
                });
            } else {
                $filter = new TwigFilter("formToken", static function () {
                    return "Auth protocol not configured";
                });
            }
            $twig->addFilter($filter);

            $filter = new TwigFilter("dateValue", static function ($dateString) {
                global $DBA;
                if (!empty($DBA)) {
                    if (substr($dateString, -1, 1) === "Z") {
                        return substr($DBA->formatDate($dateString, $DBA->dateFormat, "Y-m-d"), 0, -1);
                    }
                    return $DBA->formatDate($dateString, $DBA->dateFormat, "Y-m-d");
                }
                return $dateString;
            });

            $twig->addFilter($filter);
        }

        return $twig;
    }

    /**
     * Adds globals, filters & functions
     * @param Config $config Tina4 config
     * @param Environment $twig The instantiated twig environment
     * @return bool
     */
    public static function addTwigMethods(Config $config, Environment $twig): bool
    {
        if (!empty($config->getTwigGlobals())) {
            foreach ($config->getTwigGlobals() as $name => $method) {
                $twig->addGlobal($name, $method);
            }
        }

        if (!empty($config->getTwigFilters())) {
            foreach ($config->getTwigFilters() as $name => $method) {
                $filter = new TwigFilter($name, $method);
                $twig->addFilter($filter);
            }
        }

        if (!empty($config->getTwigFunctions())) {
            foreach ($config->getTwigFunctions() as $name => $method) {
                $function = new TwigFunction($name, $method);
                $twig->addFunction($function);
            }
        }

        return true;
    }

}