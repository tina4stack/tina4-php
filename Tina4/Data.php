<?php

namespace Tina4;

class Data
{
    public $DBA;

    /**
     * Data constructor.
     * @param null $DBA param of database
     */
    function __construct($DBA = null)
    {
        global $DBA;

        //Check if we have a database connection declared as global , add it to the data class
        foreach ($GLOBALS as $dbName => $GLOBAL) {
            if (is_object($GLOBAL)) {
                DebugLog::message("Found {$dbName}: " . get_class($GLOBAL));
                if (get_class($GLOBAL) === "Tina4\DataBase") {
                    DebugLog::message("Adding {$dbName}");
                    $this->{$dbName} = $GLOBAL;
                }
            }
        }

        //Assign database connections directly based on the variables
        if (!empty($DBA) && !is_array($DBA)) {
            $this->DBA = $DBA;
        } else {
            if (is_array($DBA)) {
                foreach ($DBA as $dbName => $dbHandle) {
                    $this->{$dbName} = $dbHandle;
                }
            }
        }
    }

}