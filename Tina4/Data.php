<?php
namespace Tina4;

class Data
{
    public $DBA;

    function __construct()
    {
        global $DBA;
        if ($DBA) {
            $this->DBA = $DBA;
        } else {
            $this->DBA = null;
        }
    }

}