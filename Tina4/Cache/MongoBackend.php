<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
 *
 * MongoBackend — MongoDB-backed cache using the same zero-dependency wire
 * protocol (OP_MSG + minimal BSON) as the Mongo session handler.
 *
 * Mirrors Python's tina4_python.cache._MongoBackend:
 *   - cache lives in collection "tina4_cache" (db name from the URL path, else
 *     "tina4_cache")
 *   - values are JSON-encoded under field "value", keyed by string "_id"
 *   - expiry is stored as an epoch-seconds float in "expires_at" and enforced
 *     lazily on read (a TTL index is also created so Mongo reaps stale docs)
 *   - credentials may come from TINA4_CACHE_USERNAME / TINA4_CACHE_PASSWORD
 *     when not embedded in the URL (parity with the DB layer)
 *   - isAvailable() pings the server
 *
 * Test isolation: the database/collection names are configurable so parallel
 * test runs can use "tina4_cache_php" without colliding with other agents.
 */

namespace Tina4\Cache;

class MongoBackend extends CacheBackend
{
    private string $host = 'localhost';
    private int $port = 27017;
    private string $database = 'tina4_cache';
    private string $collection = 'tina4_cache';
    private ?string $username = null;
    private ?string $password = null;

    private int $maxEntries;
    private int $hits = 0;
    private int $misses = 0;
    private bool $available = false;

    /** @var resource|null */
    private $socket = null;
    private int $requestId = 0;

    public function __construct(string $url = 'mongodb://localhost:27017', int $maxEntries = 1000, ?string $collection = null)
    {
        $this->maxEntries = $maxEntries;

        $parsed = parse_url($url);
        if (is_array($parsed)) {
            $this->host = $parsed['host'] ?? 'localhost';
            $this->port = $parsed['port'] ?? 27017;
            $path = ltrim($parsed['path'] ?? '', '/');
            if ($path !== '') {
                $this->database = $path;
            }
            $urlUser = isset($parsed['user']) ? urldecode($parsed['user']) : '';
            $urlPass = isset($parsed['pass']) ? urldecode($parsed['pass']) : '';
        } else {
            $urlUser = '';
            $urlPass = '';
        }

        if ($urlUser === '' && $urlPass === '') {
            $envUser = \Tina4\DotEnv::getEnv('TINA4_CACHE_USERNAME') ?? '';
            $envPass = \Tina4\DotEnv::getEnv('TINA4_CACHE_PASSWORD') ?? '';
            $urlUser = $envUser;
            $urlPass = $envPass;
        }
        $this->username = $urlUser !== '' ? $urlUser : null;
        $this->password = $urlPass !== '' ? $urlPass : null;

        if ($collection !== null && $collection !== '') {
            $this->collection = $collection;
            // Keep cache db distinct per collection prefix so parallel test
            // suites don't collide.
            $this->database = $collection;
        }

        $this->available = $this->ping();
        if ($this->available) {
            // Best-effort TTL index so Mongo reaps stale docs server-side too.
            $this->createTtlIndex();
        }
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function get(string $key): mixed
    {
        if (!$this->available) {
            $this->misses++;
            return null;
        }
        $doc = $this->findOne(['_id' => $key]);
        if ($doc === null) {
            $this->misses++;
            return null;
        }
        $exp = $doc['expires_at'] ?? null;
        if ($exp !== null && (float)$exp > 0 && microtime(true) > (float)$exp) {
            $this->deleteOne(['_id' => $key]);
            $this->misses++;
            return null;
        }
        $this->hits++;
        $decoded = json_decode((string)($doc['value'] ?? 'null'), true);
        return $decoded;
    }

    public function set(string $key, mixed $value, int $ttl): void
    {
        if (!$this->available) {
            return;
        }
        $doc = [
            '_id' => $key,
            'value' => json_encode($value),
            'expires_at' => $ttl > 0 ? microtime(true) + $ttl : 0.0,
        ];
        $this->upsert(['_id' => $key], $doc);
    }

    public function delete(string $key): bool
    {
        if (!$this->available) {
            return false;
        }
        return $this->deleteOne(['_id' => $key]) > 0;
    }

    public function clear(): void
    {
        $this->hits = 0;
        $this->misses = 0;
        if ($this->available) {
            $this->deleteMany([]);
        }
    }

    public function stats(): array
    {
        $size = 0;
        if ($this->available) {
            $size = $this->countDocuments();
        }
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'size' => $size,
            'backend' => 'mongodb',
        ];
    }

    public function name(): string
    {
        return 'mongodb';
    }

    // ── Mongo operations ───────────────────────────────────────────

    private function ping(): bool
    {
        try {
            $result = $this->command(['ping' => 1, '$db' => 'admin']);
            return (int)($result['ok'] ?? 0) === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    private function createTtlIndex(): void
    {
        try {
            $this->command([
                'createIndexes' => $this->collection,
                'indexes' => [[
                    'key' => ['expires_at' => 1],
                    'name' => 'expires_at_ttl',
                    'expireAfterSeconds' => 0,
                ]],
                '$db' => $this->database,
            ]);
        } catch (\Throwable) {
            // Non-fatal — lazy read-time expiry still applies.
        }
    }

    private function findOne(array $filter): ?array
    {
        $result = $this->command([
            'find' => $this->collection,
            'filter' => $filter,
            'limit' => 1,
            '$db' => $this->database,
        ]);
        $docs = $result['cursor']['firstBatch'] ?? [];
        return !empty($docs) ? $docs[0] : null;
    }

    private function upsert(array $filter, array $doc): void
    {
        $this->command([
            'update' => $this->collection,
            'updates' => [[
                'q' => $filter,
                'u' => $doc,
                'upsert' => true,
            ]],
            '$db' => $this->database,
        ]);
    }

    private function deleteOne(array $filter): int
    {
        $result = $this->command([
            'delete' => $this->collection,
            'deletes' => [[
                'q' => $filter,
                'limit' => 1,
            ]],
            '$db' => $this->database,
        ]);
        return (int)($result['n'] ?? 0);
    }

    private function deleteMany(array $filter): int
    {
        $result = $this->command([
            'delete' => $this->collection,
            'deletes' => [[
                'q' => $filter,
                'limit' => 0,
            ]],
            '$db' => $this->database,
        ]);
        return (int)($result['n'] ?? 0);
    }

    private function countDocuments(): int
    {
        $result = $this->command([
            'count' => $this->collection,
            '$db' => $this->database,
        ]);
        return (int)($result['n'] ?? 0);
    }

    // ── Wire protocol (OP_MSG); BSON is Tina4\MongoBson ──────────────────────

    private function ensureConnected(): void
    {
        if ($this->socket === null) {
            $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, 5);
            if (!$this->socket) {
                throw new \RuntimeException("MongoDB connection failed: [{$errno}] {$errstr}");
            }
            stream_set_timeout($this->socket, 10);
        }
    }

    private function command(array $command): array
    {
        $this->ensureConnected();
        $this->requestId++;
        $bson = \Tina4\MongoBson::encode($command);
        $sections = pack('V', 0) . pack('C', 0) . $bson;
        $totalLength = 16 + strlen($sections);
        $header = pack('V', $totalLength)
            . pack('V', $this->requestId)
            . pack('V', 0)
            . pack('V', 2013);
        fwrite($this->socket, $header . $sections);
        return $this->readResponse();
    }

    private function readResponse(): array
    {
        $headerData = $this->readExact(16);
        $header = unpack('VmsgLen/VrequestId/VresponseTo/Vopcode', $headerData);
        $remaining = $header['msgLen'] - 16;
        $payload = $this->readExact($remaining);
        return \Tina4\MongoBson::decode(substr($payload, 5));
    }

    private function readExact(int $length): string
    {
        $buf = '';
        while (strlen($buf) < $length) {
            $chunk = fread($this->socket, $length - strlen($buf));
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('Failed to read MongoDB response');
            }
            $buf .= $chunk;
        }
        return $buf;
    }
}
