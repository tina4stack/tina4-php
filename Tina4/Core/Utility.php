<?php

namespace Tina4;
use Coyl\Git\Git;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Extension\DebugExtension;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4 (Andre van Zuydam)
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Trait Utility
 * A bunch of useful methods used across different classes in the library
 * @package Tina4
 */
trait Utility
{
    /**
     * Recursively includes directories
     * @param $dirName
     */
    public static function includeDirectory($dirName): void
    {
        $d = dir($dirName);
        while (($file = $d->read()) !== false) {
            $pathInfo = pathinfo($file);

            if (isset ($pathInfo["extension"]) && strtolower($pathInfo["extension"]) === "php") {
                $fileNameRoute = realpath($dirName) . DIRECTORY_SEPARATOR . $file;

                require_once $fileNameRoute;
            } else {
                $fileNameRoute = realpath($dirName) . DIRECTORY_SEPARATOR . $file;

                if (($file !== ".") && ($file !== "..") && is_dir($fileNameRoute)) {
                    self::includeDirectory($fileNameRoute);
                }
            }
        }
        $d->close();
    }


    /**
     * Recursively copy the files
     * @param $src
     * @param $dst
     */
    public static function recurseCopy($src, $dst): void
    {
        if (file_exists($src)) {
            $dir = opendir($src);
            if (!file_exists($dst) && !mkdir($dst, $mode = 0755, true) && !is_dir($dst)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $dst));
            }
            while (false !== ($file = readdir($dir))) {
                if (($file !== '.') && ($file !== '..')) {
                    if (is_dir($src . '/' . $file)) {
                        self::recurseCopy($src . '/' . $file, $dst . '/' . $file);
                    } else {
                        copy($src . '/' . $file, $dst . '/' . $file);
                    }
                }
            }
            closedir($dir);
        }
    }

    /**
     * Makes sure the field is a date field and formats the data accordingly
     * @param $dateString
     * @param $databaseFormat
     * @return bool
     */
    public function isDate($dateString, $databaseFormat): bool
    {
        if (is_array($dateString) || is_object($dateString)) {
            return false;
        }
        if (substr($dateString, -1, 1) === "Z")
        {
            $dateParts = explode("T", $dateString);
        } else {
            $dateParts = explode(" ", $dateString);
        }
        $d = \DateTime::createFromFormat($databaseFormat, $dateParts[0]);
        return $d && $d->format($databaseFormat) === $dateParts[0];
    }

    /**
     * Returns a formatted date
     * @param $dateString
     * @param $databaseFormat
     * @param $outputFormat
     * @return string
     */
    public function formatDate($dateString, $databaseFormat, $outputFormat): ?string
    {
        //Hacky fix for weird dates?
        $dateString = str_replace(".000000", "", $dateString);

        if (!empty($dateString)) {
            if ($dateString[strlen($dateString) - 1] === "Z") {
                $delimiter = "T";
                $dateParts = explode($delimiter, $dateString);
                $d = \DateTime::createFromFormat($databaseFormat, $dateParts[0]);
                if ($d) {
                    return $d->format($outputFormat) . $delimiter . $dateParts[1];
                }
                return null;
            }

            if (strpos($dateString, ":") !== false) {
                $databaseFormat .= " H:i:s";
                if (strpos($outputFormat, "T")) {
                    $outputFormat .= "H:i:s";
                } else {
                    $outputFormat .= " H:i:s";
                }
            }
            $d = \DateTime::createFromFormat($databaseFormat, $dateString);
            if ($d) {
                return $d->format($outputFormat);
            }

            return null;
        } else {
            return null;
        }
    }

    /**
     * This tests a string result from the DB to see if it is binary or not so it gets base64 encoded on the result
     * @param $string
     * @return bool
     */
    public function isBinary($string): bool
    {
        //immediately return back binary if we can get an image size
        if (is_numeric($string) || empty($string)) {
            return false;
        }
        if (is_string($string) && strlen($string) > 50 && @is_array(@getimagesizefromstring($string))) {
            return true;
        }
        $isBinary = false;
        $string = str_ireplace("\t", "", $string);
        $string = str_ireplace("\n", "", $string);
        $string = str_ireplace("\r", "", $string);
        if (is_string($string) && ctype_print($string) === false && strspn($string, '01') === strlen($string)) {
            $isBinary = true;
        }
        return $isBinary;
    }

    /**
     * Return a camel cased version of the name
     * @param $name
     * @return string
     */
    public function camelCase($name): string
    {
        $fieldName = "";
        $name = strtolower($name);
        for ($i = 0, $iMax = strlen($name); $i < $iMax; $i++) {
            if ($name[$i] === "_") {
                $i++;
                if ($i < strlen($name)) {
                    $fieldName .= strtoupper($name[$i]);
                }
            } else {
                $fieldName .= $name[$i];
            }
        }
        return $fieldName;
    }


    public function getDebugBackTrace()
    {
        $debug = debug_backtrace();
        $trace = [];
        foreach ($debug as $id => $debugInfo) {
            $trace[] = $debugInfo["file"];
        }
        return $trace;
    }

    /**
     * Deletes all folders and sub folders
     * Credit to https://paulund.co.uk/php-delete-directory-and-files-in-directory
     * @param $target
     */
    public function deleteFiles($target): void
    {
        if(is_dir($target)){
            $files = glob( $target . '/{,.}*[!.]',GLOB_MARK|GLOB_BRACE); //GLOB_MARK adds a slash to directories returned
            foreach( $files as $file ){
                $this->deleteFiles( $file );
            }

            rmdir( $target );
        } elseif(is_file($target)) {
            unlink( $target );
        }
    }

    /**
     * Gets all the files from a folder
     * @param $target
     * @param string $filter
     * @return array
     */
    public function getFiles($target, $filter="*"): array
    {
        $result = [];
        if(is_dir($target)){
            $files = glob( $target . '/{,.}*[!.]',GLOB_MARK|GLOB_BRACE); //GLOB_MARK adds a slash to directories returned
            foreach( $files as $file ){
                $result = array_merge($result, $this->getFiles( $file , $filter));
            }
        } elseif(is_file($target)) {
            if ($filter === "*")
            {
                $result[] = realpath($target);
            } else if ( strpos($target, $filter) !== false ){
                $result[] = realpath($target);
            }
        }
        return $result;
    }

    /**
     * Logic to determine the sub folder - result must be /folder/
     * @param string $documentRoot
     * @return string|null
     */
    public function getSubFolder($documentRoot=""): ?string
    {
        if (defined("TINA4_SUB_FOLDER")) {
            return TINA4_SUB_FOLDER;
        }

        $subFolder = str_replace($_SERVER["DOCUMENT_ROOT"], "", $documentRoot);

        if ($subFolder === $documentRoot || $subFolder === DIRECTORY_SEPARATOR || $subFolder === ".")
        {
            $subFolder = "";
        }

        define("TINA4_SUB_FOLDER", $subFolder);
        return $subFolder;
    }

    /**
     * Iterate the directory
     * @param $path
     * @param string $relativePath
     * @param string $event On click
     * @return string
     */
    public static function iterateDirectory($path, $relativePath = "", $event="onclick=\"window.alert('implement event on iterateDirectory');\""): string
    {
        if (empty($relativePath)) {
            $relativePath = $path;
        }
        $files = scandir($path);
        asort($files);

        $dirItems = [];
        $fileItems = [];

        foreach ($files as $id => $fileName) {
            if ($fileName[0] === "." || $fileName === "cache" || $fileName === "vendor") {
                continue;
            }
            if ($fileName !== "." && $fileName !== ".." && is_dir($path . "/" . $fileName)) {
                $html = '<li data-jstree=\'{"icon":"//img.icons8.com/metro/26/000000/folder-invoices.png"}\'>' . $fileName;
                $html .= self::iterateDirectory($path . "/" . $fileName, $relativePath, $event);
                $html .= "</li>";
                $dirItems[] = $html;

            } else {
                $fileItems[] = '<li '.$event.' file-data="'.str_replace("./", "/", $path . "/" . $fileName).'" data-jstree=\'{"icon":"//img.icons8.com/metro/26/000000/file.png"}\'>' . $fileName . '</li>';
            }

        }

        $html = "<ul>";
        $html .= implode("", $dirItems);
        $html .= implode("", $fileItems);
        $html .= "</ul>";
        return $html;
    }

    /**
     * Initialize twig engine
     * @param Auth|null $auth
     * @param Config|null $config
     * @return Environment
     * @throws LoaderError
     */
    public static function initTwig(?Config $config = null): Environment
    {
        global $twig;
        //Twig initialization
        if (empty($twig)) {
            $twigPaths = TINA4_TEMPLATE_LOCATIONS_INTERNAL;

            if (TINA4_DEBUG) {
                Debug::message("TINA4: Twig Paths - " . str_replace("\n", "", print_r($twigPaths, 1)), TINA4_LOG_DEBUG);
            }

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
                } else
                    if (file_exists(TINA4_SUB_FOLDER .$twigPath)) {
                        $twigLoader->addPath(TINA4_SUB_FOLDER.$twigPath, '__main__');
                    } else {
                        $twigLoader->addPath(str_replace(DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR, TINA4_DOCUMENT_ROOT . DIRECTORY_SEPARATOR . $twigPath), '__main__');
                    }
            }

            if (TINA4_DEBUG) {
                $twig = new Environment($twigLoader, ["debug" => TINA4_DEBUG, "cache" => false]);
                $twig->addExtension(new DebugExtension());
            } else {
                $twig = new Environment($twigLoader, ["cache" => "./cache"]);
            }
            $twig->addGlobal('Tina4', new Caller());
            if (isset($_SERVER["HTTP_HOST"])) {
                $twig->addGlobal('url', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]");
            }

            $twig->addGlobal('baseUrl', TINA4_SUB_FOLDER);
            $twig->addGlobal('baseURL', TINA4_SUB_FOLDER);
            $twig->addGlobal('uniqId', uniqid('', true));
            $auth = $config->getAuthentication();
            if ($auth === null)
            {
                $auth = new Auth();
                $config->setAuthentication($auth);
            }

            if ($auth !== null) {
                $twig->addGlobal('formToken', $config->getAuthentication()->getToken());
            }

            if (isset($_COOKIE) && !empty($_COOKIE)) {
                $twig->addGlobal('cookie', $_COOKIE);
            }

            if (isset($_SESSION) && !empty($_SESSION)) {
                $twig->addGlobal('session', $_SESSION);
            }

            if (isset($_REQUEST) && !empty($_REQUEST)) {
                $twig->addGlobal('request', $_REQUEST);
            }

            if (isset($_SERVER) && !empty($_SERVER)) {
                $twig->addGlobal('server', $_SERVER);
            }

            if ($config !== null) {
                self::addTwigMethods($config, $twig);
            }
            //Add form Token

            if ($auth !== null) {
                $filter = new TwigFilter("formToken", function ($payload) use ($auth) {
                    if (!empty($_SERVER) && isset($_SERVER["REMOTE_ADDR"])) {
                        return _input(["type" => "hidden", "name" => "formToken", "value" => $auth->getToken(["formName" => $payload])]) . "";
                    }

                    return "";
                });
                $twig->addFilter($filter);
            } else {
                $filter = new TwigFilter("formToken", function() {
                    return "Auth protocol not configured";
                });
                $twig->addFilter($filter);
            }

            $filter = new TwigFilter("dateValue", function ($dateString) {
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
            return $twig;
        }  else {
            return $twig;
        }
    }

    /**
     * @param Config $config
     * @param Environment $twig
     * @return bool
     */
    public static function addTwigMethods(Config $config, Environment $twig): bool
    {
        if ($config !== null && !empty($config->getTwigGlobals())) {
            foreach ($config->getTwigGlobals() as $name => $method) {
                $twig->addGlobal($name, $method);
            }
        }

        if ($config !== null && !empty($config->getTwigFilters())) {
            foreach ($config->getTwigFilters() as $name => $method) {
                $filter = new TwigFilter($name, $method);
                $twig->addFilter($filter);
            }
        }

        if ($config !== null && !empty($config->getTwigFunctions())) {
            foreach ($config->getTwigFunctions() as $name => $method) {
                $function = new TwigFunction($name, $method);
                $twig->addFunction($function);
            }
        }

        return true;
    }

    /**
     * Runs git on the repository
     * @todo finish this implementation
     * @param $gitEnabled
     * @param $gitMessage
     * @param $push
     */
    public function initGit($gitEnabled, $gitMessage, $push = false): void
    {
        global $GIT;
        if ($gitEnabled) {
            try {
                Git::setBin("git");
                $GIT = Git::open($this->documentRoot);
                $message = "";
                if (!empty($gitMessage)) {
                    $message = " " . $gitMessage;
                }

                try {
                    if (strpos($GIT->status(), "nothing to commit") === false) {
                        $GIT->add();
                        $GIT->commit("Tina4: Committed changes at " . date("Y-m-d H:i:s") . $message);
                        if ($push) {
                            $GIT->push("", "master");
                        }
                    }
                } catch (\Exception $e) {

                }

            } catch (\Exception $e) {
                echo $e->getMessage();
            }
        }
    }
}
