<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * WebSocketConnection — Represents a single WebSocket client connection.
 * Passed to Router::websocket() handlers as the first argument.
 */

namespace Tina4;

class WebSocketConnection
{
    /** Unique connection identifier */
    public readonly string $id;

    /** The WebSocket path this connection is on */
    public readonly string $path;

    /** @var resource The underlying socket */
    private $socket;

    /** @var Server|null Reference to the server for broadcasting */
    private ?Server $server;

    /**
     * @param string   $id     Unique connection ID
     * @param string   $path   WebSocket route path
     * @param resource $socket Raw socket resource
     * @param Server|null $server Server reference for broadcast
     */
    public function __construct(string $id, string $path, $socket, ?Server $server = null)
    {
        $this->id = $id;
        $this->path = $path;
        $this->socket = $socket;
        $this->server = $server;
    }

    /**
     * Send a message to this specific client.
     */
    public function send(string $message): void
    {
        $frame = WebSocket::encodeFrame($message);
        @fwrite($this->socket, $frame);
    }

    /**
     * Broadcast a message to all clients on the same WebSocket path.
     * Excludes the current connection by default.
     *
     * @param string $message    Message to broadcast
     * @param bool   $includeSelf Whether to include this connection in the broadcast
     */
    public function broadcast(string $message, bool $includeSelf = false): void
    {
        if ($this->server !== null) {
            $exclude = $includeSelf ? null : $this->id;
            $this->server->broadcastWebSocket($message, $this->path, $exclude);
        }
    }

    /**
     * Close this WebSocket connection.
     *
     * @param int    $code   Close status code (default: 1000 Normal)
     * @param string $reason Close reason
     */
    public function close(int $code = WebSocket::CLOSE_NORMAL, string $reason = ''): void
    {
        $payload = pack('n', $code) . $reason;
        $frame = WebSocket::encodeFrame($payload, WebSocket::OP_CLOSE);
        @fwrite($this->socket, $frame);

        if ($this->server !== null) {
            $this->server->removeWebSocketClient($this->id);
        }
    }

    /**
     * Get the underlying socket resource.
     *
     * @return resource
     */
    public function getSocket()
    {
        return $this->socket;
    }
}
