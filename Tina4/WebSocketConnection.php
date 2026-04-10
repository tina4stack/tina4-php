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

    /** Client IP address */
    public readonly string $ip;

    /** HTTP headers from the upgrade request */
    public readonly array $headers;

    /** Route params extracted from path pattern */
    public readonly array $params;

    /** @var resource The underlying socket */
    private $socket;

    /** @var Server|null Reference to the server for broadcasting */
    private ?Server $server;

    /** @var WebSocket|null Reference to the WebSocket manager for rooms */
    private ?WebSocket $ws;

    /**
     * @param string      $id      Unique connection ID
     * @param string      $path    WebSocket route path
     * @param resource    $socket  Raw socket resource
     * @param Server|null $server  Server reference for broadcast
     * @param string      $ip      Client IP address
     * @param array       $headers HTTP headers from upgrade request
     * @param array       $params  Route params from path pattern
     * @param WebSocket|null $ws   WebSocket manager for room operations
     */
    public function __construct(
        string $id,
        string $path,
        $socket,
        ?Server $server = null,
        string $ip = '',
        array $headers = [],
        array $params = [],
        ?WebSocket $ws = null,
    ) {
        $this->id = $id;
        $this->path = $path;
        $this->socket = $socket;
        $this->server = $server;
        $this->ip = $ip;
        $this->headers = $headers;
        $this->params = $params;
        $this->ws = $ws;
    }

    /**
     * Send a message to this specific client.
     */
    public function send(string $message): void
    {
        $frame = WebSocket::buildFrame($message);
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
     * Join a room.
     */
    public function joinRoom(string $roomName): void
    {
        if ($this->ws !== null) {
            $this->ws->joinRoom($this->id, $roomName);
        }
    }

    /**
     * Leave a room.
     */
    public function leaveRoom(string $roomName): void
    {
        if ($this->ws !== null) {
            $this->ws->leaveRoom($this->id, $roomName);
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
        $frame = WebSocket::buildFrame($payload, WebSocket::OP_CLOSE);
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
