<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Extend this class to make your own processes
 * @package Tina4
 */
class Process implements ProcessInterface
{
    public $name;

    /**
     * Process constructor.
     * @param string $name
     */
    public function __construct(string $name = "")
    {
        if (!empty($name)) {
            $this->name = $name;
        }
        Debug::message("Initializing Process: {$this->name}", TINA4_LOG_DEBUG);
    }

    /**
     * Returns true or false whether the process can run
     * @return bool
     */
    public function canRun(): bool
    {
        // TODO: Implement canRun() method.
    }

    /**
     * The code that will run when this process can run
     */
    public function run()
    {
        // TODO: Implement run() method.
    }
}
