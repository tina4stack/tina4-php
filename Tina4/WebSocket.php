<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * WebSocket — Zero-dependency RFC 6455 WebSocket server.
 * Uses PHP stream_socket_server() with stream_select() for non-blocking I/O.
 * Matches the Python tina4_python.websocket implementation.
 */

namespace Tina4;

class WebSocket
{
    /** RFC 6455 magic GUID for handshake */
    public const MAGIC_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** WebSocket opcodes */
    public const OP_CONTINUATION = 0x0;
    public const OP_TEXT = 0x1;
    public const OP_BINARY = 0x2;
    public const OP_CLOSE = 0x8;
    public const OP_PING = 0x9;
    public const OP_PONG = 0xA;

    /** Close codes */
    public const CLOSE_NORMAL = 1000;
    public const CLOSE_GOING_AWAY = 1001;
    public const CLOSE_PROTOCOL_ERROR = 1002;

    private int $port;
    private string $host;
    private array $config;

    /** @var array<string, callable> Event handlers: open, message, close, error */
    private array $handlers = [];

    /** @var array<string, array> Connected clients: clientId => [socket, ip, connected_at, buffer] */
    private array $clients = [];

    /** @var resource|null Server socket */
    private $server = null;

    private bool $running = false;

    /**
     * @param int   $port   Port to listen on
     * @param array $config Configuration: host, maxConnections
     */
    public function __construct(int $port = 8080, array $config = [])
    {
        $envPort = getenv('TINA4_WS_PORT');
        $this->port = $envPort ? (int)$envPort : $port;
        $this->host = $config['host'] ?? '0.0.0.0';
        $this->config = $config;
    }

    /**
     * Register an event handler.
     *
     * @param string   $event   Event name: 'open', 'message', 'close', 'error'
     * @param callable $handler Handler function
     * @return self
     */
    public function on(string $event, callable $handler): self
    {
        $this->handlers[$event] = $handler;
        return $this;
    }

    /**
     * Broadcast a message to all connected clients on the same path.
     *
     * When a path is provided, only clients whose 'path' property matches
     * will receive the message. When path is null, sends to all clients
     * (backward compatible).
     *
     * @param string     $message    Message to send
     * @param array|null $excludeIds Client IDs to exclude
     * @param string|null $path      Only send to clients on this path
     */
    public function broadcast(string $message, ?array $excludeIds = null, ?string $path = null): void
    {
        $frame = self::encodeFrame($message);
        foreach ($this->clients as $id => $client) {
            if ($excludeIds && in_array($id, $excludeIds)) {
                continue;
            }
            if ($path !== null && ($client['path'] ?? '/') !== $path) {
                continue;
            }
            $this->writeToSocket($client['socket'], $frame);
        }
    }

    /**
     * Send a message to a specific client.
     *
     * @param string $clientId Client ID
     * @param string $message  Message to send
     */
    public function send(string $clientId, string $message): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }
        $frame = self::encodeFrame($message);
        $this->writeToSocket($this->clients[$clientId]['socket'], $frame);
    }

    /**
     * Start the WebSocket server (blocking).
     */
    public function start(): void
    {
        $address = "tcp://{$this->host}:{$this->port}";
        $this->server = stream_socket_server($address, $errno, $errstr);

        if (!$this->server) {
            throw new \RuntimeException("Failed to start WebSocket server: {$errstr} ({$errno})");
        }

        stream_set_blocking($this->server, false);
        $this->running = true;

        while ($this->running) {
            $read = [$this->server];
            foreach ($this->clients as $client) {
                $read[] = $client['socket'];
            }
            $write = null;
            $except = null;

            $changed = @stream_select($read, $write, $except, 0, 200000);
            if ($changed === false) {
                break;
            }
            if ($changed === 0) {
                continue;
            }

            // New connection
            if (in_array($this->server, $read)) {
                $newSocket = @stream_socket_accept($this->server, 0);
                if ($newSocket) {
                    $this->handleNewConnection($newSocket);
                }
                $key = array_search($this->server, $read);
                unset($read[$key]);
            }

            // Data from existing clients
            foreach ($read as $socket) {
                $clientId = $this->findClientId($socket);
                if ($clientId === null) {
                    continue;
                }
                $this->handleClientData($clientId);
            }
        }

        $this->cleanup();
    }

    /**
     * Get all connected client IDs and info.
     *
     * @return array
     */
    public function getClients(): array
    {
        $result = [];
        foreach ($this->clients as $id => $client) {
            $result[] = [
                'id' => $id,
                'ip' => $client['ip'],
                'connected_at' => $client['connected_at'],
            ];
        }
        return $result;
    }

    /**
     * Stop the server.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Get port number.
     */
    public function getPort(): int
    {
        return $this->port;
    }

    // ── WebSocket handshake ─────────────────────────────────────

    /**
     * Compute the Sec-WebSocket-Accept value per RFC 6455.
     *
     * @param string $key The Sec-WebSocket-Key header value
     * @return string
     */
    public static function computeAcceptKey(string $key): string
    {
        return base64_encode(sha1($key . self::MAGIC_GUID, true));
    }

    /**
     * Build the HTTP 101 Switching Protocols response.
     *
     * @param string $key Sec-WebSocket-Key
     * @return string
     */
    public static function buildHandshakeResponse(string $key): string
    {
        $accept = self::computeAcceptKey($key);
        return "HTTP/1.1 101 Switching Protocols\r\n"
             . "Upgrade: websocket\r\n"
             . "Connection: Upgrade\r\n"
             . "Sec-WebSocket-Accept: {$accept}\r\n"
             . "\r\n";
    }

    /**
     * Parse HTTP headers from raw request data.
     *
     * @param string $data Raw HTTP request
     * @return array Headers (lowercase keys)
     */
    public static function parseHttpHeaders(string $data): array
    {
        $lines = explode("\r\n", $data);
        $headers = [];

        if (!empty($lines[0])) {
            $parts = explode(' ', $lines[0]);
            if (count($parts) >= 2) {
                $headers['_method'] = $parts[0];
                $headers['_path'] = $parts[1];
            }
        }

        for ($i = 1; $i < count($lines); $i++) {
            $pos = strpos($lines[$i], ':');
            if ($pos !== false) {
                $key = strtolower(trim(substr($lines[$i], 0, $pos)));
                $val = trim(substr($lines[$i], $pos + 1));
                $headers[$key] = $val;
            }
        }

        return $headers;
    }

    // ── Frame encoding/decoding ─────────────────────────────────

    /**
     * Encode a text message into a WebSocket frame (server-to-client, unmasked).
     *
     * @param string $message Message text
     * @param int    $opcode  Opcode (default: OP_TEXT)
     * @return string Binary frame
     */
    public static function encodeFrame(string $message, int $opcode = self::OP_TEXT): string
    {
        $frame = '';
        $length = strlen($message);

        // FIN bit + opcode
        $frame .= chr(0x80 | $opcode);

        // Payload length (server frames are never masked)
        if ($length < 126) {
            $frame .= chr($length);
        } elseif ($length < 65536) {
            $frame .= chr(126) . pack('n', $length);
        } else {
            $frame .= chr(127) . pack('J', $length);
        }

        $frame .= $message;
        return $frame;
    }

    /**
     * Decode a WebSocket frame. Handles masking from client frames.
     *
     * @param string $data Raw frame data
     * @return array|null [fin, opcode, payload] or null on insufficient data
     */
    public static function decodeFrame(string $data): ?array
    {
        $len = strlen($data);
        if ($len < 2) {
            return null;
        }

        $firstByte = ord($data[0]);
        $secondByte = ord($data[1]);

        $fin = ($firstByte >> 7) & 1;
        $opcode = $firstByte & 0x0F;
        $masked = ($secondByte >> 7) & 1;
        $payloadLen = $secondByte & 0x7F;

        $offset = 2;

        if ($payloadLen === 126) {
            if ($len < 4) {
                return null;
            }
            $payloadLen = unpack('n', substr($data, 2, 2))[1];
            $offset = 4;
        } elseif ($payloadLen === 127) {
            if ($len < 10) {
                return null;
            }
            $payloadLen = unpack('J', substr($data, 2, 8))[1];
            $offset = 10;
        }

        $maskKey = null;
        if ($masked) {
            if ($len < $offset + 4) {
                return null;
            }
            $maskKey = substr($data, $offset, 4);
            $offset += 4;
        }

        if ($len < $offset + $payloadLen) {
            return null;
        }

        $payload = substr($data, $offset, $payloadLen);

        if ($maskKey !== null) {
            $unmasked = '';
            for ($i = 0; $i < $payloadLen; $i++) {
                $unmasked .= chr(ord($payload[$i]) ^ ord($maskKey[$i % 4]));
            }
            $payload = $unmasked;
        }

        return [
            'fin' => (bool)$fin,
            'opcode' => $opcode,
            'payload' => $payload,
            'length' => $offset + $payloadLen,
        ];
    }

    // ── Internal helpers ────────────────────────────────────────

    /**
     * Handle a new incoming connection and perform the WebSocket handshake.
     */
    private function handleNewConnection($socket): void
    {
        $data = @fread($socket, 4096);
        if (empty($data)) {
            @fclose($socket);
            return;
        }

        $headers = self::parseHttpHeaders($data);

        // Validate upgrade request
        if (strtolower($headers['upgrade'] ?? '') !== 'websocket') {
            @fwrite($socket, "HTTP/1.1 400 Bad Request\r\n\r\n");
            @fclose($socket);
            return;
        }

        $wsKey = $headers['sec-websocket-key'] ?? null;
        if (!$wsKey) {
            @fwrite($socket, "HTTP/1.1 400 Bad Request\r\n\r\n");
            @fclose($socket);
            return;
        }

        // Send handshake response
        $response = self::buildHandshakeResponse($wsKey);
        @fwrite($socket, $response);

        stream_set_blocking($socket, false);

        $clientId = bin2hex(random_bytes(4));
        $peerName = stream_socket_get_name($socket, true);

        $this->clients[$clientId] = [
            'socket' => $socket,
            'ip' => $peerName ?: 'unknown',
            'connected_at' => time(),
            'buffer' => '',
            'path' => $headers['_path'] ?? '/',
        ];

        // Fire open event
        if (isset($this->handlers['open'])) {
            try {
                ($this->handlers['open'])($clientId);
            } catch (\Throwable $e) {
                $this->fireError($clientId, $e);
            }
        }
    }

    /**
     * Handle incoming data from a client.
     */
    private function handleClientData(string $clientId): void
    {
        $client = &$this->clients[$clientId];
        $data = @fread($client['socket'], 65536);

        if ($data === false || $data === '') {
            $this->disconnectClient($clientId);
            return;
        }

        $client['buffer'] .= $data;

        while (true) {
            $frame = self::decodeFrame($client['buffer']);
            if ($frame === null) {
                break;
            }

            $client['buffer'] = substr($client['buffer'], $frame['length']);

            switch ($frame['opcode']) {
                case self::OP_TEXT:
                case self::OP_BINARY:
                    if (isset($this->handlers['message'])) {
                        try {
                            ($this->handlers['message'])($clientId, $frame['payload']);
                        } catch (\Throwable $e) {
                            $this->fireError($clientId, $e);
                        }
                    }
                    break;

                case self::OP_PING:
                    $pong = self::encodeFrame($frame['payload'], self::OP_PONG);
                    $this->writeToSocket($client['socket'], $pong);
                    break;

                case self::OP_PONG:
                    // Ignore
                    break;

                case self::OP_CLOSE:
                    $this->disconnectClient($clientId);
                    return;
            }
        }
    }

    /**
     * Disconnect a client and fire the close event.
     */
    private function disconnectClient(string $clientId): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }

        $socket = $this->clients[$clientId]['socket'];
        @fclose($socket);
        unset($this->clients[$clientId]);

        if (isset($this->handlers['close'])) {
            try {
                ($this->handlers['close'])($clientId);
            } catch (\Throwable $e) {
                // Ignore errors in close handler
            }
        }
    }

    /**
     * Fire the error event handler.
     */
    private function fireError(string $clientId, \Throwable $e): void
    {
        if (isset($this->handlers['error'])) {
            ($this->handlers['error'])($clientId, $e);
        }
    }

    /**
     * Find a client ID by socket resource.
     */
    private function findClientId($socket): ?string
    {
        foreach ($this->clients as $id => $client) {
            if ($client['socket'] === $socket) {
                return $id;
            }
        }
        return null;
    }

    /**
     * Write data to a socket, suppressing errors.
     */
    private function writeToSocket($socket, string $data): void
    {
        @fwrite($socket, $data);
    }

    /**
     * Clean up all connections and close the server.
     */
    private function cleanup(): void
    {
        foreach (array_keys($this->clients) as $id) {
            $this->disconnectClient($id);
        }
        if ($this->server) {
            @fclose($this->server);
            $this->server = null;
        }
    }
}
