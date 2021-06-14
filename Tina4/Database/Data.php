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
    use Utility;

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

        //Check if we have a database connection declared as global , add it to the data class
        foreach ($GLOBALS as $dbName => $GLOBAL) {
            if (!empty($GLOBAL)) {
                if ($dbName === "cache") {
                    $this->cache = $GLOBAL;
                }
                if ($dbName[0] === "_" || $dbName === "cache" || $dbName === "auth") {
                    continue;
                }
                if (is_object($GLOBAL) && in_array(get_class($GLOBAL), TINA4_DATABASE_TYPES)) {
                    Debug::message("Adding {$dbName}");
                    $this->{$dbName} = $GLOBAL;
                }
            }
        }
    }
}