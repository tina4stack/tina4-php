<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * The back bone of extending and incorporating Tina4 libraries together
 * @package Tina4
 */
class Module
{
    /**
     * Adds a module
     * @param string $name
     * @param string $version
     * @param string $nameSpace
     * @param null $config
     */
    public static function addModule(string $name = "Tina4Module", string $version = "1.0.0", string $nameSpace = "", $config = null): void
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

        self::addPath("routePath", $moduleFolder . DIRECTORY_SEPARATOR . "src" . DIRECTORY_SEPARATOR . "routes", $baseName);
        self::addPath("includePath", $moduleFolder . DIRECTORY_SEPARATOR . "src" . DIRECTORY_SEPARATOR . "app", $baseName);
        self::addPath("includePath", $moduleFolder . DIRECTORY_SEPARATOR . "src" . DIRECTORY_SEPARATOR . "orm", $baseName);
        self::addPath("templatePath", $moduleFolder . DIRECTORY_SEPARATOR . "src" . DIRECTORY_SEPARATOR . "templates", $baseName);
        self::addPath("migrationPath", $moduleFolder . DIRECTORY_SEPARATOR . "migrations", $baseName);
        self::addPath("scssPath", $moduleFolder . DIRECTORY_SEPARATOR . "src" . DIRECTORY_SEPARATOR . "scss", $baseName);
    }

    /**
     * Gets the path to the module folder in the vendor folder
     * @return string Path to the module folder
     */
    public static function getModuleFolder(): string
    {
        $backtrace = debug_backtrace();
        if (isset($backtrace[1])) {
            return dirname($backtrace[1]['file']);
        } else {
            return __DIR__;
        }
    }

    /**
     * Add Path method
     * @param string $pathType Can be a routePath, templatePath, migrationPath, scssPath
     * @param string $path Path to where the modules reside
     * @param string $baseName Name of the Tina4 Module
     */
    public static function addPath(string $pathType, string $path, string $baseName): void
    {
        global $_TINA4_MODULES;
        if (file_exists($path)) {
            $_TINA4_MODULES[$baseName][$pathType][] = $path;
        }
    }

    /**
     * Gets the Route Folders
     * @return array
     */
    public static function getRouteFolders(): array
    {
        return self::getFolders("routePath");
    }

    public static function getFolders(string $folderType): array
    {
        global $_TINA4_MODULES;
        $folders = [];
        if (empty($_TINA4_MODULES)) {
            return [];
        } else {
            foreach ($_TINA4_MODULES as $moduleName => $module) {
                foreach ($module[$folderType] as $routePath) {
                    $folders[] = $routePath;
                }
            }
        }
        return $folders;
    }

    /**
     * Gets the Template Folders
     * @return array
     */
    public static function getTemplateFolders(): array
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
    public static function getIncludeFolders(): array
    {
        return self::getFolders("includePath");
    }

    /**
     * Gets the Migration Folders
     * @return array
     */
    public static function getMigrationFolders(): array
    {
        return self::getFolders("migrationPath");
    }

    /**
     * Gets the Include Folders
     * @return array
     */
    public static function getSCSSFolders(): array
    {
        return self::getFolders("scssPath");
    }

    /**
     * Gets the configs for the modules
     * @return array
     */
    public static function getModuleConfigs(): array
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
