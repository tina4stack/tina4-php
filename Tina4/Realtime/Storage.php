<?php

namespace Tina4\Realtime;

/**
 * Storage backend selection + key generation for the realtime "files" feature.
 * Mirrors Python's storage.py select_storage() / storage_key().
 */
final class Storage
{
    /**
     * Resolve the storage backend from an explicit instance or the environment.
     * Falls back to LocalStorage (logging why) when an s3 backend cannot be
     * built — a real store, never a silent no-op.
     */
    public static function select(?StorageBackend $storage = null): StorageBackend
    {
        if ($storage !== null) {
            return $storage;
        }
        $name = strtolower(getenv('TINA4_STORAGE_BACKEND') ?: 'local');
        if ($name === 's3') {
            try {
                return new S3Storage();
            } catch (\Throwable $e) {
                \Tina4\Log::warning(
                    'realtime files: S3 storage unavailable (' . $e->getMessage()
                    . '); falling back to local filesystem storage.'
                );
                return new LocalStorage();
            }
        }
        return new LocalStorage();
    }

    /**
     * Generate an opaque, collision-free storage key, preserving the extension.
     * The key carries no user-controlled path segment, so it can never traverse
     * outside the storage root.
     */
    public static function key(string $filename = ''): string
    {
        $ext = '';
        if ($filename !== '' && str_contains($filename, '.')) {
            $raw = substr(strrchr($filename, '.'), 1);
            $clean = preg_replace('/[^A-Za-z0-9]/', '', $raw);
            if ($clean !== '') {
                $ext = '.' . substr($clean, 0, 12);
            }
        }
        return bin2hex(random_bytes(16)) . $ext;
    }
}
