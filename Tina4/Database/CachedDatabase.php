<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * CachedDatabase — Transparent query cache decorator for any DatabaseAdapter.
 *
 * Wraps a DatabaseAdapter and caches SELECT results from fetch() and fetchOne().
 * Write operations (insert, update, delete, execute) invalidate the entire cache.
 *
 * Opt-in via .env:
 *   TINA4_DB_CACHE=true          # enable (default: false)
 *   TINA4_DB_CACHE_TTL=30        # TTL in seconds (default: 30)
 *
 * Usage:
 *   $database = Database::create('sqlite:///app.db');
 *   $db = new CachedDatabase($database->getAdapter());
 *   $db->fetch("SELECT * FROM users");   // cached on second call
 *   $db->cacheStats();                   // ["enabled" => true, "hits" => 1, ...]
 */

namespace Tina4\Database;

class CachedDatabase implements DatabaseAdapter
{
    private DatabaseAdapter $adapter;
    private bool $enabled;
    private int $ttl;
    private int $hits = 0;
    private int $misses = 0;

    /** @var array<string, array{expires: float, value: mixed}> */
    private array $cache = [];

    public function __construct(DatabaseAdapter $adapter, ?bool $enabled = null, ?int $ttl = null)
    {
        $this->adapter = $adapter;
        $this->enabled = $enabled ?? \Tina4\DotEnv::isTruthy(\Tina4\DotEnv::getEnv('TINA4_DB_CACHE') ?? 'false');
        $this->ttl = $ttl ?? (int) (\Tina4\DotEnv::getEnv('TINA4_DB_CACHE_TTL') ?? '30');
    }

    // ── Cache helpers ─────────────────────────────────────────

    private function cacheKey(string $sql, array $params = []): string
    {
        return hash('sha256', $sql . json_encode($params));
    }

    private function cacheGet(string $key): mixed
    {
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
        $this->cache[$key] = [
            'expires' => microtime(true) + $this->ttl,
            'value' => $value,
        ];
    }

    private function cacheInvalidate(): void
    {
        $this->cache = [];
    }

    /**
     * Return cache statistics.
     *
     * @return array{enabled: bool, hits: int, misses: int, size: int, ttl: int}
     */
    public function cacheStats(): array
    {
        return [
            'enabled' => $this->enabled,
            'hits' => $this->hits,
            'misses' => $this->misses,
            'size' => count($this->cache),
            'ttl' => $this->ttl,
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

    public function fetch(string $sql, int $limit = 10, int $offset = 0, array $params = []): array
    {
        if ($this->enabled) {
            $key = $this->cacheKey($sql . ":L{$limit}:O{$offset}", $params);
            $cached = $this->cacheGet($key);
            if ($cached !== null) {
                $this->hits++;
                return $cached;
            }
            $result = $this->adapter->fetch($sql, $limit, $offset, $params);
            $this->cacheSet($key, $result);
            $this->misses++;
            return $result;
        }
        return $this->adapter->fetch($sql, $limit, $offset, $params);
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        if ($this->enabled) {
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

    public function execute(string $sql, array $params = []): bool
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
