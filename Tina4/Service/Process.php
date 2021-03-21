<?php

namespace Tina4;
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4 (Andre van Zuydam)
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Class Process
 * Extend this class to make your own processes
 * @package Tina4
 */
class Process
{
    public $name;

    /**
     * Process constructor.
     * @param $name
     * @param $function
     */
    public function __construct($name = "")
    {
        $this->name = $name;
    }

    /**
     * Can run
     * @return bool
     */
    public function canRun(): bool
    {
        return true;
    }

    /**
     * The script that runs things
     */
    public function run()
    {
        echo "You should overwrite this!";
    }
}