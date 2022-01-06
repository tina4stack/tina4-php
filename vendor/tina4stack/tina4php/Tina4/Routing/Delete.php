<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Sets the $method variable in Class Route to DELETE using the definition in Class Tina4Php
 * Delete route intercepts delete calls made to the web server e.g. Delete::add()
 * @package Tina4
 * @example examples\exampleRouteDeleteAdd.php
 */
class Delete extends Route
{
    /**
     * @var string Sets $method to DELETE
     */
    public static $method = TINA4_DELETE;
}
