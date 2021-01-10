<?php

namespace Tina4;
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4 (Andre van Zuydam)
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Class Module
 * The back bone of extending and incorporating Tina4 libraries together
 * @package Tina4
 */
class Module
{
    public static function getModuleFolder()
    {
        $backtrace = debug_backtrace();
        if (isset($backtrace[1])) {
            return dirname($backtrace[1]['file']);
        } else {
            return __DIR__;
        }
    }

    /**
     * Adds a module
     * @param string $name
     * @param string $version
     * @param string $nameSpace
     * @param Config $config
     */
    public static function addModule($name = "Tina4Module", $version = "1.0.0", $nameSpace = "", $config = null)
    {
        $moduleFolder = self::getModuleFolder();

        if (empty($nameSpace)) {
            $nameSpace = $name;
        }

        global $_TINA4_MODULES;
        if (empty($_TINA4_MODULES)) {
            $_TINA4_MODULES = [];
        }

        $baseName = basename($moduleFolder);

        $_TINA4_MODULES[$baseName]["name"] = $name;
        $_TINA4_MODULES[$baseName]["version"] = $version;
        $_TINA4_MODULES[$baseName]["nameSpace"] = $nameSpace;
        $_TINA4_MODULES[$baseName]["baseDir"] = $moduleFolder;
        $_TINA4_MODULES[$baseName]["config"] = $config;
        $_TINA4_MODULES[$baseName]["templatePath"] = [];
        $_TINA4_MODULES[$baseName]["includePath"] = [];
        $_TINA4_MODULES[$baseName]["routePath"] = [];
        $_TINA4_MODULES[$baseName]["migrationPath"] = [];
        $_TINA4_MODULES[$baseName]["scssPath"] = [];

        self::addRoutePath($moduleFolder . DIRECTORY_SEPARATOR . "api", $baseName);
        self::addRoutePath($moduleFolder . DIRECTORY_SEPARATOR . "routes", $baseName);
        self::addIncludePath($moduleFolder . DIRECTORY_SEPARATOR . "app", $baseName);
        self::addIncludePath($moduleFolder . DIRECTORY_SEPARATOR . "objects", $baseName);
        self::addTemplatePath($moduleFolder . DIRECTORY_SEPARATOR . "templates", $baseName);
        self::addMigrationPath($moduleFolder . DIRECTORY_SEPARATOR . "migrations", $baseName);
        self::addSCSSPath($moduleFolder . DIRECTORY_SEPARATOR . "scss", $baseName);

    }

    /**
     * Add Path
     * @param $pathType
     * @param $path
     */
    public static function addPath($pathType, $path, $baseName)
    {
        global $_TINA4_MODULES;
        if (file_exists($path)) {
            $_TINA4_MODULES[$baseName][$pathType][] = $path;
        }
    }

    /**
     * Add Route Path
     * @param $path
     * @param $baseName
     */
    public static function addRoutePath($path, $baseName)
    {
        self::addPath("routePath", $path, $baseName);
    }

    /**
     * Add Include Path
     * @param $path
     * @param $baseName
     */
    public static function addIncludePath($path, $baseName)
    {
        self::addPath("includePath", $path, $baseName);
    }

    /**
     * Add Template Path
     * @param $path
     * @param $baseName
     */
    public static function addTemplatePath($path, $baseName)
    {
        self::addPath("templatePath", $path, $baseName);
    }

    /**
     * Add Migration Path
     * @param $path
     * @param $baseName
     */
    public static function addMigrationPath($path, $baseName)
    {
        self::addPath("migrationPath", $path, $baseName);
    }

    /**
     * Add SCSS Path
     * @param $path
     * @param $baseName
     */
    public static function addSCSSPath($path, $baseName)
    {
        self::addPath("scssPath", $path, $baseName);
    }


    /**
     * Gets the Route Folders
     * @return array
     */
    public static function getRouteFolders()
    {
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
    public static function getTemplateFolders()
    {
        global $_TINA4_MODULES;
        $routes = [];
        if (empty($_TINA4_MODULES)) {
            return [];
        } else {

            foreach ($_TINA4_MODULES as $moduleName => $module) {
                foreach ($module["templatePath"] as $routePath) {
                    $routes[] = ["path" => $routePath, "nameSpace" => $module["nameSpace"]];
                }
            }
        }


        return $routes;
    }

    /**
     * Gets the Include Folders
     * @return array
     */
    public static function getIncludeFolders()
    {
        global $_TINA4_MODULES;
        $routes = [];
        if (empty($_TINA4_MODULES)) {
            return [];
        } else {
            foreach ($_TINA4_MODULES as $moduleName => $module) {
                foreach ($module["includePath"] as $routePath) {
                    $routes[] = $routePath;
                }
            }
        }
        return $routes;
    }

    /**
     * Gets the Migration Folders
     * @return array
     */
    public static function getMigrationFolders()
    {
        global $_TINA4_MODULES;
        $routes = [];
        if (empty($_TINA4_MODULES)) {
            return [];
        } else {
            foreach ($_TINA4_MODULES as $moduleName => $module) {
                foreach ($module["migrationPath"] as $routePath) {
                    $routes[] = $routePath;
                }
            }
        }
        return $routes;
    }

    /**
     * Gets the Include Folders
     * @return array
     */
    public static function getSCSSFolders()
    {
        global $_TINA4_MODULES;
        $routes = [];
        if (empty($_TINA4_MODULES)) {
            return [];
        } else {
            foreach ($_TINA4_MODULES as $moduleName => $module) {
                foreach ($module["scssPath"] as $routePath) {
                    $routes[] = $routePath;
                }
            }
        }
        return $routes;
    }

    public static function getModuleConfigs()
    {
        global $_TINA4_MODULES;
        $configs = [];
        if (empty($_TINA4_MODULES)) {
            return [];
        } else {
            foreach ($_TINA4_MODULES as $moduleName => $module) {

                if (!empty($module["config"])) {
                    $configs[] = $module["config"];
                }

            }
        }
        return $configs;
    }

}