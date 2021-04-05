<?php

namespace Tina4;
/**
 * Render a twig file or string
 * @param $fileNameString
 * @param array $data
 * @return string
 * @throws \Twig\Error\LoaderError
 * @tests
 *
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
            Utility::initTwig();
        }

        $internalTwig = clone $twig;

        if (is_file($fileNameString)) {
            $newPath = dirname($fileName) . DIRECTORY_SEPARATOR;
            $renderFile = str_replace($location, "", $fileName);
            $internalTwig->getLoader()->addPath(TINA4_DOCUMENT_ROOT . $newPath);
            return $internalTwig->render($renderFile, $data);
        } else
            if ($internalTwig->getLoader()->exists($fileName)) {
                return $internalTwig->render($fileName, $data);
            } else
                if ($internalTwig->getLoader()->exists(basename($fileName))) {
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
        return $exception->getMessage();
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