<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Sets the $method variable in Class Route to GET using the definition in Class Tina4Php
 * Get route intercepts get calls made to the web server e.g. Get::add()
 * @package Tina4
 * @example examples\exampleRouteGetAdd.php
 */
class Get extends Route
{
    /**
     * @var string Sets $method to GET
     */
    public static $method = TINA4_GET;
}
