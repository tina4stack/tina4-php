<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Kafka Queue Backend — Kafka protocol via raw TCP sockets (zero deps).
 *
 * Environment variables:
 *   TINA4_KAFKA_BROKERS  — comma-separated broker list (default: localhost:9092)
 *   TINA4_KAFKA_GROUP_ID — consumer group ID (default: tina4_consumer_group)
 */

namespace Tina4\Queue;

class KafkaBackend implements QueueBackend
{
    private string $brokers;
    private string $groupId;

    /** @var resource|null TCP socket */
    private $socket = null;

    /** @var int Correlation ID counter */
    private int $correlationId = 0;

    /** @var string Client ID */
    private string $clientId = 'tina4-php';

    /** @var array<string, int> Topic partition offsets for consuming */
    private array $offsets = [];

    /** @var array<string, bool> Topics we have sent initial metadata for */
    private array $knownTopics = [];

    /**
     * @param array $config Configuration overrides:
     *   'brokers', 'group_id'
     */
    public function __construct(array $config = [])
    {
        $this->brokers = $config['brokers'] ?? (getenv('TINA4_KAFKA_BROKERS') ?: 'localhost:9092');
        $this->groupId = $config['group_id'] ?? (getenv('TINA4_KAFKA_GROUP_ID') ?: 'tina4_consumer_group');
    }

    /**
     * Connect to the first available Kafka broker via raw TCP socket.
     *
     * @throws \RuntimeException If connection fails
     */
    public function connect(): void
    {
        $brokerList = explode(',', $this->brokers);
        $connected = false;

        foreach ($brokerList as $broker) {
            $broker = trim($broker);
            $parts = explode(':', $broker);
            $host = $parts[0];
            $port = (int)($parts[1] ?? 9092);

            $this->socket = @fsockopen($host, $port, $errno, $errstr, 10);
            if ($this->socket) {
                stream_set_timeout($this->socket, 30);
                $connected = true;
                break;
            }
        }

        if (!$connected) {
            throw new \RuntimeException("Kafka connection failed: could not connect to any broker in [{$this->brokers}]");
        }
    }

    /** {@inheritDoc} */
    public function enqueue(string $topic, array $message): string
    {
        $this->ensureConnected();
        $this->ensureTopicMetadata($topic);

        $id = $message['id'] ?? bin2hex(random_bytes(8));
        $message['id'] = $id;
        $value = json_encode($message);

        $this->sendProduce($topic, $id, $value);

        return $id;
    }

    /** {@inheritDoc} */
    public function dequeue(string $topic): ?array
    {
        $this->ensureConnected();
        $this->ensureTopicMetadata($topic);

        $offset = $this->offsets[$topic] ?? 0;
        $result = $this->sendFetch($topic, $offset);

        if ($result === null) {
            return null;
        }

        // Advance offset
        $this->offsets[$topic] = $result['nextOffset'];

        return $result['message'];
    }

    /** {@inheritDoc} */
    public function acknowledge(string $topic, string $messageId): void
    {
        // In this simple implementation, offset advancement in dequeue
        // serves as acknowledgment. For full Kafka consumer group support,
        // OffsetCommit would be sent here.
    }

    /** {@inheritDoc} */
    public function requeue(string $topic, array $message): void
    {
        $this->enqueue($topic, $message);
    }

    /** {@inheritDoc} */
    public function deadLetter(string $topic, array $message): void
    {
        $this->enqueue($topic . '.dead_letter', $message);
    }

    /** {@inheritDoc} */
    public function size(string $topic): int
    {
        // Kafka doesn't have a simple "queue size" concept.
        // We return 0 as a placeholder — proper implementation would
        // query earliest and latest offsets.
        return 0;
    }

    /** {@inheritDoc} */
    public function close(): void
    {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    // ── Kafka Protocol Implementation ────────────────────────────

    private function ensureConnected(): void
    {
        if ($this->socket === null) {
            $this->connect();
        }
    }

    private function ensureTopicMetadata(string $topic): void
    {
        if (isset($this->knownTopics[$topic])) {
            return;
        }

        $this->sendMetadataRequest($topic);
        $this->knownTopics[$topic] = true;
    }

    /**
     * Read a Kafka response from the socket.
     *
     * @return string The response payload (after length+correlationId)
     */
    private function readResponse(): string
    {
        $header = fread($this->socket, 4);
        if (strlen($header) < 4) {
            throw new \RuntimeException('Failed to read Kafka response length');
        }

        $length = unpack('N', $header)[1];
        $payload = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = fread($this->socket, min($remaining, 8192));
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('Failed to read Kafka response payload');
            }
            $payload .= $chunk;
            $remaining -= strlen($chunk);
        }

        // Skip correlation ID (first 4 bytes of payload)
        return substr($payload, 4);
    }

    /**
     * Build a Kafka request header.
     */
    private function requestHeader(int $apiKey, int $apiVersion): string
    {
        $this->correlationId++;
        return pack('nnN', $apiKey, $apiVersion, $this->correlationId)
            . $this->kafkaString($this->clientId);
    }

    /**
     * Send a complete Kafka request (with length prefix).
     */
    private function sendRequest(string $data): void
    {
        $frame = pack('N', strlen($data)) . $data;
        fwrite($this->socket, $frame);
    }

    /**
     * Encode a Kafka-style string (int16 length prefix).
     */
    private function kafkaString(string $s): string
    {
        return pack('n', strlen($s)) . $s;
    }

    /**
     * Encode a Kafka-style bytes (int32 length prefix).
     */
    private function kafkaBytes(string $s): string
    {
        return pack('N', strlen($s)) . $s;
    }

    /**
     * Encode a Kafka nullable bytes (int32 length, -1 for null).
     */
    private function kafkaNullableBytes(?string $s): string
    {
        if ($s === null) {
            return pack('N', 0xFFFFFFFF); // -1 as unsigned int32
        }
        return pack('N', strlen($s)) . $s;
    }

    /**
     * Send a MetadataRequest (API key 3) to learn about the topic.
     */
    private function sendMetadataRequest(string $topic): void
    {
        $header = $this->requestHeader(3, 0);
        $body = pack('N', 1) . $this->kafkaString($topic); // 1 topic

        $this->sendRequest($header . $body);
        $this->readResponse(); // Consume the metadata response
    }

    /**
     * Send a ProduceRequest (API key 0).
     */
    private function sendProduce(string $topic, string $key, string $value): void
    {
        $header = $this->requestHeader(0, 0);

        // Build the message (MessageSet format v0)
        $message = pack('C', 0)           // magic byte
            . pack('C', 0)                // attributes
            . $this->kafkaBytes($key)     // key
            . $this->kafkaBytes($value);  // value

        $crc = crc32($message);
        $fullMessage = pack('N', $crc) . $message;

        // MessageSet: offset (8 bytes) + message size (4 bytes) + message
        $messageSet = pack('J', 0) . pack('N', strlen($fullMessage)) . $fullMessage;

        // ProduceRequest body
        $body = pack('n', 1)              // required acks = 1
            . pack('N', 5000)             // timeout ms
            . pack('N', 1)                // 1 topic
            . $this->kafkaString($topic)
            . pack('N', 1)                // 1 partition
            . pack('N', 0)                // partition 0
            . pack('N', strlen($messageSet))
            . $messageSet;

        $this->sendRequest($header . $body);
        $this->readResponse();
    }

    /**
     * Send a FetchRequest (API key 1).
     *
     * @return array{message: array, nextOffset: int}|null
     */
    private function sendFetch(string $topic, int $offset): ?array
    {
        $header = $this->requestHeader(1, 0);

        $body = pack('N', -1)             // replica id
            . pack('N', 5000)             // max wait ms
            . pack('N', 1)                // min bytes
            . pack('N', 1)                // 1 topic
            . $this->kafkaString($topic)
            . pack('N', 1)                // 1 partition
            . pack('N', 0)                // partition 0
            . pack('J', $offset)          // fetch offset
            . pack('N', 1048576);         // max bytes (1MB)

        $this->sendRequest($header . $body);

        $response = $this->readResponse();

        // Parse FetchResponse — simplified parsing
        // Skip: topic count(4) + topic name + partition count(4) + partition(4)
        //       + error code(2) + high watermark(8) + message set size(4)
        $pos = 4; // number of topics
        $topicNameLen = unpack('n', substr($response, $pos, 2))[1];
        $pos += 2 + $topicNameLen; // skip topic name
        $pos += 4; // partition count
        $pos += 4; // partition id
        $errorCode = unpack('n', substr($response, $pos, 2))[1];
        $pos += 2;
        $pos += 8; // high watermark
        $messageSetSize = unpack('N', substr($response, $pos, 4))[1];
        $pos += 4;

        if ($messageSetSize === 0 || $errorCode !== 0) {
            return null;
        }

        // Parse first message from MessageSet
        $msStart = $pos;
        $msgOffset = unpack('J', substr($response, $pos, 8))[1];
        $pos += 8;
        $msgSize = unpack('N', substr($response, $pos, 4))[1];
        $pos += 4;

        // Skip CRC(4) + magic(1) + attributes(1)
        $pos += 6;

        // Key
        $keyLen = unpack('N', substr($response, $pos, 4))[1];
        $pos += 4;
        if ($keyLen > 0 && $keyLen !== 0xFFFFFFFF) {
            $pos += $keyLen;
        }

        // Value
        $valueLen = unpack('N', substr($response, $pos, 4))[1];
        $pos += 4;
        if ($valueLen <= 0 || $valueLen === 0xFFFFFFFF) {
            return null;
        }

        $value = substr($response, $pos, $valueLen);
        $message = json_decode($value, true);

        if ($message === null) {
            return null;
        }

        return [
            'message' => $message,
            'nextOffset' => $msgOffset + 1,
        ];
    }
}
