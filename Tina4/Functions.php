<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Render a twig file or string
 * @param $fileNameString
 * @param array $data
 * @param string $location
 * @return string
 */
function renderTemplate($fileNameString, $data = [], $location = ""): string
{
    if (!defined("TINA4_DOCUMENT_ROOT")) {
        define("TINA4_DOCUMENT_ROOT", $_SERVER["DOCUMENT_ROOT"] . DIRECTORY_SEPARATOR);
    }

    $fileName = str_replace(TINA4_DOCUMENT_ROOT, "", $fileNameString);
    try {
        global $twig;

        if (empty($twig)) {
            TwigUtility::initTwig();
        }

        $internalTwig = clone $twig;

        if (is_file($fileNameString)) {
            $newPath = dirname($fileName) . DIRECTORY_SEPARATOR;
            if ($location === "") {
                $location = $newPath;
            }
            $renderFile = str_replace($location, "", $fileName);

            if ($renderFile[0] === DIRECTORY_SEPARATOR) {
                $renderFile = substr($renderFile, 1);
            }
            $internalTwig->getLoader()->addPath(TINA4_DOCUMENT_ROOT . $newPath);
            return $internalTwig->render($renderFile, $data);
        } elseif (strlen($fileName) > 1 && $fileName[0] === DIRECTORY_SEPARATOR || strlen($fileName) > 1 && $fileName[0] === "/") {
            $fileName = substr($fileName, 1);
        }

        if ($internalTwig->getLoader()->exists($fileName)) {
            return $internalTwig->render($fileName, $data);
        } elseif ($internalTwig->getLoader()->exists(basename($fileName))) {
            return $internalTwig->render(basename($fileName), $data);
        } else {
            if (!is_file($fileNameString)) {
                $fileName = "." . DIRECTORY_SEPARATOR . "cache" . DIRECTORY_SEPARATOR . "template" . md5($fileNameString) . ".twig";
                file_put_contents($fileName, $fileNameString);
            }
            $internalTwig->getLoader()->addPath(TINA4_DOCUMENT_ROOT . "cache");
            return $internalTwig->render("template" . md5($fileNameString) . ".twig", $data);
        }
    } catch (\Exception $exception) {
        return $exception->getFile() . " (" . $exception->getLine() . ") " . $exception->getMessage();
    }
}

/**
 * Redirect
 * @param string $url The URL to be redirected to
 * @param integer $statusCode Code of status
 * @example examples\exampleTina4PHPRedirect.php
 */
function redirect(string $url, $statusCode = 303)
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
 * Initialize function loads the library for use
 */
function Initialize() {
    if (file_exists("./Tina4/Initialize.php")) {
        require_once "./Tina4/Initialize.php";
    } else {
        require_once __DIR__."/Initialize.php";
    }
}