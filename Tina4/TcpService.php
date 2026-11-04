<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Tina4;

/**
 * A named raw TCP listener and its connection registry (ADR-0102, feature 142).
 *
 * In PHP there is no async loop of its own: the service folds into Server's
 * single stream_select accept loop, which calls readResources()/handleReadable()
 * /onIdle() each pass. The standalone pump() drives the same handler methods for
 * tests and non-Server callers, so there is exactly one code path.
 */
final class TcpService
{
    private int $port;
    private string $name;
    /** @var array<string, callable> */
    private array $callbacks;
    private float $idleTimeout;
    private ?int $maxConnections;
    private int $maxBacklog;

    /** @var array<int, TcpConnection> resourceId => connection */
    private array $connections = [];
    /** @var array<string, TcpConnection> appId => connection */
    private array $byId = [];
    /** @var array<int, resource> resourceId => listen socket */
    private array $listenSockets = [];
    private bool $owner = false;
    private ?int $boundPort = null;
    private float $lastReaperSweep = 0.0;

    public function __construct(int $port, array $options = [])
    {
        $this->port = $port;
        $this->name = (string)($options['name'] ?? 'default');
        $this->callbacks = [];
        foreach (['on_connect', 'on_data', 'on_close', 'on_line'] as $event) {
            if (isset($options[$event]) && is_callable($options[$event])) {
                $this->callbacks[$event] = $options[$event];
            }
        }
        // Default OFF: tracker sockets are deliberately long-lived and mostly
        // idle between location packets, so a default reaper would kill healthy
        // devices.
        $this->idleTimeout = (float)($options['idle_timeout'] ?? 0);
        $this->maxConnections = isset($options['max_connections'])
            ? (int)$options['max_connections'] : null;
        $this->maxBacklog = self::resolveMaxBacklog($options['max_backlog'] ?? null);
    }

    /** A stalled-reader ceiling in bytes (explicit option wins over the env). */
    private static function resolveMaxBacklog($explicit): int
    {
        $default = 1 << 20; // 1 MiB
        if ($explicit !== null) {
            $value = (int)$explicit;
            return $value > 0 ? $value : $default;
        }
        $raw = trim((string)(DotEnv::getEnv('TINA4_TCP_MAX_BACKLOG', '') ?? ''));
        if ($raw === '') {
            return $default;
        }
        $value = (int)$raw;
        return $value > 0 ? $value : $default;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function maxBacklog(): int
    {
        return $this->maxBacklog;
    }

    public function callback(string $event): ?callable
    {
        return $this->callbacks[$event] ?? null;
    }

    public function isOwner(): bool
    {
        return $this->owner;
    }

    public function boundPort(): int
    {
        return $this->boundPort ?? $this->port;
    }

    public function count(): int
    {
        return count($this->connections);
    }

    // ── Decorator-style setters (parity with the Python @svc.on_* handlers) ──

    public function onConnect(callable $fn): self
    {
        $this->callbacks['on_connect'] = $fn;
        return $this;
    }

    public function onData(callable $fn): self
    {
        $this->callbacks['on_data'] = $fn;
        return $this;
    }

    public function onClose(callable $fn): self
    {
        $this->callbacks['on_close'] = $fn;
        return $this;
    }

    public function onLine(callable $fn): self
    {
        $this->callbacks['on_line'] = $fn;
        return $this;
    }

    // ── Registry (D4 — server -> device push) ────────────────────────────────

    public function registerId(string $appId, TcpConnection $conn): void
    {
        $this->byId[$appId] = $conn; // supersede: the live reconnection wins
    }

    public function connection(string|int $appId): ?TcpConnection
    {
        $conn = $this->byId[(string)$appId] ?? null;
        if ($conn === null || $conn->isClosed()) {
            return null;
        }
        return $conn;
    }

    /**
     * Write bytes to exactly the connection identified by $appId. A send to an
     * unknown or closed id is a NO-OP that returns false — never an exception —
     * so an HTTP route addressing an offline device does not 500.
     */
    public function sendTo(string|int $appId, string $data): bool
    {
        $conn = $this->connection($appId);
        if ($conn === null) {
            return false;
        }
        return $conn->send($data);
    }

    /** Write bytes to every live connection. Returns how many received it. */
    public function broadcast(string $data): int
    {
        $delivered = 0;
        foreach ($this->connections as $conn) {
            if ($conn->send($data)) {
                $delivered++;
            }
        }
        return $delivered;
    }

    // ── Lifecycle ────────────────────────────────────────────────────────────

    /**
     * Bind the listener on $host:port. SO_REUSEPORT is OFF, so under a
     * multi-worker deployment exactly ONE worker wins the bind; a worker that
     * finds the port already bound skips silently (single-owner, D1).
     *
     * @return bool True when THIS call bound the port (this worker is the owner).
     */
    public function start(string $host = '0.0.0.0'): bool
    {
        if ($this->listenSockets !== []) {
            return $this->owner;
        }
        $context = stream_context_create([
            'socket' => ['backlog' => 1024, 'tcp_nodelay' => true],
        ]);
        $socket = @stream_socket_server(
            "tcp://{$host}:{$this->port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );
        if (!$socket) {
            // Already bound by another worker (or the family is unavailable):
            // single-owner, skip without raising.
            $this->owner = false;
            Log::info(sprintf(
                "tcp_service '%s' port %d is already bound by another worker — "
                . "single-owner, this worker will not bind (%s)",
                $this->name,
                $this->port,
                $errstr
            ));
            return false;
        }
        stream_set_blocking($socket, false);
        $this->listenSockets[(int)$socket] = $socket;
        $this->owner = true;
        $localName = @stream_socket_get_name($socket, false);
        $this->boundPort = $localName ? (int)self::portOf($localName) : $this->port;
        Log::info("tcp_service '{$this->name}' listening on {$host}:{$this->boundPort}");
        return true;
    }

    private static function portOf(string $name): string
    {
        $pos = strrpos($name, ':');
        return $pos === false ? '0' : substr($name, $pos + 1);
    }

    /** @return array<int, resource> Listen + client resources for the read set. */
    public function readResources(): array
    {
        $resources = $this->listenSockets;
        foreach ($this->connections as $id => $conn) {
            $socket = $conn->socket();
            if (is_resource($socket)) {
                $resources[$id] = $socket;
            }
        }
        return $resources;
    }

    /** True when $socket is one of this service's listen or client sockets. */
    public function ownsSocket($socket): bool
    {
        $id = (int)$socket;
        return isset($this->listenSockets[$id]) || isset($this->connections[$id]);
    }

    /** Dispatch a readable socket: accept on a listener, read on a client. */
    public function handleReadable($socket): void
    {
        if (!is_resource($socket)) {
            return;
        }
        if (isset($this->listenSockets[(int)$socket])) {
            $this->acceptOn($socket);
        } else {
            $this->readClient($socket);
        }
    }

    private function acceptOn($listenSocket): void
    {
        $client = @stream_socket_accept($listenSocket, 0, $peerName);
        if (!$client) {
            return;
        }
        // Refuse past the limit BEFORE the connection joins the registry.
        if ($this->maxConnections !== null && count($this->connections) >= $this->maxConnections) {
            @fclose($client);
            return;
        }
        stream_set_blocking($client, false);
        $conn = new TcpConnection($client, $this, (string)$peerName);
        $this->connections[(int)$client] = $conn;
        $onConnect = $this->callbacks['on_connect'] ?? null;
        if ($onConnect !== null) {
            try {
                $onConnect($conn);
            } catch (\Throwable $e) {
                Log::error("tcp_service '{$this->name}' on_connect error: " . $e->getMessage());
            }
        }
    }

    private function readClient($socket): void
    {
        $conn = $this->connections[(int)$socket] ?? null;
        if ($conn === null) {
            return;
        }
        $data = @fread($socket, 65536);
        if ($data === '' || $data === false) {
            // Peer closed (or a read error) — fire on_close once and remove.
            if ($data === false || feof($socket)) {
                $this->closeConnection($conn);
            }
            return;
        }
        $conn->touch();

        $onData = $this->callbacks['on_data'] ?? null;
        if ($onData !== null) {
            // Raw bytes, exactly as received — never newline-split, never
            // decoded. The app frames the stream itself.
            try {
                $onData($conn, $data);
            } catch (\Throwable $e) {
                Log::error("tcp_service '{$this->name}' on_data error: " . $e->getMessage());
            }
        }

        $onLine = $this->callbacks['on_line'] ?? null;
        if ($onLine !== null) {
            $buffer = &$conn->lineBuffer();
            $buffer .= $data;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line = rtrim($line, "\r");
                try {
                    $onLine($conn, $line);
                } catch (\Throwable $e) {
                    Log::error("tcp_service '{$this->name}' on_line error: " . $e->getMessage());
                }
            }
        }
    }

    /** Close a connection the server initiated (reap, drain, explicit). */
    private function closeConnection(TcpConnection $conn): void
    {
        $socket = $conn->socket();
        if (is_resource($socket)) {
            @fclose($socket);
        }
        $this->remove($conn);
    }

    /**
     * Remove a connection from the registry and fire on_close exactly once.
     * Called by TcpConnection::close() and by the server's read/drain paths.
     */
    public function remove(TcpConnection $conn): void
    {
        $socket = $conn->socket();
        $id = is_resource($socket) ? (int)$socket : null;
        $wasTracked = false;
        if ($id !== null && isset($this->connections[$id])) {
            unset($this->connections[$id]);
            $wasTracked = true;
        } else {
            // Socket already closed — find and drop it by object identity.
            foreach ($this->connections as $key => $tracked) {
                if ($tracked === $conn) {
                    unset($this->connections[$key]);
                    $wasTracked = true;
                    break;
                }
            }
        }
        if ($conn->id !== null && ($this->byId[$conn->id] ?? null) === $conn) {
            // Only remove the id when THIS connection still owns it; a newer
            // connection that superseded it keeps the id.
            unset($this->byId[$conn->id]);
        }
        if ($wasTracked) {
            $conn->fireClose();
        }
    }

    /** Idle work per loop tick: flush pending writes, reap idle connections. */
    public function onIdle(): void
    {
        foreach ($this->connections as $conn) {
            $conn->flush();
        }
        $this->reapIdle();
    }

    public function reapIdle(): int
    {
        if ($this->idleTimeout <= 0) {
            return 0;
        }
        $now = microtime(true);
        // Sweep often enough that a silent connection is closed within about one
        // timeout window of going quiet (sub-second timeouts need a fast sweep).
        $interval = max(0.05, min(1.0, $this->idleTimeout / 2.0));
        if (($now - $this->lastReaperSweep) < $interval) {
            return 0;
        }
        $this->lastReaperSweep = $now;
        $stale = [];
        foreach ($this->connections as $conn) {
            if (($now - $conn->lastActivity()) >= $this->idleTimeout) {
                $stale[] = $conn;
            }
        }
        foreach ($stale as $conn) {
            $this->closeConnection($conn);
        }
        return count($stale);
    }

    /** Stop accepting new connections (close listen sockets). Live conns stay. */
    public function stopAccepting(): void
    {
        foreach ($this->listenSockets as $id => $socket) {
            if (is_resource($socket)) {
                @fclose($socket);
            }
            unset($this->listenSockets[$id]);
        }
    }

    /** Close every live connection (firing on_close) and release the listener. */
    public function drain(): void
    {
        foreach ($this->connections as $conn) {
            $conn->flush();            // best-effort final write
            $this->closeConnection($conn);
        }
        $this->stopAccepting();
    }

    public function stop(): void
    {
        $this->stopAccepting();
        $this->drain();
    }

    // ── Standalone pump (tests / non-Server callers) ──────────────────────────

    /**
     * Run ONE real stream_select pass over this service's own sockets and
     * dispatch — the same handler methods the Server loop drives, so there is
     * no second code path. Used by standalone callers and the test suite.
     */
    public function pump(float $seconds = 0.05): void
    {
        $read = array_values($this->readResources());
        if ($read !== []) {
            $write = null;
            $except = null;
            $usec = (int)round(($seconds - floor($seconds)) * 1_000_000);
            $changed = @stream_select($read, $write, $except, (int)floor($seconds), $usec);
            if ($changed) {
                foreach ($read as $socket) {
                    $this->handleReadable($socket);
                }
            }
        } else {
            $usec = (int)round($seconds * 1_000_000);
            if ($usec > 0) {
                usleep($usec);
            }
        }
        $this->onIdle();
    }
}
