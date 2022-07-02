<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

use Composer\Autoload\ClassLoader;

/**
 * Extending this class makes any class "data" aware
 * @package Tina4
 */
class Data
{

    /** @var \Tina4\DataBase $DBA */
    public $DBA;
    public $cache;
    public $projectRoot;
    public $documentRoot;
    public $subFolder;

    /**
     * Data constructor
     */
    public function __construct()
    {
        if (!defined("TINA4_DOCUMENT_ROOT")) {
            $reflection = new \ReflectionClass(ClassLoader::class);
            $vendorDir = dirname($reflection->getFileName());
            $this->projectRoot = dirname(dirname(__DIR__ . ".." . DIRECTORY_SEPARATOR) . ".." . DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            $vendorFolder = explode("vendor", $this->projectRoot);
            if (count($vendorFolder) > 0) {
                $this->documentRoot = $vendorFolder[0];
                $this->subFolder = $this->getSubFolder($vendorFolder[0]);
            } else {
                $this->documentRoot = str_replace("vendor", "", dirname($vendorDir . ".." . DIRECTORY_SEPARATOR));
                $this->subFolder = $this->getSubFolder($this->documentRoot);
            }

            define("TINA4_DOCUMENT_ROOT", $this->documentRoot);
            define("TINA4_PROJECT_ROOT", $this->projectRoot);
        } else {
            $this->documentRoot = TINA4_DOCUMENT_ROOT;
            $this->projectRoot = TINA4_PROJECT_ROOT;
            $this->subFolder = TINA4_SUB_FOLDER;
        }

        //Check if we have a .env file
        $fileName = TINA4_DOCUMENT_ROOT."/.env";
        if (!file_exists($fileName)) {
            Debug::message("Created an ENV file for you {$fileName}");
            file_put_contents($fileName, "[Project Settings]\nVERSION=1.0.0\nTINA4_DEBUG=true\nTINA4_DEBUG_LEVEL=[TINA4_LOG_ALL]\nTINA4_CACHE_ON=false\n[Open API]\nSWAGGER_TITLE=Tina4 Project\nSWAGGER_DESCRIPTION=Edit your .env file to change this description\nSWAGGER_VERSION=1.0.0");
        }

        //Check if we have a database connection declared as global , add it to the data class
        foreach ($GLOBALS as $dbName => $GLOBAL) {
            if (!empty($GLOBAL)) {
                if ($dbName === "cache") {
                    $this->cache = $GLOBAL;
                }
                if ($dbName[0] === "_" || $dbName === "cache" || $dbName === "auth") {
                    continue;
                }

                if (!defined("TINA4_DATABASE_TYPES")) {
                    \Tina4\Initialize();
                }

                if (is_object($GLOBAL) && in_array(get_class($GLOBAL), TINA4_DATABASE_TYPES)) {
                    $this->{$dbName} = $GLOBAL;
                }
            }
        }
    }

    /**
     * Logic to determine the sub folder - result must be /folder/
     * @param string $documentRoot
     * @return string|null
     */
    public function getSubFolder(string $documentRoot = ""): ?string
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
}
