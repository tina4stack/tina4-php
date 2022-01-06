<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Sets the $method variable in Class Route to PATCH using the definition in Class Tina4Php
 * Patch route intercepts patch calls made to the web server e.g. Patch::add()
 * @package Tina4
 */
class Patch extends Route
{
    /**
     * @var string Sets $method to PATCH
     */
    public static $method = TINA4_PATCH;
}
