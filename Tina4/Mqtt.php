<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Zero-dependency MQTT 3.1.1 client -- the protocol every broker and every IoT
 * device already speaks.
 *
 * Built on PHP streams (`stream_socket_client`, `pack`/`unpack`) plus `ext-openssl`
 * for TLS: no Composer package, so an app that talks to Mosquitto / EMQX / HiveMQ /
 * AWS IoT adds nothing to its dependency tree.
 *
 * Shaped like Tina4\Queue on purpose -- publish / subscribe / consume:
 *
 *     use Tina4\Mqtt;
 *
 *     $mqtt = new Mqtt();                                 // TINA4_MQTT_URL
 *     $mqtt->publish('fleet/meter-42/telemetry', '{"kwh":12.5}', qos: 1);
 *
 *     foreach ($mqtt->consume('fleet/+/telemetry', qos: 1) as $message) {
 *         if ($message->isDuplicate()) { continue; }      // QoS 1 is at-least-once
 *         store($message->topic, $message->payload);
 *     }
 *
 * Environment: TINA4_MQTT_URL (default mqtt://127.0.0.1:1883),
 * TINA4_MQTT_CLIENT_ID, TINA4_MQTT_KEEPALIVE (seconds, default 60),
 * TINA4_MQTT_CA_FILE, TINA4_MQTT_TLS_VERIFY.
 *
 * No background task is started by default. When a connection is otherwise idle
 * the broker drops it past 1.5x keepalive, so opt in to the cooperative keepalive
 * with startKeepalive() -- it registers a Server::onTick() callback exactly like
 * the queue consumers do, rather than spawning a process of its own.
 *
 * Single reader: like every MQTT client the socket has ONE network reader. Call
 * receive()/consume() from one place; PHP has no real threads, so writes never
 * need a lock.
 */
final class Mqtt
{
    // Control packet types. The low nibble of SUBSCRIBE is 0x2 because MQTT 3.1.1
    // mandates QoS 1 on that packet.
    private const CONNECT = 0x10;
    private const CONNACK = 0x20;
    private const PUBLISH = 0x30;
    private const PUBACK = 0x40;
    private const SUBSCRIBE = 0x82;
    private const SUBACK = 0x90;
    private const PINGREQ = 0xC0;
    private const PINGRESP = 0xD0;
    private const DISCONNECT = 0xE0;

    private const PROTOCOL_LEVEL = 0x04; // 4 == MQTT 3.1.1
    private const DEFAULT_PORT = 1883;
    private const DEFAULT_TLS_PORT = 8883;
    private const DEFAULT_URL = 'mqtt://127.0.0.1:1883';
    private const DEFAULT_KEEPALIVE = 60;
    private const SUBSCRIPTION_REFUSED = 0x80;
    private const MAX_REMAINING_LENGTH = 268435455; // 4 varint bytes
    private const READ_CHUNK = 16384;

    /**
     * QoS 2 is refused, never silently downgraded. A caller who asked for
     * exactly-once and quietly got at-least-once would double-process every
     * duplicate forever without ever seeing an error.
     */
    private const QOS2_REFUSED_MESSAGE =
        'MQTT QoS 2 (exactly-once delivery) is not supported by Tina4 -- this ' .
        'client speaks QoS 0 and QoS 1 only, and refuses QoS 2 rather than ' .
        'silently downgrading it to QoS 1. Use QoS 1 with an idempotent consumer ' .
        'keyed on (device_id, device_timestamp): duplicates are then harmless, ' .
        'which is what exactly-once was for.';

    /** @var array<int,string> Human-readable CONNACK return codes (MQTT 3.1.1 section 3.2.2.3). */
    private const CONNACK_RETURN_CODES = [
        1 => 'unacceptable protocol version',
        2 => 'client identifier rejected',
        3 => 'server unavailable',
        4 => 'bad user name or password',
        5 => 'not authorised',
    ];

    public readonly string $host;
    public readonly int $port;
    public readonly string $clientId;
    public readonly int $keepalive;
    public readonly bool $cleanSession;
    public readonly ?string $username;

    private readonly bool $tls;
    private readonly ?string $password;
    private readonly ?string $caFile;
    private readonly bool $tlsVerify;
    private readonly ?string $willTopic;
    private readonly mixed $willPayload;
    private readonly int $willQos;
    private readonly bool $willRetain;
    private readonly float $timeout;
    private readonly ?float $readTimeout;

    private int $packetId = 0;
    /** @var array<int,MqttMessage> */
    private array $inbox = [];
    private float $lastWriteAt = 0.0;
    /** @var resource|null */
    private $socket = null;
    /** @var callable|null */
    private $keepaliveTask = null;
    private string $readBuffer = '';
    private int $readCursor = 0;

    /**
     * @param string|null   $url          mqtt://host:port, or mqtts://host:port for TLS. Credentials may ride in the userinfo (mqtt://user:pass@host), percent-decoded. Falls back to TINA4_MQTT_URL, then localhost.
     * @param string|null   $clientId     broker-visible session id (TINA4_MQTT_CLIENT_ID, else generated). A durable session (cleanSession false) needs a STABLE id.
     * @param string|null   $username     broker username; overrides the url's userinfo when given
     * @param string|null   $password     broker password; MQTT 3.1.1 forbids one without a username
     * @param string|null   $caFile       PEM CA bundle used to verify the broker's certificate (TINA4_MQTT_CA_FILE)
     * @param bool|null      $tlsVerify    false disables certificate verification (TINA4_MQTT_TLS_VERIFY); never the default, and it logs a warning naming the risk
     * @param int|null       $keepalive    seconds (TINA4_MQTT_KEEPALIVE, default 60)
     * @param bool           $cleanSession false keeps the subscription + queues QoS 1 messages for us while offline, replayed on reconnect
     * @param string|null    $willTopic    Last Will topic, published by the broker when we die WITHOUT a DISCONNECT
     * @param mixed          $willPayload  Last Will payload
     * @param int            $willQos      Last Will QoS
     * @param bool           $willRetain   whether the Last Will is retained
     * @param float          $timeout      seconds to wait for a control-packet answer (CONNACK / PUBACK / SUBACK)
     * @param float|null     $readTimeout  seconds to wait for an application message; null blocks
     * @param bool           $connect      false builds the client without opening a socket
     *
     * @throws \InvalidArgumentException on a bad url or a password without a username
     * @throws MqttError                 on a connection / handshake failure (when $connect is true)
     */
    public function __construct(
        ?string $url = null,
        ?string $clientId = null,
        ?string $username = null,
        ?string $password = null,
        ?string $caFile = null,
        ?bool $tlsVerify = null,
        ?int $keepalive = null,
        bool $cleanSession = true,
        ?string $willTopic = null,
        mixed $willPayload = null,
        int $willQos = 0,
        bool $willRetain = false,
        float $timeout = 5,
        ?float $readTimeout = null,
        bool $connect = true
    ) {
        $parsed = self::parseUrl($url ?? (Env::str('TINA4_MQTT_URL', '') ?: self::DEFAULT_URL));
        $this->host = $parsed['host'];
        $this->port = $parsed['port'];
        $this->tls = $parsed['tls'];

        // Explicit arguments win over the url's userinfo: the more specific source.
        $this->username = $username ?? $parsed['username'];
        $pw = $password ?? $parsed['password'];
        if ($pw === '') {
            $pw = null;
        }
        $this->password = $pw;
        if ($this->password !== null && ($this->username === null || $this->username === '')) {
            throw new \InvalidArgumentException(
                'MQTT password without a username is not allowed by MQTT 3.1.1 -- ' .
                'supply both (mqtt://user:pass@host, or username:/password:) or neither'
            );
        }

        $this->caFile = $caFile ?? (Env::str('TINA4_MQTT_CA_FILE', '') ?: null);
        $this->tlsVerify = $tlsVerify ?? Env::bool('TINA4_MQTT_TLS_VERIFY', true);

        $cid = $clientId ?? (Env::str('TINA4_MQTT_CLIENT_ID', '') ?: null);
        if ($cid === null || $cid === '') {
            $cid = 'tina4-' . bin2hex(random_bytes(8));
        }
        $this->clientId = $cid;

        $this->keepalive = $keepalive ?? Env::int('TINA4_MQTT_KEEPALIVE', self::DEFAULT_KEEPALIVE);
        $this->cleanSession = $cleanSession;
        $this->willTopic = $willTopic;
        $this->willPayload = $willPayload;
        $this->willQos = $willQos;
        $this->willRetain = $willRetain;
        $this->timeout = $timeout;
        $this->readTimeout = $readTimeout;

        if ($willTopic !== null) {
            $this->refuseUnsupportedQos($willQos);
        }

        if ($connect) {
            $this->connect();
        }
    }

    // -- url parsing --------------------------------------------------------

    /**
     * Split an MQTT url into ['host', 'port', 'tls', 'username', 'password'].
     *
     * "mqtt://host:port" and "tcp://host:port" are plain TCP (default port 1883);
     * "mqtts://host:port" is TLS (default 8883). A bare "host" or "host:port"
     * works too, and an IPv6 literal is bracketed ("mqtt://[::1]:1883").
     * Credentials ride in the userinfo and are percent-decoded, so a password
     * containing @ : or / survives.
     *
     * @param string $url the connection url
     * @return array{host: string, port: int, tls: bool, username: ?string, password: ?string}
     * @throws \InvalidArgumentException on an empty url, an unsupported scheme, or a malformed host/port
     */
    public static function parseUrl(string $url): array
    {
        $raw = trim($url);
        if ($raw === '') {
            throw new \InvalidArgumentException('MQTT url is empty -- set TINA4_MQTT_URL (e.g. ' . self::DEFAULT_URL . ')');
        }

        $scheme = null;
        if (preg_match('#^([A-Za-z][A-Za-z0-9+.\-]*)://#', $raw, $m)) {
            $scheme = strtolower($m[1]);
            if (!in_array($scheme, ['mqtt', 'tcp', 'mqtts'], true)) {
                throw new \InvalidArgumentException(
                    "unsupported MQTT url scheme '{$scheme}' in '{$raw}' -- this client speaks " .
                    'mqtt://, tcp:// or mqtts:// (TLS). WebSocket transports are not implemented.'
                );
            }
            $rest = substr($raw, strlen($m[0]));
        } else {
            $rest = $raw;
        }

        $tls = $scheme === 'mqtts';

        // Split on the LAST "@" so a password containing an un-encoded "@" still
        // leaves the host intact.
        $username = null;
        $password = null;
        $atPos = strrpos($rest, '@');
        if ($atPos !== false) {
            $userinfo = substr($rest, 0, $atPos);
            $rest = substr($rest, $atPos + 1);
            $colon = strpos($userinfo, ':');
            if ($colon === false) {
                $username = self::percentDecode($userinfo);
            } else {
                $username = self::percentDecode(substr($userinfo, 0, $colon));
                $password = self::percentDecode(substr($userinfo, $colon + 1));
            }
        }

        // host is a [bracketed ipv6] or a run without : or /
        $hostPort = explode('/', $rest, 2)[0];
        $host = $hostPort;
        $portStr = null;
        if (str_starts_with($hostPort, '[')) {
            $close = strpos($hostPort, ']');
            if ($close === false) {
                throw new \InvalidArgumentException("malformed MQTT url '{$raw}' -- unclosed IPv6 bracket");
            }
            $host = substr($hostPort, 1, $close - 1);
            $after = substr($hostPort, $close + 1);
            if (str_starts_with($after, ':')) {
                $portStr = substr($after, 1);
            }
        } else {
            $colon = strpos($hostPort, ':');
            if ($colon !== false) {
                $host = substr($hostPort, 0, $colon);
                $portStr = substr($hostPort, $colon + 1);
            }
        }

        if ($host === '') {
            throw new \InvalidArgumentException("malformed MQTT url '{$raw}' -- expected mqtt://host:port");
        }
        if ($portStr !== null && $portStr !== '' && !ctype_digit($portStr)) {
            throw new \InvalidArgumentException("malformed MQTT url '{$raw}' -- port must be numeric");
        }

        return [
            'host' => $host,
            'port' => ($portStr !== null && $portStr !== '') ? (int) $portStr : ($tls ? self::DEFAULT_TLS_PORT : self::DEFAULT_PORT),
            'tls' => $tls,
            'username' => ($username !== null && $username !== '') ? $username : null,
            'password' => $password,
        ];
    }

    /**
     * Decode %XX in url userinfo. rawurldecode, NOT urldecode: the latter also
     * turns "+" into a space, which silently corrupts a password.
     *
     * @param string $value the raw (still-encoded) userinfo component
     * @return string the decoded value
     */
    private static function percentDecode(string $value): string
    {
        return rawurldecode($value);
    }

    /**
     * Remaining Length varint: 7 bits per byte, high bit means "another byte
     * follows". A single-byte assumption works for every packet under 128 bytes
     * and then fails, so this is exercised directly at 0 / 127 / 128 / 16383.
     *
     * @param int $value the length to encode (0 .. 268435455)
     * @return string the 1-4 encoded bytes
     * @throws \InvalidArgumentException when $value is out of range
     */
    public static function encodeRemainingLength(int $value): string
    {
        if ($value < 0 || $value > self::MAX_REMAINING_LENGTH) {
            throw new \InvalidArgumentException("remaining length {$value} is outside 0.." . self::MAX_REMAINING_LENGTH);
        }
        $out = '';
        $length = $value;
        do {
            $byte = $length & 0x7F;
            $length >>= 7;
            $out .= chr($length > 0 ? ($byte | 0x80) : $byte);
        } while ($length > 0);
        return $out;
    }

    // -- connection ---------------------------------------------------------

    /**
     * Open the socket and complete the CONNECT / CONNACK handshake. Also the
     * reconnect path: an existing socket is closed first, and a durable session
     * (cleanSession false) resumes with the same clientId.
     *
     * @return bool true once the broker accepts the connection
     * @throws MqttError on a socket, TLS, or CONNACK failure
     */
    public function connect(): bool
    {
        $this->closeSocket();

        $contextOptions = ['socket' => ['tcp_nodelay' => true]];
        if ($this->tls) {
            $contextOptions['ssl'] = $this->tlsContextOptions();
        }
        $context = stream_context_create($contextOptions);

        // Connect over plain TCP first, then upgrade to TLS explicitly. Doing the
        // handshake as a separate step lets us capture the real OpenSSL error (a
        // suppressed ssl:// connect can hide "certificate verify failed" behind a
        // generic message).
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($socket === false) {
            throw new MqttError("could not connect to MQTT broker at {$this->host}:{$this->port}: {$errstr} (errno {$errno})");
        }
        stream_set_blocking($socket, true);
        if ($this->tls) {
            $this->enableCrypto($socket);
        }
        $this->socket = $socket;

        $this->inbox = [];
        $this->readBuffer = '';
        $this->readCursor = 0;

        // Payload order is FIXED: client id, will topic, will message, username,
        // password. Emitting them in any other order shifts every field after it.
        $body = self::mqttString('MQTT')
            . pack('C2', self::PROTOCOL_LEVEL, $this->connectFlags())
            . pack('n', $this->keepalive)
            . self::mqttString($this->clientId);
        if ($this->willTopic !== null) {
            $body .= self::mqttString($this->willTopic);
            $willBytes = self::payloadBytes($this->willPayload);
            $body .= pack('n', strlen($willBytes)) . $willBytes;
        }
        if ($this->username !== null) {
            $body .= self::mqttString($this->username);
        }
        if ($this->password !== null) {
            $body .= self::mqttString($this->password);
        }
        $this->writePacket(self::CONNECT, $body);

        [$header, $payload] = $this->readPacket($this->deadlineIn($this->timeout));
        if ($header !== self::CONNACK || strlen($payload) < 2) {
            throw new MqttError(sprintf('expected CONNACK, got 0x%02x', $header));
        }
        $returnCode = ord($payload[1]);
        if ($returnCode !== 0) {
            $reason = self::CONNACK_RETURN_CODES[$returnCode] ?? 'unknown return code';
            throw new MqttError("broker refused the connection: {$reason} (CONNACK return code {$returnCode})");
        }
        return true;
    }

    /**
     * Whether a socket is currently open.
     *
     * @return bool
     */
    public function connected(): bool
    {
        return $this->socket !== null;
    }

    /**
     * True when this connection runs over TLS (mqtts://).
     *
     * @return bool
     */
    public function tls(): bool
    {
        return $this->tls;
    }

    /**
     * The negotiated cipher suite name, or null on a plain connection. A real
     * name here is proof the TLS handshake actually completed.
     *
     * @return string|null
     */
    public function cipher(): ?string
    {
        if (!$this->tls || !$this->connected()) {
            return null;
        }
        $meta = stream_get_meta_data($this->socket);
        return $meta['crypto']['cipher_name'] ?? null;
    }

    /**
     * The negotiated TLS protocol version ("TLSv1.3"), or null when plain.
     *
     * @return string|null
     */
    public function tlsVersion(): ?string
    {
        if (!$this->tls || !$this->connected()) {
            return null;
        }
        $meta = stream_get_meta_data($this->socket);
        return $meta['crypto']['protocol'] ?? null;
    }

    /**
     * Debug view that NEVER exposes the password -- a client can end up in a
     * var_dump, a log line, or an exception report (parity with Python __repr__
     * and Ruby #inspect, both of which hide the password).
     *
     * @return array<string,mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'scheme' => $this->tls ? 'mqtts' : 'mqtt',
            'host' => $this->host,
            'port' => $this->port,
            'clientId' => $this->clientId,
            'username' => $this->username,
            'connected' => $this->connected(),
        ];
    }

    // -- publish / subscribe / receive -------------------------------------

    /**
     * Publish an application message. Returns the packet identifier for QoS 1
     * (the broker's PUBACK must carry it back) and null for QoS 0.
     *
     * retain=true tells the broker to keep this as the topic's last known value
     * and hand it to every FUTURE subscriber -- how a dashboard shows current
     * state the moment it connects. Publishing an EMPTY payload with retain=true
     * clears a retained value.
     *
     * @param string $topic   the topic to publish to
     * @param mixed  $payload the message payload (string/scalar; null == empty)
     * @param int    $qos     0 or 1 (QoS 2 is refused)
     * @param bool   $retain  keep this as the topic's retained value
     * @return int|null the packet id for QoS 1, else null
     * @throws \InvalidArgumentException on QoS 2 or an invalid QoS
     * @throws MqttError                 on a write / acknowledgement failure
     */
    public function publish(string $topic, mixed $payload, int $qos = 0, bool $retain = false): ?int
    {
        $this->refuseUnsupportedQos($qos);
        $payloadBytes = self::payloadBytes($payload);
        $topicField = self::mqttString($topic);
        // The packet identifier exists ONLY when QoS > 0.
        $packetId = $qos > 0 ? $this->nextPacketId() : null;

        $body = $topicField;
        if ($packetId !== null) {
            $body .= pack('n', $packetId);
        }
        $body .= $payloadBytes;
        $this->writePacket(self::PUBLISH | ($qos << 1) | ($retain ? 0x01 : 0x00), $body);

        if ($qos === 1) {
            $this->waitForAcknowledgement(self::PUBACK, $packetId, 'PUBACK');
        }
        return $packetId;
    }

    /**
     * Subscribe to a topic filter ("fleet/+/telemetry", "fleet/#"). Returns the
     * QoS the broker GRANTED, which can be lower than requested.
     *
     * A SUBACK carrying 0x80 is a REFUSAL, not a success -- treating any SUBACK
     * as success means sitting on a dead subscription receiving nothing, so it
     * raises here.
     *
     * @param string $topicFilter the topic filter to subscribe to
     * @param int    $qos         requested QoS (0 or 1)
     * @return int the granted QoS
     * @throws \InvalidArgumentException on QoS 2 or an invalid QoS
     * @throws MqttError                 on a malformed or refused SUBACK
     */
    public function subscribe(string $topicFilter, int $qos = 1): int
    {
        $this->refuseUnsupportedQos($qos);
        $packetId = $this->nextPacketId();
        $filterField = self::mqttString($topicFilter);

        $body = pack('n', $packetId) . $filterField . chr($qos);
        $this->writePacket(self::SUBSCRIBE, $body);

        $payload = $this->waitForAcknowledgement(self::SUBACK, $packetId, 'SUBACK');
        if (strlen($payload) < 3) {
            throw new MqttError("malformed SUBACK: no return code for '{$topicFilter}'");
        }
        $granted = ord($payload[2]);
        if ($granted === self::SUBSCRIPTION_REFUSED) {
            throw new MqttError(
                "broker refused the subscription to '{$topicFilter}' (SUBACK return " .
                'code 0x80) -- check the topic filter and the broker ACLs'
            );
        }
        return $granted;
    }

    /**
     * Read the next application message.
     *
     * ack=true (the default) acknowledges a QoS 1 delivery immediately, which is
     * right for a synchronous read. Pass ack=false when the message must be stored
     * before the broker is allowed to forget it -- an unacknowledged QoS 1 message
     * is redelivered with DUP set. consume() does exactly that.
     *
     * @param float|null $timeout seconds to wait; null uses the client's readTimeout
     * @param bool       $ack     acknowledge a QoS 1 delivery immediately
     * @return MqttMessage the next message
     * @throws MqttTimeoutError when no message arrives within the timeout
     * @throws MqttError        on a protocol / read failure
     */
    public function receive(?float $timeout = null, bool $ack = true): MqttMessage
    {
        $message = array_shift($this->inbox);
        if ($message === null) {
            $message = $this->readPublish($this->deadlineIn($timeout ?? $this->readTimeout));
        }
        if ($ack) {
            $message->acknowledge();
        }
        return $message;
    }

    /**
     * Long-running consumer, mirroring Queue::consume().
     *
     *     foreach ($mqtt->consume('fleet/+/telemetry', qos: 1) as $message) {
     *         store($message);
     *     }
     *
     * The message is acknowledged AFTER the loop body hands control back to the
     * generator (the next iteration), so a body that throws leaves the message
     * unacknowledged and the broker redelivers it with DUP set -- at-least-once,
     * the point of QoS 1. iterations > 0 stops after that many messages.
     *
     * @param string|null $topicFilter subscribe to this filter first (skip when already subscribed)
     * @param int         $qos         requested QoS for the subscribe
     * @param int         $iterations  stop after this many messages (0 == run forever)
     * @param float|null  $timeout     per-message read timeout
     * @return \Generator<int,MqttMessage>
     * @throws MqttError on a protocol / read failure
     */
    public function consume(?string $topicFilter = null, int $qos = 1, int $iterations = 0, ?float $timeout = null): \Generator
    {
        if ($topicFilter !== null) {
            $this->subscribe($topicFilter, $qos);
        }
        $consumed = 0;
        while (true) {
            $message = $this->receive($timeout, false);
            yield $message;
            $message->acknowledge();
            $consumed++;
            if ($iterations > 0 && $consumed >= $iterations) {
                break;
            }
        }
    }

    /**
     * PUBACK a QoS 1 delivery. Called by MqttMessage::acknowledge().
     *
     * @param int $packetId the packet identifier to acknowledge
     * @return bool true once the PUBACK is written
     * @throws MqttError on a write failure
     */
    public function acknowledge(int $packetId): bool
    {
        $this->writePacket(self::PUBACK, pack('n', $packetId));
        return true;
    }

    // -- keepalive ----------------------------------------------------------

    /**
     * PINGREQ and wait for the PINGRESP. Use this when nothing else is reading the
     * socket; under a consume loop use startKeepalive() instead.
     *
     * @param float|null $timeout seconds to wait for the PINGRESP
     * @return bool true when the broker answers
     * @throws MqttError on an unexpected packet or a read failure
     */
    public function ping(?float $timeout = null): bool
    {
        $this->sendKeepalive();
        $deadline = $this->deadlineIn($timeout ?? $this->timeout);
        while (true) {
            [$header, $payload] = $this->readPacket($deadline);
            if ($header === self::PINGRESP) {
                return true;
            }
            if ($this->stashPublish($header, $payload)) {
                continue;
            }
            throw new MqttError(sprintf('expected PINGRESP, got 0x%02x', $header));
        }
    }

    /**
     * Write a PINGREQ without waiting for the answer. The PINGRESP is absorbed by
     * whatever is reading the socket (receive() skips it).
     *
     * @return bool
     * @throws MqttError on a write failure
     */
    public function sendKeepalive(): bool
    {
        $this->writePacket(self::PINGREQ, '');
        return true;
    }

    /**
     * Opt in to the cooperative keepalive. Registers a Server::onTick() callback --
     * the same mechanism the queue consumers use -- that sends a PINGREQ only when
     * the connection has gone quiet, so an actively publishing client costs no
     * extra packets.
     *
     * @param float|null $interval tick interval in seconds; defaults to keepalive/2
     * @return callable the registered tick callback (pass to stopKeepalive via the client)
     * @throws MqttError when keepalive is disabled or no server loop is running
     */
    public function startKeepalive(?float $interval = null): callable
    {
        if ($this->keepaliveTask !== null) {
            return $this->keepaliveTask;
        }
        if ($this->keepalive <= 0) {
            throw new MqttError('keepalive is disabled (keepalive=0) -- nothing to schedule');
        }
        $server = Server::getInstance();
        if ($server === null) {
            throw new MqttError(
                'no running Tina4 server loop to attach the keepalive to -- ' .
                'call ping() from your own loop, or start the server first'
            );
        }
        $seconds = $interval ?? max($this->keepalive / 2.0, 1.0);
        $callback = function () use ($seconds): void {
            if ($this->connected() && $this->idleFor($seconds)) {
                $this->sendKeepalive();
            }
        };
        $server->onTick($callback, $seconds);
        $this->keepaliveTask = $callback;
        return $callback;
    }

    /**
     * Stop the cooperative keepalive registered by startKeepalive().
     *
     * @return bool true if a task was stopped, false if none was running
     */
    public function stopKeepalive(): bool
    {
        if ($this->keepaliveTask === null) {
            return false;
        }
        $server = Server::getInstance();
        if ($server !== null) {
            $server->stopTick($this->keepaliveTask);
        }
        $this->keepaliveTask = null;
        return true;
    }

    /**
     * Say goodbye properly: DISCONNECT then close. The broker discards the Last
     * Will on a graceful disconnect.
     *
     * @return bool
     */
    public function disconnect(): bool
    {
        $this->stopKeepalive();
        try {
            if ($this->connected()) {
                $this->writePacket(self::DISCONNECT, '');
            }
        } catch (MqttError) {
            // Already gone -- closing is still the right outcome.
        } finally {
            $this->closeSocket();
        }
        return true;
    }

    /**
     * Drop the socket WITHOUT a DISCONNECT -- what a crashed or unplugged device
     * looks like to the broker, and therefore what fires the Last Will.
     *
     * @return bool
     */
    public function kill(): bool
    {
        $this->stopKeepalive();
        $this->closeSocket();
        return true;
    }

    // -- internals ----------------------------------------------------------

    /**
     * Build the CONNECT flag byte from the session / will / credential state.
     *
     * @return int
     */
    private function connectFlags(): int
    {
        $flags = $this->cleanSession ? 0x02 : 0x00;
        if ($this->willTopic !== null) {
            // Will flag 0x04, will QoS at bits 3-4, will retain 0x20.
            $flags |= 0x04 | ($this->willQos << 3);
            if ($this->willRetain) {
                $flags |= 0x20;
            }
        }
        if ($this->username !== null) {
            $flags |= 0x80;
        }
        if ($this->password !== null) {
            $flags |= 0x40;
        }
        return $flags;
    }

    /**
     * Build the per-stream TLS context 'ssl' options.
     *
     * PHP's SSL options live on the stream context, so each connection already
     * verifies against its OWN store -- there is no process-wide default store to
     * poison the way OpenSSL's global store can be in Ruby/Python. Verification
     * uses the supplied CA (or the system CAs) and matches the certificate against
     * the host (including IP-address SANs) via peer_name.
     *
     * @return array<string,mixed>
     * @throws MqttError when a configured CA file does not exist
     */
    private function tlsContextOptions(): array
    {
        if ($this->tlsVerify) {
            $options = [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
                'peer_name' => $this->host,
            ];
            if ($this->caFile !== null) {
                if (!is_file($this->caFile)) {
                    throw new MqttError(
                        "MQTT CA file not found: {$this->caFile} -- TINA4_MQTT_CA_FILE (or caFile:) " .
                        "must point at the broker's CA certificate in PEM form"
                    );
                }
                $options['cafile'] = $this->caFile;
            }
            return $options;
        }

        Log::warning(
            "MQTT TLS certificate verification is DISABLED for mqtts://{$this->host}:{$this->port} -- " .
            'the connection is encrypted but the broker\'s identity is NOT verified, so a ' .
            'man in the middle can read and rewrite this traffic. Set TINA4_MQTT_CA_FILE ' .
            '(or caFile:) to the broker\'s CA and drop TINA4_MQTT_TLS_VERIFY=false.'
        );
        return [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ];
    }

    /**
     * Upgrade the plain TCP socket to TLS, capturing the real OpenSSL error on
     * failure so a rejected certificate reports WHY (not a generic message).
     *
     * @param resource $socket the connected plain socket to wrap
     * @return void
     * @throws MqttError on a handshake / verification failure
     */
    private function enableCrypto($socket): void
    {
        $errors = [];
        set_error_handler(static function (int $no, string $str) use (&$errors): bool {
            $errors[] = $str;
            return true;
        });
        try {
            $ok = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        } finally {
            restore_error_handler();
        }
        if ($ok !== true) {
            $detail = trim(implode('; ', $errors));
            while (($e = openssl_error_string()) !== false) {
                $detail .= ($detail !== '' ? '; ' : '') . $e;
            }
            @fclose($socket);
            $this->socket = null;
            throw new MqttError(
                "MQTT TLS handshake with {$this->host}:{$this->port} failed: " .
                ($detail !== '' ? $detail : 'certificate verification failed')
            );
        }
    }

    /**
     * Refuse an unsupported QoS loudly. QoS 2 is a deliberate, documented refusal;
     * anything other than 0/1 is a caller mistake.
     *
     * @param int $qos the requested QoS
     * @return void
     * @throws \InvalidArgumentException always, for QoS 2 or any value outside 0/1
     */
    private function refuseUnsupportedQos(int $qos): void
    {
        if ($qos === 2) {
            throw new \InvalidArgumentException(self::QOS2_REFUSED_MESSAGE);
        }
        if ($qos !== 0 && $qos !== 1) {
            throw new \InvalidArgumentException("qos must be 0 or 1 (got {$qos})");
        }
    }

    /**
     * Encode an MQTT string: a 2-byte big-endian length followed by UTF-8 bytes.
     *
     * @param string $value the string to encode
     * @return string the length-prefixed bytes
     * @throws \InvalidArgumentException when the string exceeds 65535 bytes
     */
    private static function mqttString(string $value): string
    {
        if (strlen($value) > 0xFFFF) {
            throw new \InvalidArgumentException('MQTT string is longer than 65535 bytes');
        }
        return pack('n', strlen($value)) . $value;
    }

    /**
     * Coerce a payload to its byte string. null becomes empty.
     *
     * @param mixed $payload the payload to coerce
     * @return string
     */
    private static function payloadBytes(mixed $payload): string
    {
        if ($payload === null) {
            return '';
        }
        if (is_string($payload)) {
            return $payload;
        }
        if (is_bool($payload)) {
            return $payload ? '1' : '0';
        }
        return (string) $payload;
    }

    /**
     * The next packet identifier (1..65535; 0 is invalid).
     *
     * @return int
     */
    private function nextPacketId(): int
    {
        $this->packetId = ($this->packetId % 0xFFFF) + 1;
        return $this->packetId;
    }

    /**
     * Write a full control packet (fixed header + body) in one buffer.
     *
     * @param int    $header the control byte
     * @param string $body   the packet body
     * @return bool true once fully written
     * @throws MqttError when not connected or on a write failure
     */
    private function writePacket(int $header, string $body): bool
    {
        if (!$this->connected()) {
            throw new MqttError('not connected to an MQTT broker');
        }
        $packet = chr($header) . self::encodeRemainingLength(strlen($body)) . $body;
        $total = strlen($packet);
        $written = 0;
        while ($written < $total) {
            $n = @fwrite($this->socket, substr($packet, $written));
            if ($n === false || $n === 0) {
                $meta = stream_get_meta_data($this->socket);
                if (!empty($meta['timed_out'])) {
                    throw new MqttError('MQTT write timed out');
                }
                throw new MqttError('MQTT write failed');
            }
            $written += $n;
        }
        $this->lastWriteAt = $this->monotonic();
        return true;
    }

    /**
     * Read one control packet.
     *
     * The fixed header is read in exactly 1 + N bytes (N <= 4 for the varint) so
     * the next packet's header is never consumed by a speculative over-read.
     *
     * @param float|null $deadline monotonic deadline, or null to block
     * @return array{0: int, 1: string} [controlByte, body]
     * @throws MqttError        on a malformed Remaining Length or a read failure
     * @throws MqttTimeoutError on a timeout
     */
    private function readPacket(?float $deadline): array
    {
        $header = ord($this->readExact(1, $deadline));
        $multiplier = 1;
        $length = 0;
        while (true) {
            $byte = ord($this->readExact(1, $deadline));
            $length += ($byte & 0x7F) * $multiplier;
            if (($byte & 0x80) === 0) {
                break;
            }
            $multiplier <<= 7;
            if ($multiplier > 0x200000) {
                throw new MqttError('malformed Remaining Length (more than 4 varint bytes)');
            }
        }
        return [$header, $length === 0 ? '' : $this->readExact($length, $deadline)];
    }

    /**
     * Read exactly $count bytes, filling one buffer a whole chunk at a time.
     *
     * TCP is a stream: a single read returns SHORT, so this loops. Blocking reads
     * with a per-read timeout keep TLS correct without a select() -- decrypted
     * plaintext buffered inside OpenSSL is returned by the next fread(), so there
     * is no "select the raw socket while plaintext waits" hang.
     *
     * @param int        $count    number of bytes required
     * @param float|null $deadline monotonic deadline, or null to block
     * @return string exactly $count bytes
     * @throws MqttTimeoutError on a timeout
     * @throws MqttError        when not connected, on EOF, or on a read failure
     */
    private function readExact(int $count, ?float $deadline): string
    {
        if (!$this->connected()) {
            throw new MqttError('not connected to an MQTT broker');
        }
        while ($this->bufferedBytes() < $count) {
            $this->fillReadBuffer($deadline);
        }
        return $this->takeBuffered($count);
    }

    /**
     * How many unread bytes sit in the read buffer.
     *
     * @return int
     */
    private function bufferedBytes(): int
    {
        return strlen($this->readBuffer) - $this->readCursor;
    }

    /**
     * Take $count bytes off the front of the read buffer, resetting it when drained.
     *
     * @param int $count number of bytes to take
     * @return string
     */
    private function takeBuffered(int $count): string
    {
        $chunk = substr($this->readBuffer, $this->readCursor, $count);
        $this->readCursor += $count;
        if ($this->readCursor >= strlen($this->readBuffer)) {
            $this->readBuffer = '';
            $this->readCursor = 0;
        }
        return $chunk;
    }

    /**
     * Block for more bytes, honouring the deadline via a per-read socket timeout.
     *
     * @param float|null $deadline monotonic deadline, or null to block ~forever
     * @return void
     * @throws MqttTimeoutError on a timeout
     * @throws MqttError        on EOF or a read failure
     */
    private function fillReadBuffer(?float $deadline): void
    {
        if ($deadline !== null) {
            $remaining = $deadline - $this->monotonic();
            if ($remaining <= 0) {
                throw new MqttTimeoutError('timed out waiting for the MQTT broker');
            }
            $seconds = (int) floor($remaining);
            $micros = (int) (($remaining - $seconds) * 1_000_000);
            stream_set_timeout($this->socket, $seconds, $micros);
        } else {
            // No deadline: block, but not literally forever (defeats a hung broker).
            stream_set_timeout($this->socket, 315_360_000);
        }

        $data = @fread($this->socket, self::READ_CHUNK);
        $meta = stream_get_meta_data($this->socket);
        if ($data === '' || $data === false) {
            if (!empty($meta['timed_out'])) {
                throw new MqttTimeoutError('timed out waiting for the MQTT broker');
            }
            if (feof($this->socket)) {
                throw new MqttError('broker closed the connection');
            }
            throw new MqttError('MQTT read failed');
        }
        $this->readBuffer .= $data;
    }

    /**
     * Read packets until the next PUBLISH, skipping the PINGRESPs the keepalive
     * generates.
     *
     * @param float|null $deadline monotonic deadline, or null to block
     * @return MqttMessage
     * @throws MqttError        on an unexpected packet
     * @throws MqttTimeoutError on a timeout
     */
    private function readPublish(?float $deadline): MqttMessage
    {
        while (true) {
            [$header, $payload] = $this->readPacket($deadline);
            if ($header === self::PINGRESP) {
                continue;
            }
            $message = $this->parsePublish($header, $payload);
            if ($message !== null) {
                return $message;
            }
            throw new MqttError(sprintf('expected PUBLISH, got 0x%02x', $header));
        }
    }

    /**
     * Park a PUBLISH that arrives while we wait for a PUBACK/SUBACK/PINGRESP
     * (normal when the same connection both publishes and subscribes) so receive()
     * still delivers it, in order, instead of it being mistaken for the ack.
     *
     * @param int    $header  the control byte
     * @param string $payload the packet body
     * @return bool true when the packet was a PUBLISH and got parked
     */
    private function stashPublish(int $header, string $payload): bool
    {
        $message = $this->parsePublish($header, $payload);
        if ($message === null) {
            return false;
        }
        $this->inbox[] = $message;
        return true;
    }

    /**
     * Parse a PUBLISH packet into an MqttMessage, or null if it is not a PUBLISH.
     *
     * @param int    $header  the control byte
     * @param string $payload the packet body
     * @return MqttMessage|null
     */
    private function parsePublish(int $header, string $payload): ?MqttMessage
    {
        if (($header & 0xF0) !== self::PUBLISH) {
            return null;
        }
        $qos = ($header & 0x06) >> 1;
        $topicLength = unpack('n', substr($payload, 0, 2))[1];
        $offset = 2 + $topicLength;
        $topic = substr($payload, 2, $topicLength);
        $packetId = null;
        // The packet identifier is present ONLY when QoS > 0.
        if ($qos > 0) {
            $packetId = unpack('n', substr($payload, $offset, 2))[1];
            $offset += 2;
        }

        return new MqttMessage(
            $topic,
            substr($payload, $offset),
            $qos,
            ($header & 0x01) === 0x01,
            ($header & 0x08) === 0x08,
            $packetId,
            $this
        );
    }

    /**
     * Wait for a specific acknowledgement, tolerating interleaved PUBLISH and
     * PINGRESP packets. A mismatched packet identifier is silent data loss if
     * ignored, so it raises.
     *
     * @param int      $expectedHeader the control byte we are waiting for
     * @param int|null $packetId       the packet id the ack must carry
     * @param string   $name           the packet name, for error messages
     * @return string the acknowledgement body
     * @throws MqttError        on the wrong packet or an id mismatch
     * @throws MqttTimeoutError on a timeout
     */
    private function waitForAcknowledgement(int $expectedHeader, ?int $packetId, string $name): string
    {
        $deadline = $this->deadlineIn($this->timeout);
        while (true) {
            [$header, $payload] = $this->readPacket($deadline);
            if ($header === self::PINGRESP) {
                continue;
            }
            if ($this->stashPublish($header, $payload)) {
                continue;
            }
            if ($header !== $expectedHeader) {
                throw new MqttError(sprintf('expected %s, got 0x%02x', $name, $header));
            }
            $receivedId = unpack('n', substr($payload, 0, 2))[1];
            if ($receivedId !== $packetId) {
                throw new MqttError(
                    "{$name} packet identifier mismatch: broker acknowledged {$receivedId} but we sent {$packetId}"
                );
            }
            return $payload;
        }
    }

    /**
     * Whether the connection has been quiet for at least $seconds.
     *
     * @param float $seconds the idle threshold
     * @return bool
     */
    private function idleFor(float $seconds): bool
    {
        return ($this->monotonic() - $this->lastWriteAt) >= $seconds;
    }

    /**
     * A monotonic deadline $seconds from now, or null when $seconds is null.
     *
     * @param float|null $seconds the timeout window
     * @return float|null
     */
    private function deadlineIn(?float $seconds): ?float
    {
        return $seconds !== null ? $this->monotonic() + $seconds : null;
    }

    /**
     * Monotonic clock in seconds (immune to wall-clock jumps).
     *
     * @return float
     */
    private function monotonic(): float
    {
        return hrtime(true) / 1e9;
    }

    /**
     * Close and forget the socket.
     *
     * @return void
     */
    private function closeSocket(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }
}
