<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Sets the $method variable in Class Route to PUT using the definition in Class Tina4Php
 * Put route intercepts put calls made to the web server e.g. Put::add()
 * @package Tina4
 */
class Put extends Route
{
    /**
     * @var string Sets $method to PUT
     */
    public static $method = TINA4_PUT;
}
