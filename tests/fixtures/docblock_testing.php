<?php

/**
 * Fixture file for Testing::discover() docblock tests.
 */

/**
 * @tests assertEqual([5, 3], 8)
 * @tests assertEqual([0, 0], 0)
 * @tests assertEqual([-1, 1], 0)
 */
function docblock_add(int $a, int $b): int
{
    return $a + $b;
}

/**
 * @tests assertRaises(InvalidArgumentException::class, [null])
 * @tests assertEqual([5, 3], 8)
 */
function docblock_add_safe($a, $b = null)
{
    if ($b === null) {
        throw new \InvalidArgumentException("b required");
    }
    return $a + $b;
}

/**
 * @tests assertTrue([10])
 * @tests assertFalse([0])
 */
function docblock_truthy($value)
{
    return (bool) $value;
}
