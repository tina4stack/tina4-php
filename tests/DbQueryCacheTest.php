<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Request-scoped DB query cache (default-on) — protects the DB from rapid
 * identical reads. Mirrors tina4_python tests/test_db_query_cache.py exactly.
 *
 * Layers:
 *   • request-scoped (DEFAULT ON, off-switch TINA4_QUERY_CACHE=false) — dedupes
 *     identical SELECTs, cleared per request + on writes, short safety TTL.
 *   • persistent (opt-in TINA4_DB_CACHE=true) — cross-request TTL cache, NOT
 *     cleared per request.
 *
 * NOTE: the cache mode is read at construction, so every test sets env via
 * putenv()/$_ENV BEFORE calling Database::create().
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\CachedDatabase;
use Tina4\Database\Database;

class DbQueryCacheTest extends TestCase
{
    /**
     * Set a cache-relevant env var. DotEnv::getEnv() consults its internal
     * store (cleared via resetEnv() in setUp), then $_ENV, then getenv() — so
     * setting $_ENV + putenv() is what the constructor reads.
     */
    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }

    /** Remove a cache-relevant env var from every layer DotEnv::getEnv consults. */
    private function clearEnv(string $key): void
    {
        unset($_ENV[$key]);
        putenv($key);
    }

    protected function setUp(): void
    {
        // Start from a clean slate every test — the default-on behaviour must
        // be observed with NO env overrides leaking in from a previous case.
        \Tina4\DotEnv::resetEnv();
        foreach (['TINA4_DB_CACHE', 'TINA4_DB_CACHE_TTL', 'TINA4_QUERY_CACHE', 'TINA4_QUERY_CACHE_TTL'] as $k) {
            $this->clearEnv($k);
        }
    }

    protected function tearDown(): void
    {
        \Tina4\DotEnv::resetEnv();
        foreach (['TINA4_DB_CACHE', 'TINA4_DB_CACHE_TTL', 'TINA4_QUERY_CACHE', 'TINA4_QUERY_CACHE_TTL'] as $k) {
            $this->clearEnv($k);
        }
    }

    private function makeDb(): Database
    {
        $db = Database::create('sqlite::memory:', autoCommit: true);
        $db->execute('CREATE TABLE t (id INTEGER PRIMARY KEY, n TEXT)');
        $db->execute("INSERT INTO t (n) VALUES ('a')");
        $db->execute("INSERT INTO t (n) VALUES ('b')");
        return $db;
    }

    // ── Request-scoped default ────────────────────────────────────

    public function testOnByDefault(): void
    {
        $db = $this->makeDb();
        $stats = $db->cacheStats();
        $this->assertTrue($stats['enabled']);
        $this->assertSame('request', $stats['mode']);
    }

    public function testIdenticalFetchesDedupe(): void
    {
        $db = $this->makeDb();
        $db->fetch('SELECT * FROM t');   // miss -> populates
        $db->fetch('SELECT * FROM t');   // hit
        $stats = $db->cacheStats();
        $this->assertGreaterThanOrEqual(1, $stats['hits']);
        $this->assertSame(1, $stats['size']);
    }

    public function testWriteInvalidates(): void
    {
        $db = $this->makeDb();
        $db->fetch('SELECT * FROM t');
        $this->assertSame(1, $db->cacheStats()['size']);
        $db->execute("INSERT INTO t (n) VALUES ('c')");
        $this->assertSame(0, $db->cacheStats()['size']);
    }

    public function testInsertHelperInvalidates(): void
    {
        $db = $this->makeDb();
        $db->fetch('SELECT * FROM t');
        $this->assertSame(1, $db->cacheStats()['size']);
        $db->insert('t', ['n' => 'd']);
        $this->assertSame(0, $db->cacheStats()['size']);
    }

    // ── Request boundary ──────────────────────────────────────────

    public function testResetClearsRequestCache(): void
    {
        $db = $this->makeDb();
        $db->fetch('SELECT * FROM t');
        $this->assertSame(1, $db->cacheStats()['size']);
        // Simulate the dispatcher firing at the start of the next request.
        CachedDatabase::resetRequestCaches();
        $this->assertSame(0, $db->cacheStats()['size']);
    }

    public function testResetPreservesCounters(): void
    {
        $db = $this->makeDb();
        $db->fetch('SELECT * FROM t');
        $db->fetch('SELECT * FROM t');  // one hit
        $hitsBefore = $db->cacheStats()['hits'];
        $this->assertGreaterThanOrEqual(1, $hitsBefore);
        // The request boundary clears entries but keeps cumulative counters.
        CachedDatabase::resetRequestCaches();
        $this->assertSame($hitsBefore, $db->cacheStats()['hits']); // cumulative counters survive
        $this->assertSame(0, $db->cacheStats()['size']);
    }

    // ── Off switch ────────────────────────────────────────────────

    public function testQueryCacheFalseDisables(): void
    {
        $this->setEnv('TINA4_QUERY_CACHE', 'false');
        $db = $this->makeDb();
        $stats = $db->cacheStats();
        $this->assertFalse($stats['enabled']);
        $this->assertSame('off', $stats['mode']);
        $db->fetch('SELECT * FROM t');
        $db->fetch('SELECT * FROM t');
        $this->assertSame(0, $db->cacheStats()['size']);  // nothing cached
        $this->assertSame(0, $db->cacheStats()['hits']);
    }

    // ── Persistent mode ───────────────────────────────────────────

    public function testDbCacheTrueIsPersistent(): void
    {
        $this->setEnv('TINA4_DB_CACHE', 'true');
        $db = $this->makeDb();
        $stats = $db->cacheStats();
        $this->assertTrue($stats['enabled']);
        $this->assertSame('persistent', $stats['mode']);
        $this->assertSame(30, $stats['ttl']);
    }

    public function testPersistentSurvivesRequestReset(): void
    {
        $this->setEnv('TINA4_DB_CACHE', 'true');
        $db = $this->makeDb();
        $db->fetch('SELECT * FROM t');
        $this->assertSame(1, $db->cacheStats()['size']);
        CachedDatabase::resetRequestCaches();  // no-op in persistent mode
        $this->assertSame(1, $db->cacheStats()['size']);
    }
}
