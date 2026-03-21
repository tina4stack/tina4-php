<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Response Cache Middleware — caches GET responses in memory with TTL.
 * Matches the Node.js tina4-nodejs/packages/core/src/cache.ts implementation.
 *
 * Environment variables:
 *   TINA4_CACHE_TTL         — default TTL in seconds (default: 60, 0 = disabled)
 *   TINA4_CACHE_MAX_ENTRIES — maximum cache entries (default: 1000)
 */

namespace Tina4\Middleware;

class ResponseCache
{
    /** @var array<string, array{body: string, contentType: string, statusCode: int, expiresAt: float}> */
    private static array $store = [];

    /** @var int Default TTL in seconds */
    private int $ttl;

    /** @var int Maximum cache entries */
    private int $maxEntries;

    /** @var int[] Status codes to cache */
    private array $statusCodes;

    /**
     * @param array $config Configuration overrides:
     *   'ttl'         => int    Default TTL in seconds (default: 60)
     *   'maxEntries'  => int    Maximum cache entries (default: 1000)
     *   'statusCodes' => int[]  Status codes to cache (default: [200])
     */
    public function __construct(array $config = [])
    {
        $envTtl = getenv('TINA4_CACHE_TTL');
        $envMax = getenv('TINA4_CACHE_MAX_ENTRIES');

        $this->ttl = $config['ttl'] ?? ($envTtl !== false ? (int)$envTtl : 60);
        $this->maxEntries = $config['maxEntries'] ?? ($envMax !== false ? (int)$envMax : 1000);
        $this->statusCodes = $config['statusCodes'] ?? [200];
    }

    /**
     * Check if a cache entry exists for the given request.
     * Returns the cached entry or null.
     *
     * @param string $method HTTP method
     * @param string $url    Request URL (including query string)
     * @return array|null Cached entry with 'body', 'contentType', 'statusCode' or null
     */
    public function lookup(string $method, string $url): ?array
    {
        // Only cache GET requests
        if (strtoupper($method) !== 'GET') {
            return null;
        }

        if ($this->ttl <= 0) {
            return null;
        }

        $key = $this->cacheKey($method, $url);

        if (!isset(self::$store[$key])) {
            return null;
        }

        $entry = self::$store[$key];

        if (microtime(true) > $entry['expiresAt']) {
            unset(self::$store[$key]);
            return null;
        }

        return [
            'body' => $entry['body'],
            'contentType' => $entry['contentType'],
            'statusCode' => $entry['statusCode'],
        ];
    }

    /**
     * Store a response in the cache.
     *
     * @param string $method      HTTP method
     * @param string $url         Request URL
     * @param string $body        Response body
     * @param string $contentType Response content type
     * @param int    $statusCode  HTTP status code
     */
    public function store(string $method, string $url, string $body, string $contentType, int $statusCode): void
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

        // Evict oldest if at capacity
        if (count(self::$store) >= $this->maxEntries) {
            $firstKey = array_key_first(self::$store);
            if ($firstKey !== null) {
                unset(self::$store[$firstKey]);
            }
        }

        $key = $this->cacheKey($method, $url);
        self::$store[$key] = [
            'body' => $body,
            'contentType' => $contentType,
            'statusCode' => $statusCode,
            'expiresAt' => microtime(true) + $this->ttl,
        ];
    }

    /**
     * Get cache statistics.
     *
     * @return array{size: int, keys: string[]}
     */
    public function cacheStats(): array
    {
        // Clean expired entries first
        $this->sweep();

        return [
            'size' => count(self::$store),
            'keys' => array_keys(self::$store),
        ];
    }

    /**
     * Clear all cached responses.
     */
    public function clearCache(): void
    {
        self::$store = [];
    }

    /**
     * Remove expired entries from the cache.
     *
     * @return int Number of entries removed
     */
    public function sweep(): int
    {
        $removed = 0;
        $now = microtime(true);

        foreach (self::$store as $key => $entry) {
            if ($now > $entry['expiresAt']) {
                unset(self::$store[$key]);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Build a cache key from method + URL.
     */
    private function cacheKey(string $method, string $url): string
    {
        return strtoupper($method) . ':' . $url;
    }
}
