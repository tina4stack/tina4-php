<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Kafka Queue Backend — Kafka protocol via raw TCP sockets (zero deps).
 *
 * Environment variables:
 *   TINA4_KAFKA_BROKERS  — comma-separated broker list (default: localhost:9092)
 *   TINA4_KAFKA_GROUP_ID — consumer group ID (default: tina4_consumer_group)
 *
 *   TLS/SASL (each read as TINA4_KAFKA_<NAME> first, then bare KAFKA_<NAME>):
 *   TINA4_KAFKA_SECURITY_PROTOCOL — e.g. SSL / SASL_SSL (default: PLAINTEXT)
 *   TINA4_KAFKA_SSL_CA_LOCATION   — CA cert path for TLS brokers/proxies
 *   TINA4_KAFKA_SASL_MECHANISM / TINA4_KAFKA_SASL_USERNAME / TINA4_KAFKA_SASL_PASSWORD — optional SASL
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

    /** @var array<string, string> Resolved SSL/SASL client config (empty = PLAINTEXT). */
    private array $security = [];

    /** @var array<string, string> Producer client config (bootstrap + client id + security). */
    private array $producerConfig = [];

    /** @var array<string, string> Consumer client config (adds group id + offset reset + security). */
    private array $consumerConfig = [];

    /**
     * @param array $config Configuration overrides:
     *   'brokers', 'group_id'
     */
    /**
     * Lowest Produce/Fetch versions Kafka 4.x still accepts.
     *
     * Kafka 4.0 removed message formats v0/v1, and with them Produce v0-v2 and
     * Fetch v0-v3. A request at a removed version is not answered with an error
     * code -- the broker logs UnsupportedVersionException and CLOSES THE SOCKET,
     * so the client only sees EOF. These are the first versions that carry
     * RecordBatch v2, which is what encodeRecordBatch/decodeFirstRecord speak.
     */
    private const PRODUCE_VERSION = 3;
    private const FETCH_VERSION = 4;

    public function __construct(array $config = [])
    {
        $this->brokers = $config['brokers'] ?? (getenv('TINA4_KAFKA_BROKERS') ?: 'localhost:9092');
        $this->groupId = $config['group_id'] ?? (getenv('TINA4_KAFKA_GROUP_ID') ?: 'tina4_consumer_group');

        // Resolve SSL/SASL once and apply it to BOTH the producer and the
        // consumer client setup (parity with the Python master's
        // _connect_confluent merging **security into each config).
        $this->security = self::securityConfig();

        $this->producerConfig = [
            'bootstrap.servers' => $this->brokers,
            'client.id' => $this->clientId,
        ] + $this->security;

        $this->consumerConfig = [
            'bootstrap.servers' => $this->brokers,
            'client.id' => $this->clientId,
            'group.id' => $this->groupId,
            'auto.offset.reset' => 'earliest',
            'enable.auto.commit' => 'false',
        ] + $this->security;
    }

    /**
     * Build SSL/SASL client config from env (for a TLS broker/proxy).
     *
     * Each setting is read from the Tina4-namespaced env var first
     * (TINA4_KAFKA_SECURITY_PROTOCOL …) and falls back to the bare
     * librdkafka-convention name (KAFKA_SECURITY_PROTOCOL …) that many Kafka
     * deployments already set. Honours security.protocol (e.g. SSL, SASL_SSL),
     * ssl.ca.location, and optional SASL (mechanism / username / password).
     * Unset keys are omitted, leaving the PLAINTEXT default.
     *
     * Mirrors tina4_python/queue_backends/kafka_backend.py::_security_config.
     *
     * @return array<string, string>
     */
    public static function securityConfig(): array
    {
        // rdkafka key -> env suffix (read as TINA4_KAFKA_<suffix>, then KAFKA_<suffix>)
        $mapping = [
            'security.protocol' => 'SECURITY_PROTOCOL',
            'ssl.ca.location' => 'SSL_CA_LOCATION',
            'sasl.mechanism' => 'SASL_MECHANISM',
            'sasl.username' => 'SASL_USERNAME',
            'sasl.password' => 'SASL_PASSWORD',
        ];

        $config = [];
        foreach ($mapping as $rdk => $suffix) {
            $value = getenv("TINA4_KAFKA_{$suffix}");
            if ($value === false || $value === '') {
                $value = getenv("KAFKA_{$suffix}");
            }
            if ($value !== false && $value !== '') {
                $config[$rdk] = $value;
            }
        }

        return $config;
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

        // Honour the resolved security config: an SSL/SASL_SSL security.protocol
        // means the transport is TLS — open a tls:// socket (with the optional
        // ssl.ca.location CA bundle) instead of a plain TCP one.
        $protocol = strtoupper($this->security['security.protocol'] ?? 'PLAINTEXT');
        $useTls = str_contains($protocol, 'SSL');

        foreach ($brokerList as $broker) {
            $broker = trim($broker);
            $parts = explode(':', $broker);
            $host = $parts[0];
            $port = (int)($parts[1] ?? 9092);

            if ($useTls) {
                $sslOpts = ['verify_peer' => true, 'verify_peer_name' => true];
                if (!empty($this->security['ssl.ca.location'])) {
                    $sslOpts['cafile'] = $this->security['ssl.ca.location'];
                }
                $context = stream_context_create(['ssl' => $sslOpts]);
                $this->socket = @stream_socket_client(
                    "tls://{$host}:{$port}",
                    $errno,
                    $errstr,
                    10,
                    STREAM_CLIENT_CONNECT,
                    $context
                );
            } else {
                $this->socket = @fsockopen($host, $port, $errno, $errstr, 10);
            }

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
        if ((int)($message['delay_seconds'] ?? 0) > 0) {
            throw new \RuntimeException(
                'The kafka queue backend cannot honour push(delay): Kafka has no '
                . 'per-message delay at all. A consumer reads a partition in offset '
                . 'order, so a delayed record would stall every record behind it. Use '
                . 'the file or mongodb backend for delayed jobs, or schedule the push '
                . 'itself.'
            );
        }

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
    // ── Varints (Kafka record format v2 uses zigzag LEB128) ──────────

    /**
     * Zigzag-encode a signed integer so small negatives stay small.
     *
     * Kafka's record format v2 encodes lengths and deltas as zigzag varints, so
     * -1 (a null key/value) costs one byte instead of ten.
     */
    private static function zigzag(int $n): int
    {
        // >> on a negative PHP int is arithmetic (sign-propagating), so this
        // yields -1 for negatives and 0 for positives -- exactly the mask needed.
        return ($n << 1) ^ ($n >> 63);
    }

    /**
     * Encode a signed integer as a zigzag LEB128 varint.
     *
     * Bounded by the record format's own limits: lengths and deltas are int32 and
     * timestamps int64, all far inside PHP's 64-bit int, so no overflow path exists
     * for values this protocol can legally carry.
     */
    private static function varint(int $value): string
    {
        $u = self::zigzag($value);
        $out = '';
        do {
            $byte = $u & 0x7F;
            $u >>= 7;
            if ($u !== 0) {
                $byte |= 0x80;
            }
            $out .= chr($byte);
        } while ($u !== 0);

        return $out;
    }

    /**
     * Read one zigzag LEB128 varint, advancing $pos past it.
     */
    private static function readVarint(string $buf, int &$pos): int
    {
        $result = 0;
        $shift = 0;
        while ($pos < strlen($buf)) {
            $b = ord($buf[$pos]);
            $pos++;
            $result |= ($b & 0x7F) << $shift;
            if (($b & 0x80) === 0) {
                break;
            }
            $shift += 7;
        }

        // un-zigzag
        return ($result >> 1) ^ -($result & 1);
    }

    // ── RecordBatch v2 ──────────────────────────────────────────────

    /**
     * Encode a single record into a RecordBatch v2.
     *
     * Kafka 4.x REMOVED message formats v0/v1, and with them Produce v0-v2 and
     * Fetch v0-v3: a v0 Produce is answered with
     *   UnsupportedVersionException: Received request for api with key 0 (Produce)
     *   and unsupported version 0
     * and the broker CLOSES THE SOCKET, so the client sees a bare EOF with no
     * error code to report. (Note `kafka-broker-api-versions.sh` still advertises
     * "Produce(0): 0 to 13" -- the advertised range and the handler disagree, so
     * trust the broker log, not the tool.)
     *
     * The batch is a header plus varint-encoded records, and the CRC is
     * **CRC32C (Castagnoli)** over everything after the crc field -- NOT the
     * CRC32 that format v0 used and that PHP's crc32() computes. Getting that
     * wrong is silently accepted by the encoder and rejected by the broker, so it
     * goes through hash('crc32c', ...) (PHP 8.1+).
     */
    private function encodeRecordBatch(string $key, string $value): string
    {
        $timestamp = (int)(microtime(true) * 1000);

        // One record, offsetDelta 0, timestampDelta 0, no headers.
        $recordBody = pack('C', 0)                       // attributes (unused, must be 0)
            . self::varint(0)                            // timestampDelta
            . self::varint(0)                            // offsetDelta
            . self::varint(strlen($key)) . $key          // key
            . self::varint(strlen($value)) . $value      // value
            . self::varint(0);                           // header count
        $record = self::varint(strlen($recordBody)) . $recordBody;

        // Everything the CRC covers: attributes onwards.
        $afterCrc = pack('n', 0)                         // attributes (no compression)
            . pack('N', 0)                               // lastOffsetDelta (1 record => 0)
            . pack('J', $timestamp)                      // baseTimestamp
            . pack('J', $timestamp)                      // maxTimestamp
            . "\xFF\xFF\xFF\xFF\xFF\xFF\xFF\xFF"         // producerId -1 (not idempotent)
            . "\xFF\xFF"                                 // producerEpoch -1
            . "\xFF\xFF\xFF\xFF"                         // baseSequence -1
            . pack('N', 1)                               // record count
            . $record;

        $crc = hash('crc32c', $afterCrc, true);          // raw 4 bytes, big-endian

        // partitionLeaderEpoch + magic + crc + the CRC-covered region.
        $afterLength = "\xFF\xFF\xFF\xFF"                // partitionLeaderEpoch -1
            . pack('C', 2)                               // magic = 2 (record format v2)
            . $crc
            . $afterCrc;

        return pack('J', 0)                              // baseOffset
            . pack('N', strlen($afterLength))            // batchLength
            . $afterLength;
    }

    /**
     * Send a ProduceRequest v3 (the lowest version Kafka 4.x still accepts).
     *
     * @throws \RuntimeException when the broker reports a partition error
     */
    private function sendProduce(string $topic, string $key, string $value): void
    {
        // Topic auto-creation is ASYNCHRONOUS: the MetadataRequest triggers it, but
        // the partition leader is not elected by the time the first produce lands,
        // so a brand-new topic answers UNKNOWN_TOPIC_OR_PARTITION (3) or
        // LEADER_NOT_AVAILABLE (5). Both are retriable, and every real Kafka client
        // retries them after a metadata refresh.
        //
        // This matters more than it looks: the previous implementation never read
        // the error code at all, so it reported SUCCESS on exactly this race and
        // silently dropped the message. Reading the code turned a data-loss bug
        // into a visible one; retrying is what makes it correct.
        $retriable = [3, 5];
        $attempts = 0;

        while (true) {
            $attempts++;
            $errorCode = $this->produceOnce($topic, $key, $value);
            if ($errorCode === 0) {
                return;
            }
            if (!in_array($errorCode, $retriable, true) || $attempts >= 10) {
                throw new \RuntimeException(
                    "Kafka rejected the produce for topic {$topic}: error code {$errorCode}"
                    . ($attempts > 1 ? " after {$attempts} attempts" : '')
                );
            }
            // Re-ask for metadata (which is what creates the topic) and back off.
            unset($this->knownTopics[$topic]);
            $this->ensureTopicMetadata($topic);
            usleep(200000);
        }
    }

    /**
     * One ProduceRequest v3 round trip.
     *
     * @return int the partition error code (0 = success)
     */
    private function produceOnce(string $topic, string $key, string $value): int
    {
        $header = $this->requestHeader(0, self::PRODUCE_VERSION);
        $batch = $this->encodeRecordBatch($key, $value);

        $body = "\xFF\xFF"                    // transactional_id = null (v3+)
            . pack('n', 1)                    // acks = 1
            . pack('N', 5000)                 // timeout ms
            . pack('N', 1)                    // 1 topic
            . $this->kafkaString($topic)
            . pack('N', 1)                    // 1 partition
            . pack('N', 0)                    // partition 0
            . pack('N', strlen($batch))       // records as BYTES
            . $batch;

        $this->sendRequest($header . $body);
        $response = $this->readResponse();

        // responses[1] -> name -> partitions[1] -> index, error_code, ...
        $pos = 4;                                                     // topic array count
        $nameLen = unpack('n', substr($response, $pos, 2))[1];
        $pos += 2 + $nameLen;
        $pos += 4;                                                    // partition array count
        $pos += 4;                                                    // partition index

        return unpack('n', substr($response, $pos, 2))[1];
    }

    /**
     * Send a FetchRequest (API key 1).
     *
     * @return array{message: array, nextOffset: int}|null
     */
    /**
     * Decode the FIRST record out of a RecordBatch v2 blob.
     *
     * Returns null when the blob holds no usable record -- an empty batch, a
     * control batch (transaction markers, which carry no user payload), or a
     * record whose value is not the JSON this queue writes.
     *
     * @return array{value: string, offset: int}|null
     */
    private function decodeFirstRecord(string $records): ?array
    {
        if (strlen($records) < 61) {   // a v2 batch header alone is 61 bytes
            return null;
        }

        $baseOffset = unpack('J', substr($records, 0, 8))[1];
        $magic = ord($records[16]);
        if ($magic !== 2) {
            // Only format v2 is possible on a broker that still talks to us; a
            // different magic means the response was mis-framed, so say so rather
            // than silently returning "no message".
            throw new \RuntimeException(
                "Unexpected Kafka record format v{$magic}; this client speaks v2 only"
            );
        }

        $attributes = unpack('n', substr($records, 21, 2))[1];
        if (($attributes & 0x20) !== 0) {
            return null;                        // control batch: no user records
        }
        if (($attributes & 0x07) !== 0) {
            throw new \RuntimeException(
                'Kafka returned a COMPRESSED record batch; this client does not '
                . 'decompress. Set compression.type=producer or none on the topic.'
            );
        }

        $count = unpack('N', substr($records, 57, 4))[1];
        if ($count === 0) {
            return null;
        }

        $pos = 61;                              // first record starts after the header
        self::readVarint($records, $pos);       // record length (not needed)
        $pos += 1;                              // record attributes
        self::readVarint($records, $pos);       // timestampDelta
        $offsetDelta = self::readVarint($records, $pos);

        $keyLen = self::readVarint($records, $pos);
        if ($keyLen > 0) {
            $pos += $keyLen;
        }

        $valueLen = self::readVarint($records, $pos);
        if ($valueLen <= 0) {
            return null;                        // null or empty value
        }

        return [
            'value' => substr($records, $pos, $valueLen),
            'offset' => $baseOffset + $offsetDelta,
        ];
    }

    /**
     * Send a FetchRequest v4 (the lowest version Kafka 4.x still accepts -- it
     * dropped v0-v3 along with the old message formats) and decode the first
     * record out of the returned RecordBatch v2.
     *
     * @return array{message: array, nextOffset: int}|null
     */
    private function sendFetch(string $topic, int $offset): ?array
    {
        $header = $this->requestHeader(1, self::FETCH_VERSION);

        $body = "\xFF\xFF\xFF\xFF"            // replica id -1 (ordinary consumer)
            . pack('N', 5000)                 // max wait ms
            . pack('N', 1)                    // min bytes
            . pack('N', 52428800)             // max bytes (50MB) -- v3+
            . pack('C', 0)                    // isolation level: read_uncommitted -- v4+
            . pack('N', 1)                    // 1 topic
            . $this->kafkaString($topic)
            . pack('N', 1)                    // 1 partition
            . pack('N', 0)                    // partition 0
            . pack('J', $offset)              // fetch offset
            . pack('N', 1048576);             // partition max bytes

        $this->sendRequest($header . $body);
        $response = $this->readResponse();

        $pos = 0;
        $pos += 4;                                                    // throttle_time_ms
        $pos += 4;                                                    // topic array count
        $nameLen = unpack('n', substr($response, $pos, 2))[1];
        $pos += 2 + $nameLen;                                         // topic name
        $pos += 4;                                                    // partition array count
        $pos += 4;                                                    // partition index
        $errorCode = unpack('n', substr($response, $pos, 2))[1];
        $pos += 2;
        $pos += 8;                                                    // high watermark
        $pos += 8;                                                    // last stable offset -- v4+

        // aborted_transactions: nullable array, -1 means null.
        $aborted = unpack('N', substr($response, $pos, 4))[1];
        $pos += 4;
        if ($aborted !== 0xFFFFFFFF) {
            $pos += $aborted * 16;                                    // producer_id + first_offset
        }

        $recordsSize = unpack('N', substr($response, $pos, 4))[1];
        $pos += 4;

        // UNKNOWN_TOPIC_OR_PARTITION (3) and LEADER_NOT_AVAILABLE (5) mean "nothing
        // to read here yet", not a failure: a consumer that starts before its
        // producer, or right after auto-creation (which is asynchronous), sees them
        // routinely, and dequeue() is documented to return null when there is no
        // message. Any OTHER code is a real protocol/authorisation failure and still
        // throws loudly.
        if ($errorCode === 3 || $errorCode === 5) {
            return null;
        }
        if ($errorCode !== 0) {
            throw new \RuntimeException(
                "Kafka rejected the fetch for topic {$topic}: error code {$errorCode}"
            );
        }
        if ($recordsSize === 0 || $recordsSize === 0xFFFFFFFF) {
            return null;                                              // nothing available
        }

        $record = $this->decodeFirstRecord(substr($response, $pos, $recordsSize));
        if ($record === null) {
            return null;
        }

        $message = json_decode($record['value'], true);
        if (!is_array($message)) {
            return null;
        }

        return [
            'message' => $message,
            'nextOffset' => $record['offset'] + 1,
        ];
    }
}
