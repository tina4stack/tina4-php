<?php

namespace Tina4;

class Process
{
    public $name;

    /**
     * Process constructor.
     * @param $name
     * @param $function
     */
    function __construct($name)
    {
        $this->name = $name;
    }

    function canRun() {
        return true;
    }

    function run () {
        echo "You should overwrite this!";
    }
}