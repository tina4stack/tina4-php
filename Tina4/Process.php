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
    public function __construct($name="")
    {
        $this->name = $name;
    }

    /**
     * Can run
     * @return bool
     */
    public function canRun() : bool
    {
        return true;
    }

    /**
     * The script that runs things
     */
    public function run ()
    {
        echo "You should overwrite this!";
    }
}