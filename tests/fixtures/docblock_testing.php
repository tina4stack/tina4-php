<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * Fixture file for Testing::discover() docblock tests.
 */

/**
 * @tests expectEqual([5, 3], 8)
 * @tests expectEqual([0, 0], 0)
 * @tests expectEqual([-1, 1], 0)
 */
function docblock_add(int $a, int $b): int
{
    return $a + $b;
}

/**
 * @tests expectRaises(InvalidArgumentException::class, [null])
 * @tests expectEqual([5, 3], 8)
 */
function docblock_add_safe($a, $b = null)
{
    if ($b === null) {
        throw new \InvalidArgumentException("b required");
    }
    return $a + $b;
}

/**
 * @tests expectTrue([10])
 * @tests expectFalse([0])
 */
function docblock_truthy($value)
{
    return (bool) $value;
}
