<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * FileBackend — file-based cache. Stores entries as JSON files in data/cache/.
 *
 * Mirrors Python's tina4_python.cache._FileBackend. Keys are SHA-256 hashed to
 * a safe filename; entries carry their own expiry and the oldest are evicted
 * once maxEntries is reached. This is the graceful-degradation target when a
 * network backend is unreachable, so it is always available.
 */

namespace Tina4\Cache;

class FileBackend extends CacheBackend
{
    private string $dir;
    private int $maxEntries;
    private int $hits = 0;
    private int $misses = 0;

    public function __construct(string $cacheDir = 'data/cache', int $maxEntries = 1000)
    {
        $this->dir = rtrim($cacheDir, '/');
        $this->maxEntries = $maxEntries;
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }
    }

    private function keyPath(string $key): string
    {
        return $this->dir . '/' . hash('sha256', $key) . '.json';
    }

    public function get(string $key): mixed
    {
        $path = $this->keyPath($key);
        if (!file_exists($path)) {
            $this->misses++;
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            $this->misses++;
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->misses++;
            return null;
        }
        $expiresAt = $data['expiresAt'] ?? null;
        if ($expiresAt !== null && microtime(true) > $expiresAt) {
            @unlink($path);
            $this->misses++;
            return null;
        }
        $this->hits++;
        return $data['value'] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }
        // Evict oldest if at capacity.
        $files = glob($this->dir . '/*.json');
        if ($files !== false) {
            while (count($files) >= $this->maxEntries) {
                usort($files, fn($a, $b) => filemtime($a) <=> filemtime($b));
                $oldest = array_shift($files);
                if ($oldest !== null) {
                    @unlink($oldest);
                }
            }
        }
        $entry = [
            'key' => $key,
            'value' => $value,
            'expiresAt' => $ttl > 0 ? microtime(true) + $ttl : null,
        ];
        @file_put_contents($this->keyPath($key), json_encode($entry));
    }

    public function delete(string $key): bool
    {
        $path = $this->keyPath($key);
        if (file_exists($path)) {
            return @unlink($path);
        }
        return false;
    }

    public function clear(): void
    {
        $this->hits = 0;
        $this->misses = 0;
        $files = glob($this->dir . '/*.json');
        if ($files !== false) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }

    public function stats(): array
    {
        // Sweep expired.
        $now = microtime(true);
        $count = 0;
        $files = glob($this->dir . '/*.json');
        if ($files !== false) {
            foreach ($files as $file) {
                $raw = @file_get_contents($file);
                $data = $raw !== false ? json_decode($raw, true) : null;
                if (is_array($data)) {
                    $exp = $data['expiresAt'] ?? null;
                    if ($exp !== null && $now > $exp) {
                        @unlink($file);
                    } else {
                        $count++;
                    }
                }
            }
        }
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'size' => $count,
            'backend' => 'file',
        ];
    }

    public function name(): string
    {
        return 'file';
    }

    public function sweep(): int
    {
        $now = microtime(true);
        $removed = 0;
        $files = glob($this->dir . '/*.json');
        if ($files !== false) {
            foreach ($files as $file) {
                $raw = @file_get_contents($file);
                $data = $raw !== false ? json_decode($raw, true) : null;
                if (is_array($data)) {
                    $exp = $data['expiresAt'] ?? null;
                    if ($exp !== null && $now > $exp) {
                        @unlink($file);
                        $removed++;
                    }
                }
            }
        }
        return $removed;
    }
}
