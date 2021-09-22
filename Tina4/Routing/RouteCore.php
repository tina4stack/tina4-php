<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * The RouteCore from which to inherit router classes
 * @package Tina4
 */
interface RouteCore
{
    /**
     * Adds a route to the global routing table
     * @param string $routePath
     * @param \Closure|Array $function can be in the form function or [Class, method]
     * @param false $inlineParamsToRequest
     * @param false $secure
     */
    public static function add(string $routePath, $function, bool $inlineParamsToRequest = false, bool $secure = false): void;
}
