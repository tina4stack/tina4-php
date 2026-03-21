<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Server — Custom non-blocking HTTP server with WebSocket support.
 * Replaces `php -S` for development. Uses stream_socket_server + stream_select
 * for concurrent connection handling. Zero external dependencies.
 *
 * Features:
 *   - HTTP/1.1 request parsing with keep-alive
 *   - WebSocket upgrade detection and RFC 6455 handshake
 *   - WebSocket frame encoding/decoding (text, ping/pong, close)
 *   - Routes HTTP requests through the Tina4 Router
 *   - Dev toolbar injection when TINA4_DEBUG=true
 *   - Serves static files via StaticFiles
 */

namespace Tina4;

class Server
{
    /** @var resource|null Server socket */
    private $socket = null;

    /** @var array<int, resource> All connected client sockets */
    private array $clients = [];

    /** @var array<int, string> Read buffers keyed by socket resource ID */
    private array $buffers = [];

    /** @var array<string, array{socket: resource, path: string, buffer: string, id: string}> WebSocket clients keyed by connection ID */
    private array $wsClients = [];

    /** @var array<int, string> Map socket resource ID => WebSocket connection ID */
    private array $wsSocketMap = [];

    /** @var bool Server running flag */
    private bool $running = false;

    /** @var string Host to bind to */
    private string $host;

    /** @var int Port to listen on */
    private int $port;

    /**
     * @param string $host Host to bind to
     * @param int    $port Port to listen on
     */
    public function __construct(string $host = '0.0.0.0', int $port = 7146)
    {
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * Start the server event loop.
     * Blocks indefinitely, handling HTTP and WebSocket connections.
     */
    public function start(): void
    {
        $context = stream_context_create(['socket' => ['backlog' => 128]]);
        $this->socket = @stream_socket_server(
            "tcp://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );

        if (!$this->socket) {
            throw new \RuntimeException(
                "Failed to start server on {$this->host}:{$this->port}: {$errstr} ({$errno})"
            );
        }

        stream_set_blocking($this->socket, false);
        $this->running = true;

        // Register signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            if (defined('SIGTERM')) {
                pcntl_signal(SIGTERM, function () {
                    $this->stop();
                });
            }
            if (defined('SIGINT')) {
                pcntl_signal(SIGINT, function () {
                    $this->stop();
                });
            }
        }

        while ($this->running) {
            // Dispatch pending signals
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $read = array_merge([$this->socket], $this->clients);
            $write = null;
            $except = null;

            $changed = @stream_select($read, $write, $except, 0, 200000);
            if ($changed === false) {
                break;
            }
            if ($changed === 0) {
                continue;
            }

            foreach ($read as $socket) {
                if ($socket === $this->socket) {
                    // Accept new connection
                    $client = @stream_socket_accept($this->socket, 0);
                    if ($client) {
                        stream_set_blocking($client, false);
                        $resourceId = (int)$client;
                        $this->clients[$resourceId] = $client;
                        $this->buffers[$resourceId] = '';
                    }
                } elseif ($this->isWebSocketClient($socket)) {
                    // WebSocket data
                    $this->handleWebSocketFrame($socket);
                } else {
                    // HTTP request data
                    $data = @fread($socket, 65536);
                    if ($data === '' || $data === false) {
                        $this->removeClient($socket);
                    } else {
                        $resourceId = (int)$socket;
                        $this->buffers[$resourceId] = ($this->buffers[$resourceId] ?? '') . $data;
                        $this->processHttpBuffer($socket);
                    }
                }
            }
        }

        $this->cleanup();
    }

    /**
     * Stop the server gracefully.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Check if a socket is a WebSocket client.
     */
    private function isWebSocketClient($socket): bool
    {
        $resourceId = (int)$socket;
        return isset($this->wsSocketMap[$resourceId]);
    }

    /**
     * Process the HTTP read buffer for a client.
     * Detects complete HTTP requests by looking for the header/body boundary.
     */
    private function processHttpBuffer($client): void
    {
        $resourceId = (int)$client;
        $buffer = $this->buffers[$resourceId] ?? '';

        // Check if we have a complete HTTP request (headers end with \r\n\r\n)
        $headerEnd = strpos($buffer, "\r\n\r\n");
        if ($headerEnd === false) {
            return; // Not enough data yet
        }

        $headerSection = substr($buffer, 0, $headerEnd);
        $bodyStart = $headerEnd + 4;

        // Check Content-Length for body
        $contentLength = 0;
        if (preg_match('/content-length:\s*(\d+)/i', $headerSection, $m)) {
            $contentLength = (int)$m[1];
        }

        $totalExpected = $bodyStart + $contentLength;
        if (strlen($buffer) < $totalExpected) {
            return; // Body not fully received yet
        }

        // Extract this request and keep any remaining data in buffer
        $rawRequest = substr($buffer, 0, $totalExpected);
        $this->buffers[$resourceId] = substr($buffer, $totalExpected);

        $this->handleHttp($client, $rawRequest);
    }

    /**
     * Handle an HTTP request. Detects WebSocket upgrade requests.
     *
     * @param resource $client     Client socket
     * @param string   $rawRequest Raw HTTP request data
     */
    private function handleHttp($client, string $rawRequest): void
    {
        $headers = WebSocket::parseHttpHeaders($rawRequest);
        $method = $headers['_method'] ?? 'GET';
        $rawPath = $headers['_path'] ?? '/';

        // Check for WebSocket upgrade
        if (strtolower($headers['upgrade'] ?? '') === 'websocket') {
            $this->handleWebSocketUpgrade($client, $headers);
            return;
        }

        // Parse path and query string
        $parts = explode('?', $rawPath, 2);
        $path = urldecode($parts[0]);
        $queryString = $parts[1] ?? '';

        // Parse query parameters
        $queryParams = [];
        if ($queryString !== '') {
            parse_str($queryString, $queryParams);
        }

        // Extract body
        $headerEnd = strpos($rawRequest, "\r\n\r\n");
        $body = $headerEnd !== false ? substr($rawRequest, $headerEnd + 4) : '';

        // Parse body content based on content type
        $contentType = $headers['content-type'] ?? '';
        $parsedBody = $body;
        if (str_contains($contentType, 'application/json') && $body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $parsedBody = $decoded;
            }
        } elseif (str_contains($contentType, 'application/x-www-form-urlencoded') && $body !== '') {
            $formData = [];
            parse_str($body, $formData);
            $parsedBody = $formData;
        }

        // Build Request object
        $request = new Request(
            method: strtoupper($method),
            path: $path,
            query: $queryParams,
            body: $parsedBody,
            headers: $this->buildHeaderArray($headers),
        );

        // Dispatch through Tina4 Router
        $response = Router::dispatch($request, new Response());

        // Build HTTP response
        $statusCode = $response->getStatusCode();
        $statusText = $this->getStatusText($statusCode);
        $responseBody = $response->getBody();
        $responseHeaders = $response->getHeaders();

        // Dev toolbar injection
        $isDev = DotEnv::isTruthy(DotEnv::getEnv('TINA4_DEBUG', 'false'));
        if ($isDev && str_contains($response->getContentType() ?? '', 'text/html')) {
            // Toolbar is already injected by Router::dispatch
        }

        // Set content length
        $responseHeaders['Content-Length'] = strlen($responseBody);

        // Connection handling
        $keepAlive = strtolower($headers['connection'] ?? '') === 'keep-alive';
        $responseHeaders['Connection'] = $keepAlive ? 'keep-alive' : 'close';

        // Build raw response
        $httpResponse = "HTTP/1.1 {$statusCode} {$statusText}\r\n";
        foreach ($responseHeaders as $name => $value) {
            $httpResponse .= "{$name}: {$value}\r\n";
        }
        $httpResponse .= "\r\n";
        $httpResponse .= $responseBody;

        @fwrite($client, $httpResponse);

        if (!$keepAlive) {
            $this->removeClient($client);
        }
    }

    /**
     * Perform WebSocket upgrade handshake.
     *
     * @param resource $client  Client socket
     * @param array    $headers Parsed HTTP headers
     */
    private function handleWebSocketUpgrade($client, array $headers): void
    {
        $wsKey = $headers['sec-websocket-key'] ?? null;
        if (!$wsKey) {
            $this->sendHttpError($client, 400, 'Bad Request: Missing Sec-WebSocket-Key');
            return;
        }

        $path = $headers['_path'] ?? '/';

        // Check if there is a registered WebSocket route
        $wsRoutes = Router::getWebSocketRoutes();
        $matchedHandler = null;
        foreach ($wsRoutes as $wsRoute) {
            if ($wsRoute['path'] === $path) {
                $matchedHandler = $wsRoute['handler'];
                break;
            }
        }

        if ($matchedHandler === null) {
            $this->sendHttpError($client, 404, 'No WebSocket handler registered for this path');
            return;
        }

        // Send handshake response
        $response = WebSocket::buildHandshakeResponse($wsKey);
        @fwrite($client, $response);

        // Register as WebSocket client
        $connectionId = bin2hex(random_bytes(8));
        $resourceId = (int)$client;

        $this->wsClients[$connectionId] = [
            'socket' => $client,
            'path' => $path,
            'buffer' => '',
            'id' => $connectionId,
            'handler' => $matchedHandler,
        ];
        $this->wsSocketMap[$resourceId] = $connectionId;

        // Create WebSocketConnection object
        $connection = new WebSocketConnection($connectionId, $path, $client, $this);

        // Fire the handler with 'open' message
        try {
            $matchedHandler($connection, null, 'open');
        } catch (\Throwable $e) {
            Log::error('WebSocket open handler error: ' . $e->getMessage());
        }
    }

    /**
     * Handle an incoming WebSocket frame from a client.
     *
     * @param resource $socket Client socket
     */
    private function handleWebSocketFrame($socket): void
    {
        $resourceId = (int)$socket;
        $connectionId = $this->wsSocketMap[$resourceId] ?? null;

        if ($connectionId === null || !isset($this->wsClients[$connectionId])) {
            $this->removeClient($socket);
            return;
        }

        $wsClient = &$this->wsClients[$connectionId];

        $data = @fread($socket, 65536);
        if ($data === false || $data === '') {
            $this->removeWebSocketClient($connectionId);
            return;
        }

        $wsClient['buffer'] .= $data;

        while (true) {
            $frame = WebSocket::decodeFrame($wsClient['buffer']);
            if ($frame === null) {
                break;
            }

            $wsClient['buffer'] = substr($wsClient['buffer'], $frame['length']);

            switch ($frame['opcode']) {
                case WebSocket::OP_TEXT:
                case WebSocket::OP_BINARY:
                    $connection = new WebSocketConnection(
                        $connectionId,
                        $wsClient['path'],
                        $socket,
                        $this
                    );
                    try {
                        ($wsClient['handler'])($connection, $frame['payload'], 'message');
                    } catch (\Throwable $e) {
                        Log::error('WebSocket message handler error: ' . $e->getMessage());
                    }
                    break;

                case WebSocket::OP_PING:
                    $pong = WebSocket::encodeFrame($frame['payload'], WebSocket::OP_PONG);
                    @fwrite($socket, $pong);
                    break;

                case WebSocket::OP_PONG:
                    // Ignore unsolicited pongs
                    break;

                case WebSocket::OP_CLOSE:
                    // Echo close frame back
                    $closeFrame = WebSocket::encodeFrame(
                        $frame['payload'],
                        WebSocket::OP_CLOSE
                    );
                    @fwrite($socket, $closeFrame);
                    $this->removeWebSocketClient($connectionId);
                    return;
            }
        }
    }

    /**
     * Broadcast a WebSocket message to all clients on a given path.
     *
     * @param string      $message   Message to send
     * @param string|null $path      Only broadcast to clients on this path (null = all)
     * @param string|null $excludeId Connection ID to exclude
     */
    public function broadcastWebSocket(string $message, ?string $path = null, ?string $excludeId = null): void
    {
        $frame = WebSocket::encodeFrame($message);
        foreach ($this->wsClients as $id => $client) {
            if ($excludeId !== null && $id === $excludeId) {
                continue;
            }
            if ($path !== null && $client['path'] !== $path) {
                continue;
            }
            @fwrite($client['socket'], $frame);
        }
    }

    /**
     * Remove a WebSocket client by connection ID.
     * Called by WebSocketConnection::close() and internally.
     */
    public function removeWebSocketClient(string $connectionId): void
    {
        if (!isset($this->wsClients[$connectionId])) {
            return;
        }

        $wsClient = $this->wsClients[$connectionId];
        $socket = $wsClient['socket'];
        $resourceId = (int)$socket;

        // Fire close event
        $connection = new WebSocketConnection(
            $connectionId,
            $wsClient['path'],
            $socket,
            $this
        );
        try {
            ($wsClient['handler'])($connection, null, 'close');
        } catch (\Throwable $e) {
            // Ignore errors in close handler
        }

        // Clean up
        unset($this->wsClients[$connectionId]);
        unset($this->wsSocketMap[$resourceId]);
        unset($this->clients[$resourceId]);
        unset($this->buffers[$resourceId]);
        @fclose($socket);
    }

    /**
     * Remove a regular HTTP client socket.
     */
    public function removeClient($socket): void
    {
        $resourceId = (int)$socket;

        // If it's a WebSocket client, use the WebSocket removal
        if (isset($this->wsSocketMap[$resourceId])) {
            $this->removeWebSocketClient($this->wsSocketMap[$resourceId]);
            return;
        }

        unset($this->clients[$resourceId]);
        unset($this->buffers[$resourceId]);
        @fclose($socket);
    }

    /**
     * Send a simple HTTP error response and close the connection.
     */
    private function sendHttpError($client, int $code, string $message): void
    {
        $statusText = $this->getStatusText($code);
        $body = json_encode(['error' => $message]);
        $response = "HTTP/1.1 {$code} {$statusText}\r\n"
            . "Content-Type: application/json\r\n"
            . "Content-Length: " . strlen($body) . "\r\n"
            . "Connection: close\r\n"
            . "\r\n"
            . $body;
        @fwrite($client, $response);
        $this->removeClient($client);
    }

    /**
     * Build a clean header array from parsed headers (exclude internal keys).
     */
    private function buildHeaderArray(array $headers): array
    {
        $clean = [];
        foreach ($headers as $key => $value) {
            if (!str_starts_with($key, '_')) {
                $clean[$key] = $value;
            }
        }
        return $clean;
    }

    /**
     * Get HTTP status text for a code.
     */
    private function getStatusText(int $code): string
    {
        return match ($code) {
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            304 => 'Not Modified',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            default => 'Unknown',
        };
    }

    /**
     * Clean up all connections and close the server socket.
     */
    private function cleanup(): void
    {
        // Close all WebSocket clients
        foreach (array_keys($this->wsClients) as $id) {
            $this->removeWebSocketClient($id);
        }

        // Close all HTTP clients
        foreach ($this->clients as $client) {
            @fclose($client);
        }
        $this->clients = [];
        $this->buffers = [];

        // Close server socket
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Get the number of active WebSocket connections.
     */
    public function getWebSocketClientCount(): int
    {
        return count($this->wsClients);
    }

    /**
     * Get information about connected WebSocket clients.
     *
     * @return array<int, array{id: string, path: string}>
     */
    public function getWebSocketClients(): array
    {
        $result = [];
        foreach ($this->wsClients as $client) {
            $result[] = [
                'id' => $client['id'],
                'path' => $client['path'],
            ];
        }
        return $result;
    }

    /**
     * Get the host this server is bound to.
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Get the port this server is listening on.
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * Check if the server is running.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }
}
