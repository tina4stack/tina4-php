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
 *   $backplane = WebSocketBackplaneFactory::create();
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

    public function subscribe(string $channel, callable $callback): void
    {
        $this->subscriptions[$channel] = $callback;
        // Note: In production this runs in a loop or separate process.
        // The subscriber connection enters subscribe mode which blocks,
        // so this is typically called in a dedicated worker/fiber.
        $this->subscriber->subscribe([$channel], function ($redis, $chan, $msg) {
            if (isset($this->subscriptions[$chan])) {
                ($this->subscriptions[$chan])($msg);
            }
        });
    }

    public function unsubscribe(string $channel): void
    {
        unset($this->subscriptions[$channel]);
        $this->subscriber->unsubscribe([$channel]);
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

        // Process incoming messages in background
        $this->client->process();
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
class WebSocketBackplaneFactory
{
    public static function create(?string $url = null): ?WebSocketBackplaneInterface
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
