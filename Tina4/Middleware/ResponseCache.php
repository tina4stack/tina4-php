<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Response Cache — Multi-backend GET response caching middleware.
 *
 * Public API (parity with Python tina4_python.cache):
 *   - ResponseCache class — used as middleware on a route
 *   - ResponseCache::cacheStats() — static, returns {hits, misses, size, backend, keys}
 *   - ResponseCache::clearCache() — static, flushes all entries
 *   - cache_stats() / cache_clear() — namespace-level convenience wrappers
 *
 * Internal lookup/store of GET responses is performed by the middleware hooks
 * (beforeCache, afterCache) and is NOT exposed publicly. Use the middleware
 * by attaching ResponseCache to your route, not by calling lookup/store directly.
 *
 * Backends are selected via the TINA4_CACHE_BACKEND env var and built by the
 * unified \Tina4\Cache\CacheFactory:
 *   memory    — in-process array cache (default, zero deps)
 *   file      — JSON files in data/cache/
 *   redis     — Redis (ext-redis or raw RESP over TCP)
 *   valkey    — Valkey (Redis wire-protocol; reuses the redis transport)
 *   memcached — Memcached (zero-dep text protocol over a socket)
 *   mongodb   — MongoDB TTL collection
 *   database  — tina4_cache table in any Tina4-supported database
 *
 * When a configured network/driver backend is unreachable, the factory logs a
 * warning and falls back to the FILE backend (a real cache, not a no-op).
 *
 * Environment variables (LOCKED scheme, parity with Python):
 *   TINA4_CACHE_BACKEND      — memory|file|redis|valkey|memcached|mongodb|database
 *   TINA4_CACHE_URL           — connection for redis/valkey/memcached/mongo, OR a
 *                               SQL URL for database (falls back to TINA4_DATABASE_URL)
 *   TINA4_CACHE_TTL           — default TTL in seconds  (default: 60, 0 = disabled)
 *   TINA4_CACHE_MAX_ENTRIES   — maximum cache entries   (default: 1000)
 *   TINA4_CACHE_DIR           — file backend directory  (default: data/cache)
 *   TINA4_CACHE_USERNAME / TINA4_CACHE_PASSWORD — redis/valkey/mongo credentials
 */

namespace Tina4\Middleware;

use Tina4\Cache\CacheBackend;
use Tina4\Cache\CacheFactory;

class ResponseCache
{
    /** @var int Default TTL in seconds */
    private int $ttl;

    /** @var int Maximum cache entries */
    private int $maxEntries;

    /** @var int[] Status codes to cache */
    private array $statusCodes;

    /** @var string Active backend name */
    private string $backend;

    /** @var string Connection URL (redis/valkey/memcached/mongo or SQL for database) */
    private string $cacheUrl;

    /** @var string File cache directory */
    private string $cacheDir;

    /** @var CacheBackend The unified pluggable backend */
    private CacheBackend $backendImpl;

    /** @var int Hit counter */
    private static int $hits = 0;

    /** @var int Miss counter */
    private static int $misses = 0;

    /**
     * @param array $config Configuration overrides:
     *   'ttl'         => int    Default TTL in seconds (default: 60)
     *   'maxEntries'  => int    Maximum cache entries (default: 1000)
     *   'statusCodes' => int[]  Status codes to cache (default: [200])
     *   'backend'     => string Cache backend (memory|file|redis|valkey|memcached|mongodb|database)
     *   'cacheUrl'    => string Connection URL
     *   'cacheDir'    => string File cache directory
     */
    public function __construct(array $config = [])
    {
        $envTtl = getenv('TINA4_CACHE_TTL');
        $envMax = getenv('TINA4_CACHE_MAX_ENTRIES');
        $envBackend = getenv('TINA4_CACHE_BACKEND');
        $envUrl = getenv('TINA4_CACHE_URL');
        $envDir = getenv('TINA4_CACHE_DIR');

        $this->ttl = $config['ttl'] ?? ($envTtl !== false ? (int)$envTtl : 60);
        $this->maxEntries = $config['maxEntries'] ?? ($envMax !== false ? (int)$envMax : 1000);
        $this->statusCodes = $config['statusCodes'] ?? [200];
        $this->backend = $config['backend'] ?? ($envBackend !== false ? strtolower(trim($envBackend)) : 'memory');
        $this->cacheUrl = $config['cacheUrl'] ?? ($envUrl !== false ? $envUrl : '');
        $this->cacheDir = $config['cacheDir'] ?? ($envDir !== false ? $envDir : 'data/cache');

        // Build the unified backend. The factory handles availability probing
        // and graceful file-fallback, and reports the real backend name (so a
        // redis backend that fell back will report "file").
        $this->backendImpl = CacheFactory::create(
            backend: $this->backend,
            url: $this->cacheUrl !== '' ? $this->cacheUrl : null,
            maxEntries: $this->maxEntries,
            cacheDir: $this->cacheDir,
        );
        // Reflect the actual backend chosen (post-fallback).
        $this->backend = $this->backendImpl->name();
    }

    // ── Middleware hooks ─────────────────────────────────────────

    /**
     * Middleware hook — checks for a cached entry before the route handler runs.
     *
     * If a valid cached entry exists for this GET request, short-circuits
     * by returning the cached body via the response callable. Otherwise
     * tags the request so afterCache() can capture the response.
     *
     * @param object $request  Tina4 request (must expose ->method and ->url or similar)
     * @param object $response Tina4 response object
     * @return array{0: object, 1: object}
     */
    public function beforeCache(object $request, object $response): array
    {
        if ($this->ttl <= 0) {
            return [$request, $response];
        }

        $method = strtoupper((string)($request->method ?? 'GET'));
        if ($method !== 'GET') {
            return [$request, $response];
        }

        $url = (string)($request->url ?? $request->path ?? '/');
        $hit = $this->internalLookup($method, $url);
        if ($hit !== null) {
            // Replay the cached response
            if (is_callable($response)) {
                $response = $response($hit['body'], $hit['statusCode'], $hit['contentType']);
            }
            return [$request, $response];
        }

        // Tag for afterCache
        $request->_cacheKey = $this->cacheKey($method, $url);
        return [$request, $response];
    }

    /**
     * Middleware hook — captures the response body and stores it after the
     * route handler runs.
     *
     * @param object $request
     * @param object $response
     * @return array{0: object, 1: object}
     */
    public function afterCache(object $request, object $response): array
    {
        if ($this->ttl <= 0) {
            return [$request, $response];
        }

        $cacheKey = $request->_cacheKey ?? null;
        if ($cacheKey === null) {
            return [$request, $response];
        }

        $statusCode = (int)($response->statusCode ?? $response->httpCode ?? 200);
        if (!in_array($statusCode, $this->statusCodes, true)) {
            return [$request, $response];
        }

        $body = (string)($response->body ?? $response->content ?? '');
        $contentType = (string)($response->contentType ?? 'application/json');

        $entry = [
            'body' => $body,
            'contentType' => $contentType,
            'statusCode' => $statusCode,
            'expiresAt' => microtime(true) + $this->ttl,
        ];
        $this->backendImpl->set($cacheKey, $entry, $this->ttl);

        return [$request, $response];
    }

    // ── Internal lookup / store (response cache, NOT public) ─────

    /**
     * @internal Used by middleware hooks only.
     */
    private function internalLookup(string $method, string $url): ?array
    {
        if (strtoupper($method) !== 'GET') {
            return null;
        }

        if ($this->ttl <= 0) {
            return null;
        }

        $key = $this->cacheKey($method, $url);
        $entry = $this->backendImpl->get($key);

        if (!is_array($entry)) {
            self::$misses++;
            return null;
        }

        if (isset($entry['expiresAt']) && microtime(true) > $entry['expiresAt']) {
            $this->backendImpl->delete($key);
            self::$misses++;
            return null;
        }

        self::$hits++;
        return [
            'body' => $entry['body'] ?? '',
            'contentType' => $entry['contentType'] ?? 'application/json',
            'statusCode' => $entry['statusCode'] ?? 200,
        ];
    }

    /**
     * @internal Used by middleware hooks only.
     */
    private function internalStore(string $method, string $url, string $body, string $contentType, int $statusCode): void
    {
        if (strtoupper($method) !== 'GET') {
            return;
        }

        if ($this->ttl <= 0) {
            return;
        }

        if (!in_array($statusCode, $this->statusCodes, true)) {
            return;
        }

        $key = $this->cacheKey($method, $url);
        $entry = [
            'body' => $body,
            'contentType' => $contentType,
            'statusCode' => $statusCode,
            'expiresAt' => microtime(true) + $this->ttl,
        ];

        $this->backendImpl->set($key, $entry, $this->ttl);
    }

    // ── Internal direct KV (used by namespace-level cache_get/set/delete) ──

    /**
     * @internal Used by namespace-level cache_get().
     */
    private function internalGet(string $key): mixed
    {
        $entry = $this->backendImpl->get('direct:' . $key);
        if (!is_array($entry)) {
            self::$misses++;
            return null;
        }
        if (isset($entry['expiresAt']) && microtime(true) > $entry['expiresAt']) {
            $this->backendImpl->delete('direct:' . $key);
            self::$misses++;
            return null;
        }
        self::$hits++;
        return $entry['value'] ?? null;
    }

    /**
     * @internal Used by namespace-level cache_set().
     */
    private function internalSet(string $key, mixed $value, int $ttl = 0): void
    {
        $effectiveTtl = $ttl > 0 ? $ttl : $this->ttl;
        $entry = [
            'value' => $value,
            'expiresAt' => $effectiveTtl > 0 ? microtime(true) + $effectiveTtl : null,
        ];
        $this->backendImpl->set('direct:' . $key, $entry, $effectiveTtl);
    }

    /**
     * @internal Used by namespace-level cache_delete().
     */
    private function internalDelete(string $key): bool
    {
        return $this->backendImpl->delete('direct:' . $key);
    }

    // ── Public management methods ────────────────────────────────

    /**
     * Get cache statistics for this instance.
     *
     * @return array{hits: int, misses: int, size: int, backend: string, keys: string[]}
     */
    public function getStats(): array
    {
        $stats = $this->backendImpl->stats();

        return [
            'hits' => self::$hits,
            'misses' => self::$misses,
            'size' => $stats['size'] ?? 0,
            'backend' => $stats['backend'] ?? $this->backend,
            'keys' => [],
        ];
    }

    /**
     * Clear all cached responses for this instance and reset stats.
     */
    public function clear(): void
    {
        self::$hits = 0;
        self::$misses = 0;
        $this->backendImpl->clear();
    }

    /**
     * Remove expired entries from the cache.
     *
     * Delegates to the backend's sweep(): in-process backends (memory/file)
     * actively reap expired entries and report the count; network/driver
     * backends expire server-side via TTL and report 0.
     *
     * @return int Number of entries removed
     */
    public function sweep(): int
    {
        return $this->backendImpl->sweep();
    }

    /**
     * Get the active backend name (post-fallback).
     */
    public function getBackend(): string
    {
        return $this->backendImpl->name();
    }

    // ── Static module-level API (parity with Python) ─────────────

    /**
     * Return cache statistics from the lazy module-level singleton.
     *
     * Mirrors Python's tina4_python.cache.cache_stats().
     *
     * @return array{hits: int, misses: int, size: int, backend: string, keys: string[]}
     */
    public static function cacheStats(): array
    {
        return cache_instance()->getStats();
    }

    /**
     * Flush all cached entries on the lazy module-level singleton.
     *
     * Mirrors Python's tina4_python.cache.clear_cache().
     */
    public static function clearCache(): void
    {
        cache_instance()->clear();
    }

    // ── Internal accessors used by namespace functions ───────────

    /**
     * @internal Wrapper used by namespace-level cache_get().
     */
    public function _internalGet(string $key): mixed
    {
        return $this->internalGet($key);
    }

    /**
     * @internal Wrapper used by namespace-level cache_set().
     */
    public function _internalSet(string $key, mixed $value, int $ttl = 0): void
    {
        $this->internalSet($key, $value, $ttl);
    }

    /**
     * @internal Wrapper used by namespace-level cache_delete().
     */
    public function _internalDelete(string $key): bool
    {
        return $this->internalDelete($key);
    }

    /**
     * @internal Wrapper used by tests to verify middleware behaviour.
     */
    public function _internalLookup(string $method, string $url): ?array
    {
        return $this->internalLookup($method, $url);
    }

    /**
     * @internal Wrapper used by tests to verify middleware behaviour.
     */
    public function _internalStore(string $method, string $url, string $body, string $contentType, int $statusCode): void
    {
        $this->internalStore($method, $url, $body, $contentType, $statusCode);
    }

    /**
     * Build a cache key from method + URL.
     */
    private function cacheKey(string $method, string $url): string
    {
        return strtoupper($method) . ':' . $url;
    }
}

// ── Module-level convenience functions ──────────────────────────
// The namespace-level cache_instance()/cache_get()/cache_set()/cache_delete()/
// cache_clear()/cache_stats() helpers live in CacheFunctions.php so they are
// available via composer `files` autoload without first touching this class.
