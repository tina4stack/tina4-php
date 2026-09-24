<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


namespace Tina4;

use Stringable;

/**
 * Marker class that bypasses Frond's auto-HTML-escaping.
 *
 * Wrap strings produced by trusted filters (e.g. pre-rendered HTML, JSON
 * embedded in a script tag) in a SafeString so the template engine emits
 * the value verbatim instead of escaping it.
 *
 * Parity with Python's tina4_python.frond.engine.SafeString.
 *
 * @example
 *     Frond::addFilter('uppercase_html', fn($v) => new SafeString('<b>' . strtoupper($v) . '</b>'));
 */
final class SafeString implements Stringable
{
    public function __construct(private string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
