<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * CacheFactory — build a CacheBackend from explicit params or env vars.
 *
 * Mirrors Python's tina4_python.cache._create_backend exactly, including the
 * graceful-degradation behaviour: if a configured network/driver backend is
 * unreachable (or its credentials are wrong), the factory logs a warning and
 * falls back to the file backend (a persistent, zero-dep, always-available
 * cache) rather than silently degrading to a no-op.
 *
 * Env scheme (LOCKED — matches Python):
 *   TINA4_CACHE_BACKEND      memory|file|redis|valkey|memcached|mongodb|database
 *   TINA4_CACHE_URL          connection for redis/valkey/memcached/mongo, OR a
 *                            SQL URL for database (falls back to TINA4_DATABASE_URL)
 *   TINA4_CACHE_TTL          default TTL (60)
 *   TINA4_CACHE_MAX_ENTRIES  max cached entries (1000)
 *   TINA4_CACHE_DIR          file backend directory (data/cache)
 *   TINA4_CACHE_USERNAME / TINA4_CACHE_PASSWORD  credentials for redis/valkey/mongo
 */

namespace Tina4\Cache;

class CacheFactory
{
    /**
     * Build a cache backend.
     *
     * @param string|null $backend    Backend name (defaults to TINA4_CACHE_BACKEND, then "memory").
     * @param string|null $url        Connection URL (defaults per-backend from TINA4_CACHE_URL).
     * @param int|null    $maxEntries Max entries (defaults to TINA4_CACHE_MAX_ENTRIES, then 1000).
     * @param string|null $cacheDir   File cache dir (defaults to TINA4_CACHE_DIR, then data/cache).
     * @param string|null $mongoCollection Optional Mongo collection override (test isolation).
     */
    public static function create(
        ?string $backend = null,
        ?string $url = null,
        ?int $maxEntries = null,
        ?string $cacheDir = null,
        ?string $mongoCollection = null
    ): CacheBackend {
        $backend = $backend ?? (\Tina4\DotEnv::getEnv('TINA4_CACHE_BACKEND') ?? 'memory');
        $maxEntries = $maxEntries ?? (int)(\Tina4\DotEnv::getEnv('TINA4_CACHE_MAX_ENTRIES') ?? '1000');
        $backend = strtolower(trim($backend));

        switch ($backend) {
            case 'redis':
                $url = $url ?? (\Tina4\DotEnv::getEnv('TINA4_CACHE_URL') ?? 'redis://localhost:6379');
                $be = new RedisBackend($url, $maxEntries);
                break;
            case 'valkey':
                $url = $url ?? (\Tina4\DotEnv::getEnv('TINA4_CACHE_URL') ?? 'valkey://localhost:6379');
                $be = new ValkeyBackend($url, $maxEntries);
                break;
            case 'memcached':
            case 'memcache':
                $url = $url ?? (\Tina4\DotEnv::getEnv('TINA4_CACHE_URL') ?? 'memcached://localhost:11211');
                $be = new MemcachedBackend($url, $maxEntries);
                break;
            case 'mongodb':
            case 'mongo':
                $url = $url ?? (\Tina4\DotEnv::getEnv('TINA4_CACHE_URL') ?? 'mongodb://localhost:27017');
                $be = new MongoBackend($url, $maxEntries, $mongoCollection);
                break;
            case 'database':
            case 'db':
                $be = new DatabaseBackend($url, $maxEntries);
                break;
            case 'file':
                $cacheDir = $cacheDir ?? (\Tina4\DotEnv::getEnv('TINA4_CACHE_DIR') ?? 'data/cache');
                return new FileBackend($cacheDir, $maxEntries);
            default:
                return new MemoryBackend($maxEntries);
        }

        // Graceful degradation — fall back to file (persistent, always
        // available) rather than a silent no-op.
        if (!$be->isAvailable()) {
            if (class_exists('\Tina4\Log')) {
                try {
                    \Tina4\Log::warning(
                        "Cache backend '{$backend}' is unavailable "
                        . '(driver missing or service unreachable) — falling back to \'file\'.'
                    );
                } catch (\Throwable) {
                    // never let logging break cache creation
                }
            }
            $cacheDir = $cacheDir ?? (\Tina4\DotEnv::getEnv('TINA4_CACHE_DIR') ?? 'data/cache');
            return new FileBackend($cacheDir, $maxEntries);
        }

        return $be;
    }
}
