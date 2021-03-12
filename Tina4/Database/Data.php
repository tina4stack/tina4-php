<?php

namespace Tina4;
use Composer\Autoload\ClassLoader;

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4 (Andre van Zuydam)
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Class Data
 * Extending this class makes any class "data" aware
 * @package Tina4
 */
class Data
{
    public $DBA;
    public $cache;
    public $rootFolder;

    /**
     * Data constructor
     */
    public function __construct()
    {
        $reflection = new \ReflectionClass(ClassLoader::class);
        $vendorDir = dirname($reflection->getFileName());

        $this->rootFolder = dirname($vendorDir . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR);

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