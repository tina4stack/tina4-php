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

    public function __construct(string $url = 'memcached://localhost:11211', int $maxEntries = 1000)
    {
        $this->maxEntries = $maxEntries;

        $cleaned = str_replace(['memcached://', 'memcache://'], '', $url);
        $hostPort = explode('/', $cleaned)[0];
        $parts = explode(':', $hostPort);
        $this->host = ($parts[0] ?? '') !== '' ? $parts[0] : 'localhost';
        $this->port = isset($parts[1]) && $parts[1] !== '' ? (int)$parts[1] : 11211;

        $this->available = str_starts_with($this->command("version\r\n", "\r\n"), 'VERSION');
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    private function mcKey(string $key): string
    {
        return $this->prefix . hash('sha256', $key);
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

    public function set(string $key, mixed $value, int $ttl): void
    {
        $data = json_encode($value);
        $exptime = $ttl > 0 ? $ttl : 0;
        $mcKey = $this->mcKey($key);
        $payload = 'set ' . $mcKey . ' 0 ' . $exptime . ' ' . strlen($data) . "\r\n" . $data . "\r\n";
        $this->command($payload, "\r\n");
        $this->own[$mcKey] = $exptime > 0 ? microtime(true) + $exptime : 0.0;
    }

    public function delete(string $key): bool
    {
        $mcKey = $this->mcKey($key);
        $resp = $this->command('delete ' . $mcKey . "\r\n", "\r\n");
        unset($this->own[$mcKey]);
        return str_starts_with($resp, 'DELETED');
    }

    /**
     * Remove OUR entries, not the whole server's.
     *
     * This used to send `flush_all`, which wipes EVERY key on the memcached
     * instance - including every other application sharing it. cacheClear() is
     * public API, so calling it destroyed other tenants' data. No other backend
     * does that: they each clear only what they own.
     *
     * Now that the backend tracks the keys it wrote, it deletes exactly those.
     * A key it never wrote is not its to remove.
     */
    public function clear(): void
    {
        $this->hits = 0;
        $this->misses = 0;
        foreach (array_keys($this->own) as $mcKey) {
            $this->command('delete ' . $mcKey . "\r\n", "\r\n");
        }
        $this->own = [];
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
