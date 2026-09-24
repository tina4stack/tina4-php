<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


declare(strict_types=1);

// Global namespace, like FreePort and TestServer. Test support.

/**
 * An in-memory message body with PSR-7 StreamInterface semantics for the parts
 * App::__invoke() reads (it casts the body to string).
 */
final class Psr7Stream
{
    private int $position = 0;

    /**
     * @param string $contents The whole body
     */
    public function __construct(private readonly string $contents = '')
    {
    }

    /** @return string The WHOLE stream, from the start, as PSR-7 requires of __toString() */
    public function __toString(): string
    {
        return $this->contents;
    }

    /** @return string The rest of the stream from the current position */
    public function getContents(): string
    {
        $rest = (string) substr($this->contents, $this->position);
        $this->position = strlen($this->contents);
        return $rest;
    }

    /** @return int|null The body length in bytes */
    public function getSize(): ?int
    {
        return strlen($this->contents);
    }
}
