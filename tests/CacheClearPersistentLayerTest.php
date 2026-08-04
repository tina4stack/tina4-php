<?php

/**
 * Tina4 - The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * CACHE CONTRACT - cacheClear() clears the PERSISTENT layer.
 *
 * Pins `cache-clear-clears-the-persistent-layer` from
 * tina4-documentation/plan/v3/fixtures/cache_contract.json (ADR-0024):
 *
 *     The database-level cacheClear() clears the PERSISTENT shared backend, not
 *     only the in-process layer.
 *
 * MEASURED in Python: Database.cache_clear() cleared the in-process dict and
 * the counters and never touched the shared backend, so with TINA4_DB_CACHE=true
 * it was a no-op on every provider. Code that clears the cache after a bulk
 * import appeared to work in development, where the cache IS in-process, and
 * did nothing in production, where it is shared. PHP, Ruby and Node already
 * cleared the backend, so Python was fixed rather than mirrored.
 *
 * PHP is correct here - CachedDatabase::cacheClear() calls
 * $this->cacheBackend->clear(). These are PARITY LOCK-IN tests, and they are
 * mutation-proven so they are real gates rather than decoration.
 *
 * HOW ENTRIES ARE COUNTED
 *     Not through the backend's own stats(): RedisBackend::stats() reports size
 *     only on the ext-redis client path, and with no extension installed the
 *     raw RESP transport (the zero-dependency default) always reports 0. So the
 *     count comes from a SCAN over the real Redis on a socket this test owns -
 *     which is also what the Python master does for a different reason, namely
 *     that counting real entries cannot go quietly green if the key format
 *     changes.
 *
 * SERVICE ADDRESS
 *     TINA4_TEST_REDIS_URL  (default redis://127.0.0.1:6379)
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;

class CacheClearPersistentLayerTest extends TestCase
{
    private const SQL = 'SELECT owner FROM widget WHERE id = ?';
    private const PREFIX = 'tina4:cache:';

    /** @var array<int, string> */
    private array $temporaryFiles = [];

    private function redisUrl(): string
    {
        return getenv('TINA4_TEST_REDIS_URL') ?: 'redis://127.0.0.1:6379';
    }

    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }

    private function clearEnv(string $key): void
    {
        unset($_ENV[$key]);
        putenv($key);
    }

    /** @return array<int, string> */
    private function managedEnvKeys(): array
    {
        return [
            'TINA4_DB_CACHE', 'TINA4_DB_CACHE_TTL', 'TINA4_DB_CACHE_BACKEND',
            'TINA4_DB_CACHE_URL', 'TINA4_AUTO_CACHING', 'TINA4_AUTO_CACHING_TTL',
        ];
    }

    protected function setUp(): void
    {
        \Tina4\DotEnv::resetEnv();
        foreach ($this->managedEnvKeys() as $key) {
            $this->clearEnv($key);
        }
    }

    protected function tearDown(): void
    {
        \Tina4\DotEnv::resetEnv();
        foreach ($this->managedEnvKeys() as $key) {
            $this->clearEnv($key);
        }
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->temporaryFiles = [];
    }

    private function requireRedis(): void
    {
        [$host, $port] = $this->endpoint();
        $sock = @fsockopen($host, $port, $errno, $errstr, 2);
        if (!$sock) {
            $this->markTestSkipped("redis service not reachable at {$host}:{$port}");
        }
        fclose($sock);
    }

    /** @return array{0: string, 1: int} */
    private function endpoint(): array
    {
        $url = $this->redisUrl();
        $parts = parse_url(str_contains($url, '://') ? $url : '//' . $url);
        return [$parts['host'] ?? '127.0.0.1', (int)($parts['port'] ?? 6379)];
    }

    /** Point the persistent DB query cache at ONE real shared Redis. */
    private function usePersistentRedis(): void
    {
        $this->setEnv('TINA4_DB_CACHE', 'true');
        $this->setEnv('TINA4_DB_CACHE_TTL', '300');
        $this->setEnv('TINA4_DB_CACHE_BACKEND', 'redis');
        $this->setEnv('TINA4_DB_CACHE_URL', $this->redisUrl());
    }

    /**
     * Count the entries actually present in the REAL Redis under our prefix.
     *
     * A SCAN cursor loop over a socket this test owns - an oracle independent
     * of the code under test, so a broken clear cannot report itself clean.
     */
    private function entriesInRedis(): int
    {
        [$host, $port] = $this->endpoint();
        $sock = fsockopen($host, $port, $errno, $errstr, 5);
        $this->assertNotFalse($sock, "could not open a RESP socket to {$host}:{$port}");
        stream_set_timeout($sock, 5);

        $count = 0;
        $cursor = '0';
        do {
            $args = ['SCAN', $cursor, 'MATCH', self::PREFIX . '*', 'COUNT', '500'];
            $wire = '*' . count($args) . "\r\n";
            foreach ($args as $arg) {
                $wire .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
            }
            fwrite($sock, $wire);
            $reply = $this->respRead($sock);
            if (!is_array($reply) || count($reply) !== 2) {
                break;
            }
            [$cursor, $keys] = $reply;
            if (is_array($keys)) {
                $count += count($keys);
            }
        } while (is_string($cursor) && $cursor !== '' && $cursor !== '0');
        fclose($sock);
        return $count;
    }

    private function respRead($sock): string|array|null
    {
        $line = fgets($sock);
        if ($line === false || $line === '') {
            return null;
        }
        $prefix = $line[0];
        $body = rtrim(substr($line, 1), "\r\n");
        if ($prefix === '+' || $prefix === ':') {
            return $body;
        }
        if ($prefix === '-') {
            return null;
        }
        if ($prefix === '$') {
            $length = (int)$body;
            if ($length < 0) {
                return null;
            }
            $payload = '';
            while (strlen($payload) < $length + 2) {
                $chunk = fread($sock, $length + 2 - strlen($payload));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $payload .= $chunk;
            }
            return substr($payload, 0, $length);
        }
        if ($prefix === '*') {
            $count = (int)$body;
            if ($count < 0) {
                return null;
            }
            $items = [];
            for ($index = 0; $index < $count; $index++) {
                $items[] = $this->respRead($sock);
            }
            return $items;
        }
        return $body;
    }

    private function tempDatabasePath(string $name): string
    {
        $path = sys_get_temp_dir() . '/tina4-clear-persistent-' . bin2hex(random_bytes(6)) . "-{$name}.db";
        $this->temporaryFiles[] = $path;
        return $path;
    }

    private function seed(string $path): Database
    {
        $database = Database::create('sqlite:///' . $path, autoCommit: true);
        $database->execute('CREATE TABLE IF NOT EXISTS widget (id INTEGER PRIMARY KEY, owner TEXT)');
        $database->execute('DELETE FROM widget');
        $database->insert('widget', ['id' => 1, 'owner' => 'original']);
        return $database;
    }

    // -- the rule ------------------------------------------------------------

    /**
     * The rule, asserted against the REAL shared backend.
     *
     * The entry must be GONE from Redis after cacheClear(), not merely gone
     * from the in-process array that production never uses.
     */
    public function testCacheClearClearsThePersistentBackend(): void
    {
        $this->requireRedis();
        $this->usePersistentRedis();

        $database = $this->seed($this->tempDatabasePath('app'));
        $this->assertSame(
            'persistent',
            $database->cacheStats()['mode'],
            'precondition: a persistent backend is configured'
        );
        $database->cacheClear();

        $database->fetch(self::SQL, [1]);
        $this->assertGreaterThan(
            0,
            $this->entriesInRedis(),
            'precondition: the read was cached in redis'
        );

        $database->cacheClear();

        $this->assertSame(
            0,
            $this->entriesInRedis(),
            'cacheClear() left the entry in the SHARED backend - it cleared only '
            . 'the in-process array, so clearing the cache after a bulk import '
            . 'works in development and does nothing in production'
        );
    }

    /**
     * A clear on one instance must be seen by every instance.
     *
     * The end-to-end shape of the same rule: the point of a shared backend is
     * that an operator can clear the cache from ONE process after a bulk import.
     */
    public function testCacheClearIsVisibleToAnotherInstance(): void
    {
        $this->requireRedis();
        $this->usePersistentRedis();

        $path = $this->tempDatabasePath('shared');
        $one = $this->seed($path);
        $two = Database::create('sqlite:///' . $path, autoCommit: true);
        $one->cacheClear();

        $one->fetch(self::SQL, [1]);
        $this->assertGreaterThan(0, $this->entriesInRedis(), 'precondition: shared visibility');
        $this->assertSame(
            [['owner' => 'original']],
            array_map(
                static fn(array $row): array => ['owner' => $row['owner']],
                $two->fetch(self::SQL, [1])->records
            ),
            'precondition: instance two reads the same row'
        );

        $one->cacheClear();

        $this->assertSame(
            0,
            $this->entriesInRedis(),
            'instance two still sees the entry instance one cleared - the clear '
            . 'never reached the shared backend'
        );
    }

    /**
     * NEGATIVE: clearing must not poison the cache.
     *
     * A clear that also broke subsequent caching would pass the assertion above
     * and still be wrong, so this pins that a read AFTER a clear caches again.
     */
    public function testCacheClearLeavesTheBackendUsable(): void
    {
        $this->requireRedis();
        $this->usePersistentRedis();

        $database = $this->seed($this->tempDatabasePath('reusable'));
        $database->cacheClear();
        $database->fetch(self::SQL, [1]);

        $this->assertGreaterThan(
            0,
            $this->entriesInRedis(),
            'nothing was cached after cacheClear() - the clear left the backend unusable'
        );
        $stats = $database->cacheStats();
        $this->assertSame('persistent', $stats['mode']);
        $this->assertSame('redis', $stats['backend']);
    }

    /**
     * NEGATIVE: the request-scoped and off modes must not break.
     *
     * With no shared backend configured there is nothing to clear remotely, and
     * cacheClear() must still clear the in-process layer and reset the counters
     * rather than failing on a null backend.
     */
    public function testCacheClearWithoutAPersistentBackendIsSafe(): void
    {
        $this->setEnv('TINA4_DB_CACHE', 'false');
        $this->setEnv('TINA4_AUTO_CACHING', 'true');
        $this->clearEnv('TINA4_DB_CACHE_URL');

        $database = $this->seed($this->tempDatabasePath('local'));
        $this->assertSame(
            'request',
            $database->cacheStats()['mode'],
            'precondition: no shared backend in request-scoped mode'
        );

        $database->fetch(self::SQL, [1]);
        $database->cacheClear();

        $stats = $database->cacheStats();
        $this->assertSame('request', $stats['mode']);
        $this->assertSame(0, $stats['size']);
        $this->assertSame(0, $stats['hits']);
        $this->assertSame(0, $stats['misses']);
    }
}
