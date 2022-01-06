<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Interface ProcessInterface
 * @package Tina4
 */
interface ProcessInterface
{
    /**
     * Process constructor.
     * @param string $name
     */
    public function __construct(string $name = "");

    /**
     * Can run
     * @return bool
     */
    public function canRun(): bool;

    /**
     * The script that runs things
     */
    public function run();
}
