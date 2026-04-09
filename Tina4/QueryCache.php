<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * In-memory TTL cache for query results with simple FIFO eviction.
 *
 * Matches the QueryCache API of the Ruby / Node.js / Python frameworks
 * so cross-framework parity is preserved. Instance-based — create one
 * per connection or per use case.
 *
 *     $cache = new QueryCache(defaultTtl: 60, maxSize: 1000);
 *     $cache->set(QueryCache::queryKey($sql, $params), $rows, 30);
 *     $rows = $cache->get($key);
 *     $rows = $cache->remember($key, 60, fn() => $db->fetch($sql, $params));
 */
class QueryCache
{
    /** @var array<string, array{value: mixed, expiresAt: float, tags: array<int, string>}> */
    private array $store = [];

    private int $defaultTtl;
    private int $maxSize;

    public function __construct(int $defaultTtl = 60, int $maxSize = 1000)
    {
        $this->defaultTtl = $defaultTtl;
        $this->maxSize = $maxSize;
    }

    /**
     * Generate a stable cache key from a SQL query and its parameters.
     */
    public static function queryKey(string $sql, array $params = []): string
    {
        return 'query:' . hash('sha256', $sql . json_encode($params));
    }

    /**
     * Get a cached value. Returns null if the key is missing or expired.
     */
    public function get(string $key): mixed
    {
        if (!isset($this->store[$key])) {
            return null;
        }
        $entry = $this->store[$key];
        if (microtime(true) > $entry['expiresAt']) {
            unset($this->store[$key]);
            return null;
        }
        return $entry['value'];
    }

    /**
     * Set a cached value with optional TTL in seconds (0 = use default) and
     * optional tags for grouped invalidation via clearTag().
     *
     * @param array<int, string> $tags
     */
    public function set(string $key, mixed $value, int $ttl = 0, array $tags = []): void
    {
        if (!isset($this->store[$key]) && count($this->store) >= $this->maxSize) {
            // FIFO eviction — remove the oldest entry.
            $firstKey = array_key_first($this->store);
            if ($firstKey !== null) {
                unset($this->store[$firstKey]);
            }
        }
        $this->store[$key] = [
            'value' => $value,
            'expiresAt' => microtime(true) + ($ttl > 0 ? $ttl : $this->defaultTtl),
            'tags' => $tags,
        ];
    }

    /**
     * Remove all entries that carry the given tag. Returns the number removed.
     */
    public function clearTag(string $tag): int
    {
        $removed = 0;
        foreach ($this->store as $key => $entry) {
            if (in_array($tag, $entry['tags'], true)) {
                unset($this->store[$key]);
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * Return true if the key exists and is not expired.
     */
    public function has(string $key): bool
    {
        if (!isset($this->store[$key])) {
            return false;
        }
        if (microtime(true) > $this->store[$key]['expiresAt']) {
            unset($this->store[$key]);
            return false;
        }
        return true;
    }

    /**
     * Delete a specific key. Returns true if it existed.
     */
    public function delete(string $key): bool
    {
        if (!isset($this->store[$key])) {
            return false;
        }
        unset($this->store[$key]);
        return true;
    }

    /**
     * Remove all expired entries. Returns the number removed.
     */
    public function sweep(): int
    {
        $removed = 0;
        $now = microtime(true);
        foreach ($this->store as $key => $entry) {
            if ($now > $entry['expiresAt']) {
                unset($this->store[$key]);
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * Clear all cached entries.
     */
    public function clear(): void
    {
        $this->store = [];
    }

    /**
     * Return the number of entries currently in the cache.
     */
    public function size(): int
    {
        return count($this->store);
    }

    /**
     * Return the cached value or compute it via the factory and store it.
     */
    public function remember(string $key, int $ttl, callable $factory): mixed
    {
        $cached = $this->get($key);
        if ($cached !== null) {
            return $cached;
        }
        $value = $factory();
        $this->set($key, $value, $ttl);
        return $value;
    }
}
