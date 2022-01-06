<?php
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * General pattern for a database execution
 */
interface DataBaseExec
{
    /**
     * Stub for the exec method
     * @param $params
     * @param $tranId
     */
    public function exec($params, $tranId);
}