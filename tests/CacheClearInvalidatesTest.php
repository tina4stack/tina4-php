<?php

/**
 * Tina4 - The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * CACHE CONTRACT - clear() really invalidates, on EVERY provider.
 *
 * Pins `clear-really-invalidates-on-every-provider` from
 * tina4-documentation/plan/v3/fixtures/cache_contract.json (ADR-0024):
 *
 *     clear() removes every entry this cache can serve, on EVERY provider. It
 *     is never a no-op, and never limited to the keys the local process
 *     happens to have written.
 *
 * WHY THIS FILE EXISTS AND THE EXISTING CACHE TESTS DID NOT CATCH IT
 *     RedisBackend has TWO transports: ext-redis when the extension is loaded,
 *     and a raw RESP socket otherwise. The raw path is what a ZERO-DEPENDENCY
 *     install runs - every developer's first install, and what ADR-0024 rule 4
 *     says counts double. Its clear() was a literal no-op with a comment
 *     claiming parity with Python. MemcachedBackend::clear() deleted only the
 *     keys THIS process wrote, so a second instance kept serving stale rows.
 *
 *     Nothing here is mocked. Every assertion is answered by a real Redis /
 *     Valkey / Memcached over a real socket. Selecting WHICH of the two shipped
 *     transports a backend uses is configuration, not a stand-in.
 *
 * SERVICE ADDRESSES
 *     Default to localhost; override per service so a developer (or a parallel
 *     agent) can point at their own isolated containers:
 *         TINA4_TEST_CACHE_REDIS_URL      (default redis://127.0.0.1:6379)
 *         TINA4_TEST_CACHE_VALKEY_URL     (default valkey://127.0.0.1:6380)
 *         TINA4_TEST_CACHE_MEMCACHED_URL  (default memcached://127.0.0.1:11211)
 *
 *     A skip reason below names its service, so under TINA4_REQUIRE_SERVICES=1
 *     an unreachable service is a hard FAILURE, never a quiet green.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Cache\MemcachedBackend;
use Tina4\Cache\RedisBackend;
use Tina4\Cache\ValkeyBackend;

class CacheClearInvalidatesTest extends TestCase
{
    private const DEFAULT_REDIS_URL = 'redis://127.0.0.1:6379';
    private const DEFAULT_VALKEY_URL = 'valkey://127.0.0.1:6380';
    private const DEFAULT_MEMCACHED_URL = 'memcached://127.0.0.1:11211';

    private function redisUrl(): string
    {
        return getenv('TINA4_TEST_CACHE_REDIS_URL') ?: self::DEFAULT_REDIS_URL;
    }

    private function valkeyUrl(): string
    {
        return getenv('TINA4_TEST_CACHE_VALKEY_URL') ?: self::DEFAULT_VALKEY_URL;
    }

    private function memcachedUrl(): string
    {
        return getenv('TINA4_TEST_CACHE_MEMCACHED_URL') ?: self::DEFAULT_MEMCACHED_URL;
    }

    /**
     * Split a cache URL into [host, port] with a per-service default port.
     *
     * @return array{0: string, 1: int}
     */
    private function endpoint(string $url, int $defaultPort): array
    {
        $parts = parse_url(str_contains($url, '://') ? $url : '//' . $url);
        return [$parts['host'] ?? '127.0.0.1', (int)($parts['port'] ?? $defaultPort)];
    }

    private function requireService(string $url, int $defaultPort, string $service): void
    {
        [$host, $port] = $this->endpoint($url, $defaultPort);
        $sock = @fsockopen($host, $port, $errno, $errstr, 2);
        if (!$sock) {
            $this->markTestSkipped("{$service} service not reachable at {$host}:{$port}");
        }
        fclose($sock);
    }

    // -- Independent oracles -------------------------------------------------
    // Deliberately NOT the framework's own transport. A tenant-isolation claim
    // proved with the code under test would pass even if that code were broken,
    // so the outsider key is written and read back over a socket this test owns.

    /**
     * Speak RESP directly and return the reply for each command sent.
     *
     * @param  array<int, array<int, string>> $commands
     * @return array<int, string|array|null>
     */
    private function respTalk(string $url, array $commands): array
    {
        [$host, $port] = $this->endpoint($url, 6379);
        $sock = fsockopen($host, $port, $errno, $errstr, 5);
        $this->assertNotFalse($sock, "could not open a RESP socket to {$host}:{$port}");
        stream_set_timeout($sock, 5);

        $replies = [];
        foreach ($commands as $args) {
            $wire = '*' . count($args) . "\r\n";
            foreach ($args as $arg) {
                $wire .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
            }
            fwrite($sock, $wire);
            $replies[] = $this->respOracleRead($sock);
        }
        fclose($sock);
        return $replies;
    }

    /** Read ONE complete reply: enough of RESP for +simple, :int and $bulk. */
    private function respOracleRead($sock): string|array|null
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
        return $body;
    }

    /** Speak the memcached text protocol directly. */
    private function memcachedTalk(string $payload, string $terminator): string
    {
        [$host, $port] = $this->endpoint($this->memcachedUrl(), 11211);
        $sock = fsockopen($host, $port, $errno, $errstr, 5);
        $this->assertNotFalse($sock, "could not open a memcached socket to {$host}:{$port}");
        stream_set_timeout($sock, 5);
        fwrite($sock, $payload);
        $buffer = '';
        while (!str_contains($buffer, $terminator)) {
            $chunk = fread($sock, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buffer .= $chunk;
            $meta = stream_get_meta_data($sock);
            if (!empty($meta['timed_out'])) {
                break;
            }
        }
        fclose($sock);
        return $buffer;
    }

    // -- Transport selection (configuration, not a mock) ---------------------

    /**
     * Which redis transports actually exist in THIS build.
     *
     * RedisBackend uses ext-redis as its only client library; with the
     * extension absent the raw RESP socket is the one and only transport, and
     * that is exactly the zero-dependency default install.
     *
     * @return array<int, string>
     */
    private function redisTransports(): array
    {
        return extension_loaded('redis') ? ['resp', 'driver'] : ['resp'];
    }

    /** Pin a backend to the requested transport, verified live. */
    private function pin(RedisBackend $backend, string $transport): RedisBackend
    {
        $reflection = new ReflectionObject($backend);
        if ($transport === 'resp') {
            $reflection->getProperty('client')->setValue($backend, null);
            $reflection->getProperty('useRaw')->setValue($backend, true);
            // Re-derive availability over the RAW socket - a real AUTH+PING
            // handshake, never an assumption inherited from the driver connect.
            $live = $reflection->getMethod('probe')->invoke($backend);
            $reflection->getProperty('available')->setValue($backend, $live);
            $this->assertTrue(
                $backend->isAvailable(),
                'raw RESP transport could not reach the server'
            );
            return $backend;
        }
        $this->assertNotNull(
            $reflection->getProperty('client')->getValue($backend),
            'ext-redis is loaded but its client never connected'
        );
        $reflection->getProperty('useRaw')->setValue($backend, false);
        return $backend;
    }

    private function key(): string
    {
        return 'contract-' . bin2hex(random_bytes(16));
    }

    // -- redis / valkey ------------------------------------------------------

    /**
     * clear() must actually remove entries on EVERY transport.
     *
     * The measured defect: on the raw RESP path clear() did nothing at all, so
     * a write never invalidated the persistent DB query cache and every
     * instance kept serving pre-write rows until the TTL ran out.
     */
    public function testClearOnTheRawRespTransportIsNotANoOp(): void
    {
        $this->requireService($this->redisUrl(), 6379, 'redis');

        foreach ($this->redisTransports() as $transport) {
            $backend = $this->pin(new RedisBackend($this->redisUrl()), $transport);

            $key = $this->key();
            $backend->set($key, ['row' => 'before'], 300);
            $this->assertSame(
                ['row' => 'before'],
                $backend->get($key),
                "precondition: the value is cached on the {$transport} transport"
            );

            $backend->clear();

            $this->assertNull(
                $backend->get($key),
                "clear() left the entry readable on the {$transport} transport - "
                . 'it is a no-op, so a write never invalidates the cache'
            );
        }
    }

    /**
     * clear() is not limited to what THIS process wrote.
     *
     * Two Tina4 instances share one Redis. Instance A writes, instance B clears
     * after a write; A's entry must be gone. A clear scoped to the local
     * process's own write log would leave A serving stale rows forever.
     */
    public function testClearRemovesEntriesWrittenByAnotherInstance(): void
    {
        $this->requireService($this->redisUrl(), 6379, 'redis');

        foreach ($this->redisTransports() as $transport) {
            $writer = $this->pin(new RedisBackend($this->redisUrl()), $transport);
            $clearer = $this->pin(new RedisBackend($this->redisUrl()), $transport);

            $key = $this->key();
            $writer->set($key, ['row' => 'from-instance-a'], 300);
            $this->assertSame(
                ['row' => 'from-instance-a'],
                $clearer->get($key),
                "precondition: shared visibility on the {$transport} transport"
            );

            $clearer->clear();

            $this->assertNull(
                $writer->get($key),
                "clear() on instance B left instance A's entry readable on the "
                . "{$transport} transport - cross-instance invalidation does not hold"
            );
        }
    }

    /**
     * NEGATIVE: clear() must not be a FLUSHALL.
     *
     * Removing every entry THIS cache can serve is the rule. Removing every key
     * on a shared Redis is a different and far worse thing: it destroys other
     * applications' data. Both halves are the contract.
     */
    public function testClearLeavesAnotherTenantsKeysUntouched(): void
    {
        $this->requireService($this->redisUrl(), 6379, 'redis');

        foreach ($this->redisTransports() as $transport) {
            $outsiderKey = 'someone-elses-app:' . bin2hex(random_bytes(16));
            $this->respTalk($this->redisUrl(), [['SET', $outsiderKey, 'not-ours']]);

            $backend = $this->pin(new RedisBackend($this->redisUrl()), $transport);
            $ourKey = $this->key();
            $backend->set($ourKey, ['row' => 1], 300);

            $backend->clear();

            // Positive half: ours is gone.
            $this->assertNull(
                $backend->get($ourKey),
                "clear() did not remove this cache's own entry on the {$transport} transport"
            );
            // Negative half: theirs survives, checked over a socket this test owns.
            [$survived] = $this->respTalk($this->redisUrl(), [['GET', $outsiderKey]]);
            $this->respTalk($this->redisUrl(), [['DEL', $outsiderKey]]);
            $this->assertSame(
                'not-ours',
                $survived,
                "clear() destroyed a key outside the tina4 prefix on the {$transport} "
                . 'transport - it is flushing the whole server, not this cache'
            );
        }
    }

    /**
     * clear() must remove EVERY entry, not one socket-buffer's worth.
     *
     * The raw RESP reader did a single fread and string-split the result, so a
     * multi-bulk reply larger than what one read returns silently truncated. A
     * fix built on that would clear only some of the keys and still look green
     * on a small test, and SCAN is a cursor - stopping after the first page
     * leaves the rest of the keyspace live.
     *
     * The key count is load-bearing and was MEASURED, not guessed. SCAN's COUNT
     * bounds the buckets walked per call, not the keys returned, so a small
     * keyspace finishes in one call and a single-page scan looks correct.
     * Against Redis 7.4.10 with COUNT 500: 250 keys completed in 1 iteration
     * (no gate at all), 600 in 2, 1200 in 3. 1200 is used so the cursor must be
     * followed with margin to spare.
     */
    public function testClearRemovesManyEntriesNotJustTheFirstPage(): void
    {
        $this->requireService($this->redisUrl(), 6379, 'redis');

        $backend = $this->pin(new RedisBackend($this->redisUrl()), 'resp');
        $marker = bin2hex(random_bytes(16));
        $keys = [];
        for ($index = 0; $index < 1200; $index++) {
            $keys[] = "contract-{$marker}-{$index}";
        }
        foreach ($keys as $key) {
            $backend->set($key, ['i' => $key], 300);
        }

        // One entry far larger than a single socket read. fread() on a socket
        // returns what has ARRIVED, not what was asked for, so only a reader
        // that assembles the DECLARED bulk length across reads gets this back
        // intact - a single-fread reader hands over a truncated value.
        $bigKey = "contract-{$marker}-big";
        $bigValue = ['blob' => str_repeat('t', 65536)];
        $backend->set($bigKey, $bigValue, 300);
        $keys[] = $bigKey;
        $this->assertSame(
            $bigValue,
            $backend->get($bigKey),
            'a value larger than one socket read came back corrupt - the RESP '
            . 'reader is not assembling the declared bulk length'
        );

        $backend->clear();

        $survivors = [];
        foreach ($keys as $key) {
            if ($backend->get($key) !== null) {
                $survivors[] = $key;
            }
        }
        $this->assertSame(
            [],
            $survivors,
            count($survivors) . ' of ' . count($keys) . ' entries survived clear() - '
            . 'the reply was truncated or the scan stopped after one page'
        );
    }

    /** The same rule on Valkey - a second provider on the same wire protocol. */
    public function testClearInvalidatesOnValkeyToo(): void
    {
        $this->requireService($this->valkeyUrl(), 6379, 'valkey');

        $backend = $this->pin(new ValkeyBackend($this->valkeyUrl()), 'resp');
        $key = $this->key();
        $backend->set($key, ['row' => 'before'], 300);
        $this->assertSame(['row' => 'before'], $backend->get($key));

        $backend->clear();

        $this->assertNull(
            $backend->get($key),
            "clear() is a no-op on valkey's RESP transport"
        );
    }

    // -- memcached -----------------------------------------------------------

    /**
     * memcached clear() must invalidate globally, not per-process.
     *
     * The measured defect: clear() deleted only the keys the LOCAL process had
     * written, so a second instance kept serving stale rows. memcached has no
     * KEYS scan, so the answer is the documented namespace-generation idiom -
     * bump a shared counter and every previously-written key becomes
     * unreachable to EVERY instance at once.
     */
    public function testMemcachedClearInvalidatesForASecondInstance(): void
    {
        $this->requireService($this->memcachedUrl(), 11211, 'memcached');

        $writer = new MemcachedBackend($this->memcachedUrl());
        $clearer = new MemcachedBackend($this->memcachedUrl());

        $key = $this->key();
        $writer->set($key, ['row' => 'from-instance-a'], 300);
        $this->assertSame(
            ['row' => 'from-instance-a'],
            $clearer->get($key),
            'precondition: shared visibility'
        );

        $clearer->clear();

        $this->assertNull(
            $writer->get($key),
            "clear() on instance B left instance A's entry readable - memcached has "
            . 'no cross-instance invalidation, so a second instance serves stale rows'
        );
    }

    /**
     * NEGATIVE: memcached clear() must never be a global flush_all.
     *
     * flush_all wipes every key on the instance, including every other
     * application's. That is the failure mode the namespace generation avoids.
     */
    public function testMemcachedClearLeavesAnotherTenantsKeysUntouched(): void
    {
        $this->requireService($this->memcachedUrl(), 11211, 'memcached');

        $outsiderKey = 'someone-elses-app-' . bin2hex(random_bytes(16));
        $payload = 'not-ours';
        $this->memcachedTalk(
            "set {$outsiderKey} 0 300 " . strlen($payload) . "\r\n" . $payload . "\r\n",
            "\r\n"
        );

        $backend = new MemcachedBackend($this->memcachedUrl());
        $backend->set($this->key(), ['row' => 1], 300);
        $backend->clear();

        $response = $this->memcachedTalk("get {$outsiderKey}\r\n", "END\r\n");
        $this->memcachedTalk("delete {$outsiderKey}\r\n", "\r\n");
        $this->assertStringContainsString(
            'not-ours',
            $response,
            'clear() destroyed a key outside the tina4 namespace - it is flushing '
            . 'the whole memcached instance, not this cache'
        );
    }

    /**
     * A cleared entry must not come back when the generation is re-read.
     *
     * Guards the namespace fix against an in-process generation cache that goes
     * stale: after clear(), a brand-new backend instance (a fresh process) must
     * also miss, and a value written afterwards must be readable by everyone.
     */
    public function testMemcachedEntriesWrittenBeforeAClearStayInvalid(): void
    {
        $this->requireService($this->memcachedUrl(), 11211, 'memcached');

        $writer = new MemcachedBackend($this->memcachedUrl());
        $key = $this->key();
        $writer->set($key, ['row' => 'before'], 300);
        $writer->clear();

        $fresh = new MemcachedBackend($this->memcachedUrl());
        $this->assertNull(
            $fresh->get($key),
            'a process that started AFTER the clear can still read the cleared '
            . 'entry - the invalidation did not reach the shared server'
        );

        // The namespace stays usable, not permanently poisoned: a value written
        // after the clear is readable by an instance that never saw the write.
        $writer->set($key, ['row' => 'after'], 300);
        $this->assertSame(
            ['row' => 'after'],
            (new MemcachedBackend($this->memcachedUrl()))->get($key),
            'a write after clear() is invisible to another instance - the writer '
            . 'and the reader disagree about the namespace generation'
        );
    }

    /**
     * NEGATIVE: only THIS cache's entries go; the backend stays usable.
     *
     * A generation bump must not break subsequent writes or reads (the classic
     * off-by-one where the reader and the writer disagree about the generation).
     */
    public function testMemcachedClearDoesNotStrandLiveEntriesFromOtherKeys(): void
    {
        $this->requireService($this->memcachedUrl(), 11211, 'memcached');

        $backend = new MemcachedBackend($this->memcachedUrl());
        $backend->clear();

        $keys = [];
        for ($index = 0; $index < 5; $index++) {
            $keys[$index] = $this->key();
        }
        foreach ($keys as $index => $key) {
            $backend->set($key, ['i' => $index], 300);
        }
        foreach ($keys as $index => $key) {
            $this->assertSame(
                ['i' => $index],
                $backend->get($key),
                'write and read disagree after a clear'
            );
        }
        $this->assertSame(5, $backend->stats()['size']);
    }
}
