<?php

namespace Tina4;

/**
 * WebSocket Backplane Abstraction for Tina4 PHP.
 *
 * Enables broadcasting WebSocket messages across multiple server instances
 * using a shared pub/sub channel (e.g. Redis). Without a backplane configured,
 * broadcast() only reaches connections on the local process.
 *
 * Configuration via environment variables:
 *   TINA4_WS_BACKPLANE     — Backend type: "redis", "nats", or "" (default: none)
 *   TINA4_WS_BACKPLANE_URL — Connection string (default: tcp://127.0.0.1:6379)
 *
 * Usage:
 *   $backplane = WebSocketBackplaneFactory::create_backplane();
 *   if ($backplane) {
 *       $backplane->subscribe("chat", function ($msg) { relayToLocal($msg); });
 *       $backplane->publish("chat", '{"user":"A","text":"hello"}');
 *   }
 */

/**
 * Base backplane interface for scaling WebSocket broadcast across instances.
 *
 * Implementations relay messages over a shared bus so every server instance
 * receives every broadcast, not just the originator.
 */
interface WebSocketBackplaneInterface
{
    /**
     * Publish a message to all instances listening on $channel.
     */
    public function publish(string $channel, string $message): void;

    /**
     * Subscribe to $channel. $callback is invoked with (string $message)
     * for each incoming message.
     */
    public function subscribe(string $channel, callable $callback): void;

    /**
     * Stop listening on $channel.
     */
    public function unsubscribe(string $channel): void;

    /**
     * Non-blocking pump for single-threaded event loops.
     *
     * Tina4's PHP server is a single `stream_select()` loop, so a backplane
     * cannot block waiting for the next pub/sub message. Instead the server
     * calls poll() on every idle tick: the implementation drains whatever
     * messages are already buffered on its subscriber socket and dispatches
     * each to the registered callback, returning immediately if nothing is
     * pending. A backplane that has no non-blocking mode may no-op here.
     */
    public function poll(): void;

    /**
     * Return the underlying subscriber socket/stream resource so the server
     * can add it to its `stream_select()` read set, or null when the
     * implementation does not expose one (the server then time-slices poll()).
     *
     * @return resource|null
     */
    public function getSubscriberStream();

    /**
     * Tear down connections.
     */
    public function close(): void;
}

/**
 * Redis / Valkey pub/sub backplane.
 *
 * Uses the phpredis extension when it is loaded, otherwise falls back to the
 * raw RESP protocol over a TCP socket (zero-dependency) — the same dual-path
 * approach as {@see \Tina4\Cache\RedisBackend}. The backplane therefore works
 * out of the box against a real Redis/Valkey server with or without ext-redis
 * installed (parity with the Python master, which uses a real redis client).
 *
 * Construction performs a real connection (and, on the raw path, a real PING
 * handshake) so an unreachable server raises here — the manager catches that
 * and degrades to local-only, never crashing a broadcast.
 */
class RedisBackplane implements WebSocketBackplaneInterface
{
    /** phpredis publisher connection (ext-redis path only). */
    private ?\Redis $redis = null;
    /** phpredis subscriber connection (ext-redis path only). */
    private ?\Redis $subscriber = null;

    private string $host = '127.0.0.1';
    private int $port = 6379;

    /** True when running the zero-dependency raw RESP path. */
    private bool $useRaw = false;

    /** Persistent raw subscriber socket (raw path only). @var resource|null */
    private $rawSocket = null;
    /** Read buffer for partial RESP frames on the raw subscriber socket. */
    private string $rawBuffer = '';

    /** @var array<string, callable> channel => callback */
    private array $subscriptions = [];

    public function __construct(?string $url = null)
    {
        $url = $url ?? ($_ENV['TINA4_WS_BACKPLANE_URL']
            ?? getenv('TINA4_WS_BACKPLANE_URL')
            ?: 'tcp://127.0.0.1:6379');

        // parse_url needs a scheme; accept bare "host:port" too.
        $parsed = parse_url(str_contains($url, '://') ? $url : 'tcp://' . $url);
        $this->host = $parsed['host'] ?? '127.0.0.1';
        $this->port = (int)($parsed['port'] ?? 6379);

        // Prefer ext-redis when present (parity with the cache backend), else
        // speak raw RESP over a real socket so the backplane is never dead.
        if (extension_loaded('redis')) {
            $this->redis = new \Redis();
            $this->redis->connect($this->host, $this->port);
            $this->subscriber = new \Redis();
            $this->subscriber->connect($this->host, $this->port);
            return;
        }

        $this->useRaw = true;
        $this->openRawSubscriber();
    }

    /**
     * Open the persistent subscriber socket and verify the server answers a
     * PING. Raises on failure so the manager degrades to local-only.
     */
    private function openRawSubscriber(): void
    {
        $sock = @fsockopen($this->host, $this->port, $errno, $errstr, 5);
        if (!$sock) {
            throw new \RuntimeException(
                "RedisBackplane could not connect to {$this->host}:{$this->port}: {$errstr}"
            );
        }
        // Real handshake — a non-PONG means this is not a usable Redis/Valkey.
        fwrite($sock, "*1\r\n\$4\r\nPING\r\n");
        $pong = fread($sock, 64);
        if ($pong === false || !str_starts_with($pong, '+PONG')) {
            fclose($sock);
            throw new \RuntimeException(
                "RedisBackplane PING to {$this->host}:{$this->port} failed"
            );
        }
        // Non-blocking so poll() never stalls the single-threaded event loop.
        stream_set_blocking($sock, false);
        $this->rawSocket = $sock;
    }

    /** Encode a RESP array command (e.g. PUBLISH chan msg). */
    private static function respEncode(string ...$args): string
    {
        $cmd = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $cmd .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }
        return $cmd;
    }

    public function publish(string $channel, string $message): void
    {
        if (!$this->useRaw) {
            $this->redis->publish($channel, $message);
            return;
        }
        // Raw path: a short-lived publisher socket per publish keeps publishing
        // independent of the (non-blocking) subscriber socket's pub/sub mode.
        $sock = @fsockopen($this->host, $this->port, $errno, $errstr, 5);
        if (!$sock) {
            throw new \RuntimeException(
                "RedisBackplane publish could not connect to {$this->host}:{$this->port}: {$errstr}"
            );
        }
        stream_set_timeout($sock, 5);
        $written = @fwrite($sock, self::respEncode('PUBLISH', $channel, $message));
        // Consume the integer reply (number of subscribers that received it).
        @fread($sock, 64);
        @fclose($sock);
        if ($written === false) {
            throw new \RuntimeException('RedisBackplane publish write failed');
        }
    }

    /**
     * Register the callback and put the subscriber connection into pub/sub mode
     * without blocking.
     *
     * A blocking subscribe would freeze Tina4's single-threaded event loop, so
     * instead we issue SUBSCRIBE and drain replies non-blockingly in poll().
     */
    public function subscribe(string $channel, callable $callback): void
    {
        $this->subscriptions[$channel] = $callback;
        if (!$this->useRaw) {
            try {
                $this->subscriber->setOption(\Redis::OPT_READ_TIMEOUT, 0.0);
                $this->subscriber->rawCommand('SUBSCRIBE', $channel);
            } catch (\Throwable $e) {
                \Tina4\Log::warning('RedisBackplane subscribe degraded: ' . $e->getMessage());
            }
            return;
        }
        if (is_resource($this->rawSocket)) {
            @fwrite($this->rawSocket, self::respEncode('SUBSCRIBE', $channel));
        }
    }

    /**
     * Drain any pub/sub messages buffered on the subscriber socket and
     * dispatch each to its registered callback. Non-blocking.
     */
    public function poll(): void
    {
        if (empty($this->subscriptions)) {
            return;
        }
        if (!$this->useRaw) {
            // Cap the per-tick drain so a flood can't starve the HTTP loop.
            for ($i = 0; $i < 100; $i++) {
                try {
                    $reply = $this->subscriber->rawCommand('PING');
                } catch (\Throwable $e) {
                    return;
                }
                if (!is_array($reply) || ($reply[0] ?? null) !== 'message') {
                    return;
                }
                $chan = $reply[1] ?? null;
                $msg = $reply[2] ?? null;
                if ($chan !== null && isset($this->subscriptions[$chan]) && $msg !== null) {
                    ($this->subscriptions[$chan])($msg);
                }
            }
            return;
        }
        $this->pollRaw();
    }

    /**
     * Raw RESP path: append whatever is readable on the subscriber socket to
     * the buffer, then parse out every complete pub/sub reply array. A pub/sub
     * "message" reply is a 3-element array: ["message", channel, payload].
     */
    private function pollRaw(): void
    {
        if (!is_resource($this->rawSocket)) {
            return;
        }
        // Cap reads per tick so a flood can't starve the HTTP loop.
        for ($i = 0; $i < 100; $i++) {
            $chunk = @fread($this->rawSocket, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $this->rawBuffer .= $chunk;
            if (strlen($chunk) < 65536) {
                break;
            }
        }

        while (true) {
            $offset = 0;
            $reply = $this->parseResp($this->rawBuffer, $offset);
            if ($reply === self::INCOMPLETE) {
                break; // wait for more bytes next tick
            }
            // Consume the parsed bytes.
            $this->rawBuffer = substr($this->rawBuffer, $offset);

            if (is_array($reply)
                && count($reply) === 3
                && strtolower((string)($reply[0] ?? '')) === 'message'
            ) {
                $chan = $reply[1];
                $msg = $reply[2];
                if (isset($this->subscriptions[$chan]) && $msg !== null) {
                    ($this->subscriptions[$chan])($msg);
                }
            }
            // Other replies (subscribe confirmations, pongs) are ignored.
            if ($this->rawBuffer === '') {
                break;
            }
        }
    }

    /** Sentinel meaning "not enough bytes in the buffer to parse a full reply". */
    private const INCOMPLETE = "\0__tina4_resp_incomplete__\0";

    /**
     * Minimal RESP parser. Returns the decoded value and advances $offset past
     * the consumed bytes, or self::INCOMPLETE when the buffer holds a partial
     * frame. Supports the reply types pub/sub uses: arrays, bulk strings,
     * simple strings, integers, errors, and nulls.
     *
     * @return mixed|string self::INCOMPLETE when more bytes are needed
     */
    private function parseResp(string $buf, int &$offset)
    {
        if ($offset >= strlen($buf)) {
            return self::INCOMPLETE;
        }
        $type = $buf[$offset];
        $lineEnd = strpos($buf, "\r\n", $offset);
        if ($lineEnd === false) {
            return self::INCOMPLETE;
        }
        $line = substr($buf, $offset + 1, $lineEnd - ($offset + 1));
        $after = $lineEnd + 2;

        switch ($type) {
            case '+': // simple string
            case '-': // error
                $offset = $after;
                return $line;
            case ':': // integer
                $offset = $after;
                return (int)$line;
            case '$': // bulk string
                $len = (int)$line;
                if ($len < 0) {
                    $offset = $after;
                    return null;
                }
                if (strlen($buf) < $after + $len + 2) {
                    return self::INCOMPLETE;
                }
                $value = substr($buf, $after, $len);
                $offset = $after + $len + 2; // skip payload + trailing CRLF
                return $value;
            case '*': // array
                $count = (int)$line;
                if ($count < 0) {
                    $offset = $after;
                    return null;
                }
                $items = [];
                $cursor = $after;
                for ($i = 0; $i < $count; $i++) {
                    $element = $this->parseResp($buf, $cursor);
                    if ($element === self::INCOMPLETE) {
                        return self::INCOMPLETE;
                    }
                    $items[] = $element;
                }
                $offset = $cursor;
                return $items;
            default:
                // Unknown byte — skip the line to stay in sync.
                $offset = $after;
                return $line;
        }
    }

    public function getSubscriberStream()
    {
        // The raw path owns a real socket the server can add to its
        // stream_select() read set. The phpredis path does not expose one.
        return $this->useRaw ? $this->rawSocket : null;
    }

    public function unsubscribe(string $channel): void
    {
        unset($this->subscriptions[$channel]);
        if (!$this->useRaw) {
            try {
                $this->subscriber->rawCommand('UNSUBSCRIBE', $channel);
            } catch (\Throwable $e) {
                // best-effort
            }
            return;
        }
        if (is_resource($this->rawSocket)) {
            @fwrite($this->rawSocket, self::respEncode('UNSUBSCRIBE', $channel));
        }
    }

    public function close(): void
    {
        $this->subscriptions = [];
        if ($this->useRaw) {
            if (is_resource($this->rawSocket)) {
                @fclose($this->rawSocket);
            }
            $this->rawSocket = null;
            $this->rawBuffer = '';
            return;
        }
        $this->subscriber?->close();
        $this->redis?->close();
    }
}

/**
 * NATS pub/sub backplane.
 *
 * Requires the `basis-company/nats` Composer package. The class checks for
 * its presence at instantiation time so the rest of Tina4 works fine without
 * it — an exception is thrown only when this class is actually constructed.
 *
 * NATS connection URL defaults to nats://localhost:4222.
 *
 * The subscription listener is POLLED, not backgrounded: poll() calls the
 * client's process(0) and is driven from the server's idle tick, so incoming
 * messages are drained between requests on the one process Tina4 runs.
 *
 * This docblock used to claim "a background Fiber (PHP 8.1+)". There is no
 * Fiber here and never was - the framework constructs none anywhere. A PHP
 * Fiber would not buy anything either: it only switches at an explicit
 * Fiber::suspend(), so a blocking client call inside one blocks the thread
 * exactly as it does now. See the note in Server.php.
 */
class NATSBackplane implements WebSocketBackplaneInterface
{
    private mixed $client;
    private string $url;
    private array $subscriptions = [];

    public function __construct(?string $url = null)
    {
        if (!class_exists('\Basis\Nats\Client')) {
            throw new \RuntimeException(
                "The 'basis-company/nats' package is required for NATSBackplane. "
                . "Install it with: composer require basis-company/nats"
            );
        }

        $this->url = $url ?? ($_ENV['TINA4_WS_BACKPLANE_URL']
            ?? getenv('TINA4_WS_BACKPLANE_URL')
            ?: 'nats://localhost:4222');

        $parsed = parse_url($this->url);
        $host = $parsed['host'] ?? 'localhost';
        $port = $parsed['port'] ?? 4222;

        $configuration = new \Basis\Nats\Configuration([
            'host' => $host,
            'port' => $port,
        ]);

        if (!empty($parsed['user'])) {
            $configuration->setUser($parsed['user']);
        }
        if (!empty($parsed['pass'])) {
            $configuration->setPass($parsed['pass']);
        }

        $this->client = new \Basis\Nats\Client($configuration);
        $this->client->connect();
    }

    public function publish(string $channel, string $message): void
    {
        $this->client->publish($channel, $message);
    }

    public function subscribe(string $channel, callable $callback): void
    {
        $this->subscriptions[$channel] = $this->client->subscribe($channel, function ($msg) use ($callback) {
            $callback($msg->body);
        });
    }

    /**
     * Pump the NATS client once (non-blocking) so any queued messages are
     * delivered to their callbacks. Called from the server's idle tick.
     */
    public function poll(): void
    {
        if (empty($this->subscriptions)) {
            return;
        }
        try {
            $this->client->process(0);
        } catch (\Throwable $e) {
            // Never let a poll failure kill the event loop.
        }
    }

    public function getSubscriberStream()
    {
        // The basis-company/nats client manages its own socket internally and
        // does not expose it; the server time-slices poll() instead.
        return null;
    }

    public function unsubscribe(string $channel): void
    {
        if (isset($this->subscriptions[$channel])) {
            $this->subscriptions[$channel]->unsubscribe();
            unset($this->subscriptions[$channel]);
        }
    }

    public function close(): void
    {
        foreach ($this->subscriptions as $sub) {
            $sub->unsubscribe();
        }
        $this->subscriptions = [];
        $this->client->disconnect();
    }
}

/**
 * Factory that reads TINA4_WS_BACKPLANE and returns the appropriate
 * backplane instance, or null if no backplane is configured.
 *
 * This keeps backplane usage entirely optional — callers simply check
 * if ($backplane) before publishing.
 */
/**
 * Per-process backplane wiring shared by both WebSocket server surfaces
 * (the integrated {@see Server} and the standalone {@see WebSocket} class).
 *
 * The RedisBackplane / NATSBackplane / factory already existed but were never
 * wired into a broadcast — so a broadcast only ever reached the local process
 * and multi-instance scaling was dead. This manager closes that gap, mirroring
 * the Python-master `WebSocketManager` backplane semantics:
 *
 *   instance A: broadcast() → deliver locally → publish() → channel
 *                                                              │
 *   instance B: poll() on idle tick → onRaw() ────────────────┘
 *                 │ (origin guard drops A's own echoes by src id)
 *                 └→ relay to B's LOCAL connections only (never re-publishes)
 *
 * Cross-framework constants: the channel name ("tina4:ws") and the envelope
 * JSON shape ({src, kind, exclude, room, path, +text|b64}) are identical in
 * Python, PHP, Ruby, and Node so a cluster can mix runtimes.
 *
 * PHP is single-threaded (one stream_select loop), so unlike Python there is
 * no thread→loop bridge: relays are driven synchronously from poll() on the
 * server's idle tick. A backplane failure logs and degrades to local-only —
 * it must NEVER crash a broadcast.
 */
class WebSocketBackplaneManager
{
    /** Shared pub/sub channel — IDENTICAL across all four frameworks. */
    public const CHANNEL = 'tina4:ws';

    private ?WebSocketBackplaneInterface $backplane = null;
    private bool $started = false;

    /** Stable per-process id used by the origin guard to drop our own echoes. */
    public readonly string $instanceId;

    /**
     * @var callable Local delivery sink. Invoked as
     *               fn(string $kind, string|null $room, string|null $path,
     *                  string|null $exclude, string $message): void
     *               — delivers ONLY to local connections, never re-publishes.
     */
    private $relay;

    /**
     * @param callable $relay Local-only delivery callback (see $relay above).
     */
    public function __construct(callable $relay)
    {
        $this->relay = $relay;
        $this->instanceId = bin2hex(random_bytes(8));
    }

    /** True once a real backplane is wired (i.e. cluster fan-out is live). */
    public function isActive(): bool
    {
        return $this->backplane !== null;
    }

    /**
     * Lazily wire the configured backplane on first broadcast. Idempotent and
     * best-effort: a failure logs and leaves the manager local-only. Marks
     * started=true even on failure so there is no retry storm per broadcast.
     */
    public function ensure(): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;
        try {
            $backplane = WebSocketBackplaneFactory::create_backplane();
            if ($backplane === null) {
                return; // No backplane configured — stay local-only.
            }
            $this->backplane = $backplane;
            $this->backplane->subscribe(self::CHANNEL, [$this, 'onRaw']);
            \Tina4\Log::info(sprintf(
                'WebSocket backplane active (instance %s, channel \'%s\')',
                $this->instanceId,
                self::CHANNEL
            ));
        } catch (\Throwable $e) {
            $this->backplane = null;
            \Tina4\Log::error('WebSocket backplane wiring failed, continuing local-only: ' . $e->getMessage());
        }
    }

    /**
     * Drain pending cluster messages. Called from the server's idle tick — a
     * no-op when no backplane is wired.
     */
    public function poll(): void
    {
        if ($this->backplane === null) {
            return;
        }
        try {
            $this->backplane->poll();
        } catch (\Throwable $e) {
            \Tina4\Log::warning('WebSocket backplane poll failed: ' . $e->getMessage());
        }
    }

    /** @return resource|null subscriber stream for the server's select set */
    public function getSubscriberStream()
    {
        return $this->backplane?->getSubscriberStream();
    }

    /**
     * Publish a broadcast to sibling instances. No-op without a backplane.
     * Best-effort — a publish failure logs and is swallowed so the local
     * broadcast that already happened is never undone by a flaky message bus.
     *
     * JSON cannot carry bytes, so a string message is stored under "text" and a
     * binary message (detected as invalid UTF-8) under "b64" (base64).
     */
    public function publish(
        string $kind,
        string $message,
        ?string $room = null,
        ?string $path = null,
        ?string $exclude = null
    ): void {
        if ($this->backplane === null) {
            return;
        }
        $envelope = [
            'src' => $this->instanceId,
            'kind' => $kind,
            'exclude' => $exclude,
            'room' => $room,
            'path' => $path,
        ];
        if (self::isBinary($message)) {
            $envelope['b64'] = base64_encode($message);
        } else {
            $envelope['text'] = $message;
        }
        try {
            $this->backplane->publish(self::CHANNEL, json_encode($envelope));
        } catch (\Throwable $e) {
            \Tina4\Log::warning('WebSocket backplane publish failed: ' . $e->getMessage());
        }
    }

    /**
     * Receive a raw envelope from the backplane. Origin-guards our own echoes,
     * decodes, and relays to LOCAL connections only (via the injected sink).
     * Public so the backplane callback can target it; safe to call directly
     * from tests with a hand-crafted envelope.
     */
    public function onRaw(string $raw): void
    {
        $env = json_decode($raw, true);
        if (!is_array($env)) {
            return;
        }
        // Origin guard: ignore our own broadcasts echoed back over the channel.
        // We already delivered them locally; relaying again would double-send.
        if (($env['src'] ?? null) === $this->instanceId) {
            return;
        }
        $message = self::decodeEnvelopeMessage($env);
        if ($message === null) {
            return;
        }
        $kind = $env['kind'] ?? 'all';
        ($this->relay)(
            $kind,
            $env['room'] ?? null,
            $env['path'] ?? null,
            $env['exclude'] ?? null,
            $message
        );
    }

    /**
     * Reconstruct the original string/binary message from an envelope.
     * str → {"text": ...}, bytes → {"b64": base64(...)}.
     */
    private static function decodeEnvelopeMessage(array $env): ?string
    {
        if (array_key_exists('text', $env) && is_string($env['text'])) {
            return $env['text'];
        }
        if (array_key_exists('b64', $env) && is_string($env['b64'])) {
            $decoded = base64_decode($env['b64'], true);
            return $decoded === false ? null : $decoded;
        }
        return null;
    }

    /** True if $s is not valid UTF-8 (so it must travel as base64). */
    private static function isBinary(string $s): bool
    {
        return $s !== '' && !\Tina4\Str::isUtf8($s);
    }

    public function close(): void
    {
        if ($this->backplane !== null) {
            try {
                $this->backplane->close();
            } catch (\Throwable $e) {
                // best-effort teardown
            }
            $this->backplane = null;
        }
        $this->started = false;
    }
}

class WebSocketBackplaneFactory
{
    public static function create_backplane(?string $url = null): ?WebSocketBackplaneInterface
    {
        $backend = strtolower(trim(
            $_ENV['TINA4_WS_BACKPLANE']
            ?? getenv('TINA4_WS_BACKPLANE')
            ?: ''
        ));

        return match ($backend) {
            'redis' => new RedisBackplane($url),
            'nats' => new NATSBackplane($url),
            '' => null,
            default => throw new \InvalidArgumentException(
                "Unknown TINA4_WS_BACKPLANE value: '{$backend}'"
            ),
        };
    }
}
