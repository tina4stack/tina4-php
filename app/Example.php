<?php
/**
 * Class Example
 * This example class demonstrates some basic Tina4 concepts
 */

class Example
{

    /**
     * @param $a
     * @param $b
     * @return mixed
     * @tests
     *   assert (1,1) === 2, "1 + 1 = 2"
     *   assert (1,2) !== 4, "1 + 2 <> 4"
     */
    public function add2Numbers($a, $b) {
        return $a + $b;
    }
}