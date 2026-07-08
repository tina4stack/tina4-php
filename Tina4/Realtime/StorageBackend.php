<?php

namespace Tina4\Realtime;

/**
 * Blob store interface for the realtime "files" feature.
 *
 * put() writes bytes, get() reads them back, url() returns a directly-fetchable
 * URL when the backend supports one (else null — serve via the app download
 * route), delete() removes, exists() checks presence. Mirrors the Python
 * StorageBackend so the wire behaviour is identical across frameworks.
 */
interface StorageBackend
{
    public function put(string $key, string $data, string $mime = 'application/octet-stream'): void;

    public function get(string $key): ?string;

    public function url(string $key, int $ttl = 3600): ?string;

    public function delete(string $key): void;

    public function exists(string $key): bool;
}
