<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * CachedDatabase — Transparent query cache decorator for any DatabaseAdapter.
 *
 * Wraps a DatabaseAdapter and caches SELECT results from fetch() and fetchOne().
 * Write operations (insert, update, delete, execute) invalidate the entire cache.
 *
 * One store, two layers (mirrors tina4_python connection.py exactly). BOTH
 * layers are OPT-IN / DEFAULT OFF:
 *   • request-scoped (DEFAULT OFF, opt-in TINA4_AUTO_CACHING=true) — dedupes
 *     identical SELECTs to protect the DB from rapid repeat reads. Cleared at the
 *     START of every HTTP request AND on any write, with a short safety TTL
 *     (TINA4_AUTO_CACHING_TTL, default 5s) for non-request contexts (CLI/workers).
 *     It is OFF by default because an on-by-default request cache is a footgun:
 *     a `SELECT MAX(id)` (or generator read) right before an INSERT in the same
 *     request returns a cached pre-write value → duplicate primary keys; any
 *     read-after-write in one request shows stale state. Turn it on only for
 *     read-heavy endpoints.
 *   • persistent (opt-in, TINA4_DB_CACHE=true) — cross-request TTL cache that is
 *     NOT cleared per request; entries expire by TINA4_DB_CACHE_TTL (default 30s).
 *
 * .env knobs:
 *   TINA4_DB_CACHE=true          # persistent cross-request cache (default: false)
 *   TINA4_DB_CACHE_TTL=30        # persistent TTL in seconds (default: 30)
 *   TINA4_AUTO_CACHING=true       # request-scoped cache (default: false — opt-in)
 *   TINA4_AUTO_CACHING_TTL=5      # request-scoped TTL in seconds (default: 5)
 *
 * enabled = persistent || requestScoped
 * mode    = persistent ? "persistent" : (requestScoped ? "request" : "off")
 * ttl     = persistent ? TINA4_DB_CACHE_TTL : TINA4_AUTO_CACHING_TTL
 *
 * IMPORTANT: Tina4 PHP runs a LONG-RUNNING built-in server, so in-memory state
 * persists across requests. The request dispatcher calls
 * CachedDatabase::resetRequestCaches() at the start of every request so the
 * request-scoped layer never serves rows across requests.
 *
 * Usage:
 *   $database = Database::create('sqlite:///app.db');
 *   $db = new CachedDatabase($database->getAdapter());
 *   $db->fetch("SELECT * FROM users");   // cached on second call
 *   $db->cacheStats();                   // ["enabled" => true, "mode" => "request", ...]
 */

namespace Tina4\Database;

class CachedDatabase implements DatabaseAdapter
{
    /**
     * Live CachedDatabase instances, so the request dispatcher can clear the
     * request-scoped query cache on every wrapper at the start of a request.
     *
     * Held as weak references so a closed/garbage-collected connection does
     * not keep the wrapper alive.
     *
     * @var \WeakMap<CachedDatabase, true>|null
     */
    private static ?\WeakMap $instances = null;

    private DatabaseAdapter $adapter;
    private bool $persistent;
    private bool $requestScoped;
    private bool $enabled;
    private int $ttl;
    private int $hits = 0;
    private int $misses = 0;

    /** @var array<string, array{expires: float, value: mixed}> */
    private array $cache = [];

    /**
     * Persistent mode (TINA4_DB_CACHE=true) routes through the unified
     * CacheBackend (TINA4_DB_CACHE_BACKEND, default memory) so multiple
     * instances can share one cache with global write-invalidation. Request-
     * scoped mode keeps the in-process $cache above (ephemeral, fastest,
     * never serialized). Parity with Python's Database._cache_backend.
     *
     * @var \Tina4\Cache\CacheBackend|null
     */
    private ?\Tina4\Cache\CacheBackend $cacheBackend = null;

    public function __construct(DatabaseAdapter $adapter, ?bool $enabled = null, ?int $ttl = null)
    {
        $this->adapter = $adapter;

        // Both layers are opt-in / default OFF. Persistent (TINA4_DB_CACHE)
        // takes precedence over request-scoped (TINA4_AUTO_CACHING) when both
        // are enabled. Request-scoped defaults OFF to avoid the read-after-write
        // footgun (stale MAX(id)/generator reads → duplicate keys).
        $this->persistent = \Tina4\DotEnv::isTruthy(\Tina4\DotEnv::getEnv('TINA4_DB_CACHE') ?? 'false');
        $this->requestScoped = \Tina4\DotEnv::isTruthy(\Tina4\DotEnv::getEnv('TINA4_AUTO_CACHING') ?? 'false');

        // $enabled is an explicit override (used by tests); otherwise derive it.
        $this->enabled = $enabled ?? ($this->persistent || $this->requestScoped);

        if ($ttl !== null) {
            $this->ttl = $ttl;
        } elseif ($this->persistent) {
            $this->ttl = (int) (\Tina4\DotEnv::getEnv('TINA4_DB_CACHE_TTL') ?? '30');
        } else {
            $this->ttl = (int) (\Tina4\DotEnv::getEnv('TINA4_AUTO_CACHING_TTL') ?? '5');
        }

        // Persistent mode → shared unified CacheBackend. Request-scoped mode
        // stays in the in-process $cache (never serialized).
        if ($this->persistent) {
            try {
                $this->cacheBackend = \Tina4\Cache\CacheFactory::create(
                    backend: \Tina4\DotEnv::getEnv('TINA4_DB_CACHE_BACKEND') ?? 'memory',
                    url: \Tina4\DotEnv::getEnv('TINA4_DB_CACHE_URL'),
                );
            } catch (\Throwable) {
                $this->cacheBackend = null; // fall back to the in-process dict
            }
        }

        // Register this wrapper so resetRequestCaches() can reach it.
        if (self::$instances === null) {
            self::$instances = new \WeakMap();
        }
        self::$instances[$this] = true;
    }

    // ── Cache helpers ─────────────────────────────────────────

    private function cacheKey(string $sql, array $params = []): string
    {
        return hash('sha256', $sql . json_encode($params));
    }

    private function cacheGet(string $key): mixed
    {
        // Persistent mode → shared CacheBackend (serialized result). The
        // backend returns the reconstructed value, or null on miss.
        if ($this->cacheBackend !== null) {
            $raw = $this->cacheBackend->get($key);
            return is_array($raw) && array_key_exists('value', $raw)
                ? $raw['value']
                : null;
        }
        // Request-scoped mode → in-process dict (stores the value directly).
        if (!isset($this->cache[$key])) {
            return null;
        }
        if (microtime(true) > $this->cache[$key]['expires']) {
            unset($this->cache[$key]);
            return null;
        }
        return $this->cache[$key]['value'];
    }

    private function cacheSet(string $key, mixed $value): void
    {
        // Persistent mode → serialize (records list / row dict) into the shared
        // backend so other instances reconstruct it cross-process. The "value"
        // wrapper lets us distinguish a cached null fetchOne from a miss.
        if ($this->cacheBackend !== null) {
            $this->cacheBackend->set($key, ['value' => $value], $this->ttl);
            return;
        }
        $this->cache[$key] = [
            'expires' => microtime(true) + $this->ttl,
            'value' => $value,
        ];
    }

    private function cacheInvalidate(): void
    {
        if ($this->cacheBackend !== null) {
            $this->cacheBackend->clear();
            return;
        }
        $this->cache = [];
    }

    /**
     * Clear the request-scoped query cache at the START of an HTTP request.
     *
     * No-op in persistent mode (TINA4_DB_CACHE=true) so cross-request entries
     * survive up to their TTL. Cumulative hit/miss counters are preserved
     * (parity with Python's Database.cache_new_request()).
     */
    public function cacheNewRequest(): void
    {
        if ($this->requestScoped && !$this->persistent) {
            $this->cache = [];
        }
    }

    /**
     * Clear the request-scoped query cache on every live CachedDatabase wrapper.
     *
     * The request dispatcher (Router::dispatch) calls this at the START of each
     * HTTP request so request-scoped caching never serves rows across requests
     * (zero cross-request staleness). Persistent-mode wrappers are left alone.
     * Parity with Python's Database.reset_request_caches().
     */
    public static function resetRequestCaches(): void
    {
        if (self::$instances === null) {
            return;
        }
        foreach (self::$instances as $instance => $_) {
            try {
                $instance->cacheNewRequest();
            } catch (\Throwable) {
                // Never let a single bad connection break request dispatch.
            }
        }
    }

    /**
     * Return cache statistics reflecting the real cache.
     *
     * @return array{enabled: bool, mode: string, hits: int, misses: int, size: int, ttl: int, backend: string}
     */
    public function cacheStats(): array
    {
        // Persistent mode → report the shared backend's size + name.
        if ($this->cacheBackend !== null) {
            $bs = $this->cacheBackend->stats();
            return [
                'enabled' => true,
                'mode' => 'persistent',
                'hits' => $this->hits,
                'misses' => $this->misses,
                'size' => $bs['size'] ?? 0,
                'ttl' => $this->ttl,
                'backend' => $bs['backend'] ?? $this->cacheBackend->name(),
            ];
        }
        return [
            'enabled' => $this->enabled,
            'mode' => $this->persistent ? 'persistent' : ($this->requestScoped ? 'request' : 'off'),
            'hits' => $this->hits,
            'misses' => $this->misses,
            'size' => count($this->cache),
            'ttl' => $this->ttl,
            'backend' => 'memory',
        ];
    }

    /**
     * Flush the query cache and reset counters.
     */
    public function cacheClear(): void
    {
        $this->cache = [];
        $this->hits = 0;
        $this->misses = 0;
        if ($this->cacheBackend !== null) {
            $this->cacheBackend->clear();
        }
    }

    // ── DatabaseAdapter interface ─────────────────────────────

    public function open(): void
    {
        $this->adapter->open();
    }

    public function close(): void
    {
        $this->adapter->close();
    }

    public function query(string $sql, array $params = []): array
    {
        return $this->adapter->query($sql, $params);
    }

    public function fetch(string $sql, array $params = [], int $limit = 100, int $offset = 0, bool $noCache = false): array
    {
        // $noCache=true bypasses the query cache for this one call — no lookup,
        // no store — and runs straight against the adapter. Works in either
        // cache mode. Parity with Python Database.fetch(no_cache=True).
        if ($this->enabled && !$noCache) {
            $key = $this->cacheKey($sql . ":L{$limit}:O{$offset}", $params);
            $cached = $this->cacheGet($key);
            if ($cached !== null) {
                $this->hits++;
                return $cached;
            }
            $result = $this->adapter->fetch($sql, $params, $limit, $offset);
            $this->cacheSet($key, $result);
            $this->misses++;
            return $result;
        }
        return $this->adapter->fetch($sql, $params, $limit, $offset);
    }

    public function fetchOne(string $sql, array $params = [], bool $noCache = false): ?array
    {
        // $noCache=true bypasses the query cache for this one call (see fetch()).
        if ($this->enabled && !$noCache) {
            $key = $this->cacheKey($sql . ':ONE', $params);
            $cached = $this->cacheGet($key);
            if ($cached !== null) {
                $this->hits++;
                return $cached;
            }
            $result = $this->adapter->fetchOne($sql, $params);
            $this->cacheSet($key, $result);
            $this->misses++;
            return $result;
        }
        return $this->adapter->fetchOne($sql, $params);
    }

    public function execute(string $sql, array $params = []): bool|DatabaseResult
    {
        if ($this->enabled) {
            $this->cacheInvalidate();
        }
        return $this->adapter->execute($sql, $params);
    }

    public function insert(string $table, array $data): bool
    {
        if ($this->enabled) {
            $this->cacheInvalidate();
        }
        return $this->adapter->insert($table, $data);
    }

    public function update(string $table, array $data, string $where = '', array $whereParams = []): bool
    {
        if ($this->enabled) {
            $this->cacheInvalidate();
        }
        return $this->adapter->update($table, $data, $where, $whereParams);
    }

    public function delete(string $table, string|array $filter = '', array $whereParams = []): bool
    {
        if ($this->enabled) {
            $this->cacheInvalidate();
        }
        return $this->adapter->delete($table, $filter, $whereParams);
    }

    public function executeMany(string $sql, array $paramsList = []): int
    {
        if ($this->enabled) {
            $this->cacheInvalidate();
        }
        return $this->adapter->executeMany($sql, $paramsList);
    }

    public function tableExists(string $table): bool
    {
        return $this->adapter->tableExists($table);
    }

    public function getColumns(string $table): array
    {
        return $this->adapter->getColumns($table);
    }

    public function getTables(): array
    {
        return $this->adapter->getTables();
    }

    public function lastInsertId(): int|string
    {
        return $this->adapter->lastInsertId();
    }

    /**
     * Rows affected by the most recent write — delegate to the wrapped adapter
     * so the count survives when the DB cache is enabled.
     */
    public function affectedRows(): int
    {
        return method_exists($this->adapter, 'affectedRows') ? (int) $this->adapter->affectedRows() : 0;
    }

    public function startTransaction(): void
    {
        $this->adapter->startTransaction();
    }

    public function commit(): void
    {
        $this->adapter->commit();
    }

    public function rollback(): void
    {
        $this->adapter->rollback();
    }

    public function error(): ?string
    {
        return $this->adapter->error();
    }

    /**
     * Access the underlying adapter directly (for driver-specific ops).
     */
    public function getAdapter(): DatabaseAdapter
    {
        return $this->adapter;
    }
}
