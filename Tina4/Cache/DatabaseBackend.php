<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * DatabaseBackend — stores entries in a tina4_cache table in any
 * Tina4-supported database. Zero extra infrastructure; reuses the Database
 * layer.
 *
 * Mirrors Python's tina4_python.cache._DatabaseBackend:
 *   - reads TINA4_CACHE_URL (interpreted as a SQL URL), falling back to the
 *     app's own TINA4_DATABASE_URL — cache in the DB you already run
 *   - there is NO separate TINA4_CACHE_DB_URL var
 *   - tina4_cache(cache_key, value, expires_at) with TTL enforced on read
 *
 * IMPORTANT: the cache's own DB connection must NOT itself cache (no
 * recursion). We connect via Database::create(autoCommit: true) and then take
 * getAdapter(), which always returns the RAW driver adapter — unwrapping any
 * CachedDatabase decorator — so no query caching can recurse through this
 * connection regardless of TINA4_AUTO_CACHING / TINA4_DB_CACHE.
 */

namespace Tina4\Cache;

use Tina4\Database\Database;
use Tina4\Database\DatabaseAdapter;

class DatabaseBackend extends CacheBackend
{
    private ?DatabaseAdapter $adapter = null;
    private int $maxEntries;
    private int $hits = 0;
    private int $misses = 0;
    private bool $available = false;

    public function __construct(?string $url = null, int $maxEntries = 1000)
    {
        $this->maxEntries = $maxEntries;

        // The database backend reads TINA4_CACHE_URL (interpreted as a SQL
        // URL), falling back to the app's own TINA4_DATABASE_URL.
        $url = $url
            ?? \Tina4\DotEnv::getEnv('TINA4_CACHE_URL')
            ?? \Tina4\DotEnv::getEnv('TINA4_DATABASE_URL')
            ?? 'sqlite:///data/tina4.db';

        try {
            // autoCommit so writes persist on engines that default to manual
            // commit; getAdapter() returns the RAW adapter (never a cache
            // wrapper) so this connection can never recurse into caching.
            $db = Database::create($url, autoCommit: true);
            $this->adapter = $db->getAdapter();
            $this->adapter->execute(
                'CREATE TABLE IF NOT EXISTS tina4_cache '
                . '(cache_key VARCHAR(255) PRIMARY KEY, value TEXT, expires_at DOUBLE PRECISION)'
            );
            $this->available = true;
        } catch (\Throwable) {
            $this->available = false;
        }
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function get(string $key): mixed
    {
        if (!$this->available) {
            $this->misses++;
            return null;
        }
        $row = $this->adapter->fetchOne(
            'SELECT value, expires_at FROM tina4_cache WHERE cache_key = ?',
            [$key]
        );
        if ($row === null) {
            $this->misses++;
            return null;
        }
        $exp = $row['expires_at'] ?? null;
        if ($exp !== null && (float)$exp > 0 && microtime(true) > (float)$exp) {
            $this->adapter->execute('DELETE FROM tina4_cache WHERE cache_key = ?', [$key]);
            $this->misses++;
            return null;
        }
        $this->hits++;
        return json_decode((string)($row['value'] ?? 'null'), true);
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        if (!$this->available) {
            return;
        }
        $exp = $ttl > 0 ? microtime(true) + $ttl : 0.0;
        $serialized = json_encode($value);
        $this->adapter->execute('DELETE FROM tina4_cache WHERE cache_key = ?', [$key]);
        $this->adapter->execute(
            'INSERT INTO tina4_cache (cache_key, value, expires_at) VALUES (?, ?, ?)',
            [$key, $serialized, $exp]
        );
    }

    public function delete(string $key): bool
    {
        if (!$this->available) {
            return false;
        }
        $existed = $this->adapter->fetchOne(
            'SELECT 1 AS x FROM tina4_cache WHERE cache_key = ?',
            [$key]
        ) !== null;
        $this->adapter->execute('DELETE FROM tina4_cache WHERE cache_key = ?', [$key]);
        return $existed;
    }

    public function clear(): void
    {
        $this->hits = 0;
        $this->misses = 0;
        if ($this->available) {
            $this->adapter->execute('DELETE FROM tina4_cache');
        }
    }

    /**
     * Delete expired rows and return how many went.
     *
     * The base class returns 0 because redis, valkey, memcached and mongodb
     * expire entries SERVER-SIDE - nothing was evicted because there was
     * nothing left to evict, and 0 is the honest answer. A SQL table expires
     * nothing by itself. Before this override the database backend inherited
     * that 0, so expired rows were removed only when someone happened to read
     * that exact key again: the table grew without bound and the one API whose
     * job is reclaiming that space reported success having done nothing.
     *
     * `expires_at > 0` is load-bearing: an entry stored with ttl <= 0 is
     * permanent and carries 0, so a bare `now > expires_at` would evict every
     * permanent entry on the first sweep.
     *
     * @return int How many expired rows were deleted
     */
    public function sweep(): int
    {
        if (!$this->available) {
            return 0;
        }
        $now = microtime(true);
        $row = $this->adapter->fetchOne(
            'SELECT COUNT(*) AS c FROM tina4_cache WHERE expires_at > 0 AND expires_at < ?',
            [$now]
        );
        $expired = $row !== null && isset($row['c']) ? (int)$row['c'] : 0;
        if ($expired > 0) {
            $this->adapter->execute(
                'DELETE FROM tina4_cache WHERE expires_at > 0 AND expires_at < ?',
                [$now]
            );
        }
        return $expired;
    }

    public function stats(): array
    {
        $size = 0;
        if ($this->available) {
            $row = $this->adapter->fetchOne('SELECT COUNT(*) AS c FROM tina4_cache');
            $size = $row !== null && isset($row['c']) ? (int)$row['c'] : 0;
        }
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'size' => $size,
            'backend' => 'database',
        ];
    }

    public function name(): string
    {
        return 'database';
    }
}
