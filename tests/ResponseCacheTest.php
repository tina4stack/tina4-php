<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Middleware\ResponseCache;

class ResponseCacheTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear the static store before each test
        $cache = new ResponseCache(['ttl' => 60]);
        $cache->clearCache();
    }

    // -- Store and lookup ----------------------------------------------------

    public function testStoresGetResponse(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $cache->store('GET', '/api/users', '{"users":[]}', 'application/json', 200);

        $hit = $cache->lookup('GET', '/api/users');
        $this->assertNotNull($hit);
        $this->assertEquals('{"users":[]}', $hit['body']);
        $this->assertEquals('application/json', $hit['contentType']);
        $this->assertEquals(200, $hit['statusCode']);
    }

    public function testCacheHitOnSecondRequest(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $cache->store('GET', '/page', '<html></html>', 'text/html', 200);

        $first = $cache->lookup('GET', '/page');
        $second = $cache->lookup('GET', '/page');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertEquals($first['body'], $second['body']);
    }

    // -- Non-GET methods not cached ------------------------------------------

    public function testPostNotCached(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $cache->store('POST', '/api/users', '{}', 'application/json', 200);
        $this->assertNull($cache->lookup('POST', '/api/users'));
    }

    public function testPutNotCached(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $cache->store('PUT', '/api/users/1', '{}', 'application/json', 200);
        $this->assertNull($cache->lookup('PUT', '/api/users/1'));
    }

    public function testDeleteNotCached(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $cache->store('DELETE', '/api/users/1', '', 'text/plain', 204);
        $this->assertNull($cache->lookup('DELETE', '/api/users/1'));
    }

    // -- TTL expiry ----------------------------------------------------------

    public function testTtlExpiry(): void
    {
        $cache = new ResponseCache(['ttl' => 1]);
        $cache->store('GET', '/expire', 'data', 'text/plain', 200);

        $this->assertNotNull($cache->lookup('GET', '/expire'));

        usleep(1100000); // 1.1 seconds

        $this->assertNull($cache->lookup('GET', '/expire'));
    }

    public function testTtlZeroDisablesCache(): void
    {
        $cache = new ResponseCache(['ttl' => 0]);
        $cache->store('GET', '/disabled', 'data', 'text/plain', 200);
        $this->assertNull($cache->lookup('GET', '/disabled'));
    }

    // -- LRU eviction --------------------------------------------------------

    public function testLruEvictionAtMaxEntries(): void
    {
        $cache = new ResponseCache(['ttl' => 60, 'maxEntries' => 3]);

        $cache->store('GET', '/a', 'a', 'text/plain', 200);
        $cache->store('GET', '/b', 'b', 'text/plain', 200);
        $cache->store('GET', '/c', 'c', 'text/plain', 200);

        // At capacity. Storing a 4th should evict the oldest (/a).
        $cache->store('GET', '/d', 'd', 'text/plain', 200);

        $this->assertNull($cache->lookup('GET', '/a'));
        $this->assertNotNull($cache->lookup('GET', '/b'));
        $this->assertNotNull($cache->lookup('GET', '/d'));
    }

    // -- Status code filtering -----------------------------------------------

    public function testOnlyConfiguredStatusCodesCached(): void
    {
        $cache = new ResponseCache(['ttl' => 60, 'statusCodes' => [200]]);

        $cache->store('GET', '/ok', 'ok', 'text/plain', 200);
        $cache->store('GET', '/notfound', 'nf', 'text/plain', 404);

        $this->assertNotNull($cache->lookup('GET', '/ok'));
        $this->assertNull($cache->lookup('GET', '/notfound'));
    }

    public function testMultipleStatusCodesCached(): void
    {
        $cache = new ResponseCache(['ttl' => 60, 'statusCodes' => [200, 301]]);

        $cache->store('GET', '/ok', 'ok', 'text/plain', 200);
        $cache->store('GET', '/redirect', 'redir', 'text/plain', 301);
        $cache->store('GET', '/error', 'err', 'text/plain', 500);

        $this->assertNotNull($cache->lookup('GET', '/ok'));
        $this->assertNotNull($cache->lookup('GET', '/redirect'));
        $this->assertNull($cache->lookup('GET', '/error'));
    }

    // -- cacheStats ----------------------------------------------------------

    public function testCacheStatsReturnsCorrectCounts(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $cache->store('GET', '/x', 'x', 'text/plain', 200);
        $cache->store('GET', '/y', 'y', 'text/plain', 200);

        $stats = $cache->cacheStats();
        $this->assertEquals(2, $stats['size']);
        $this->assertArrayHasKey('backend', $stats);
    }

    public function testCacheStatsEmpty(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $stats = $cache->cacheStats();
        $this->assertEquals(0, $stats['size']);
    }

    public function testCacheStatsHasBackendField(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $stats = $cache->cacheStats();
        $this->assertEquals('memory', $stats['backend']);
    }

    // -- clearCache ----------------------------------------------------------

    public function testClearCacheResetsEverything(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $cache->store('GET', '/a', 'a', 'text/plain', 200);
        $cache->store('GET', '/b', 'b', 'text/plain', 200);

        $cache->clearCache();

        $this->assertNull($cache->lookup('GET', '/a'));
        $this->assertNull($cache->lookup('GET', '/b'));
        $stats = $cache->cacheStats();
        $this->assertEquals(0, $stats['size']);
    }

    // -- sweep ---------------------------------------------------------------

    public function testSweepRemovesExpiredEntries(): void
    {
        $cache = new ResponseCache(['ttl' => 1]);
        $cache->store('GET', '/sweep1', 'a', 'text/plain', 200);
        $cache->store('GET', '/sweep2', 'b', 'text/plain', 200);

        usleep(1100000); // 1.1 seconds

        // Store one more that is not expired
        $fresh = new ResponseCache(['ttl' => 60]);
        $fresh->store('GET', '/fresh', 'c', 'text/plain', 200);

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

        // Test basic operations
        $cache->store('GET', '/file-test', 'data', 'text/plain', 200);
        $hit = $cache->lookup('GET', '/file-test');
        $this->assertNotNull($hit);
        $this->assertEquals('data', $hit['body']);

        // Cleanup
        $cache->clearCache();
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

    // -- Direct cache API ---------------------------------------------------

    public function testDirectSetAndGet(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $cache->clearCache();
        $cache->set('test_key', ['hello' => 'world'], 60);
        $result = $cache->get('test_key');
        $this->assertEquals(['hello' => 'world'], $result);
    }

    public function testDirectGetMissing(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $cache->clearCache();
        $this->assertNull($cache->get('nonexistent_key_12345'));
    }

    public function testDirectDelete(): void
    {
        $cache = new ResponseCache(['ttl' => 60]);
        $cache->clearCache();
        $cache->set('del_key', 'value', 60);
        $this->assertTrue($cache->delete('del_key'));
        $this->assertNull($cache->get('del_key'));
        $this->assertFalse($cache->delete('del_key'));
    }

    // -- File backend direct API ---------------------------------------------

    public function testFileBackendDirectAPI(): void
    {
        $cacheDir = sys_get_temp_dir() . '/tina4_test_cache_direct_' . uniqid();
        $cache = new ResponseCache([
            'ttl' => 60,
            'backend' => 'file',
            'cacheDir' => $cacheDir,
        ]);

        $cache->set('file_key', ['data' => true], 60);
        $result = $cache->get('file_key');
        $this->assertEquals(['data' => true], $result);

        $cache->delete('file_key');
        $this->assertNull($cache->get('file_key'));

        // Cleanup
        $cache->clearCache();
        @rmdir($cacheDir);
    }
}
