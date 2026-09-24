<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * MongoDB Session Handler — stores sessions in MongoDB via raw TCP sockets.
 * Zero external dependencies.
 *
 * Environment variables:
 *   TINA4_SESSION_MONGO_URI        — MongoDB connection URI (default: mongodb://localhost:27017).
 *                                    TINA4_SESSION_MONGO_URL is accepted as a legacy alias.
 *   TINA4_SESSION_MONGO_DB         — database name (default: tina4)
 *   TINA4_SESSION_MONGO_COLLECTION — collection name (default: sessions)
 *   TINA4_SESSION_TTL              — session TTL in seconds (default: 3600)
 */

namespace Tina4\Session;

class MongoSessionHandler
{
    private string $mongoUrl;
    private string $database;
    private string $collection;
    private int $ttl;

    /** @var resource|null TCP socket */
    private $socket = null;

    /** @var int Request ID counter */
    private int $requestId = 0;

    /** @var string Parsed host */
    private string $host;

    /** @var int Parsed port */
    private int $port;

    /**
     * @param array $config Configuration overrides:
     *   'url'        => string  MongoDB URL
     *   'database'   => string  Database name
     *   'collection' => string  Collection name
     *   'ttl'        => int     Session TTL in seconds
     */
    public function __construct(array $config = [])
    {
        // Canonical TINA4_SESSION_MONGO_URI; TINA4_SESSION_MONGO_URL is a legacy alias.
        $this->mongoUrl = $config['url'] ?? (getenv('TINA4_SESSION_MONGO_URI') ?: getenv('TINA4_SESSION_MONGO_URL') ?: 'mongodb://localhost:27017');
        $this->database = $config['database'] ?? (getenv('TINA4_SESSION_MONGO_DB') ?: 'tina4');
        // TINA4_SESSION_MONGO_COLLECTION reaches this handler the way every other
        // coordinate does — EXPLICIT CONFIGURATION FIRST, THEN THE ENVIRONMENT,
        // THEN THE DEFAULT. Python, Ruby and Node have all read this variable;
        // PHP read nothing, so Session::getMongoHandler() (which constructs with
        // NO config at all) wrote every session to `sessions` no matter what the
        // .env said. One .env, four frameworks, three of them agreeing — the
        // ADR-0024 failure mode: identical configuration, different observable
        // outcome, and silent, because writing to the wrong collection is not an
        // error, so the backend-failure policy can never fire.
        $this->collection = $config['collection'] ?? (getenv('TINA4_SESSION_MONGO_COLLECTION') ?: 'sessions');
        $this->ttl = (int)($config['ttl'] ?? (getenv('TINA4_SESSION_TTL') ?: 3600));

        $parsed = parse_url($this->mongoUrl);
        $this->host = $parsed['host'] ?? 'localhost';
        $this->port = $parsed['port'] ?? 27017;
    }

    /**
     * Read session data by session ID.
     *
     * @param string $sessionId The session ID
     * @return array Session data or empty array
     */
    public function read(string $sessionId): array
    {
        $this->ensureConnected();

        $result = $this->findOne(
            $this->database . '.' . $this->collection,
            ['_id' => $sessionId]
        );

        if ($result === null) {
            return [];
        }

        // Expiry is an ABSOLUTE deadline stamped at write time, and an absent or
        // zero stamp means "never expires" — so it is guarded OUT of the
        // comparison, never fed INTO it.
        //
        // This previously read `time() - ($result['last_accessed'] ?? 0) > $this->ttl`,
        // which puts a missing stamp (0) into a subtraction that is then always
        // true, so an unstamped document was DESTROYED on read. It survived only
        // because this handler's own write() always stamps; any document written
        // by another framework, an older version, or a direct insert was destroyed.
        $expiresAt = (float)($result['expires_at'] ?? 0);
        if ($expiresAt > 0 && $expiresAt < microtime(true)) {
            $this->destroy($sessionId);
            return [];
        }

        return $result['data'] ?? [];
    }

    /**
     * Write session data.
     *
     * @param string $sessionId The session ID
     * @param array  $data      Session data to store
     */
    public function write(string $sessionId, array $data, int $ttl = 0): void
    {
        $this->ensureConnected();

        // The ttl is consumed HERE, at write time, and baked into an absolute
        // deadline. Nothing at read time needs to know what the ttl was, so a
        // read can never fabricate an expiry for a record that carries none.
        $effectiveTtl = $ttl > 0 ? $ttl : $this->ttl;
        $doc = [
            '_id' => $sessionId,
            'data' => $data,
            'expires_at' => $effectiveTtl > 0 ? microtime(true) + $effectiveTtl : 0,
            'last_accessed' => time(),
            'created_at' => time(),
        ];

        $this->upsert(
            $this->database . '.' . $this->collection,
            ['_id' => $sessionId],
            $doc
        );
    }

    /**
     * Delete a session.
     *
     * @param string $sessionId The session ID
     */
    public function destroy(string $sessionId): void
    {
        $this->ensureConnected();

        $this->deleteOne(
            $this->database . '.' . $this->collection,
            ['_id' => $sessionId]
        );
    }

    /**
     * Close the connection.
     */
    public function close(): void
    {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    // ── MongoDB Wire Protocol (OP_MSG) ──────────────────────────

    private function ensureConnected(): void
    {
        if ($this->socket === null) {
            $this->connect();
        }
    }

    private function connect(): void
    {
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, 10);
        if (!$this->socket) {
            throw new \RuntimeException("MongoDB connection failed: [{$errno}] {$errstr}");
        }
        stream_set_timeout($this->socket, 30);
    }

    /**
     * Send an OP_MSG command and read the response.
     *
     * @param array $command The command document
     * @return array Response document
     */
    private function command(array $command): array
    {
        $this->requestId++;
        $bsonCmd = \Tina4\MongoBson::encode($command);

        // OP_MSG body section: flagBits(4) + sectionKind(1) + BSON
        $sections = pack('V', 0)         // flagBits = 0
            . pack('C', 0)               // section kind = body
            . $bsonCmd;

        // Message header: length(4) + requestID(4) + responseTo(4) + opCode(4)
        $totalLength = 16 + strlen($sections);
        $header = pack('V', $totalLength)
            . pack('V', $this->requestId)
            . pack('V', 0)               // responseTo
            . pack('V', 2013);           // OP_MSG opcode

        fwrite($this->socket, $header . $sections);

        // Read response
        return $this->readResponse();
    }

    private function readResponse(): array
    {
        $headerData = fread($this->socket, 16);
        if (strlen($headerData) < 16) {
            throw new \RuntimeException('Failed to read MongoDB response header');
        }

        $header = unpack('VmsgLen/VrequestId/VresponseTo/Vopcode', $headerData);
        $remaining = $header['msgLen'] - 16;

        $payload = '';
        while ($remaining > 0) {
            $chunk = fread($this->socket, min($remaining, 8192));
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('Failed to read MongoDB response');
            }
            $payload .= $chunk;
            $remaining -= strlen($chunk);
        }

        // OP_MSG: skip flagBits(4) + sectionKind(1), then BSON
        $bsonData = substr($payload, 5);
        return \Tina4\MongoBson::decode($bsonData);
    }

    private function findOne(string $namespace, array $filter): ?array
    {
        $parts = explode('.', $namespace, 2);
        $result = $this->command([
            'find' => $parts[1],
            'filter' => $filter,
            'limit' => 1,
            '$db' => $parts[0],
        ]);

        $docs = $result['cursor']['firstBatch'] ?? [];
        return !empty($docs) ? $docs[0] : null;
    }

    private function upsert(string $namespace, array $filter, array $doc): void
    {
        $parts = explode('.', $namespace, 2);
        $this->command([
            'update' => $parts[1],
            'updates' => [
                [
                    'q' => $filter,
                    'u' => $doc,
                    'upsert' => true,
                ],
            ],
            '$db' => $parts[0],
        ]);
    }

    private function deleteOne(string $namespace, array $filter): void
    {
        $parts = explode('.', $namespace, 2);
        $this->command([
            'delete' => $parts[1],
            'deletes' => [
                [
                    'q' => $filter,
                    'limit' => 1,
                ],
            ],
            '$db' => $parts[0],
        ]);
    }
}
