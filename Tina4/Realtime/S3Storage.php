<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


namespace Tina4\Realtime;

/**
 * S3-compatible store (AWS S3, MinIO, Garage, ...). Opt-in, zero dependencies.
 *
 * Talks to S3 through Tina4's own stdlib SigV4 client ({@see S3Client}) — no
 * aws/aws-sdk-php. Presigned GET URLs let clients fetch large blobs straight
 * from object storage instead of streaming through the app.
 *
 * Constructed only when TINA4_STORAGE_BACKEND=s3; Storage::select() falls back
 * to LocalStorage (with a logged warning) if the config is incomplete — a real
 * store, never a silent no-op (mirrors Python S3Storage).
 */
class S3Storage implements StorageBackend
{
    private S3Client $client;
    private string $bucket;

    public function __construct(
        ?string $endpoint = null,
        ?string $key = null,
        #[\SensitiveParameter] ?string $secret = null,
        ?string $bucket = null,
        ?string $region = null
    ) {
        $this->bucket = self::opt($bucket, 'TINA4_STORAGE_BUCKET', '');
        if ($this->bucket === '') {
            throw new \InvalidArgumentException('S3Storage requires TINA4_STORAGE_BUCKET');
        }
        $this->client = new S3Client(
            self::opt($endpoint, 'TINA4_STORAGE_URL', '') ?: null,
            self::opt($key, 'TINA4_STORAGE_KEY', ''),
            self::opt($secret, 'TINA4_STORAGE_SECRET', ''),
            self::opt($region, 'TINA4_STORAGE_REGION', 'us-east-1')
        );
    }

    /** Resolve a setting: the explicit value, else the env var, else the default. */
    private static function opt(?string $value, string $env, string $default): string
    {
        return $value ?: (getenv($env) ?: $default);
    }

    public function put(string $key, string $data, string $mime = 'application/octet-stream'): void
    {
        $this->client->putObject($this->bucket, $key, $data, $mime);
    }

    public function get(string $key): ?string
    {
        try {
            return $this->client->getObject($this->bucket, $key);
        } catch (\Throwable) {
            return null;
        }
    }

    public function url(string $key, int $ttl = 3600): ?string
    {
        return $this->client->presignedGetUrl($this->bucket, $key, $ttl);
    }

    public function delete(string $key): void
    {
        try {
            $this->client->deleteObject($this->bucket, $key);
        } catch (\Throwable $e) {
            \Tina4\Log::error("S3Storage delete failed for {$key}: " . $e->getMessage());
        }
    }

    public function exists(string $key): bool
    {
        try {
            $this->client->headObject($this->bucket, $key);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
