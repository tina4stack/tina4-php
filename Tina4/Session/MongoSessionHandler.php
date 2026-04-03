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
 *   TINA4_SESSION_MONGO_URL — MongoDB connection URL (default: mongodb://localhost:27017)
 *   TINA4_SESSION_MONGO_DB  — database name (default: tina4)
 *   TINA4_SESSION_TTL       — session TTL in seconds (default: 3600)
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
        $this->mongoUrl = $config['url'] ?? (getenv('TINA4_SESSION_MONGO_URL') ?: 'mongodb://localhost:27017');
        $this->database = $config['database'] ?? (getenv('TINA4_SESSION_MONGO_DB') ?: 'tina4');
        $this->collection = $config['collection'] ?? 'sessions';
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

        // Check TTL expiry
        $lastAccessed = $result['last_accessed'] ?? 0;
        if (time() - $lastAccessed > $this->ttl) {
            $this->delete($sessionId);
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
    public function write(string $sessionId, array $data): void
    {
        $this->ensureConnected();

        $doc = [
            '_id' => $sessionId,
            'data' => $data,
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
    public function delete(string $sessionId): void
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
        $bsonCmd = $this->encodeBson($command);

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
        return $this->decodeBson($bsonData);
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

    // ── Minimal BSON Encoder/Decoder ────────────────────────────

    /**
     * Encode a PHP array/object as BSON.
     * Supports: string, int, float, bool, null, array (document/array).
     */
    private function encodeBson(array $doc): string
    {
        $body = '';

        foreach ($doc as $key => $value) {
            $body .= $this->encodeBsonElement((string)$key, $value);
        }

        $body .= "\x00"; // document terminator
        return pack('V', strlen($body) + 4) . $body;
    }

    private function encodeBsonElement(string $key, mixed $value): string
    {
        $ckey = $key . "\x00";

        if ($value === null) {
            return "\x0A" . $ckey;
        }

        if (is_bool($value)) {
            return "\x08" . $ckey . ($value ? "\x01" : "\x00");
        }

        if (is_int($value)) {
            if ($value >= -2147483648 && $value <= 2147483647) {
                return "\x10" . $ckey . pack('V', $value); // int32
            }
            return "\x12" . $ckey . pack('P', $value); // int64
        }

        if (is_float($value)) {
            return "\x01" . $ckey . pack('e', $value); // double
        }

        if (is_string($value)) {
            return "\x02" . $ckey . pack('V', strlen($value) + 1) . $value . "\x00";
        }

        if (is_array($value)) {
            // Check if it's a sequential array or associative
            if (array_is_list($value)) {
                // BSON array
                $indexed = [];
                foreach ($value as $i => $v) {
                    $indexed[(string)$i] = $v;
                }
                $encoded = $this->encodeBson($indexed);
                return "\x04" . $ckey . $encoded;
            }

            // BSON document
            $encoded = $this->encodeBson($value);
            return "\x03" . $ckey . $encoded;
        }

        // Fallback: convert to string
        $s = (string)$value;
        return "\x02" . $ckey . pack('V', strlen($s) + 1) . $s . "\x00";
    }

    /**
     * Decode BSON into a PHP array.
     */
    private function decodeBson(string $data): array
    {
        $pos = 0;
        return $this->decodeBsonDocument($data, $pos);
    }

    private function decodeBsonDocument(string $data, int &$pos): array
    {
        $docLen = unpack('V', substr($data, $pos, 4))[1];
        $pos += 4;
        $end = $pos + $docLen - 5; // -4 for length, -1 for terminator

        $doc = [];
        while ($pos < $end) {
            $type = ord($data[$pos]);
            $pos++;

            // Read C-string key
            $keyEnd = strpos($data, "\x00", $pos);
            $key = substr($data, $pos, $keyEnd - $pos);
            $pos = $keyEnd + 1;

            $doc[$key] = $this->decodeBsonValue($data, $pos, $type);
        }

        $pos++; // skip terminator byte

        return $doc;
    }

    private function decodeBsonValue(string $data, int &$pos, int $type): mixed
    {
        switch ($type) {
            case 0x01: // double
                $val = unpack('e', substr($data, $pos, 8))[1];
                $pos += 8;
                return $val;

            case 0x02: // string
                $len = unpack('V', substr($data, $pos, 4))[1];
                $pos += 4;
                $val = substr($data, $pos, $len - 1);
                $pos += $len;
                return $val;

            case 0x03: // document
                return $this->decodeBsonDocument($data, $pos);

            case 0x04: // array
                return array_values($this->decodeBsonDocument($data, $pos));

            case 0x08: // boolean
                $val = ord($data[$pos]) !== 0;
                $pos++;
                return $val;

            case 0x0A: // null
                return null;

            case 0x10: // int32
                $val = unpack('V', substr($data, $pos, 4))[1];
                $pos += 4;
                // Handle signed int32
                if ($val >= 2147483648) {
                    $val -= 4294967296;
                }
                return $val;

            case 0x12: // int64
                $val = unpack('P', substr($data, $pos, 8))[1];
                $pos += 8;
                return $val;

            default:
                // Skip unknown types by returning null
                return null;
        }
    }
}
