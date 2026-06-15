<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Unified cache backend set — memory / file / database / redis / valkey /
 * memcached / mongodb. Mirrors tina4_python tests/test_cache_backends.py +
 * the persistent cross-instance behaviour from tests/test_db_query_cache.py.
 *
 * Network backends SKIP when their service is unreachable, so CI without those
 * services stays green; locally (with the docker harness up) they run for real.
 *
 * Parallel isolation (other agents share these containers): redis/valkey use
 * DB index 1; mongo uses the "tina4_cache_php" collection; memcached uses
 * uniquely-prefixed keys with targeted deletes (never flush_all).
 */

use PHPUnit\Framework\TestCase;
use Tina4\Cache\CacheFactory;
use Tina4\Cache\MongoBackend;
use Tina4\Cache\RedisBackend;
use Tina4\Database\CachedDatabase;
use Tina4\Database\Database;

class CacheBackendsTest extends TestCase
{
    private function reachable(string $host, int $port): bool
    {
        $s = @fsockopen($host, $port, $e, $s2, 1);
        if ($s) {
            fclose($s);
            return true;
        }
        return false;
    }

    /** Standard round-trip contract every backend must satisfy. */
    private function roundtrip(\Tina4\Cache\CacheBackend $be, string $expectName): void
    {
        $be->clear();
        $be->set('k1', ['v' => 1, 'name' => 'Alice'], 60);
        $this->assertSame(['v' => 1, 'name' => 'Alice'], $be->get('k1'));
        $this->assertNull($be->get('missing'));
        $st = $be->stats();
        $this->assertSame($expectName, $st['backend']);
        foreach (['hits', 'misses', 'size'] as $field) {
            $this->assertArrayHasKey($field, $st);
        }
        $this->assertTrue($be->delete('k1'));
        $this->assertNull($be->get('k1'));
    }

    private function clearCacheEnv(): void
    {
        foreach ([
            'TINA4_CACHE_BACKEND', 'TINA4_CACHE_URL', 'TINA4_CACHE_DIR',
            'TINA4_CACHE_USERNAME', 'TINA4_CACHE_PASSWORD', 'TINA4_CACHE_MAX_ENTRIES',
            'TINA4_DB_CACHE', 'TINA4_DB_CACHE_BACKEND', 'TINA4_DB_CACHE_URL',
            'TINA4_AUTO_CACHING',
        ] as $k) {
            unset($_ENV[$k]);
            putenv($k);
        }
        \Tina4\DotEnv::resetEnv();
    }

    protected function setUp(): void
    {
        $this->clearCacheEnv();
    }

    protected function tearDown(): void
    {
        $this->clearCacheEnv();
    }

    // ── Local backends (always available) ──────────────────────────

    public function testMemory(): void
    {
        $this->roundtrip(CacheFactory::create('memory'), 'memory');
    }

    public function testFile(): void
    {
        $dir = sys_get_temp_dir() . '/tina4_cache_test_' . uniqid();
        $be = CacheFactory::create('file', null, null, $dir);
        $this->roundtrip($be, 'file');
        $be->clear();
        @rmdir($dir);
    }

    public function testDatabaseSqlite(): void
    {
        $path = sys_get_temp_dir() . '/tina4_cache_test_' . uniqid() . '.db';
        $be = CacheFactory::create('database', 'sqlite:///' . $path);
        $this->roundtrip($be, 'database');
        @unlink($path);
    }

    public function testUnknownFallsBackToMemory(): void
    {
        $this->assertSame('memory', CacheFactory::create('bogus')->name());
    }

    public function testUnavailableBackendFallsBackToFile(): void
    {
        // A configured backend whose service is unreachable degrades to the
        // file backend (a real working cache), not a silent no-op.
        $dir = sys_get_temp_dir() . '/tina4_cache_test_' . uniqid();
        $be = CacheFactory::create('redis', 'redis://localhost:6399', null, $dir); // dead port
        $this->assertSame('file', $be->name());
        $be->set('k', ['v' => 1], 60);
        $this->assertSame(['v' => 1], $be->get('k'));
        $be->clear();
        @rmdir($dir);
    }

    // ── Credentials (parsed in the constructor, no live server) ─────

    public function testUrlCredentialsParsed(): void
    {
        $c = (new RedisBackend('redis://alice:s3cret@127.0.0.1:6399'))->_credentials();
        $this->assertSame('alice', $c['username']);
        $this->assertSame('s3cret', $c['password']);
        $this->assertSame('127.0.0.1', $c['host']);
        $this->assertSame(6399, $c['port']);
    }

    public function testPasswordOnlyUrl(): void
    {
        $c = (new RedisBackend('redis://:justpass@127.0.0.1:6399'))->_credentials();
        $this->assertNull($c['username']);
        $this->assertSame('justpass', $c['password']);
    }

    public function testEnvCredentials(): void
    {
        $_ENV['TINA4_CACHE_USERNAME'] = 'bob';
        $_ENV['TINA4_CACHE_PASSWORD'] = 'pw';
        putenv('TINA4_CACHE_USERNAME=bob');
        putenv('TINA4_CACHE_PASSWORD=pw');
        $c = (new RedisBackend('redis://127.0.0.1:6399'))->_credentials();
        $this->assertSame('bob', $c['username']);
        $this->assertSame('pw', $c['password']);
    }

    public function testNoCredentials(): void
    {
        $c = (new RedisBackend('redis://127.0.0.1:6399'))->_credentials();
        $this->assertNull($c['username']);
        $this->assertNull($c['password']);
    }

    public function testDbIndexParsedFromUrl(): void
    {
        $c = (new RedisBackend('redis://127.0.0.1:6399/3'))->_credentials();
        $this->assertSame(3, $c['db']);
    }

    // ── Network backends (skip when unreachable) ────────────────────

    public function testRedisBackend(): void
    {
        if (!$this->reachable('localhost', 6379)) {
            $this->markTestSkipped('redis not running');
        }
        // DB index 1 for parallel isolation.
        $this->roundtrip(CacheFactory::create('redis', 'redis://localhost:6379/1'), 'redis');
    }

    public function testValkeyBackend(): void
    {
        if (!$this->reachable('localhost', 6380)) {
            $this->markTestSkipped('valkey not running');
        }
        // DB index 1 for parallel isolation.
        $this->roundtrip(CacheFactory::create('valkey', 'valkey://localhost:6380/1'), 'valkey');
    }

    public function testMemcachedBackend(): void
    {
        if (!$this->reachable('localhost', 11211)) {
            $this->markTestSkipped('memcached not running');
        }
        // Uniquely-prefixed keys + targeted delete (NO flush_all — shared box).
        $be = CacheFactory::create('memcached', 'memcached://localhost:11211');
        $this->assertSame('memcached', $be->name());
        $key = 'phpcb_' . uniqid();
        $be->set($key, ['v' => 1, 'name' => 'Alice'], 60);
        $this->assertSame(['v' => 1, 'name' => 'Alice'], $be->get($key));
        $this->assertNull($be->get('phpcb_missing_' . uniqid()));
        $st = $be->stats();
        $this->assertSame('memcached', $st['backend']);
        $this->assertTrue($be->delete($key));
        $this->assertNull($be->get($key));
    }

    public function testMongodbBackend(): void
    {
        if (!$this->reachable('localhost', 27017)) {
            $this->markTestSkipped('mongodb not running');
        }
        // Dedicated collection for parallel isolation.
        $this->roundtrip(new MongoBackend('mongodb://localhost:27017', 1000, 'tina4_cache_php'), 'mongodb');
    }

    // ── Authenticated redis from the harness (redis-auth, port 6381) ─

    public function testRedisAuthRoundtrip(): void
    {
        if (!$this->reachable('localhost', 6381)) {
            $this->markTestSkipped('auth redis not running');
        }
        // Real authenticated round-trip — must connect (not fall back to file).
        $be = CacheFactory::create('redis', 'redis://:s3cret@localhost:6381/1');
        $this->assertSame('redis', $be->name());
        $this->roundtrip($be, 'redis');
    }

    public function testRedisWrongPasswordFallsBackToFile(): void
    {
        if (!$this->reachable('localhost', 6381)) {
            $this->markTestSkipped('auth redis not running');
        }
        $dir = sys_get_temp_dir() . '/tina4_cache_test_' . uniqid();
        $be = CacheFactory::create('redis', 'redis://:wrongpass@localhost:6381/1', null, $dir);
        $this->assertSame('file', $be->name()); // bad auth → graceful fallback, not a no-op
        $be->set('k', ['v' => 1], 60);
        $this->assertSame(['v' => 1], $be->get('k'));
        $be->clear();
        @rmdir($dir);
    }

    // ── Persistent DB cache → unified backend (cross-instance) ──────

    public function testPersistentMemoryBackendReconstructsResult(): void
    {
        $_ENV['TINA4_DB_CACHE'] = 'true';
        $_ENV['TINA4_DB_CACHE_BACKEND'] = 'memory';
        putenv('TINA4_DB_CACHE=true');
        putenv('TINA4_DB_CACHE_BACKEND=memory');

        $db = Database::create('sqlite::memory:', autoCommit: true);
        $db->execute('CREATE TABLE t (id INTEGER PRIMARY KEY, n TEXT)');
        $db->execute("INSERT INTO t (n) VALUES ('a')");
        $db->execute("INSERT INTO t (n) VALUES ('b')");
        $db->cacheClear();

        $db->fetch('SELECT * FROM t ORDER BY id');       // miss → populate backend
        $r = $db->fetch('SELECT * FROM t ORDER BY id');  // hit → reconstructed
        $names = array_map(fn($row) => $row['n'], $r->records);
        $this->assertSame(['a', 'b'], $names);
        $stats = $db->cacheStats();
        $this->assertSame('persistent', $stats['mode']);
        $this->assertGreaterThanOrEqual(1, $stats['hits']);
    }

    public function testPersistentRedisBackendSharedAcrossInstances(): void
    {
        if (!$this->reachable('localhost', 6379)) {
            $this->markTestSkipped('redis not running');
        }
        // DB index 1 for parallel isolation.
        $_ENV['TINA4_DB_CACHE'] = 'true';
        $_ENV['TINA4_DB_CACHE_BACKEND'] = 'redis';
        $_ENV['TINA4_DB_CACHE_URL'] = 'redis://localhost:6379/1';
        putenv('TINA4_DB_CACHE=true');
        putenv('TINA4_DB_CACHE_BACKEND=redis');
        putenv('TINA4_DB_CACHE_URL=redis://localhost:6379/1');

        // File-backed sqlite so a second, separate connection sees the table.
        $path = sys_get_temp_dir() . '/tina4_cache_shared_' . uniqid() . '.db';
        $url = 'sqlite:///' . $path;

        $db1 = Database::create($url, autoCommit: true);
        $db1->execute('CREATE TABLE t (id INTEGER PRIMARY KEY, n TEXT)');
        $db1->execute("INSERT INTO t (n) VALUES ('x')");
        $db1->execute("INSERT INTO t (n) VALUES ('y')");
        $db1->cacheClear();                            // clear the shared redis (DB 1)
        $db1->fetch('SELECT * FROM t ORDER BY id');    // populate shared redis

        $db2 = Database::create($url, autoCommit: true); // separate instance, same redis
        $r = $db2->fetch('SELECT * FROM t ORDER BY id'); // cross-instance hit
        $stats2 = $db2->cacheStats();
        $this->assertGreaterThanOrEqual(1, $stats2['hits']);
        $this->assertSame(0, $stats2['misses']);
        $names = array_map(fn($row) => $row['n'], $r->records);
        $this->assertSame(['x', 'y'], $names);
        $this->assertSame('redis', $stats2['backend']);

        $db1->cacheClear();
        @unlink($path);
    }
}
