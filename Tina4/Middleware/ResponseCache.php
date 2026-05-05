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
 * Backends are selected via the TINA4_CACHE_BACKEND env var:
 *   memory — in-process array cache (default, zero deps)
 *   redis  — Redis / Valkey (uses ext-redis or raw RESP over TCP)
 *   file   — JSON files in data/cache/
 *
 * Environment variables:
 *   TINA4_CACHE_BACKEND      — memory | redis | file  (default: memory)
 *   TINA4_CACHE_URL           — redis://localhost:6379  (redis only)
 *   TINA4_CACHE_TTL           — default TTL in seconds  (default: 60, 0 = disabled)
 *   TINA4_CACHE_MAX_ENTRIES   — maximum cache entries   (default: 1000)
 */

namespace Tina4\Middleware;

class ResponseCache
{
    // ── Backend interface constants ──────────────────────────────

    private const BACKEND_MEMORY = 'memory';
    private const BACKEND_REDIS = 'redis';
    private const BACKEND_FILE = 'file';

    /** @var array<string, array{body: string, contentType: string, statusCode: int, expiresAt: float}> */
    private static array $memoryStore = [];

    /** @var int Default TTL in seconds */
    private int $ttl;

    /** @var int Maximum cache entries */
    private int $maxEntries;

    /** @var int[] Status codes to cache */
    private array $statusCodes;

    /** @var string Active backend name */
    private string $backend;

    /** @var string Redis URL */
    private string $redisUrl;

    /** @var string File cache directory */
    private string $cacheDir;

    /** @var \Redis|null Redis extension client */
    private ?\Redis $redisClient = null;

    /** @var int Hit counter */
    private static int $hits = 0;

    /** @var int Miss counter */
    private static int $misses = 0;

    /**
     * @param array $config Configuration overrides:
     *   'ttl'         => int    Default TTL in seconds (default: 60)
     *   'maxEntries'  => int    Maximum cache entries (default: 1000)
     *   'statusCodes' => int[]  Status codes to cache (default: [200])
     *   'backend'     => string Cache backend: memory|redis|file
     *   'cacheUrl'    => string Redis URL
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
        $this->backend = $config['backend'] ?? ($envBackend !== false ? strtolower(trim($envBackend)) : self::BACKEND_MEMORY);
        $this->redisUrl = $config['cacheUrl'] ?? ($envUrl !== false ? $envUrl : 'redis://localhost:6379');
        $this->cacheDir = $config['cacheDir'] ?? ($envDir !== false ? $envDir : 'data/cache');

        // Initialize backend
        if ($this->backend === self::BACKEND_REDIS) {
            $this->initRedis();
        } elseif ($this->backend === self::BACKEND_FILE) {
            $this->initFileDir();
        }
    }

    // ── Backend initialization ───────────────────────────────────

    private function initRedis(): void
    {
        if (extension_loaded('redis')) {
            try {
                $parsed = $this->parseRedisUrl($this->redisUrl);
                $this->redisClient = new \Redis();
                $this->redisClient->connect($parsed['host'], $parsed['port'], 5.0);
                if ($parsed['db'] > 0) {
                    $this->redisClient->select($parsed['db']);
                }
            } catch (\Exception $e) {
                $this->redisClient = null;
            }
        }
    }

    private function parseRedisUrl(string $url): array
    {
        $cleaned = str_replace('redis://', '', $url);
        $parts = explode(':', $cleaned);
        $host = $parts[0] ?: 'localhost';
        $portAndDb = isset($parts[1]) ? explode('/', $parts[1]) : ['6379'];
        $port = (int)($portAndDb[0] ?: 6379);
        $db = isset($portAndDb[1]) ? (int)$portAndDb[1] : 0;
        return ['host' => $host, 'port' => $port, 'db' => $db];
    }

    private function initFileDir(): void
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
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
        $this->backendSet($cacheKey, $entry, $this->ttl);

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
        $entry = $this->backendGet($key);

        if ($entry === null) {
            self::$misses++;
            return null;
        }

        if (microtime(true) > $entry['expiresAt']) {
            $this->backendDelete($key);
            self::$misses++;
            return null;
        }

        self::$hits++;
        return [
            'body' => $entry['body'],
            'contentType' => $entry['contentType'],
            'statusCode' => $entry['statusCode'],
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

        $this->backendSet($key, $entry, $this->ttl);
    }

    // ── Internal direct KV (used by namespace-level cache_get/set/delete) ──

    /**
     * @internal Used by namespace-level cache_get().
     */
    private function internalGet(string $key): mixed
    {
        $entry = $this->backendGet('direct:' . $key);
        if ($entry === null) {
            self::$misses++;
            return null;
        }
        if (isset($entry['expiresAt']) && microtime(true) > $entry['expiresAt']) {
            $this->backendDelete('direct:' . $key);
            self::$misses++;
            return null;
        }
        self::$hits++;
        return $entry['value'] ?? $entry;
    }

    /**
     * @internal Used by namespace-level cache_set().
     */
    private function internalSet(string $key, mixed $value, int $ttl = 0): void
    {
        $effectiveTtl = $ttl > 0 ? $ttl : $this->ttl;
        $entry = [
            'value' => $value,
            'expiresAt' => $effectiveTtl > 0 ? microtime(true) + $effectiveTtl : PHP_FLOAT_MAX,
        ];
        $this->backendSet('direct:' . $key, $entry, $effectiveTtl);
    }

    /**
     * @internal Used by namespace-level cache_delete().
     */
    private function internalDelete(string $key): bool
    {
        return $this->backendDelete('direct:' . $key);
    }

    // ── Backend operations ───────────────────────────────────────

    private function backendGet(string $key): ?array
    {
        switch ($this->backend) {
            case self::BACKEND_REDIS:
                return $this->redisGet($key);
            case self::BACKEND_FILE:
                return $this->fileGet($key);
            default:
                return self::$memoryStore[$key] ?? null;
        }
    }

    private function backendSet(string $key, array $entry, int $ttl): void
    {
        switch ($this->backend) {
            case self::BACKEND_REDIS:
                $this->redisSet($key, $entry, $ttl);
                break;
            case self::BACKEND_FILE:
                $this->fileSet($key, $entry, $ttl);
                break;
            default:
                // Evict oldest if at capacity
                if (count(self::$memoryStore) >= $this->maxEntries) {
                    $firstKey = array_key_first(self::$memoryStore);
                    if ($firstKey !== null) {
                        unset(self::$memoryStore[$firstKey]);
                    }
                }
                self::$memoryStore[$key] = $entry;
                break;
        }
    }

    private function backendDelete(string $key): bool
    {
        switch ($this->backend) {
            case self::BACKEND_REDIS:
                return $this->redisDelete($key);
            case self::BACKEND_FILE:
                return $this->fileDelete($key);
            default:
                if (isset(self::$memoryStore[$key])) {
                    unset(self::$memoryStore[$key]);
                    return true;
                }
                return false;
        }
    }

    // ── Redis backend operations ─────────────────────────────────

    private function redisGet(string $key): ?array
    {
        $fullKey = 'tina4:cache:' . $key;

        if ($this->redisClient !== null) {
            try {
                $raw = $this->redisClient->get($fullKey);
                if ($raw === false) {
                    return null;
                }
                return json_decode($raw, true);
            } catch (\Exception $e) {
                return null;
            }
        }

        // Fallback: raw RESP over TCP
        return $this->respGet($fullKey);
    }

    private function redisSet(string $key, array $entry, int $ttl): void
    {
        $fullKey = 'tina4:cache:' . $key;
        $serialized = json_encode($entry);

        if ($this->redisClient !== null) {
            try {
                if ($ttl > 0) {
                    $this->redisClient->setex($fullKey, $ttl, $serialized);
                } else {
                    $this->redisClient->set($fullKey, $serialized);
                }
            } catch (\Exception $e) {
            }
            return;
        }

        // Fallback: raw RESP
        if ($ttl > 0) {
            $this->respCommand('SETEX', $fullKey, (string)$ttl, $serialized);
        } else {
            $this->respCommand('SET', $fullKey, $serialized);
        }
    }

    private function redisDelete(string $key): bool
    {
        $fullKey = 'tina4:cache:' . $key;

        if ($this->redisClient !== null) {
            try {
                return $this->redisClient->del($fullKey) > 0;
            } catch (\Exception $e) {
                return false;
            }
        }

        $result = $this->respCommand('DEL', $fullKey);
        return $result === '1';
    }

    private function respGet(string $key): ?array
    {
        $result = $this->respCommand('GET', $key);
        if ($result === null) {
            return null;
        }
        $decoded = json_decode($result, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function respCommand(string ...$args): ?string
    {
        try {
            $parsed = $this->parseRedisUrl($this->redisUrl);
            $cmd = '*' . count($args) . "\r\n";
            foreach ($args as $arg) {
                $cmd .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
            }

            $sock = @fsockopen($parsed['host'], $parsed['port'], $errno, $errstr, 5);
            if (!$sock) {
                return null;
            }

            if ($parsed['db'] > 0) {
                $selectCmd = "*2\r\n\$6\r\nSELECT\r\n\$" . strlen((string)$parsed['db']) . "\r\n" . $parsed['db'] . "\r\n";
                fwrite($sock, $selectCmd);
                fread($sock, 1024);
            }

            fwrite($sock, $cmd);
            $response = fread($sock, 65536);
            fclose($sock);

            if ($response === false) {
                return null;
            }

            if (str_starts_with($response, '+')) {
                return trim(substr($response, 1));
            } elseif (str_starts_with($response, '$-1')) {
                return null;
            } elseif (str_starts_with($response, '$')) {
                $lines = explode("\r\n", $response);
                return $lines[1] ?? null;
            } elseif (str_starts_with($response, ':')) {
                return trim(substr($response, 1));
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    // ── File backend operations ──────────────────────────────────

    private function fileKeyPath(string $key): string
    {
        return $this->cacheDir . '/' . hash('sha256', $key) . '.json';
    }

    private function fileGet(string $key): ?array
    {
        $path = $this->fileKeyPath($key);
        if (!file_exists($path)) {
            return null;
        }
        try {
            $data = json_decode(file_get_contents($path), true);
            if (!is_array($data)) {
                return null;
            }
            if (isset($data['expiresAt']) && microtime(true) > $data['expiresAt']) {
                @unlink($path);
                return null;
            }
            return $data;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function fileSet(string $key, array $entry, int $ttl): void
    {
        $this->initFileDir();

        // Evict oldest if at capacity
        $files = glob($this->cacheDir . '/*.json');
        if ($files !== false && count($files) >= $this->maxEntries) {
            usort($files, fn($a, $b) => filemtime($a) <=> filemtime($b));
            @unlink($files[0]);
        }

        $path = $this->fileKeyPath($key);
        @file_put_contents($path, json_encode($entry));
    }

    private function fileDelete(string $key): bool
    {
        $path = $this->fileKeyPath($key);
        if (file_exists($path)) {
            return @unlink($path);
        }
        return false;
    }

    // ── Public management methods ────────────────────────────────

    /**
     * Get cache statistics for this instance.
     *
     * @return array{hits: int, misses: int, size: int, backend: string, keys: string[]}
     */
    public function getStats(): array
    {
        $this->sweep();

        $size = 0;
        $keys = [];

        switch ($this->backend) {
            case self::BACKEND_REDIS:
                if ($this->redisClient !== null) {
                    try {
                        $redisKeys = $this->redisClient->keys('tina4:cache:*');
                        $size = count($redisKeys);
                        $keys = $redisKeys;
                    } catch (\Exception $e) {
                    }
                }
                break;
            case self::BACKEND_FILE:
                $files = glob($this->cacheDir . '/*.json');
                $size = $files !== false ? count($files) : 0;
                break;
            default:
                $size = count(self::$memoryStore);
                $keys = array_keys(self::$memoryStore);
                break;
        }

        return [
            'hits' => self::$hits,
            'misses' => self::$misses,
            'size' => $size,
            'backend' => $this->backend,
            'keys' => $keys,
        ];
    }

    /**
     * Clear all cached responses for this instance and reset stats.
     */
    public function clear(): void
    {
        self::$hits = 0;
        self::$misses = 0;

        switch ($this->backend) {
            case self::BACKEND_REDIS:
                if ($this->redisClient !== null) {
                    try {
                        $keys = $this->redisClient->keys('tina4:cache:*');
                        if (!empty($keys)) {
                            $this->redisClient->del(...$keys);
                        }
                    } catch (\Exception $e) {
                    }
                }
                break;
            case self::BACKEND_FILE:
                $files = glob($this->cacheDir . '/*.json');
                if ($files !== false) {
                    foreach ($files as $file) {
                        @unlink($file);
                    }
                }
                break;
            default:
                self::$memoryStore = [];
                break;
        }
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

        switch ($this->backend) {
            case self::BACKEND_FILE:
                $files = glob($this->cacheDir . '/*.json');
                if ($files !== false) {
                    foreach ($files as $file) {
                        try {
                            $data = json_decode(file_get_contents($file), true);
                            if (is_array($data) && isset($data['expiresAt']) && $now > $data['expiresAt']) {
                                @unlink($file);
                                $removed++;
                            }
                        } catch (\Exception $e) {
                        }
                    }
                }
                break;
            default:
                foreach (self::$memoryStore as $key => $entry) {
                    if (isset($entry['expiresAt']) && $now > $entry['expiresAt']) {
                        unset(self::$memoryStore[$key]);
                        $removed++;
                    }
                }
                break;
        }

        return $removed;
    }

    /**
     * Get the active backend name.
     */
    public function getBackend(): string
    {
        return $this->backend;
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
