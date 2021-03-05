<?php
namespace Tina4;
/**
 * Render a twig file or string
 * @param $fileNameString
 * @param array $data
 * @return string
 * @throws \Twig\Error\LoaderError
 */
function renderTemplate($fileNameString, $data = []): string
{
    $fileName = str_replace($_SERVER["DOCUMENT_ROOT"] . DIRECTORY_SEPARATOR, "", $fileNameString);
    try {
        global $twig;

        if (!empty($twig)) {
            $internalTwig = clone $twig;
        } else {
            $twigLoader = new \Twig\Loader\FilesystemLoader();
            $internalTwig = new \Twig\Environment($twigLoader, ["debug" => TINA4_DEBUG, "auto_reload" => true]);
            $internalTwig->addExtension(new \Twig\Extension\DebugExtension());
            $internalTwig->addExtension(new \Twig\Extensions\I18nExtension());
        }

        if ($internalTwig->getLoader()->exists($fileNameString)) {
            return $internalTwig->render($fileName, $data);
        } else
            if ($internalTwig->getLoader()->exists(basename($fileNameString))) {
                return $internalTwig->render(basename($fileName), $data);
            } else
                if (is_file($fileNameString)) {
                    $renderFile = basename($fileNameString);
                    $newPath = dirname($fileName) . DIRECTORY_SEPARATOR;
                    $internalTwig->getLoader()->addPath($_SERVER["DOCUMENT_ROOT"] . DIRECTORY_SEPARATOR . $newPath);
                    return $internalTwig->render($renderFile, $data);
                } else {
                    if (!is_file($fileNameString)) {
                        $fileName = "." . DIRECTORY_SEPARATOR . "cache" . DIRECTORY_SEPARATOR . "template" . md5($fileNameString) . ".twig";
                        file_put_contents($fileName, $fileNameString);
                    }

                    $internalTwig->getLoader()->addPath($_SERVER["DOCUMENT_ROOT"] . DIRECTORY_SEPARATOR. "cache");
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
