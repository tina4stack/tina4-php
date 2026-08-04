<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * MemcachedBackend — zero-dependency Memcached text protocol over TCP.
 *
 * Mirrors Python's tina4_python.cache._MemcachedBackend exactly:
 *   - keys are SHA-256 hashed under the "tina4:cache:" prefix (memcached keys
 *     may not contain spaces/control chars and must be <= 250 chars)
 *   - values are JSON-encoded
 *   - isAvailable() probes with a `version` command (VERSION reply)
 *   - memcached stays unauthenticated (no credentials)
 */

namespace Tina4\Cache;

class MemcachedBackend extends CacheBackend
{
    /**
     * memcached's own boundary: an exptime at or below this is RELATIVE
     * seconds, above it is an ABSOLUTE unix timestamp. See exptime().
     */
    public const MAX_RELATIVE_EXPTIME = 2592000;  // 30 days

    private string $host = 'localhost';
    private int $port = 11211;
    private string $prefix = 'tina4:cache:';
    private int $maxEntries;
    private int $hits = 0;
    private int $misses = 0;

    /**
     * Keys THIS backend wrote, mapped to the moment each expires (0 = never).
     * Memcached has no KEYS/prefix scan, so a scoped count cannot be read back
     * from the server - see stats() for why the global counter it does expose
     * is the wrong answer.
     *
     * @var array<string, float>
     */
    private array $own = [];
    private bool $available = false;

    /**
     * The SHARED namespace generation counter. clear() bumps it; every real key
     * carries it, so one bump invalidates every instance at once.
     */
    private string $generationKey;

    public function __construct(string $url = 'memcached://localhost:11211', int $maxEntries = 1000)
    {
        $this->maxEntries = $maxEntries;

        $cleaned = str_replace(['memcached://', 'memcache://'], '', $url);
        $hostPort = explode('/', $cleaned)[0];
        $parts = explode(':', $hostPort);
        $this->host = ($parts[0] ?? '') !== '' ? $parts[0] : 'localhost';
        $this->port = isset($parts[1]) && $parts[1] !== '' ? (int)$parts[1] : 11211;
        $this->generationKey = $this->prefix . 'generation';

        $this->available = str_starts_with($this->command("version\r\n", "\r\n"), 'VERSION');
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * Read the SHARED namespace generation from the server.
     *
     * memcached has no KEYS scan and no prefix delete, so the only way to
     * invalidate globally without destroying other tenants is the documented
     * namespace idiom: every real key carries a generation, and clear() bumps
     * it. Every instance then computes a different key and the old entries
     * become unreachable at once, expiring under the server's own TTL/LRU.
     *
     * The generation is read from the server on every key computation,
     * deliberately. Caching it in-process would reintroduce exactly the bug
     * this fixes: an instance holding a stale generation keeps computing the
     * OLD key, and the old key still holds the old value, so it serves a stale
     * hit after another instance cleared. One extra round trip on a
     * sub-millisecond local service is the price of cross-instance
     * invalidation.
     */
    private function generation(): string
    {
        $response = $this->command('get ' . $this->generationKey . "\r\n", "END\r\n");
        if (str_starts_with($response, 'VALUE')) {
            $split = explode("\r\n", $response, 2);
            if (count($split) === 2) {
                $headerParts = preg_split('/\s+/', $split[0]);
                if (isset($headerParts[3])) {
                    return substr($split[1], 0, (int)$headerParts[3]);
                }
            }
        }
        return '0';
    }

    private function mcKey(string $key): string
    {
        // Hash to a safe, bounded key (memcached keys: no spaces/control chars,
        // 250 chars max). The generation sits IN the key so a clear() on ANY
        // instance orphans it for every instance at once.
        return $this->prefix . $this->generation() . ':' . hash('sha256', $key);
    }

    private function command(string $payload, string $terminator): string
    {
        try {
            $sock = @fsockopen($this->host, $this->port, $errno, $errstr, 5);
            if (!$sock) {
                return '';
            }
            stream_set_timeout($sock, 5);
            fwrite($sock, $payload);
            $buf = '';
            while (!str_contains($buf, $terminator)) {
                $chunk = fread($sock, 4096);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $buf .= $chunk;
                $info = stream_get_meta_data($sock);
                if (!empty($info['timed_out'])) {
                    break;
                }
            }
            fclose($sock);
            return $buf;
        } catch (\Throwable) {
            return '';
        }
    }

    public function get(string $key): mixed
    {
        $resp = $this->command('get ' . $this->mcKey($key) . "\r\n", "END\r\n");
        if (str_starts_with($resp, 'VALUE')) {
            $split = explode("\r\n", $resp, 2);
            if (count($split) === 2) {
                $header = $split[0];
                $rest = $split[1];
                $headerParts = preg_split('/\s+/', $header);
                $nbytes = isset($headerParts[3]) ? (int)$headerParts[3] : 0;
                $payload = substr($rest, 0, $nbytes);
                $decoded = json_decode($payload, true);
                if ($decoded !== null || $payload === 'null') {
                    $this->hits++;
                    return $decoded;
                }
            }
        }
        $this->misses++;
        return null;
    }

    /**
     * Convert a TTL in seconds to memcached's exptime field.
     *
     * memcached reads exptime as RELATIVE seconds at or below 2592000 (30 days)
     * and as an ABSOLUTE UNIX TIMESTAMP above it. Interpolating the caller's
     * ttl raw meant any TTL over 30 days was read as a date in 1970, so the
     * entry expired the instant it was written - and memcached still answers
     * STORED, so it presented as a 100% miss rate with nothing logged.
     *
     * CONVERT, never CLAMP. Clamping to 2592000 also makes the entry survive
     * and is also wrong: it silently discards more than half the lifetime the
     * operator explicitly configured, which is the same class of
     * silent-wrong-answer as the bug it would be replacing.
     *
     * @param  int $ttl Lifetime in seconds; 0 or less means "never expires"
     * @return int The exptime field to send to memcached
     */
    private static function exptime(int $ttl): int
    {
        if ($ttl <= 0) {
            return 0;
        }
        if ($ttl > self::MAX_RELATIVE_EXPTIME) {
            return time() + $ttl;
        }
        return $ttl;
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        $data = json_encode($value);
        $exptime = self::exptime($ttl);
        $mcKey = $this->mcKey($key);
        $payload = 'set ' . $mcKey . ' 0 ' . $exptime . ' ' . strlen($data) . "\r\n" . $data . "\r\n";
        $this->command($payload, "\r\n");
        // The write log keeps the RAW ttl, NEVER $exptime. Above the cliff
        // $exptime is already an ABSOLUTE stamp, so microtime(true) + $exptime
        // would put this deadline about 166 years out - the map would then
        // never expire anything and stats() would report expired entries as
        // live forever.
        $this->own[$mcKey] = $ttl > 0 ? microtime(true) + $ttl : 0.0;
    }

    public function delete(string $key): bool
    {
        $mcKey = $this->mcKey($key);
        $resp = $this->command('delete ' . $mcKey . "\r\n", "\r\n");
        unset($this->own[$mcKey]);
        return str_starts_with($resp, 'DELETED');
    }

    /**
     * Invalidate EVERY entry this cache can serve, on EVERY instance.
     *
     * Two wrong answers were shipped before this one. `flush_all` wipes EVERY
     * key on the instance including every other application's - cacheClear() is
     * public API, so calling it destroyed other tenants' data. Deleting only
     * the keys THIS process wrote fixed that but broke the contract the other
     * way: a second instance kept serving rows the first had just invalidated,
     * because it had never seen those keys.
     *
     * The namespace generation does both. Bumping the shared counter orphans
     * every previously-written entry for every instance at once, and touches
     * nothing outside our own prefix. The orphans are reclaimed by memcached's
     * own TTL and LRU - unreachable is what "removed" means for a cache.
     *
     * The local write log is still cleared so stats() reports honestly, and its
     * keys are deleted eagerly so the space comes back immediately rather than
     * waiting for eviction.
     */
    public function clear(): void
    {
        $this->hits = 0;
        $this->misses = 0;
        foreach (array_keys($this->own) as $mcKey) {
            $this->command('delete ' . $mcKey . "\r\n", "\r\n");
        }
        $this->own = [];

        // incr is atomic, so two instances clearing at once still both advance.
        $response = $this->command('incr ' . $this->generationKey . " 1\r\n", "\r\n");
        if (!ctype_digit(trim($response))) {
            // No counter yet: create it. `add` fails harmlessly if another
            // instance created it in the gap, and the incr then applies.
            $this->command('add ' . $this->generationKey . " 0 0 1\r\n1\r\n", "\r\n");
            $this->command('incr ' . $this->generationKey . " 1\r\n", "\r\n");
        }
    }

    /**
     * Report OUR entries, not the whole server's.
     *
     * This used to read memcached's `curr_items`, which is a GLOBAL counter: it
     * includes every key written by every other tenant of that server. On a
     * shared memcached (the normal deployment) `size` was reporting somebody
     * else's data, and every other backend here is scoped - memory counts its
     * own map, redis/valkey scan their own prefix, file counts its own
     * directory, mongo its own collection, database its own table. Memcached
     * was the only one leaking.
     *
     * It cannot be fixed by asking the server: memcached has no KEYS or
     * prefix-scan command. So the count comes from our own write log, filtered
     * by the TTLs we set. That is exact for the keys this process wrote; a key
     * EVICTED early under memory pressure is invisible to us and would be
     * over-counted, which is a far smaller and more honest error than counting
     * another application's keys.
     *
     * @return array{hits: int, misses: int, size: int, backend: string}
     */
    public function stats(): array
    {
        $now = microtime(true);
        $live = array_filter($this->own, static fn(float $expires): bool => $expires === 0.0 || $expires > $now);
        // Drop the expired ones so the log cannot grow without bound.
        $this->own = $live;

        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'size' => count($live),
            'backend' => 'memcached',
        ];
    }

    public function name(): string
    {
        return 'memcached';
    }
}
