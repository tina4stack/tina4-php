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

    /** @var resource|null AI port server socket (port+1, debug mode only) */
    private $aiSocket = null;

    /** @var array<int, true> Socket resource IDs that connected via the AI port */
    private array $aiPortConnections = [];

    /** @var int AI port number (port+1 when active, 0 otherwise) */
    private int $aiPort = 0;

    /** @var bool Server running flag */
    private bool $running = false;

    /** @var bool Cached debug mode flag (avoid parsing env on every request) */
    private bool $isDebug = false;

    /** @var bool Cached no-reload flag — disables file watcher and WebSocket live reload */
    private bool $noReload = false;

    /** @var string Host to bind to */
    private string $host;

    /** @var int Port to listen on */
    private int $port;

    // ── Hot reload ──────────────────────────────────────────────
    /** @var array<string, int> Tracked file modification times */
    private array $fileMtimes = [];

    /** @var float Last time we scanned files for changes */
    private float $lastFileCheck = 0;

    /** @var float Seconds between file change scans */
    private float $fileCheckInterval = 1.0;

    /** @var string[] WebSocket connection IDs subscribed to reload events */
    private array $reloadSubscribers = [];

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
        $context = stream_context_create([
            'socket' => [
                'backlog' => 1024,
                'so_reuseport' => true,
                'tcp_nodelay' => true,
            ],
        ]);
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
        $this->isDebug = DotEnv::isTruthy(DotEnv::getEnv('TINA4_DEBUG', 'false'));
        $this->noReload = DotEnv::isTruthy(DotEnv::getEnv('TINA4_NO_RELOAD', 'false'));

        // AI dual-port: open port+1 when TINA4_DEBUG=true and TINA4_NO_AI_PORT is not set
        $noAiPort = DotEnv::isTruthy(DotEnv::getEnv('TINA4_NO_AI_PORT', 'false'));
        if ($this->isDebug && !$noAiPort) {
            $this->aiPort = $this->port + 1000;
            try {
                $ctx = stream_context_create([
                    'socket' => [
                        'backlog' => 128,
                        'so_reuseport' => true,
                        'tcp_nodelay' => true,
                    ],
                ]);
                $this->aiSocket = @stream_socket_server(
                    "tcp://{$this->host}:{$this->aiPort}",
                    $errno,
                    $errstr,
                    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
                    $ctx
                );
                if ($this->aiSocket) {
                    stream_set_blocking($this->aiSocket, false);
                    echo "  Test Port: http://localhost:{$this->aiPort} (stable — no hot-reload)\n";
                } else {
                    echo "  Test Port: SKIPPED (port {$this->aiPort} in use)\n";
                }
            } catch (\Throwable $e) {
                echo "  Test Port: SKIPPED ({$e->getMessage()})\n";
            }
        }

        // Register built-in hot reload WebSocket endpoint (dev mode only, unless TINA4_NO_RELOAD=true)
        if ($this->isDebug && !$this->noReload) {
            Router::websocket('/__dev_reload', function ($connection, $data, $event) {
                // No-op handler — clients just connect to receive reload signals
            });
            // Build initial file map
            $this->detectFileChanges();
        }

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
            if ($this->aiSocket !== null) {
                $read[] = $this->aiSocket;
            }
            $write = null;
            $except = null;

            // 1ms timeout when idle — low latency without CPU spin
            $changed = @stream_select($read, $write, $except, 0, 1000);
            if ($changed === false) {
                break;
            }
            if ($changed === 0) {
                // Hot reload: check for file changes on idle ticks (skipped when TINA4_NO_RELOAD=true)
                if ($this->isDebug && !$this->noReload) {
                    $now = microtime(true);
                    if ($now - $this->lastFileCheck >= $this->fileCheckInterval) {
                        $this->lastFileCheck = $now;
                        if ($this->detectFileChanges()) {
                            $this->onFilesChanged();
                        }
                    }
                }
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
                } elseif ($socket === $this->aiSocket) {
                    // Accept new AI port connection
                    $client = @stream_socket_accept($this->aiSocket, 0);
                    if ($client) {
                        stream_set_blocking($client, false);
                        $resourceId = (int)$client;
                        $this->clients[$resourceId] = $client;
                        $this->buffers[$resourceId] = '';
                        $this->aiPortConnections[$resourceId] = true;
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
     * Check if a socket connected via the AI port.
     */
    private function isAiPortConnection($socket): bool
    {
        return isset($this->aiPortConnections[(int)$socket]);
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
            // Block /__dev_reload on the AI port — no live reload for AI clients
            $upgradePath = $headers['_path'] ?? '/';
            if ($this->isAiPortConnection($client) && $upgradePath === '/__dev_reload') {
                $this->sendHttpError($client, 404, 'No WebSocket handler registered for this path');
                return;
            }
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

        // Suppress hot-reload script for AI port connections
        if ($this->isAiPortConnection($client)) {
            DevAdmin::$suppressReload = true;
        }

        // Dispatch through Tina4 Router
        $response = Router::dispatch($request, new Response());

        // Restore reload suppression flag
        DevAdmin::$suppressReload = false;

        // Build HTTP response
        $statusCode = $response->getStatusCode();
        $statusText = $this->getStatusText($statusCode);
        $responseBody = $response->getBody();
        $responseHeaders = $response->getHeaders();

        // Dev toolbar injection (uses cached flag — no env parsing per request)
        if ($this->isDebug && str_contains($response->getContentType() ?? '', 'text/html')) {
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
        unset($this->aiPortConnections[$resourceId]);
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
        unset($this->aiPortConnections[$resourceId]);
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

        // Close AI port socket
        if ($this->aiSocket) {
            @fclose($this->aiSocket);
            $this->aiSocket = null;
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

    // ── Hot Reload ─────────────────────────────────────────────────

    /**
     * Scan watched directories for file changes.
     * Compares filemtime() against stored map. Returns true if anything changed.
     */
    private function detectFileChanges(): bool
    {
        $dirs = ['src', 'migrations'];
        $extensions = ['php', 'twig', 'html', 'scss', 'css', 'js', 'json'];
        $changed = false;

        // Also watch .env
        $envFile = '.env';
        if (file_exists($envFile)) {
            $mtime = filemtime($envFile);
            if (isset($this->fileMtimes[$envFile]) && $this->fileMtimes[$envFile] !== $mtime) {
                $changed = true;
                Log::info("Hot reload: .env changed");
                // Reload env vars
                DotEnv::loadEnv($envFile);
            }
            $this->fileMtimes[$envFile] = $mtime;
        }

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $ext = strtolower($file->getExtension());
                if (!in_array($ext, $extensions, true)) {
                    continue;
                }
                $path = $file->getPathname();
                $mtime = $file->getMTime();
                if (isset($this->fileMtimes[$path]) && $this->fileMtimes[$path] !== $mtime) {
                    $changed = true;
                    Log::info("Hot reload: {$path} changed");
                }
                $this->fileMtimes[$path] = $mtime;
            }
        }

        return $changed;
    }

    /**
     * Called when file changes are detected.
     * Re-discovers routes and notifies browser clients to reload.
     */
    private function onFilesChanged(): void
    {
        // Re-discover routes from src/routes/
        Router::clear();
        $routeDir = 'src/routes';
        if (is_dir($routeDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($routeDir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    try {
                        include_once $file->getPathname();
                    } catch (\Throwable $e) {
                        Log::error("Hot reload error: " . $e->getMessage());
                    }
                }
            }
        }

        // Notify all reload subscribers via WebSocket
        $this->broadcastReload();
    }

    /**
     * Send a reload signal to all connected dev clients via WebSocket.
     */
    private function broadcastReload(): void
    {
        $message = json_encode(['type' => 'reload', 'timestamp' => time()]);
        $this->broadcastWebSocket($message, '/__dev_reload');
        Log::info("Hot reload: browser refresh sent to " . count($this->reloadSubscribers) . " client(s)");
    }

    /**
     * Register a WebSocket client as a reload subscriber.
     * Called when a client connects to /__dev_reload.
     */
    public function addReloadSubscriber(string $connectionId): void
    {
        $this->reloadSubscribers[$connectionId] = true;
    }

    /**
     * Remove a reload subscriber.
     */
    public function removeReloadSubscriber(string $connectionId): void
    {
        unset($this->reloadSubscribers[$connectionId]);
    }
}
