<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
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
