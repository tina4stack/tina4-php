<?php

/**
 * Tina4 - The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * CACHE CONTRACT - sweep() returns a real count, everywhere.
 *
 * Pins `sweep-returns-a-real-count-everywhere` from
 * tina4-documentation/plan/v3/fixtures/cache_contract.json (ADR-0024):
 *
 *     sweep() evicts expired entries and returns how many it evicted, on every
 *     provider.
 *
 * MEASURED: sweep() behaved three different ways across the family - real
 * counts in Python/PHP, a NoMethodError crash on 6 of 7 providers in Ruby, and
 * a permanent 0 in Node. A monitoring dashboard reading that number is reading
 * three different things, and one of them is a crash.
 *
 * AND the defect this file found in PHP: the DATABASE backend had no sweep() at
 * all, so it inherited the base class's 0. redis, valkey, memcached and mongodb
 * expire entries SERVER-SIDE, so 0 is the honest answer for them - nothing was
 * evicted because there was nothing left to evict. A SQL table does not expire
 * anything by itself: rows were deleted only when someone happened to read that
 * exact key again, so expired rows accumulated forever and the one API whose
 * job is reclaiming that space reported success having done nothing.
 *
 * Every backend here is REAL - a real in-process store, a real directory on
 * disk, a real SQLite database, and the real network services. Nothing is
 * simulated.
 *
 * SERVICE ADDRESSES
 *     TINA4_TEST_CACHE_REDIS_URL      (default redis://127.0.0.1:6379)
 *     TINA4_TEST_CACHE_VALKEY_URL     (default valkey://127.0.0.1:6380)
 *     TINA4_TEST_CACHE_MEMCACHED_URL  (default memcached://127.0.0.1:11211)
 *     TINA4_TEST_CACHE_MONGO_URL      (default mongodb://127.0.0.1:27017)
 */

use PHPUnit\Framework\TestCase;
use Tina4\Cache\CacheBackend;
use Tina4\Cache\CacheFactory;

class CacheSweepCountsTest extends TestCase
{
    /** Collection this contract OWNS, distinct from the other suites'. */
    private const MONGO_COLLECTION = 'tina4_cache_contract_sweep_php';

    /** @var array<int, string> paths created by a test, removed in tearDown */
    private array $temporaryPaths = [];

    private function redisUrl(): string
    {
        return getenv('TINA4_TEST_CACHE_REDIS_URL') ?: 'redis://127.0.0.1:6379';
    }

    private function valkeyUrl(): string
    {
        return getenv('TINA4_TEST_CACHE_VALKEY_URL') ?: 'valkey://127.0.0.1:6380';
    }

    private function memcachedUrl(): string
    {
        return getenv('TINA4_TEST_CACHE_MEMCACHED_URL') ?: 'memcached://127.0.0.1:11211';
    }

    private function mongoUrl(): string
    {
        return getenv('TINA4_TEST_CACHE_MONGO_URL') ?: 'mongodb://127.0.0.1:27017';
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
        $path = sys_get_temp_dir() . '/tina4-sweep-' . bin2hex(random_bytes(6)) . "-{$name}";
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
     * The three providers that hold entries LOCALLY.
     *
     * memory, file and database all keep an expired entry until something
     * removes it, so their sweep count must be exact. The network providers
     * expire server-side and honestly report 0.
     *
     * @return array<int, CacheBackend>
     */
    private function localBackends(string $suffix): array
    {
        return [
            CacheFactory::create('memory'),
            CacheFactory::create('file', cacheDir: $this->tempPath("files-{$suffix}")),
            CacheFactory::create('database', url: 'sqlite:///' . $this->tempPath("db-{$suffix}.db")),
        ];
    }

    /**
     * Every provider the framework offers, each a REAL one.
     *
     * @return array<int, CacheBackend>
     */
    private function allBackends(string $suffix): array
    {
        $this->requireService($this->redisUrl(), 6379, 'redis');
        $this->requireService($this->valkeyUrl(), 6379, 'valkey');
        $this->requireService($this->memcachedUrl(), 11211, 'memcached');
        $this->requireService($this->mongoUrl(), 27017, 'mongodb');

        return array_merge($this->localBackends($suffix), [
            CacheFactory::create('redis', url: $this->redisUrl()),
            CacheFactory::create('valkey', url: $this->valkeyUrl()),
            CacheFactory::create('memcached', url: $this->memcachedUrl()),
            CacheFactory::create(
                'mongodb',
                url: $this->mongoUrl(),
                mongoCollection: self::MONGO_COLLECTION
            ),
        ]);
    }

    // -- the rule ------------------------------------------------------------

    /**
     * Every provider ANSWERS sweep() with a non-negative integer.
     *
     * This is the Ruby NoMethodError in contract form: a method that exists on
     * one provider and blows up on six is not a swappable API, and a caller
     * that has to guard for its absence cannot tell "not supported" from
     * "evicted nothing".
     */
    public function testSweepIsAvailableOnEveryProvider(): void
    {
        foreach ($this->allBackends('available') as $backend) {
            $result = $backend->sweep();
            $this->assertIsInt(
                $result,
                $backend->name() . '::sweep() did not return an integer count'
            );
            $this->assertGreaterThanOrEqual(
                0,
                $result,
                $backend->name() . '::sweep() returned a negative count'
            );
        }
    }

    /**
     * The count is REAL on every provider that holds entries locally.
     *
     * memory, file and database all keep expired entries until something
     * removes them, so the count must be exact.
     */
    public function testSweepReturnsTheNumberOfEntriesItEvicted(): void
    {
        foreach ($this->localBackends('evicted') as $backend) {
            $backend->clear();
            for ($index = 0; $index < 3; $index++) {
                $backend->set("doomed-{$index}", ['i' => $index], 1);
            }
            $backend->set('survivor', ['i' => 'keep'], 300);
            usleep(1_200_000);

            $evicted = $backend->sweep();

            $this->assertSame(
                3,
                $evicted,
                $backend->name() . "::sweep() reported {$evicted} evictions, expected 3 - "
                . 'the number a monitoring dashboard reads is not the number of '
                . 'entries actually reclaimed'
            );
            $this->assertSame(
                ['i' => 'keep'],
                $backend->get('survivor'),
                $backend->name() . '::sweep() removed a LIVE entry'
            );
        }
    }

    /**
     * The SQL cache table must actually shrink.
     *
     * A database cache does not self-expire. Before this, expired rows were
     * deleted only when someone re-read that exact key, so the table grew
     * without bound and sweep() - the API whose whole job is reclaiming that
     * space - returned 0 having done nothing.
     */
    public function testSweepEvictsExpiredEntriesFromTheDatabaseBackend(): void
    {
        $backend = CacheFactory::create(
            'database',
            url: 'sqlite:///' . $this->tempPath('sweep.db')
        );
        $backend->clear();
        for ($index = 0; $index < 4; $index++) {
            $backend->set("expired-{$index}", ['i' => $index], 1);
        }
        $backend->set('live', ['i' => 'live'], 300);
        usleep(1_200_000);
        $this->assertSame(
            5,
            $backend->stats()['size'],
            'precondition: the expired rows are still on disk'
        );

        $evicted = $backend->sweep();

        $this->assertSame(4, $evicted, "sweep() reported {$evicted}, expected 4 expired rows");
        $this->assertSame(
            1,
            $backend->stats()['size'],
            'the expired rows are still in the tina4_cache table - sweep() counted '
            . 'them but did not delete them'
        );
    }

    /**
     * NEGATIVE: a sweep with nothing to do reports 0, it does not guess.
     *
     * Catches a sweep that returns the total entry count, or the number it
     * inspected, rather than the number it evicted.
     */
    public function testSweepReturnsZeroWhenNothingHasExpired(): void
    {
        foreach ($this->localBackends('zero') as $backend) {
            $backend->clear();
            for ($index = 0; $index < 3; $index++) {
                $backend->set("live-{$index}", ['i' => $index], 300);
            }

            $this->assertSame(
                0,
                $backend->sweep(),
                $backend->name() . '::sweep() reported evictions with nothing expired'
            );
            $this->assertSame(
                3,
                $backend->stats()['size'],
                $backend->name() . '::sweep() deleted live entries'
            );
        }
    }

    /**
     * NEGATIVE: ttl <= 0 means "never expires" and sweep must respect it.
     *
     * An entry stored with no TTL carries expires_at 0. A sweep comparing
     * `now > expires_at` without excluding 0 would evict every permanent entry
     * on its first run - silently, and reported as a successful reclaim.
     */
    public function testSweepLeavesEntriesWithoutATtlAlone(): void
    {
        foreach ($this->localBackends('nottl') as $backend) {
            $backend->clear();
            $backend->set('permanent', ['i' => 'forever'], 0);
            usleep(200_000);

            $this->assertSame(
                0,
                $backend->sweep(),
                $backend->name() . '::sweep() evicted an entry stored with no TTL'
            );
            $this->assertSame(
                ['i' => 'forever'],
                $backend->get('permanent'),
                $backend->name() . ' lost a permanent entry to sweep()'
            );
        }
    }
}
