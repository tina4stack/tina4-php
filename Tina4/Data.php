<?php
namespace Tina4;

class Data
{
    public $DBA;

    function __construct()
    {
        global $DBA;
        $this->DBA = $DBA;
    }

}