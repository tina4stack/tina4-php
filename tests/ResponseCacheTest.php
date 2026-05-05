<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Public API tests for ResponseCache parity with Python tina4_python.cache.
 *
 * Public surface:
 *   - ResponseCache class — used as @middleware on a route
 *   - ResponseCache::cacheStats() — static, module-level
 *   - ResponseCache::clearCache() — static, module-level
 *   - cache_stats() / cache_clear() — namespace-level convenience wrappers
 *
 * Internal lookup/store of GET responses is covered indirectly via middleware
 * hooks (beforeCache / afterCache) and via _internal* test seams (marked
 * @internal in the class).
 */

use PHPUnit\Framework\TestCase;
use Tina4\Middleware\ResponseCache;

class ResponseCacheTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset the module-level singleton state before each test
        ResponseCache::clearCache();
    }

    // -- Internal store / lookup (via middleware test seams) -----------------

    public function testStoresGetResponse(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $cache->_internalStore('GET', '/api/users', '{"users":[]}', 'application/json', 200);

        $hit = $cache->_internalLookup('GET', '/api/users');
        $this->assertNotNull($hit);
        $this->assertEquals('{"users":[]}', $hit['body']);
        $this->assertEquals('application/json', $hit['contentType']);
        $this->assertEquals(200, $hit['statusCode']);
    }

    public function testCacheHitOnSecondRequest(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $cache->_internalStore('GET', '/page', '<html></html>', 'text/html', 200);

        $first = $cache->_internalLookup('GET', '/page');
        $second = $cache->_internalLookup('GET', '/page');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertEquals($first['body'], $second['body']);
    }

    // -- Non-GET methods not cached ------------------------------------------

    public function testPostNotCached(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $cache->_internalStore('POST', '/api/users', '{}', 'application/json', 200);
        $this->assertNull($cache->_internalLookup('POST', '/api/users'));
    }

    public function testPutNotCached(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $cache->_internalStore('PUT', '/api/users/1', '{}', 'application/json', 200);
        $this->assertNull($cache->_internalLookup('PUT', '/api/users/1'));
    }

    public function testDeleteNotCached(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $cache->_internalStore('DELETE', '/api/users/1', '', 'text/plain', 204);
        $this->assertNull($cache->_internalLookup('DELETE', '/api/users/1'));
    }

    // -- TTL expiry ----------------------------------------------------------

    public function testTtlExpiry(): void
    {
        $cache = new ResponseCache(['ttl' => 1]);
        $cache->_internalStore('GET', '/expire', 'data', 'text/plain', 200);

        $this->assertNotNull($cache->_internalLookup('GET', '/expire'));

        usleep(1100000); // 1.1 seconds

        $this->assertNull($cache->_internalLookup('GET', '/expire'));
    }

    public function testTtlZeroDisablesCache(): void
    {
        $cache = new ResponseCache(['ttl' => 0]);
        $cache->_internalStore('GET', '/disabled', 'data', 'text/plain', 200);
        $this->assertNull($cache->_internalLookup('GET', '/disabled'));
    }

    // -- LRU eviction --------------------------------------------------------

    public function testLruEvictionAtMaxEntries(): void
    {
        $cache = new ResponseCache(['ttl' => 60, 'maxEntries' => 3]);

        $cache->_internalStore('GET', '/a', 'a', 'text/plain', 200);
        $cache->_internalStore('GET', '/b', 'b', 'text/plain', 200);
        $cache->_internalStore('GET', '/c', 'c', 'text/plain', 200);

        // At capacity. Storing a 4th should evict the oldest (/a).
        $cache->_internalStore('GET', '/d', 'd', 'text/plain', 200);

        $this->assertNull($cache->_internalLookup('GET', '/a'));
        $this->assertNotNull($cache->_internalLookup('GET', '/b'));
        $this->assertNotNull($cache->_internalLookup('GET', '/d'));
    }

    // -- Status code filtering -----------------------------------------------

    public function testOnlyConfiguredStatusCodesCached(): void
    {
        $cache = new ResponseCache(['ttl' => 60, 'statusCodes' => [200]]);

        $cache->_internalStore('GET', '/ok', 'ok', 'text/plain', 200);
        $cache->_internalStore('GET', '/notfound', 'nf', 'text/plain', 404);

        $this->assertNotNull($cache->_internalLookup('GET', '/ok'));
        $this->assertNull($cache->_internalLookup('GET', '/notfound'));
    }

    public function testMultipleStatusCodesCached(): void
    {
        $cache = new ResponseCache(['ttl' => 60, 'statusCodes' => [200, 301]]);

        $cache->_internalStore('GET', '/ok', 'ok', 'text/plain', 200);
        $cache->_internalStore('GET', '/redirect', 'redir', 'text/plain', 301);
        $cache->_internalStore('GET', '/error', 'err', 'text/plain', 500);

        $this->assertNotNull($cache->_internalLookup('GET', '/ok'));
        $this->assertNotNull($cache->_internalLookup('GET', '/redirect'));
        $this->assertNull($cache->_internalLookup('GET', '/error'));
    }

    // -- cacheStats (instance) ----------------------------------------------

    public function testCacheStatsReturnsCorrectCounts(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $cache->_internalStore('GET', '/x', 'x', 'text/plain', 200);
        $cache->_internalStore('GET', '/y', 'y', 'text/plain', 200);

        $stats = $cache->getStats();
        $this->assertEquals(2, $stats['size']);
        $this->assertArrayHasKey('backend', $stats);
    }

    public function testCacheStatsEmpty(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $stats = $cache->getStats();
        $this->assertEquals(0, $stats['size']);
    }

    public function testCacheStatsHasBackendField(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $stats = $cache->getStats();
        $this->assertEquals('memory', $stats['backend']);
    }

    // -- Static module-level API (Python parity) ---------------------------

    public function testStaticCacheStats(): void
    {
        $stats = ResponseCache::cacheStats();
        $this->assertArrayHasKey('hits', $stats);
        $this->assertArrayHasKey('misses', $stats);
        $this->assertArrayHasKey('size', $stats);
        $this->assertArrayHasKey('backend', $stats);
    }

    public function testStaticClearCache(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $cache->_internalStore('GET', '/a', 'a', 'text/plain', 200);

        ResponseCache::clearCache();

        $stats = ResponseCache::cacheStats();
        $this->assertEquals(0, $stats['size']);
    }

    // -- clear (instance) ---------------------------------------------------

    public function testClearResetsEverything(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $cache->_internalStore('GET', '/a', 'a', 'text/plain', 200);
        $cache->_internalStore('GET', '/b', 'b', 'text/plain', 200);

        $cache->clear();

        $this->assertNull($cache->_internalLookup('GET', '/a'));
        $this->assertNull($cache->_internalLookup('GET', '/b'));
        $stats = $cache->getStats();
        $this->assertEquals(0, $stats['size']);
    }

    // -- sweep ---------------------------------------------------------------

    public function testSweepRemovesExpiredEntries(): void
    {
        $cache = new ResponseCache(['ttl' => 1]);
        $cache->_internalStore('GET', '/sweep1', 'a', 'text/plain', 200);
        $cache->_internalStore('GET', '/sweep2', 'b', 'text/plain', 200);

        usleep(1100000); // 1.1 seconds

        // Store one more that is not expired
        $fresh = new ResponseCache(['ttl' => 60]);
        $fresh->_internalStore('GET', '/fresh', 'c', 'text/plain', 200);

        $removed = $fresh->sweep();
        $this->assertEquals(2, $removed);
    }

    // -- Backend selection ---------------------------------------------------

    public function testDefaultBackendIsMemory(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $this->assertEquals('memory', $cache->getBackend());
    }

    public function testBackendParamSelectsFile(): void
    {
        $cacheDir = sys_get_temp_dir() . '/tina4_test_cache_' . uniqid();
        $cache = new ResponseCache([
            'ttl' => 60,
            'backend' => 'file',
            'cacheDir' => $cacheDir,
        ]);
        $this->assertEquals('file', $cache->getBackend());

        // Test basic operations via internal seams
        $cache->_internalStore('GET', '/file-test', 'data', 'text/plain', 200);
        $hit = $cache->_internalLookup('GET', '/file-test');
        $this->assertNotNull($hit);
        $this->assertEquals('data', $hit['body']);

        // Cleanup
        $cache->clear();
        @rmdir($cacheDir);
    }

    public function testEnvBackendSelection(): void
    {
        $original = getenv('TINA4_CACHE_BACKEND');
        putenv('TINA4_CACHE_BACKEND=memory');
        try {
            $cache = new ResponseCache(['ttl' => 60]);
            $this->assertEquals('memory', $cache->getBackend());
        } finally {
            if ($original !== false) {
                putenv("TINA4_CACHE_BACKEND=$original");
            } else {
                putenv('TINA4_CACHE_BACKEND');
            }
        }
    }

    public function testExplicitBackendOverridesEnv(): void
    {
        $original = getenv('TINA4_CACHE_BACKEND');
        putenv('TINA4_CACHE_BACKEND=file');
        try {
            $cache = new ResponseCache(['ttl' => 60, 'backend' => 'memory']);
            $this->assertEquals('memory', $cache->getBackend());
        } finally {
            if ($original !== false) {
                putenv("TINA4_CACHE_BACKEND=$original");
            } else {
                putenv('TINA4_CACHE_BACKEND');
            }
        }
    }

    // -- Namespace-level KV API ----------------------------------------------

    public function testNamespaceCacheSetAndGet(): void
    {
        \Tina4\Middleware\cache_clear();
        \Tina4\Middleware\cache_set('test_key', ['hello' => 'world'], 60);
        $result = \Tina4\Middleware\cache_get('test_key');
        $this->assertEquals(['hello' => 'world'], $result);
    }

    public function testNamespaceCacheGetMissing(): void
    {
        \Tina4\Middleware\cache_clear();
        $this->assertNull(\Tina4\Middleware\cache_get('nonexistent_key_12345'));
    }

    public function testNamespaceCacheDelete(): void
    {
        \Tina4\Middleware\cache_clear();
        \Tina4\Middleware\cache_set('del_key', 'value', 60);
        $this->assertTrue(\Tina4\Middleware\cache_delete('del_key'));
        $this->assertNull(\Tina4\Middleware\cache_get('del_key'));
        $this->assertFalse(\Tina4\Middleware\cache_delete('del_key'));
    }

    // -- File backend KV via namespace API -----------------------------------

    public function testFileBackendDirectAPI(): void
    {
        $cacheDir = sys_get_temp_dir() . '/tina4_test_cache_direct_' . uniqid();
        $cache = new ResponseCache([
            'ttl' => 60,
            'backend' => 'file',
            'cacheDir' => $cacheDir,
        ]);

        $cache->_internalSet('file_key', ['data' => true], 60);
        $result = $cache->_internalGet('file_key');
        $this->assertEquals(['data' => true], $result);

        $cache->_internalDelete('file_key');
        $this->assertNull($cache->_internalGet('file_key'));

        // Cleanup
        $cache->clear();
        @rmdir($cacheDir);
    }
}
