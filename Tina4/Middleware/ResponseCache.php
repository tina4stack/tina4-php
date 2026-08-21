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
        $hit = $this->internalLookup($method, $url, $request);
        if ($hit !== null) {
            // Replay the cached response and mark it as a cache HIT.
            if (is_callable($response)) {
                $response = $response($hit['body'], $hit['statusCode'], $hit['contentType']);
            }
            $this->markCacheHeaders($response, 'HIT');
            return [$request, $response];
        }

        // Stamp a provisional MISS header. afterCache RECOMPUTES the key from
        // the request rather than reading a tag written here: writing
        // `$request->_cacheKey` created a dynamic property on Tina4\Request,
        // which PHP 8.2 deprecates and PHP 9 makes an Error.
        $this->markCacheHeaders($response, 'MISS');
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

        $method = strtoupper((string)($request->method ?? 'GET'));
        if ($method !== 'GET') {
            return [$request, $response];
        }

        // This response came OUT of the cache; re-storing it would also
        // overwrite its X-Cache: HIT header with MISS.
        if ($this->headerValue($response, 'X-Cache') === 'HIT') {
            return [$request, $response];
        }

        $cacheKey = $this->cacheKey($method, (string)($request->url ?? $request->path ?? '/'));

        $statusCode = $this->extractStatus($response);
        if (!in_array($statusCode, $this->statusCodes, true)) {
            return [$request, $response];
        }

        if (!$this->mayStore($request, $response)) {
            return [$request, $response];
        }

        $body = $this->extractBody($response);
        $contentType = $this->extractContentType($response);

        $entry = [
            'body' => $body,
            'contentType' => $contentType,
            'statusCode' => $statusCode,
            'expiresAt' => microtime(true) + $this->ttl,
        ] + $this->varyEntry($request, $response);
        $this->backendImpl->set($cacheKey, $entry, $this->ttl);

        // This response was produced by the handler (not served from cache),
        // so it is a MISS — stamp the X-Cache headers the dispatcher returns.
        $this->markCacheHeaders($response, 'MISS');

        return [$request, $response];
    }

    /**
     * Static before-hook, so `->middleware([ResponseCache::class])` works.
     *
     * `Middleware::discoverMethods()` only collects PUBLIC STATIC methods, and
     * beforeCache()/afterCache() are instance methods -- so attaching the class
     * discovered no hooks at all and the cache was a SILENT no-op: no warning,
     * no header, no caching. These two delegate to the module-level singleton
     * (the same instance `\Tina4\Middleware\cache_get()` uses), which reads its
     * TTL and backend from the environment.
     *
     * @param object $request  Tina4 request
     * @param object $response Tina4 response
     * @return array{0: object, 1: object}
     */
    public static function beforeResponseCache(object $request, object $response): object|array
    {
        $cache = cache_instance();
        [$request, $out] = $cache->beforeCache($request, $response);
        // Returning the Response OBJECT is what short-circuits
        // (Middleware::applyHookResult: `$result instanceof Response` wins).
        // Returning the pair only rebinds and continues, so the handler would
        // still run and the cache would never save a call. Identity is no help
        // here -- Response::__invoke() returns $this, so the replayed response
        // IS the one passed in; the stamped X-Cache is the reliable signal.
        if ($out instanceof \Tina4\Response && $cache->headerOf($out, 'X-Cache') === 'HIT') {
            return $out;
        }
        return [$request, $out];
    }

    /**
     * Static after-hook counterpart to {@see beforeResponseCache()}.
     *
     * @param object $request  Tina4 request
     * @param object $response Tina4 response
     * @return array{0: object, 1: object}
     */
    public static function afterResponseCache(object $request, object $response): array
    {
        return cache_instance()->afterCache($request, $response);
    }

    /**
     * Express-style continuation — the entry point the router uses when this
     * middleware is attached as a string spec ("ResponseCache:300").
     *
     * Self-contained: it performs the lookup, short-circuits on a HIT (skipping
     * the handler), or runs the handler via $next and stores the result on a
     * MISS — all without mutating the Request (unlike the before/after hook
     * pair, which tags request->_cacheKey). Sets X-Cache / X-Cache-TTL on the
     * response it returns in every path.
     *
     * @param object   $request  Tina4 request
     * @param object   $response Tina4 response
     * @param callable $next     Continuation that runs the rest of the chain
     * @return object The (possibly cached) response
     */
    public function handle(object $request, object $response, callable $next): object
    {
        // Non-cacheable (disabled or non-GET) → straight through, no headers.
        $method = strtoupper((string)($request->method ?? 'GET'));
        if ($this->ttl <= 0 || $method !== 'GET') {
            return $this->normaliseResponse($next($request, $response), $response);
        }

        $url = (string)($request->url ?? $request->path ?? '/');
        $hit = $this->internalLookup($method, $url, $request);
        if ($hit !== null) {
            // HIT — replay the cached body and stamp X-Cache: HIT.
            if (is_callable($response)) {
                $response = $response($hit['body'], $hit['statusCode'], $hit['contentType']);
            }
            $this->markCacheHeaders($response, 'HIT');
            return $response;
        }

        // MISS — run the handler, store the result, stamp X-Cache: MISS.
        $finalResponse = $this->normaliseResponse($next($request, $response), $response);

        $statusCode = $this->extractStatus($finalResponse);
        if (in_array($statusCode, $this->statusCodes, true) && $this->mayStore($request, $finalResponse)) {
            $this->backendImpl->set($this->cacheKey($method, $url), [
                'body' => $this->extractBody($finalResponse),
                'contentType' => $this->extractContentType($finalResponse),
                'statusCode' => $statusCode,
                'expiresAt' => microtime(true) + $this->ttl,
            ] + $this->varyEntry($request, $finalResponse), $this->ttl);
        }
        $this->markCacheHeaders($finalResponse, 'MISS');
        return $finalResponse;
    }

    /**
     * Normalise a handler return value (Response | array | string | other)
     * into a Response with a populated body, mirroring the router dispatcher
     * so the cache always stores real content.
     */
    private function normaliseResponse(mixed $result, object $response): object
    {
        if (is_object($result) && method_exists($result, 'getBody')) {
            return $result; // already a Response
        }
        if (is_array($result) && method_exists($response, 'json')) {
            return $response->json($result);
        }
        if (is_string($result) && method_exists($response, 'html')) {
            return $response->html($result);
        }
        return $response;
    }

    /**
     * Stamp X-Cache / X-Cache-TTL headers on a response. Best-effort: uses
     * Response::header() when available, else writes a public `$headers`
     * array. No Cache-Control header is set (parity with Python/Ruby).
     */
    private function markCacheHeaders(object $response, string $state): void
    {
        if (method_exists($response, 'header')) {
            $response->header('X-Cache', $state);
            $response->header('X-Cache-TTL', (string)$this->ttl);
            return;
        }
        if (isset($response->headers) && is_array($response->headers)) {
            $response->headers['X-Cache'] = $state;
            $response->headers['X-Cache-TTL'] = (string)$this->ttl;
        }
    }

    /**
     * Best-effort body extraction. Response stores its body privately and
     * exposes it via getBody(); fall back to public body/content props.
     */
    private function extractBody(object $response): string
    {
        if (method_exists($response, 'getBody')) {
            return (string)$response->getBody();
        }
        return (string)($response->body ?? $response->content ?? '');
    }

    /**
     * Best-effort content-type extraction (getContentType(), then props).
     */
    private function extractContentType(object $response): string
    {
        if (method_exists($response, 'getContentType')) {
            $ct = $response->getContentType();
            if ($ct !== null && $ct !== '') {
                return (string)$ct;
            }
        }
        return (string)($response->contentType ?? 'application/json');
    }

    /**
     * Best-effort status extraction (getStatusCode(), then props).
     */
    private function extractStatus(object $response): int
    {
        if (method_exists($response, 'getStatusCode')) {
            return (int)$response->getStatusCode();
        }
        return (int)($response->statusCode ?? $response->httpCode ?? 200);
    }

    // ── Internal lookup / store (response cache, NOT public) ─────

    /**
     * @internal Used by middleware hooks only.
     */
    private function internalLookup(string $method, string $url, ?object $request = null): ?array
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

        // RFC 9111 s4.1 — a stored response with Vary is only reusable when
        // every nominated request header matches the request that stored it.
        if ($request !== null && !$this->varyMatches($entry, $request)) {
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

    // ── RFC 9111 conformance (shared-cache rules) ────────────────

    /**
     * Response directives that let a SHARED cache store a response to a
     * request carrying Authorization (RFC 9111 s3.5).
     */
    private const SHARED_CACHE_DIRECTIVES = ['public', 's-maxage', 'must-revalidate'];

    /**
     * Case-insensitive header lookup on a request or a response.
     *
     * @param object $carrier Request (headers array) or Response (getHeaders()/headers)
     * @param string $name    Header name
     * @return string|null The value, or null when absent
     */
    public function headerOf(object $carrier, string $name): ?string
    {
        return $this->headerValue($carrier, $name);
    }

    /**
     * Case-insensitive header lookup on a request or a response.
     *
     * @param object $carrier Request (headers) or Response (getHeaders()/headers)
     * @param string $name    Header name
     * @return string|null The value, or null when absent
     */
    private function headerValue(object $carrier, string $name): ?string
    {
        $target = strtolower($name);
        $sources = [];
        if (method_exists($carrier, 'getHeaders')) {
            $sources[] = $carrier->getHeaders();
        }
        if (isset($carrier->headers)) {
            // Request::$headers is a CaseInsensitiveArray, NOT a plain array --
            // an is_array() guard here silently returned null for every header,
            // which made both RFC 9111 checks no-op on a real request.
            $sources[] = $carrier->headers;
        }
        foreach ($sources as $headers) {
            if ($headers instanceof \ArrayAccess && isset($headers[$name])) {
                return (string)$headers[$name];
            }
            if (is_array($headers) || $headers instanceof \Traversable) {
                foreach ($headers as $key => $value) {
                    if (strtolower((string)$key) === $target) {
                        return (string)$value;
                    }
                }
            }
        }
        return null;
    }

    /**
     * The lower-cased field names in a response's Vary header.
     *
     * @param object $response The response being stored
     * @return string[] Field names, empty when there is no Vary
     */
    private function varyFields(object $response): array
    {
        $raw = $this->headerValue($response, 'Vary');
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn($f) => strtolower(trim($f)),
            explode(',', $raw)
        ), static fn($f) => $f !== ''));
    }

    /**
     * The Cache-Control directive NAMES on a request or response, lowercased.
     *
     * Parsed as comma-separated tokens with any ="value" stripped, rather than
     * by substring search, so no-cache="Set-Cookie" is recognised as no-cache
     * and a directive name never matches as a fragment of a longer one.
     *
     * @param object $carrier Request or Response carrying a Cache-Control header
     * @return string[] Lowercased directive names, empty when there is none
     */
    private function cacheControlTokens(object $carrier): array
    {
        $raw = (string)$this->headerValue($carrier, 'Cache-Control');
        if (trim($raw) === '') {
            return [];
        }
        $tokens = [];
        foreach (explode(',', $raw) as $token) {
            $name = strtolower(trim(explode('=', $token, 2)[0]));
            if ($name !== '') {
                $tokens[] = $name;
            }
        }
        return $tokens;
    }

    /**
     * Does the response carry a directive that lets a SHARED cache store it?
     *
     * RFC 9111 s3.5 — a response to an Authorization/Cookie-bearing request (or
     * one installing a Set-Cookie) is storable by a shared cache only when it
     * opts in with an explicit shared-cache directive (public / s-maxage / …).
     *
     * @param object $response The response being considered for storage
     * @return bool True when a shared-cache directive is present
     */
    private function sharedCacheAllowed(object $response): bool
    {
        return array_intersect(
            $this->cacheControlTokens($response),
            self::SHARED_CACHE_DIRECTIVES
        ) !== [];
    }

    /**
     * May a SHARED cache store this response? (RFC 9111 s3, s4.1)
     *
     * s3 — "if the cache is shared: the Authorization header field is not
     * present in the request ... or a response directive is present that
     * explicitly allows shared caching". The key is method + URL only, so
     * without this one authenticated caller's body is replayed to every later
     * caller of the same URL.
     *
     * The same s3 reasoning covers two cases the Authorization check alone does
     * not:
     *
     *   - no-store forbids storage in any cache and private forbids it in a
     *     shared one; no-cache forbids reuse without revalidation. Honouring
     *     them gives a handler a standard way to keep a response out of this
     *     cache — setting the correct header used to do nothing.
     *   - A caller identified by a session Cookie is as specific as one carrying
     *     Authorization, and Tina4's own session mechanism IS a cookie. Because
     *     the key is method + URL, storing such a response replays one signed-in
     *     user's page to whoever asks for that URL next. A response installing a
     *     session (Set-Cookie) is per-user by construction for the same reason.
     *
     * Both cookie cases stay cacheable when the response opts in explicitly with
     * a shared-cache directive, so a genuinely public page served to
     * cookie-bearing browsers keeps its hit rate.
     *
     * s4.1 — a stored response whose Vary contains "*" "always fails to
     * match", so storing one is pointless.
     *
     * @param object $request  The request that produced the response
     * @param object $response The response being considered for storage
     * @return bool True when the response may be stored
     */
    private function mayStore(object $request, object $response): bool
    {
        if (in_array('*', $this->varyFields($response), true)) {
            return false;
        }
        if (array_intersect($this->cacheControlTokens($response), ['no-store', 'private', 'no-cache']) !== []) {
            return false;
        }
        if ($this->headerValue($request, 'Authorization') !== null) {
            return $this->sharedCacheAllowed($response);
        }
        if ($this->headerValue($request, 'Cookie') !== null) {
            return $this->sharedCacheAllowed($response);
        }
        if ($this->headerValue($response, 'Set-Cookie') !== null) {
            return $this->sharedCacheAllowed($response);
        }
        return true;
    }

    /**
     * Do the nominated request headers match the ones recorded on the entry?
     *
     * RFC 9111 s4.1 — the cache MUST NOT use a stored response unless every
     * request header field nominated by its Vary value matches. An absent
     * field only matches an absent field.
     *
     * @param array  $entry   The stored entry (may carry vary/varyValues)
     * @param object $request The current request
     * @return bool True when the entry may be reused for this request
     */
    private function varyMatches(array $entry, object $request): bool
    {
        $vary = $entry['vary'] ?? [];
        if (!is_array($vary) || $vary === []) {
            return true;
        }
        $recorded = (array)($entry['varyValues'] ?? []);
        foreach ($vary as $field) {
            if ($this->headerValue($request, (string)$field) !== ($recorded[$field] ?? null)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Build the vary bookkeeping stored alongside a response.
     *
     * @param object $request  The request that produced the response
     * @param object $response The response being stored
     * @return array{vary: string[], varyValues: array<string, string|null>}
     */
    private function varyEntry(object $request, object $response): array
    {
        $vary = $this->varyFields($response);
        $values = [];
        foreach ($vary as $field) {
            $values[$field] = $this->headerValue($request, $field);
        }
        return ['vary' => $vary, 'varyValues' => $values];
    }
}

// ── Module-level convenience functions ──────────────────────────
// The namespace-level cache_instance()/cache_get()/cache_set()/cache_delete()/
// cache_clear()/cache_stats() helpers live in CacheFunctions.php so they are
// available via composer `files` autoload without first touching this class.
