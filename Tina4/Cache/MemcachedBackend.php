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
        $payload = 'set ' . $this->mcKey($key) . ' 0 ' . $exptime . ' ' . strlen($data) . "\r\n" . $data . "\r\n";
        $this->command($payload, "\r\n");
    }

    public function delete(string $key): bool
    {
        $resp = $this->command('delete ' . $this->mcKey($key) . "\r\n", "\r\n");
        return str_starts_with($resp, 'DELETED');
    }

    public function clear(): void
    {
        $this->hits = 0;
        $this->misses = 0;
        $this->command("flush_all\r\n", "\r\n");
    }

    public function stats(): array
    {
        $size = 0;
        $resp = $this->command("stats\r\n", "END\r\n");
        foreach (explode("\r\n", $resp) as $line) {
            if (str_starts_with($line, 'STAT curr_items ')) {
                $parts = preg_split('/\s+/', $line);
                $size = isset($parts[2]) ? (int)$parts[2] : 0;
            }
        }
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'size' => $size,
            'backend' => 'memcached',
        ];
    }

    public function name(): string
    {
        return 'memcached';
    }
}
