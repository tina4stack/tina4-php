<?php

/**
 * Module-level cache convenience functions.
 *
 * These live in their own file (loaded via composer `files` autoload) so that
 * \Tina4\Middleware\cache_get() / cache_set() / cache_delete() / cache_clear() /
 * cache_stats() are available globally without first touching the ResponseCache
 * class. The functions reference ResponseCache only at call time, so the class
 * is still lazily PSR-4 autoloaded on first use.
 *
 * Parity with Python tina4_python.cache, Ruby Tina4.cache_*, Node cacheGet/cacheSet.
 */

namespace Tina4\Middleware;

if (!function_exists('Tina4\Middleware\cache_instance')) {
    /**
     * Get a default cache instance (lazy singleton).
     */
    function cache_instance(): ResponseCache
    {
        static $instance = null;
        if ($instance === null) {
            $instance = new ResponseCache();
        }
        return $instance;
    }

    /**
     * Get a value from the cache by key.
     */
    function cache_get(string $key): mixed
    {
        return cache_instance()->_internalGet($key);
    }

    /**
     * Store a value in the cache with optional TTL.
     */
    function cache_set(string $key, mixed $value, int $ttl = 0): void
    {
        cache_instance()->_internalSet($key, $value, $ttl);
    }

    /**
     * Delete a key from the cache.
     */
    function cache_delete(string $key): bool
    {
        return cache_instance()->_internalDelete($key);
    }

    /**
     * Clear all entries from the cache.
     */
    function cache_clear(): void
    {
        cache_instance()->clear();
    }

    /**
     * Return cache statistics.
     */
    function cache_stats(): array
    {
        return cache_instance()->getStats();
    }
}
