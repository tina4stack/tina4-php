<?php

namespace Tina4;

/**
 * Tina4 Cache — Static facade over a shared QueryCache singleton.
 *
 * Provides the legacy application-level cache API (Cache::cacheGet,
 * Cache::cacheSet, Cache::cacheStats, …) while delegating all storage
 * to a single in-memory QueryCache instance. This keeps the TTL/LRU
 * logic in one place across the whole framework and eliminates the
 * previous duplicate store.
 *
 * Configured via TINA4_CACHE_TTL and TINA4_CACHE_MAX_ENTRIES env vars.
 *
 * Usage:
 *   Cache::cacheSet("key", $value, 60);
 *   $value = Cache::cacheGet("key");
 *   Cache::cacheDelete("key");
 *   Cache::cacheClear();
 *   $stats = Cache::cacheStats();
 */
class Cache
{
    private static ?QueryCache $instance = null;
    private static int $hits = 0;
    private static int $misses = 0;

    private static function instance(): QueryCache
    {
        if (self::$instance === null) {
            $maxEntries = (int)(getenv('TINA4_CACHE_MAX_ENTRIES') ?: 1000);
            $defaultTtl = (int)(getenv('TINA4_CACHE_TTL') ?: 60);
            self::$instance = new QueryCache($defaultTtl, $maxEntries);
        }
        return self::$instance;
    }

    /**
     * Retrieve a cached value. Returns null on miss or expiry.
     */
    public static function cacheGet(string $key): mixed
    {
        $value = self::instance()->get($key);
        if ($value === null) {
            self::$misses++;
        } else {
            self::$hits++;
        }
        return $value;
    }

    /**
     * Store a value in the cache with optional TTL in seconds.
     */
    public static function cacheSet(string $key, mixed $value, ?int $ttl = null): void
    {
        self::instance()->set($key, $value, $ttl ?? 0);
    }

    /**
     * Delete a single cached entry. Returns true if the key existed.
     */
    public static function cacheDelete(string $key): bool
    {
        return self::instance()->delete($key);
    }

    /**
     * Remove all cached entries and reset hit/miss counters.
     */
    public static function cacheClear(): void
    {
        self::instance()->clear();
        self::$hits = 0;
        self::$misses = 0;
    }

    /**
     * Alias for cacheClear().
     */
    public static function clearCache(): void
    {
        self::cacheClear();
    }

    /**
     * Return cache statistics.
     *
     * @return array{size: int, hits: int, misses: int, backend: string}
     */
    public static function cacheStats(): array
    {
        return [
            'size'    => self::instance()->size(),
            'hits'    => self::$hits,
            'misses'  => self::$misses,
            'backend' => 'memory',
        ];
    }

    /**
     * Remove all expired entries. Returns the number of entries evicted.
     */
    public static function sweep(): int
    {
        return self::instance()->sweep();
    }
}
