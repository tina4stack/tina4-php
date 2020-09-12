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
        //Check if we have a database connection declared as global , add it to the data class
        foreach ($GLOBALS as $dbName => $GLOBAL) {

            if (!empty($GLOBAL)) {
                if ($dbName[0] == "_" || $dbName === "cache") continue;
                if (is_object($GLOBAL) && in_array(get_class($GLOBAL) , TINA4_DATABASE_TYPES) ) {
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