<?php
namespace Tina4;

class Data
{
    public $DBA;

    /**
     * Data constructor.
     * @param null $DBA param of database
     */
    function __construct($DBA=null)
    {
        if (!empty($DBA)) {
            $this->DBA = $DBA;
        } else {
            global $DBA;
            if ($DBA) {
                $this->DBA = $DBA;
            } else {
                $this->DBA = null;
            }
        }
    }

}