<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

namespace Tina4;

/**
 * Raised when a seed file throws, so the seed run stopped at it (ADR-0108).
 *
 * The original error is the previous exception (getPrevious()). Extends
 * \RuntimeException so existing `catch (\RuntimeException)` handlers keep
 * catching it.
 */
class SeedError extends \RuntimeException
{
    /**
     * Builds the error naming the failing seed file and its cause.
     *
     * @param string     $seedFile  Path of the seed file that threw
     * @param string[]   $completed Paths of the seed files that ran before it, in order
     * @param \Throwable $cause     The error the seed file threw
     */
    public function __construct(
        public readonly string $seedFile,
        public readonly array $completed,
        \Throwable $cause
    ) {
        parent::__construct('Seed file failed: ' . basename($seedFile) . ': ' . $cause->getMessage(), 0, $cause);
    }
}
