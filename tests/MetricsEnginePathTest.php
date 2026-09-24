<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


use PHPUnit\Framework\TestCase;
use Tina4\Metrics;

/**
 * The PATH lookup behind `tina4 metrics` must find a binary that is on
 * PATH on Windows (cmd.exe) as well as on a Unix shell.
 *
 * Written before the fix: the lookup hid the shell's "not found" chatter
 * with `2>/dev/null`, which is a Unix-ism. On Windows cmd.exe treats that
 * as a redirect to a `\dev\null` path, fails BEFORE the lookup runs, and
 * returns nothing - so even a binary that IS on PATH read as absent and
 * `tina4 metrics` answered a spurious 503. No `tina4` install needed: the
 * test locates a binary the OS always ships.
 */
class MetricsEnginePathTest extends TestCase
{
    /**
     * locateOnPath() finds a binary that is guaranteed to be on PATH for
     * the current OS - `where` on Windows, `sh` on a Unix shell.
     */
    public function testLocatesABinaryKnownToBeOnPath(): void
    {
        $known = PHP_OS_FAMILY === "Windows" ? "where" : "sh";

        $path = $this->locateOnPath($known);

        $this->assertNotNull(
            $path,
            "a binary on PATH ({$known}) must be located on this OS"
        );
        $this->assertStringContainsStringIgnoringCase($known, (string)$path);
    }

    /** A binary that is not on PATH resolves to null, never a false hit. */
    public function testMissingBinaryResolvesToNull(): void
    {
        $this->assertNull(
            $this->locateOnPath("tina4-not-a-real-binary-" . uniqid())
        );
    }

    /** Invoke the private Metrics::locateOnPath() without a running server. */
    private function locateOnPath(string $name): ?string
    {
        $method = new \ReflectionMethod(Metrics::class, "locateOnPath");

        return $method->invoke(null, $name);
    }
}
