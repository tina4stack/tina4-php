<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * MemoryBackend — in-process LRU cache with TTL.
 *
 * Mirrors Python's tina4_python.cache._MemoryBackend. The store is per-instance
 * (PHP has no threads); entries are evicted oldest-first once maxEntries is hit.
 */

namespace Tina4\Cache;

class MemoryBackend extends CacheBackend
{
    /** @var array<string, array{value: mixed, expiresAt: ?float}> */
    private array $store = [];

    private int $maxEntries;
    private int $hits = 0;
    private int $misses = 0;

    public function __construct(int $maxEntries = 1000)
    {
        $this->maxEntries = $maxEntries;
    }

    public function get(string $key): mixed
    {
        if (!isset($this->store[$key])) {
            $this->misses++;
            return null;
        }
        $entry = $this->store[$key];
        if ($entry['expiresAt'] !== null && microtime(true) > $entry['expiresAt']) {
            unset($this->store[$key]);
            $this->misses++;
            return null;
        }
        $this->hits++;
        // Move to end (most recently used).
        unset($this->store[$key]);
        $this->store[$key] = $entry;
        return $entry['value'];
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        $expiresAt = $ttl > 0 ? microtime(true) + $ttl : null;
        unset($this->store[$key]);
        $this->store[$key] = ['value' => $value, 'expiresAt' => $expiresAt];
        while (count($this->store) > $this->maxEntries) {
            $firstKey = array_key_first($this->store);
            if ($firstKey === null) {
                break;
            }
            unset($this->store[$firstKey]);
        }
    }

    public function delete(string $key): bool
    {
        if (isset($this->store[$key])) {
            unset($this->store[$key]);
            return true;
        }
        return false;
    }

    public function clear(): void
    {
        $this->store = [];
        $this->hits = 0;
        $this->misses = 0;
    }

    public function stats(): array
    {
        // Sweep expired.
        $now = microtime(true);
        foreach ($this->store as $key => $entry) {
            if ($entry['expiresAt'] !== null && $now > $entry['expiresAt']) {
                unset($this->store[$key]);
            }
        }
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'size' => count($this->store),
            'backend' => 'memory',
        ];
    }

    public function name(): string
    {
        return 'memory';
    }

    public function sweep(): int
    {
        $now = microtime(true);
        $removed = 0;
        foreach ($this->store as $key => $entry) {
            if ($entry['expiresAt'] !== null && $now > $entry['expiresAt']) {
                unset($this->store[$key]);
                $removed++;
            }
        }
        return $removed;
    }
}
