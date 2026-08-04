<?php

/**
 * Tina4 - The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * CACHE CONTRACT - a cached null round-trips as null.
 *
 * Pins `a-cached-null-round-trips-as-null` from
 * tina4-documentation/plan/v3/fixtures/cache_contract.json (ADR-0024):
 *
 *     A cached value of null/None/nil comes back as that value, not as the
 *     storage envelope that wrapped it, and not as a miss.
 *
 * MEASURED in Node: the file backend handed back the storage ENVELOPE
 * ({key, value, expiresAt}) instead of the value when the cached value was
 * null, because it read `data.value ?? data`. The caller received an object
 * where it had stored nothing, so `if (cached)` was TRUE for a cached null and
 * the cache turned an absence into a presence. Caching "this lookup found
 * nothing" is the single most common reason to cache a null, so the wrong
 * answer was served exactly where the feature is used.
 *
 * PHP is expected to be correct here; these are PARITY LOCK-IN tests, and they
 * are mutation-proven so they are real gates rather than decoration.
 *
 * A note on what "not as a miss" can mean. In PHP, null IS the miss sentinel of
 * the backend contract, so a caller cannot separate the two from the return
 * value alone. The observable that DOES separate them is the backend's own
 * hit/miss accounting, which is public through stats(), so that is what these
 * tests assert. The stronger form - a distinguishable sentinel or a $default
 * parameter, which is what Django and Rails expose - is a contract change
 * beyond this invariant and is recorded as owed rather than smuggled in.
 *
 * Every backend here is REAL. Nothing is simulated.
 *
 * SERVICE ADDRESSES
 *     TINA4_TEST_REDIS_URL      (default redis://127.0.0.1:6379)
 *     TINA4_TEST_VALKEY_URL     (default valkey://127.0.0.1:6380)
 *     TINA4_TEST_MEMCACHED_URL  (default memcached://127.0.0.1:11211)
 *     TINA4_TEST_MONGO_URI      (default mongodb://127.0.0.1:27017)
 */

use PHPUnit\Framework\TestCase;
use Tina4\Cache\CacheBackend;
use Tina4\Cache\CacheFactory;

class CacheNullRoundTripTest extends TestCase
{
    private const MONGO_COLLECTION = 'tina4_cache_contract_null_php';

    /** @var array<int, string> paths created by a test, removed in tearDown */
    private array $temporaryPaths = [];

    private function redisUrl(): string
    {
        return getenv('TINA4_TEST_REDIS_URL') ?: 'redis://127.0.0.1:6379';
    }

    private function valkeyUrl(): string
    {
        return getenv('TINA4_TEST_VALKEY_URL') ?: 'valkey://127.0.0.1:6380';
    }

    private function memcachedUrl(): string
    {
        return getenv('TINA4_TEST_MEMCACHED_URL') ?: 'memcached://127.0.0.1:11211';
    }

    private function mongoUrl(): string
    {
        return getenv('TINA4_TEST_MONGO_URI') ?: 'mongodb://127.0.0.1:27017';
    }

    protected function setUp(): void
    {
        \Tina4\DotEnv::resetEnv();
        foreach (['TINA4_CACHE_BACKEND', 'TINA4_CACHE_URL', 'TINA4_CACHE_DIR', 'TINA4_DATABASE_URL'] as $key) {
            unset($_ENV[$key]);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                foreach ((glob($path . '/*') ?: []) as $file) {
                    @unlink($file);
                }
                @rmdir($path);
            }
        }
        $this->temporaryPaths = [];
        \Tina4\DotEnv::resetEnv();
    }

    private function tempPath(string $name): string
    {
        $path = sys_get_temp_dir() . '/tina4-null-' . bin2hex(random_bytes(6)) . "-{$name}";
        $this->temporaryPaths[] = $path;
        return $path;
    }

    private function requireService(string $url, int $defaultPort, string $service): void
    {
        $parts = parse_url(str_contains($url, '://') ? $url : '//' . $url);
        $host = $parts['host'] ?? '127.0.0.1';
        $port = (int)($parts['port'] ?? $defaultPort);
        $sock = @fsockopen($host, $port, $errno, $errstr, 2);
        if (!$sock) {
            $this->markTestSkipped("{$service} service not reachable at {$host}:{$port}");
        }
        fclose($sock);
    }

    /**
     * Every provider the framework offers, each a REAL one.
     *
     * @return array<int, CacheBackend>
     */
    private function everyBackend(string $suffix): array
    {
        $this->requireService($this->redisUrl(), 6379, 'redis');
        $this->requireService($this->valkeyUrl(), 6379, 'valkey');
        $this->requireService($this->memcachedUrl(), 11211, 'memcached');
        $this->requireService($this->mongoUrl(), 27017, 'mongodb');

        return [
            CacheFactory::create('memory'),
            CacheFactory::create('file', cacheDir: $this->tempPath("files-{$suffix}")),
            CacheFactory::create('database', url: 'sqlite:///' . $this->tempPath("db-{$suffix}.db")),
            CacheFactory::create('redis', url: $this->redisUrl()),
            CacheFactory::create('valkey', url: $this->valkeyUrl()),
            CacheFactory::create('memcached', url: $this->memcachedUrl()),
            CacheFactory::create(
                'mongodb',
                url: $this->mongoUrl(),
                mongoCollection: self::MONGO_COLLECTION
            ),
        ];
    }

    private function key(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(16));
    }

    // -- the rule ------------------------------------------------------------

    /** The rule, on every provider. */
    public function testACachedNullComesBackAsNull(): void
    {
        foreach ($this->everyBackend('comesback') as $backend) {
            $key = $this->key('null');
            $backend->set($key, null, 300);

            $got = $backend->get($key);

            $this->assertNull(
                $got,
                $backend->name() . ' returned a value for a cached null - the caller '
                . 'gets something where it stored nothing'
            );
        }
    }

    /**
     * The measured Node defect, stated so it cannot pass by accident.
     *
     * Returning the envelope makes a cached null TRUTHY, so every
     * `if ($cached)` guard in every application inverts.
     */
    public function testACachedNullIsNotTheStorageEnvelope(): void
    {
        foreach ($this->everyBackend('envelope') as $backend) {
            $key = $this->key('null');
            $backend->set($key, null, 300);

            $got = $backend->get($key);

            $this->assertIsNotArray(
                $got,
                $backend->name() . ' returned the storage ENVELOPE instead of the cached null'
            );
            $this->assertFalse(
                (bool)$got,
                $backend->name() . ' made a cached null TRUTHY, so `if ($cached)` is '
                . 'true for a value that is absent'
            );
        }
    }

    /**
     * The cache must know it HAS the entry, even though the value is null.
     *
     * This is what separates "we looked and there is nothing" from "we never
     * looked", and it is the reason to cache a null in the first place.
     */
    public function testACachedNullIsAHitNotAMiss(): void
    {
        foreach ($this->everyBackend('hit') as $backend) {
            $backend->clear();
            $key = $this->key('null');
            $backend->set($key, null, 300);
            $before = $backend->stats();

            $backend->get($key);

            $after = $backend->stats();
            $this->assertSame(
                $before['hits'] + 1,
                $after['hits'],
                $backend->name() . ' did not count a cached null as a HIT'
            );
            $this->assertSame(
                $before['misses'],
                $after['misses'],
                $backend->name() . " counted a cached null as a MISS - the cache cannot "
                . "tell 'we looked and found nothing' from 'we never looked'"
            );
        }
    }

    /**
     * NEGATIVE: fixing the null path must not turn every miss into a hit.
     *
     * The obvious wrong fix - always report a hit and return null - satisfies
     * the tests above and destroys the cache's accounting.
     */
    public function testAMissingKeyIsStillAMiss(): void
    {
        foreach ($this->everyBackend('miss') as $backend) {
            $backend->clear();
            $before = $backend->stats();

            $this->assertNull($backend->get($this->key('never-written')));

            $after = $backend->stats();
            $this->assertSame(
                $before['misses'] + 1,
                $after['misses'],
                $backend->name() . ' did not count an absent key as a MISS'
            );
            $this->assertSame(
                $before['hits'],
                $after['hits'],
                $backend->name() . ' counted an absent key as a HIT'
            );
        }
    }

    /**
     * NEGATIVE: false, 0, "" and [] are VALUES, not absences.
     *
     * A null fix built on falsiness (`$value ?: $envelope`, `if (!$value)`)
     * breaks every one of these, and each is a perfectly ordinary thing to
     * cache. assertSame is deliberate - it compares TYPE as well as value, so a
     * backend handing back "0" for 0 or "" for false is caught.
     */
    public function testOtherFalsyValuesRoundTripIntact(): void
    {
        $falsy = [
            'false' => false,
            'zero' => 0,
            'empty-string' => '',
            'empty-array' => [],
        ];
        foreach ($this->everyBackend('falsy') as $backend) {
            foreach ($falsy as $label => $value) {
                $key = $this->key("falsy-{$label}");
                $backend->set($key, $value, 300);

                $got = $backend->get($key);

                $this->assertSame(
                    $value,
                    $got,
                    $backend->name() . " mangled a cached {$label}"
                );
            }
        }
    }
}
