<?php
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

use Coyl\Git\Git;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Extension\DebugExtension;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * A bunch of useful methods used across different classes in the library
 * @package Tina4
 */
trait Utility
{
    /**
     * Gets the mime types from the filename
     * @param string $fileName Path to file to be checked or filename
     * @return string Mimetype for the file
     */
    public static function getMimeType(string $fileName): string
    {
        $ext = pathinfo($fileName, PATHINFO_EXTENSION);
        switch ($ext) {
            case "png":
            case "jpeg":
            case "ico":
            case "jpg":
                $mimeType = "image/{$ext}";
                break;
            case "svg":
                $mimeType = "image/svg+xml";
                break;
            case "css":
                $mimeType = "text/css";
                break;
            case "pdf":
                $mimeType = "application/pdf";
                break;
            case "js":
                $mimeType = "application/javascript";
                break;
            case "mp4":
                $mimeType = "video/mp4";
                break;
            default:
                $mimeType = "text/html";
                break;
        }
        return $mimeType;
    }

    /**
     * Recursively includes directories
     * @param string $dirName
     */
    public static function includeDirectory(string $dirName): void
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
     * Recursively copy the files from one place to another
     * @param string $src Absolute directory path to copy from
     * @param string $dst Absolute directory path to copy to
     */
    public static function recurseCopy(string $src, string $dst): void
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
     * Iterate the directory to return a layout of directories and files in an un ordered list
     * @param string $path Absolute path to the starting directory
     * @param string $relativePath Path from which to base the result on
     * @param string $event On click event to put on the files
     * @return string HTML string
     */
    public static function iterateDirectory(string $path, string $relativePath = "", string $event = "onclick=\"window.alert('implement event on iterateDirectory');\""): string
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
                $fileItems[] = '<li ' . $event . ' file-data="' . str_replace("./", "/", $path . "/" . $fileName) . '" data-jstree=\'{"icon":"//img.icons8.com/metro/26/000000/file.png"}\'>' . $fileName . '</li>';
            }

        }

        $html = "<ul>";
        $html .= implode("", $dirItems);
        $html .= implode("", $fileItems);
        $html .= "</ul>";
        return $html;
    }

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
            $twigPaths = TINA4_TEMPLATE_LOCATIONS_INTERNAL;

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
                } else
                    if (file_exists(TINA4_SUB_FOLDER . $twigPath)) {
                        $twigLoader->addPath(TINA4_SUB_FOLDER . $twigPath, '__main__');
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

            if ($config === null) {
                $config = new Config();
            }

            $auth = $config->getAuthentication();
            if ($auth === null) {
                $auth = new Auth();
                $config->setAuthentication($auth);
            }

            if ($auth !== null) {
                $twig->addGlobal('formToken', $config->getAuthentication()->getToken());
            }


            if (defined("TINA4_TWIG_GLOBALS") && TINA4_TWIG_GLOBALS) {
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
                $filter = new TwigFilter("formToken", function () {
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
        } else {
            return $twig;
        }
    }

    /**
     * Adds globals, filters & functions
     * @param Config $config Tina4 config
     * @param Environment $twig The instantiated twig environment
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
     * Makes sure the field is a date field and formats the data accordingly
     * @param string $dateString
     * @param string $databaseFormat
     * @return bool
     */
    public function isDate(string $dateString, string $databaseFormat): bool
    {
        if (is_array($dateString) || is_object($dateString)) {
            return false;
        }
        if (substr($dateString, -1, 1) === "Z") {
            $dateParts = explode("T", $dateString);
        } else {
            $dateParts = explode(" ", $dateString);
        }
        $d = \DateTime::createFromFormat($databaseFormat, $dateParts[0]);
        return $d && $d->format($databaseFormat) === $dateParts[0];
    }

    /**
     * Returns a formatted date in the specified output format
     * @param string $dateString Date input
     * @param string $databaseFormat Format in date format of PHP
     * @param string $outputFormat Output of the date in the specified format
     * @return string The resulting formatted date
     */
    public function formatDate(string $dateString, string $databaseFormat, string $outputFormat): ?string
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
     * @param string $string Data to be checked to see if it is binary data like images
     * @return bool True if the string is binary
     */
    public function isBinary(string $string): bool
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
     * @param string $name A field name or object name with underscores
     * @return string Camel case version of the input
     */
    public function camelCase(string $name): string
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

    /**
     * Get the trace for a debug point used in ORM
     * @return array
     */
    public function getDebugBackTrace(): array
    {
        $debug = debug_backtrace();
        $trace = [];
        foreach ($debug as $id => $debugInfo) {
            if (isset($debugInfo["file"])) {
                $trace[] = $debugInfo["file"];
            }
        }
        return $trace;
    }

    /**
     * Deletes all folders and sub folders
     * Credit to https://paulund.co.uk/php-delete-directory-and-files-in-directory
     * @param string $target Absolute path to the folder
     */
    public function deleteFiles(string $target): void
    {
        if (is_dir($target)) {
            $files = glob($target . '/{,.}*[!.]', GLOB_MARK | GLOB_BRACE); //GLOB_MARK adds a slash to directories returned
            foreach ($files as $file) {
                $this->deleteFiles($file);
            }

            rmdir($target);
        } elseif (is_file($target)) {
            unlink($target);
        }
    }

    /**
     * Gets the files based on a mask from a path, a recursive function
     * @param string $target Absolute path to the folder to scan
     * @param string $filter A filter for the type of files - *.php for example
     * @return array An array of the filenames
     */
    public function getFiles(string $target, string $filter = "*"): array
    {
        $result = [];
        if (is_dir($target)) {
            $files = glob($target . '/{,.}*[!.]', GLOB_MARK | GLOB_BRACE); //GLOB_MARK adds a slash to directories returned
            foreach ($files as $file) {
                $result = array_merge($result, $this->getFiles($file, $filter));
            }
        } elseif (is_file($target)) {
            if ($filter === "*") {
                $result[] = realpath($target);
            } else if (strpos($target, $filter) !== false) {
                $result[] = realpath($target);
            }
        }
        return $result;
    }

    /**
     * Clean URL by splitting string at "?" to get actual URL
     * @param string $url URL to be cleaned that may contain "?"
     * @return mixed Part of the URL before the "?" if it existed
     */
    public function cleanURL(string $url): string
    {
        $url = explode("?", $url, 2);

        $url[0] = str_replace(TINA4_SUB_FOLDER, "/", $url[0]);

        return str_replace("//", "/", $url[0]);
    }

    /**
     * Logic to determine the sub folder - result must be /folder/
     * @param string $documentRoot
     * @return string|null
     */
    public function getSubFolder($documentRoot = ""): ?string
    {
        if (defined("TINA4_SUB_FOLDER")) {
            return TINA4_SUB_FOLDER;
        }

        $subFolder = str_replace($_SERVER["DOCUMENT_ROOT"], "", $documentRoot);

        if ($subFolder === $documentRoot || $subFolder === DIRECTORY_SEPARATOR || $subFolder === ".") {
            $subFolder = "";
        }

        define("TINA4_BASE_URL", $subFolder);
        define("TINA4_SUB_FOLDER", $subFolder);
        return $subFolder;
    }

    /**
     * Runs git on the repository
     * @param bool $gitEnabled Flags if git is enabled
     * @param string $gitMessage A commit message
     * @param bool $push Should the commit be pushed to the repository
     * @todo finish this implementation
     */
    public function initGit(bool $gitEnabled, string $gitMessage, bool $push = false): void
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
