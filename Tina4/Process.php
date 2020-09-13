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

    /**
     * Can run
     * @return bool
     */
    function canRun() {
        return true;
    }

    /**
     * The script that runs things
     */
    function run () {
        echo "You should overwrite this!";
    }
}