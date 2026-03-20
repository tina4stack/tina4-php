<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * RabbitMQ Queue Backend — AMQP 0-9-1 via raw TCP sockets (zero deps).
 *
 * Environment variables:
 *   TINA4_RABBITMQ_HOST     — hostname (default: localhost)
 *   TINA4_RABBITMQ_PORT     — port (default: 5672)
 *   TINA4_RABBITMQ_USERNAME — username (default: guest)
 *   TINA4_RABBITMQ_PASSWORD — password (default: guest)
 *   TINA4_RABBITMQ_VHOST    — virtual host (default: /)
 */

namespace Tina4\Queue;

class RabbitMQBackend implements QueueBackend
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $vhost;

    /** @var resource|null TCP socket */
    private $socket = null;

    /** @var int AMQP channel number */
    private int $channelId = 1;

    /** @var array<string, bool> Declared queues */
    private array $declaredQueues = [];

    /** @var int Frame counter for delivery tags */
    private int $nextDeliveryTag = 1;

    /** @var int|null Last delivery tag for acknowledgment */
    private ?int $lastDeliveryTag = null;

    /**
     * @param array $config Configuration overrides:
     *   'host', 'port', 'username', 'password', 'vhost'
     */
    public function __construct(array $config = [])
    {
        $this->host = $config['host'] ?? (getenv('TINA4_RABBITMQ_HOST') ?: 'localhost');
        $this->port = (int)($config['port'] ?? (getenv('TINA4_RABBITMQ_PORT') ?: 5672));
        $this->username = $config['username'] ?? (getenv('TINA4_RABBITMQ_USERNAME') ?: 'guest');
        $this->password = $config['password'] ?? (getenv('TINA4_RABBITMQ_PASSWORD') ?: 'guest');
        $this->vhost = $config['vhost'] ?? (getenv('TINA4_RABBITMQ_VHOST') ?: '/');
    }

    /**
     * Connect to RabbitMQ via raw TCP socket and perform AMQP handshake.
     *
     * @throws \RuntimeException If connection fails
     */
    public function connect(): void
    {
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, 10);
        if (!$this->socket) {
            throw new \RuntimeException("RabbitMQ connection failed: [{$errno}] {$errstr}");
        }

        stream_set_timeout($this->socket, 30);

        // Send AMQP protocol header
        fwrite($this->socket, "AMQP\x00\x00\x09\x01");

        // Read Connection.Start
        $this->readFrame();

        // Send Connection.StartOk with PLAIN auth
        $this->sendConnectionStartOk();

        // Read Connection.Tune
        $this->readFrame();

        // Send Connection.TuneOk
        $this->sendConnectionTuneOk();

        // Send Connection.Open
        $this->sendConnectionOpen();

        // Read Connection.OpenOk
        $this->readFrame();

        // Open channel
        $this->sendChannelOpen();

        // Read Channel.OpenOk
        $this->readFrame();
    }

    /** {@inheritDoc} */
    public function enqueue(string $topic, array $message): string
    {
        $this->ensureConnected();
        $this->declareQueue($topic);

        $id = $message['id'] ?? bin2hex(random_bytes(8));
        $message['id'] = $id;
        $body = json_encode($message);

        $this->sendBasicPublish($topic, $body);

        return $id;
    }

    /** {@inheritDoc} */
    public function dequeue(string $topic): ?array
    {
        $this->ensureConnected();
        $this->declareQueue($topic);

        $result = $this->sendBasicGet($topic);
        if ($result === null) {
            return null;
        }

        $this->lastDeliveryTag = $result['deliveryTag'];
        return $result['message'];
    }

    /** {@inheritDoc} */
    public function acknowledge(string $topic, string $messageId): void
    {
        if ($this->lastDeliveryTag !== null) {
            $this->ensureConnected();
            $this->sendBasicAck($this->lastDeliveryTag);
            $this->lastDeliveryTag = null;
        }
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
        $this->ensureConnected();
        return $this->declareQueue($topic);
    }

    /** {@inheritDoc} */
    public function close(): void
    {
        if ($this->socket) {
            // Send Channel.Close
            $this->sendChannelClose();
            $this->readFrame();

            // Send Connection.Close
            $this->sendConnectionClose();
            $this->readFrame();

            fclose($this->socket);
            $this->socket = null;
        }
    }

    // ── AMQP Protocol Implementation ─────────────────────────────

    private function ensureConnected(): void
    {
        if ($this->socket === null) {
            $this->connect();
        }
    }

    /**
     * Read a single AMQP frame from the socket.
     *
     * @return array{type: int, channel: int, payload: string}
     */
    private function readFrame(): array
    {
        $header = fread($this->socket, 7);
        if (strlen($header) < 7) {
            throw new \RuntimeException('Failed to read AMQP frame header');
        }

        $data = unpack('Ctype/nchannel/Nsize', $header);
        $payload = '';
        $remaining = $data['size'];

        while ($remaining > 0) {
            $chunk = fread($this->socket, $remaining);
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('Failed to read AMQP frame payload');
            }
            $payload .= $chunk;
            $remaining -= strlen($chunk);
        }

        // Read frame-end byte (0xCE)
        $frameEnd = fread($this->socket, 1);
        if (ord($frameEnd) !== 0xCE) {
            throw new \RuntimeException('Invalid AMQP frame end marker');
        }

        return ['type' => $data['type'], 'channel' => $data['channel'], 'payload' => $payload];
    }

    /**
     * Write a raw AMQP frame to the socket.
     */
    private function writeFrame(int $type, int $channel, string $payload): void
    {
        $frame = pack('CnN', $type, $channel, strlen($payload)) . $payload . "\xCE";
        fwrite($this->socket, $frame);
    }

    /**
     * Write a method frame (type=1).
     */
    private function writeMethod(int $channel, int $classId, int $methodId, string $args = ''): void
    {
        $payload = pack('nn', $classId, $methodId) . $args;
        $this->writeFrame(1, $channel, $payload);
    }

    /**
     * Encode a short string (length-prefixed with 1 byte).
     */
    private function shortStr(string $s): string
    {
        return pack('C', strlen($s)) . $s;
    }

    /**
     * Encode a long string (length-prefixed with 4 bytes).
     */
    private function longStr(string $s): string
    {
        return pack('N', strlen($s)) . $s;
    }

    /**
     * Encode an empty AMQP table.
     */
    private function emptyTable(): string
    {
        return pack('N', 0);
    }

    private function sendConnectionStartOk(): void
    {
        $mechanism = 'PLAIN';
        $response = "\x00" . $this->username . "\x00" . $this->password;
        $locale = 'en_US';

        $args = $this->emptyTable()           // client-properties
            . $this->shortStr($mechanism)
            . $this->longStr($response)
            . $this->shortStr($locale);

        // Connection.StartOk = class 10, method 11
        $this->writeMethod(0, 10, 11, $args);
    }

    private function sendConnectionTuneOk(): void
    {
        // Connection.TuneOk = class 10, method 31
        $args = pack('nNn', 0, 131072, 60); // channel-max=0, frame-max=131072, heartbeat=60
        $this->writeMethod(0, 10, 31, $args);
    }

    private function sendConnectionOpen(): void
    {
        // Connection.Open = class 10, method 40
        $args = $this->shortStr($this->vhost)
            . $this->shortStr('')  // reserved
            . pack('C', 0);        // reserved

        $this->writeMethod(0, 10, 40, $args);
    }

    private function sendChannelOpen(): void
    {
        // Channel.Open = class 20, method 10
        $args = $this->shortStr('');
        $this->writeMethod($this->channelId, 20, 10, $args);
    }

    private function sendChannelClose(): void
    {
        // Channel.Close = class 20, method 40
        $args = pack('n', 200) . $this->shortStr('Normal shutdown') . pack('nn', 0, 0);
        $this->writeMethod($this->channelId, 20, 40, $args);
    }

    private function sendConnectionClose(): void
    {
        // Connection.Close = class 10, method 50
        $args = pack('n', 200) . $this->shortStr('Normal shutdown') . pack('nn', 0, 0);
        $this->writeMethod(0, 10, 50, $args);
    }

    /**
     * Declare a queue and return the message count.
     */
    private function declareQueue(string $queue): int
    {
        if (isset($this->declaredQueues[$queue])) {
            return 0;
        }

        // Queue.Declare = class 50, method 10
        $args = pack('n', 0)              // reserved
            . $this->shortStr($queue)
            . pack('C', 0b00000010)       // durable=true
            . $this->emptyTable();        // arguments

        $this->writeMethod($this->channelId, 50, 10, $args);

        // Read Queue.DeclareOk
        $frame = $this->readFrame();
        $this->declaredQueues[$queue] = true;

        // Parse message count from DeclareOk (after class+method+short-string)
        $payload = $frame['payload'];
        $offset = 4; // skip class_id(2) + method_id(2)
        $queueNameLen = ord($payload[$offset]);
        $offset += 1 + $queueNameLen;
        $counts = unpack('NmessageCount/NconsumerCount', substr($payload, $offset, 8));

        return $counts['messageCount'] ?? 0;
    }

    private function sendBasicPublish(string $queue, string $body): void
    {
        // Basic.Publish = class 60, method 40
        $args = pack('n', 0)              // reserved
            . $this->shortStr('')         // exchange (default)
            . $this->shortStr($queue)     // routing key
            . pack('C', 0);              // mandatory=false, immediate=false

        $this->writeMethod($this->channelId, 60, 40, $args);

        // Content header frame (type=2)
        $contentHeader = pack('nn', 60, 0)          // class-id, weight
            . pack('J', strlen($body))               // body size (64-bit)
            . pack('n', 0b1000000000000000)          // property flags: content-type set
            . $this->shortStr('application/json');

        $this->writeFrame(2, $this->channelId, $contentHeader);

        // Content body frame (type=3)
        $this->writeFrame(3, $this->channelId, $body);
    }

    /**
     * @return array{deliveryTag: int, message: array}|null
     */
    private function sendBasicGet(string $queue): ?array
    {
        // Basic.Get = class 60, method 70
        $args = pack('n', 0)
            . $this->shortStr($queue)
            . pack('C', 0); // no-ack = false

        $this->writeMethod($this->channelId, 60, 70, $args);

        $frame = $this->readFrame();
        $payload = $frame['payload'];
        $methodId = unpack('n', substr($payload, 2, 2))[1];

        // Basic.GetEmpty = method 72
        if ($methodId === 72) {
            return null;
        }

        // Basic.GetOk = method 71
        // Parse delivery tag
        $deliveryTag = unpack('J', substr($payload, 4, 8))[1];

        // Read content header
        $headerFrame = $this->readFrame();
        $bodySize = unpack('J', substr($headerFrame['payload'], 4, 8))[1];

        // Read content body
        $body = '';
        $remaining = $bodySize;
        while ($remaining > 0) {
            $bodyFrame = $this->readFrame();
            $body .= $bodyFrame['payload'];
            $remaining -= strlen($bodyFrame['payload']);
        }

        $message = json_decode($body, true) ?? [];
        return ['deliveryTag' => $deliveryTag, 'message' => $message];
    }

    private function sendBasicAck(int $deliveryTag): void
    {
        // Basic.Ack = class 60, method 80
        $args = pack('J', $deliveryTag) . pack('C', 0); // multiple=false
        $this->writeMethod($this->channelId, 60, 80, $args);
    }
}
