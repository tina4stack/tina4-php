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
 * Redis pub/sub backplane.
 *
 * Requires the phpredis extension or a userland Redis client. The class
 * checks for the \Redis class at instantiation time so the rest of Tina4
 * works fine without it — an exception is thrown only when this class is
 * actually constructed without the extension available.
 */
class RedisBackplane implements WebSocketBackplaneInterface
{
    private \Redis $redis;
    private \Redis $subscriber;
    private string $url;
    private array $subscriptions = [];

    public function __construct(?string $url = null)
    {
        if (!class_exists('\Redis')) {
            throw new \RuntimeException(
                "The phpredis extension is required for RedisBackplane. "
                . "Install it with: pecl install redis"
            );
        }

        $this->url = $url ?? ($_ENV['TINA4_WS_BACKPLANE_URL']
            ?? getenv('TINA4_WS_BACKPLANE_URL')
            ?: 'tcp://127.0.0.1:6379');

        $parsed = parse_url($this->url);
        $host = $parsed['host'] ?? '127.0.0.1';
        $port = $parsed['port'] ?? 6379;

        $this->redis = new \Redis();
        $this->redis->connect($host, $port);

        // Separate connection for subscriptions (Redis requirement)
        $this->subscriber = new \Redis();
        $this->subscriber->connect($host, $port);
    }

    public function publish(string $channel, string $message): void
    {
        $this->redis->publish($channel, $message);
    }

    /**
     * Register the callback and put the subscriber connection into pub/sub mode
     * without blocking.
     *
     * phpredis' blocking ->subscribe() would freeze Tina4's single-threaded
     * event loop, so instead we send the raw SUBSCRIBE command on the
     * subscriber socket and let poll() drain replies non-blockingly each tick.
     * Falls back to a plain registration (poll()-driven) when the low-level
     * socket isn't reachable.
     */
    public function subscribe(string $channel, callable $callback): void
    {
        $this->subscriptions[$channel] = $callback;
        try {
            // Non-blocking SUBSCRIBE: write the RESP command, keep the socket
            // in pub/sub mode, and read replies in poll().
            $this->subscriber->setOption(\Redis::OPT_READ_TIMEOUT, 0.0);
            $this->subscriber->rawCommand('SUBSCRIBE', $channel);
        } catch (\Throwable $e) {
            // Some phpredis builds don't expose rawCommand on a fresh socket;
            // poll() will still attempt to consume. Never let wiring crash.
            \Tina4\Log::warning('RedisBackplane subscribe degraded: ' . $e->getMessage());
        }
    }

    /**
     * Drain any pub/sub messages buffered on the subscriber socket and
     * dispatch each to its registered callback. Non-blocking: returns as soon
     * as no more complete messages are pending.
     */
    public function poll(): void
    {
        if (empty($this->subscriptions)) {
            return;
        }
        // Cap the per-tick drain so a flood can't starve the HTTP loop.
        for ($i = 0; $i < 100; $i++) {
            try {
                $reply = $this->subscriber->rawCommand('PING'); // keepalive + reply pump
            } catch (\Throwable $e) {
                return;
            }
            // phpredis surfaces buffered pub/sub replies through the read
            // socket; when nothing is pending we stop. Implementations that
            // can't poll this way simply no-op (handled by the catch above).
            if (!is_array($reply) || ($reply[0] ?? null) !== 'message') {
                return;
            }
            $chan = $reply[1] ?? null;
            $msg = $reply[2] ?? null;
            if ($chan !== null && isset($this->subscriptions[$chan]) && $msg !== null) {
                ($this->subscriptions[$chan])($msg);
            }
        }
    }

    public function getSubscriberStream()
    {
        // phpredis does not expose the underlying socket resource, so the
        // server time-slices poll() on idle ticks instead of select()ing it.
        return null;
    }

    public function unsubscribe(string $channel): void
    {
        unset($this->subscriptions[$channel]);
        try {
            $this->subscriber->rawCommand('UNSUBSCRIBE', $channel);
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    public function close(): void
    {
        $this->subscriptions = [];
        $this->subscriber->close();
        $this->redis->close();
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
 * Uses a background Fiber (PHP 8.1+) for the subscription listener.
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
        return $s !== '' && !mb_check_encoding($s, 'UTF-8');
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
