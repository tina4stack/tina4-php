<?php
namespace Tina4;


class Module
{

    public static $nameSpace = "module";

    public static function getModuleFolder() {
        $backtrace = debug_backtrace();
        if (isset($backtrace[1])) {
            return dirname($backtrace[1]['file']);
        } else {
            return __DIR__;
        }
    }

    /**
     * Sets the name space for the module
     * @param $name
     */
    public static function setNameSpace($name) {
        self::$nameSpace = $name;
    }

    /**
     * Adds a module
     * @param string $name
     * @param string $version
     * @param string $namespace
     */
    public static function addModule($name="Tina4Module",$version="1.0.0", $namespace="") {
        $moduleFolder = self::getModuleFolder();

        if (empty($namespace)) {
            self::setNameSpace($name);
        } else {
            self::setNameSpace($namespace);
        }

        global $_TINA4_MODULES;
        if (empty($_TINA4_MODULES)) {
            $_TINA4_MODULES = [];
        }

        $_TINA4_MODULES[basename($moduleFolder)]["name"] = $name;
        $_TINA4_MODULES[basename($moduleFolder)]["version"] = $version;
        $_TINA4_MODULES[basename($moduleFolder)]["baseDir"] = $moduleFolder;
        $_TINA4_MODULES[basename($moduleFolder)]["templatePath"] = [];
        $_TINA4_MODULES[basename($moduleFolder)]["includePath"] = [];
        $_TINA4_MODULES[basename($moduleFolder)]["routePath"] = [];

        self::addRoutePath($moduleFolder.DIRECTORY_SEPARATOR."api");
        self::addRoutePath($moduleFolder.DIRECTORY_SEPARATOR."routes");
        self::addIncludePath($moduleFolder.DIRECTORY_SEPARATOR."app");
        self::addIncludePath($moduleFolder.DIRECTORY_SEPARATOR."objects");
        self::addTemplatePath($moduleFolder.DIRECTORY_SEPARATOR."templates");


    }

    /**
     * Add Path
     * @param $pathType
     * @param $path
     */
    public static function addPath($pathType, $path) {
        global $_TINA4_MODULES;
        $moduleFolder = self::getModuleFolder();
        if (file_exists($path)) {
            $_TINA4_MODULES[basename($moduleFolder)][$pathType][] = $path;
        }
    }

    /**
     * Add Route Path
     * @param $path
     */
    public static function addRoutePath($path) {
        self::addPath("routePath", $path);
    }

    /**
     * Add Include Path
     * @param $path
     */
    public static function addIncludePath($path) {
        self::addPath("includePath", $path);
    }

    /**
     * Add Template Path
     * @param $path
     */
    public static function addTemplatePath($path) {
        self::addPath("templatePath", $path);
    }

    /**
     * Gets the Route Folders
     * @return array
     */
    public static function getRouteFolders() {
        global $_TINA4_MODULES;
        $routes = [];
        if (empty($_TINA4_MODULES)) {
            return [];
        } else {
            foreach ($_TINA4_MODULES as $moduleName => $module) {
                foreach ($module["routePath"] as $routePath) {
                    $routes[] = $routePath;
                }
            }
        }
        return $routes;
    }

    /**
     * Gets the Template Folders
     * @return array
     */
    public static function getTemplateFolders() {
        global $_TINA4_MODULES;
        $routes = [];
        if (empty($_TINA4_MODULES)) {
            return [];
        }  else {
            foreach ($_TINA4_MODULES as $moduleName => $module) {
                foreach ($module["templatePath"] as $routePath) {
                    $routes[] = ["path" => $routePath, "nameSpace" => self::$nameSpace];
                }
            }
        }


        return $routes;
    }

    /**
     * Gets the Include Folders
     * @return array
     */
    public static function getIncludeFolders() {
        global $_TINA4_MODULES;
        $routes = [];
        if (empty($_TINA4_MODULES)) {
            return [];
        }  else {
            foreach ($_TINA4_MODULES as $moduleName => $module) {
                foreach ($module["includePath"] as $routePath) {
                    $routes[] = $routePath;
                }
            }
        }
        return $routes;
    }

}