<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Cache — comprehensive direct API and advanced behavior tests.
 * Complements ResponseCacheTest by focusing on the namespace-level KV cache
 * API, TTL expiry edge cases, key patterns, and max size enforcement.
 *
 * Internal store/lookup is exercised via the @internal _internal* test seams
 * exposed on the ResponseCache class.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Middleware\ResponseCache;

class CacheTest extends TestCase
{
    protected function setUp(): void
    {
        ResponseCache::clearCache();
        \Tina4\Middleware\cache_clear();
    }

    // ── Set / Get basics (namespace-level KV API) ──────────────────

    public function testSetAndGetString(): void
    {
        \Tina4\Middleware\cache_set('key1', 'hello');
        $this->assertEquals('hello', \Tina4\Middleware\cache_get('key1'));
    }

    public function testSetAndGetInteger(): void
    {
        \Tina4\Middleware\cache_set('num', 42);
        $this->assertEquals(42, \Tina4\Middleware\cache_get('num'));
    }

    public function testSetAndGetArray(): void
    {
        $data = ['a' => 1, 'b' => [2, 3]];
        \Tina4\Middleware\cache_set('arr', $data);
        $this->assertEquals($data, \Tina4\Middleware\cache_get('arr'));
    }

    public function testSetAndGetBoolean(): void
    {
        \Tina4\Middleware\cache_set('bool_true', true);
        \Tina4\Middleware\cache_set('bool_false', false);
        $this->assertTrue(\Tina4\Middleware\cache_get('bool_true'));
        $this->assertFalse(\Tina4\Middleware\cache_get('bool_false'));
    }

    public function testSetAndGetNull(): void
    {
        \Tina4\Middleware\cache_set('known_val', 'hello');
        $this->assertEquals('hello', \Tina4\Middleware\cache_get('known_val'));
    }

    public function testGetMissingKeyReturnsNull(): void
    {
        $this->assertNull(\Tina4\Middleware\cache_get('nonexistent'));
    }

    // ── TTL expiry ─────────────────────────────────────────────────

    public function testTtlExpiryOnDirectApi(): void
    {
        $cache = new ResponseCache(['ttl' => 1]);
        $cache->_internalSet('expire_me', 'value', 1);
        $this->assertEquals('value', $cache->_internalGet('expire_me'));

        usleep(1100000); // 1.1s

        $this->assertNull($cache->_internalGet('expire_me'));
    }

    public function testCustomTtlOverridesDefault(): void
    {
        // Default TTL is 60s, but set with 1s TTL
        $cache = new ResponseCache(['ttl' => 60]);
        $cache->_internalSet('short_lived', 'value', 1);

        usleep(1100000); // 1.1s

        $this->assertNull($cache->_internalGet('short_lived'));
    }

    public function testLongTtlKeepsValue(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $cache->_internalSet('long_lived', 'value', 300);

        // Should still be there immediately
        $this->assertEquals('value', $cache->_internalGet('long_lived'));
    }

    // ── Delete ─────────────────────────────────────────────────────

    public function testDeleteExistingKey(): void
    {
        \Tina4\Middleware\cache_set('del', 'value');
        $this->assertTrue(\Tina4\Middleware\cache_delete('del'));
        $this->assertNull(\Tina4\Middleware\cache_get('del'));
    }

    public function testDeleteNonExistentKey(): void
    {
        $this->assertFalse(\Tina4\Middleware\cache_delete('nope'));
    }

    public function testDeleteDoesNotAffectOtherKeys(): void
    {
        \Tina4\Middleware\cache_set('keep', 'a');
        \Tina4\Middleware\cache_set('remove', 'b');
        \Tina4\Middleware\cache_delete('remove');
        $this->assertEquals('a', \Tina4\Middleware\cache_get('keep'));
    }

    // ── Key patterns ───────────────────────────────────────────────

    public function testKeysWithSpecialCharacters(): void
    {
        \Tina4\Middleware\cache_set('key:with:colons', 'colon');
        \Tina4\Middleware\cache_set('key/with/slashes', 'slash');
        \Tina4\Middleware\cache_set('key.with.dots', 'dot');
        \Tina4\Middleware\cache_set('key with spaces', 'space');

        $this->assertEquals('colon', \Tina4\Middleware\cache_get('key:with:colons'));
        $this->assertEquals('slash', \Tina4\Middleware\cache_get('key/with/slashes'));
        $this->assertEquals('dot', \Tina4\Middleware\cache_get('key.with.dots'));
        $this->assertEquals('space', \Tina4\Middleware\cache_get('key with spaces'));
    }

    public function testEmptyStringKey(): void
    {
        \Tina4\Middleware\cache_set('', 'empty_key');
        $this->assertEquals('empty_key', \Tina4\Middleware\cache_get(''));
    }

    public function testLongKey(): void
    {
        $longKey = str_repeat('x', 500);
        \Tina4\Middleware\cache_set($longKey, 'long');
        $this->assertEquals('long', \Tina4\Middleware\cache_get($longKey));
    }

    // ── Max size enforcement (response cache) ─────────────────────

    public function testMaxEntriesEvictionOnResponseCache(): void
    {
        $cache = new ResponseCache(['ttl' => 60, 'maxEntries' => 5]);

        for ($i = 0; $i < 5; $i++) {
            $cache->_internalStore('GET', "/page/$i", "body$i", 'text/plain', 200);
        }

        // Storing one more should evict the oldest
        $cache->_internalStore('GET', '/page/5', 'body5', 'text/plain', 200);

        $this->assertNull($cache->_internalLookup('GET', '/page/0'));
        $this->assertNotNull($cache->_internalLookup('GET', '/page/5'));
    }

    // ── Cache stats ────────────────────────────────────────────────

    public function testCacheStatsAfterOperations(): void
    {
        \Tina4\Middleware\cache_set('s1', 'v1');
        \Tina4\Middleware\cache_set('s2', 'v2');
        \Tina4\Middleware\cache_set('s3', 'v3');

        $stats = ResponseCache::cacheStats();
        $this->assertGreaterThanOrEqual(3, $stats['size']);
        $this->assertArrayHasKey('hits', $stats);
        $this->assertArrayHasKey('misses', $stats);
        $this->assertArrayHasKey('backend', $stats);
    }

    public function testCacheStatsHitsAndMisses(): void
    {
        \Tina4\Middleware\cache_set('hit_key', 'value');
        \Tina4\Middleware\cache_get('hit_key');      // hit
        \Tina4\Middleware\cache_get('miss_key');     // miss

        $stats = ResponseCache::cacheStats();
        $this->assertGreaterThanOrEqual(1, $stats['hits']);
        $this->assertGreaterThanOrEqual(1, $stats['misses']);
    }

    // ── Clear ──────────────────────────────────────────────────────

    public function testClearRemovesAllEntries(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        \Tina4\Middleware\cache_set('c1', 'v1');
        \Tina4\Middleware\cache_set('c2', 'v2');
        $cache->_internalStore('GET', '/cached', 'body', 'text/plain', 200);

        ResponseCache::clearCache();
        $cache->clear();

        $this->assertNull(\Tina4\Middleware\cache_get('c1'));
        $this->assertNull(\Tina4\Middleware\cache_get('c2'));
        $this->assertNull($cache->_internalLookup('GET', '/cached'));
    }

    public function testClearResetsStats(): void
    {
        \Tina4\Middleware\cache_set('x', 'y');
        \Tina4\Middleware\cache_get('x');
        ResponseCache::clearCache();

        $stats = ResponseCache::cacheStats();
        $this->assertEquals(0, $stats['hits']);
        $this->assertEquals(0, $stats['misses']);
    }

    // ── Sweep ──────────────────────────────────────────────────────

    public function testSweepRemovesOnlyExpired(): void
    {
        $cache = new ResponseCache(['ttl' => 1]);

        $cache->_internalStore('GET', '/old1', 'a', 'text/plain', 200);
        $cache->_internalStore('GET', '/old2', 'b', 'text/plain', 200);

        usleep(1100000); // 1.1s — these two are now expired

        // Add a fresh one
        $fresh = new ResponseCache(['ttl' => 60]);
        $fresh->_internalStore('GET', '/new', 'c', 'text/plain', 200);

        $removed = $fresh->sweep();
        $this->assertEquals(2, $removed);

        // Fresh entry survives
        $this->assertNotNull($fresh->_internalLookup('GET', '/new'));
    }

    // ── Overwrite behavior ─────────────────────────────────────────

    public function testSetOverwritesExistingKey(): void
    {
        \Tina4\Middleware\cache_set('overwrite', 'first');
        \Tina4\Middleware\cache_set('overwrite', 'second');
        $this->assertEquals('second', \Tina4\Middleware\cache_get('overwrite'));
    }

    public function testStoreOverwritesExistingUrl(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $cache->_internalStore('GET', '/page', 'old', 'text/plain', 200);
        $cache->_internalStore('GET', '/page', 'new', 'text/plain', 200);

        $hit = $cache->_internalLookup('GET', '/page');
        $this->assertEquals('new', $hit['body']);
    }

    // ── File backend ───────────────────────────────────────────────

    public function testFileBackendSetGetDelete(): void
    {
        $cacheDir = sys_get_temp_dir() . '/tina4_cache_test_' . uniqid();
        $cache = new ResponseCache([
            'ttl' => 60,
            'backend' => 'file',
            'cacheDir' => $cacheDir,
        ]);

        $cache->_internalSet('fkey', ['data' => 123]);
        $this->assertEquals(['data' => 123], $cache->_internalGet('fkey'));

        $cache->_internalDelete('fkey');
        $this->assertNull($cache->_internalGet('fkey'));

        $cache->clear();
        @rmdir($cacheDir);
    }

    public function testFileBackendSweep(): void
    {
        $cacheDir = sys_get_temp_dir() . '/tina4_cache_sweep_' . uniqid();
        $cache = new ResponseCache([
            'ttl' => 1,
            'backend' => 'file',
            'cacheDir' => $cacheDir,
        ]);

        $cache->_internalStore('GET', '/f1', 'a', 'text/plain', 200);

        usleep(1100000);

        $removed = $cache->sweep();
        $this->assertGreaterThanOrEqual(1, $removed);

        $cache->clear();
        @rmdir($cacheDir);
    }

    public function testFileBackendClearCache(): void
    {
        $cacheDir = sys_get_temp_dir() . '/tina4_cache_clear_' . uniqid();
        $cache = new ResponseCache([
            'ttl' => 60,
            'backend' => 'file',
            'cacheDir' => $cacheDir,
        ]);

        $cache->_internalSet('a', 1);
        $cache->_internalSet('b', 2);
        $cache->clear();

        $this->assertNull($cache->_internalGet('a'));
        $this->assertNull($cache->_internalGet('b'));

        @rmdir($cacheDir);
    }

    // ── TTL zero disables caching ──────────────────────────────────

    public function testTtlZeroDisablesResponseCaching(): void
    {
        $cache = new ResponseCache(['ttl' => 0]);
        $cache->_internalStore('GET', '/disabled', 'body', 'text/plain', 200);
        $this->assertNull($cache->_internalLookup('GET', '/disabled'));
    }

    // ── Case insensitive method handling ───────────────────────────

    public function testMethodCaseInsensitive(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $cache->_internalStore('get', '/lower', 'body', 'text/plain', 200);
        $hit = $cache->_internalLookup('GET', '/lower');
        $this->assertNotNull($hit);
    }
}
