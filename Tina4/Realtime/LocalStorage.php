<?php

namespace Tina4\Realtime;

/**
 * Zero-dependency filesystem blob store. The default backend.
 *
 * Keys are opaque tokens (see Storage::key); every path is resolved inside the
 * root and any traversal attempt is rejected.
 */
class LocalStorage implements StorageBackend
{
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $dir = $directory
            ?? (getenv('TINA4_STORAGE_DIR') ?: 'data/rt_storage');
        $this->directory = rtrim($dir, '/');
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }
        $this->directory = realpath($this->directory) ?: $this->directory;
    }

    private function pathFor(string $key): ?string
    {
        // Reject anything that could escape the root.
        if ($key === '' || str_contains($key, '/') || str_contains($key, '\\')
            || str_contains($key, "\0") || str_contains($key, '..')) {
            return null;
        }
        return $this->directory . DIRECTORY_SEPARATOR . $key;
    }

    public function put(string $key, string $data, string $mime = 'application/octet-stream'): void
    {
        $path = $this->pathFor($key);
        if ($path === null) {
            throw new \InvalidArgumentException("unsafe storage key: {$key}");
        }
        file_put_contents($path, $data);
    }

    public function get(string $key): ?string
    {
        $path = $this->pathFor($key);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $data = @file_get_contents($path);
        return $data === false ? null : $data;
    }

    public function url(string $key, int $ttl = 3600): ?string
    {
        // No direct URL: the permissioned app download route serves local files.
        return null;
    }

    public function delete(string $key): void
    {
        $path = $this->pathFor($key);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    public function exists(string $key): bool
    {
        $path = $this->pathFor($key);
        return $path !== null && is_file($path);
    }
}
