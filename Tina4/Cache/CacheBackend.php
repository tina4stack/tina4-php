<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * CacheBackend — abstract pluggable cache backend interface.
 *
 * Mirrors Python's tina4_python.cache._CacheBackend exactly. Backends are
 * selected via the TINA4_CACHE_BACKEND env var and built by CacheFactory:
 *
 *   memory    — in-process array cache (default, zero deps)
 *   file      — JSON files in data/cache/
 *   redis     — Redis (ext-redis or raw RESP over TCP)
 *   valkey    — Valkey (Redis wire-protocol; reuses the redis transport)
 *   memcached — Memcached (zero-dep text protocol over a socket)
 *   mongodb   — MongoDB TTL collection (reuses the session handler transport)
 *   database  — tina4_cache table in any Tina4-supported database
 *
 * Each backend stores/retrieves arbitrary JSON-serialisable values keyed by
 * a string. Network/driver backends report availability via isAvailable() so
 * the factory can gracefully fall back to the file backend when the configured
 * service is unreachable.
 */

namespace Tina4\Cache;

abstract class CacheBackend
{
    /**
     * Get a value from the cache by key. Returns null on miss/expiry.
     */
    abstract public function get(string $key): mixed;

    /**
     * Store a value with a TTL in seconds (ttl <= 0 means no expiry).
     */
    abstract public function set(string $key, mixed $value, int $ttl): void;

    /**
     * Delete a key. Returns true if it existed.
     */
    abstract public function delete(string $key): bool;

    /**
     * Flush all entries and reset stats.
     */
    abstract public function clear(): void;

    /**
     * Return cache statistics.
     *
     * @return array{hits: int, misses: int, size: int, backend: string}
     */
    abstract public function stats(): array;

    /**
     * The backend's reported name (memory|file|redis|valkey|memcached|mongodb|database).
     */
    abstract public function name(): string;

    /**
     * Whether this backend is actually usable (driver present + service
     * reachable). Local backends are always available; network/driver
     * backends override this so the factory can fall back to the file backend.
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Remove expired entries and return the count removed.
     *
     * Network/driver backends (redis/valkey/memcached/mongo/database) expire
     * entries server-side via TTL, so the default is a no-op returning 0.
     * In-process backends (memory/file) override this.
     */
    public function sweep(): int
    {
        return 0;
    }
}
