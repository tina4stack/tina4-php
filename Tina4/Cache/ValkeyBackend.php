<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
 *
 * ValkeyBackend — Valkey speaks the Redis wire protocol, so it reuses the
 * Redis client / raw-RESP transport and only reports a different name.
 *
 * Mirrors Python's tina4_python.cache._ValkeyBackend.
 */

namespace Tina4\Cache;

class ValkeyBackend extends RedisBackend
{
    public function __construct(string $url = 'valkey://localhost:6379', int $maxEntries = 1000)
    {
        parent::__construct(
            str_replace('valkey://', 'redis://', $url),
            $maxEntries,
            'valkey'
        );
    }
}
