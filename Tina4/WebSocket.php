<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
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
    public const CLOSE_POLICY_VIOLATION = 1008;

    private int $port;
    private string $host;
    private array $config;

    /** @var array<string, callable> Event handlers: open, message, close, error */
    private array $handlers = [];

    /** @var array<string, array> Route-style handlers keyed by path */
    private array $_handlers = [];

    /**
     * @var array<string, array> Connected clients keyed by clientId. Each entry:
     *   socket, ip, connected_at, buffer, path, rooms, lastActivity,
     *   fragments (string), fragmentOpcode (int)
     */
    private array $clients = [];

    /** @var array<string, array<string>> Rooms: roomName => [clientId, ...] */
    private array $rooms = [];

    /** @var resource|null Server socket */
    private $server = null;

    private bool $running = false;

    /** @var WebSocketBackplaneManager|null Lazily wired on first broadcast. */
    private ?WebSocketBackplaneManager $backplane = null;

    /** @var float Last idle-reaper sweep timestamp. */
    private float $lastReaperSweep = 0.0;

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
     * Register a route-style WebSocket handler for a path.
     *
     * Returns a callable that accepts the user's handler function.
     * The handler is called with ($conn) on open; it should set
     * $conn->onMessage and $conn->onClose callbacks internally.
     *
     * Matches Python's WebSocketServer.route(path) decorator pattern.
     *
     * A WebSocket route is PUBLIC by default. Pass $secure = true (or annotate
     * the handler with an `@secured` docblock) to require a valid JWT on the
     * upgrade — mirrors Python's @secured() on a WS handler.
     *
     * @param string        $path    WebSocket path to handle
     * @param callable|null $handler Optional handler; when provided, registered directly.
     *                               When null, returns a closure accepting the handler (decorator style).
     * @param bool          $secure  Force-secure the route imperatively (default false)
     * @return callable|self Returns $this when handler is provided, else the decorator closure.
     */
    public function route(string $path, ?callable $handler = null, bool $secure = false): callable|self
    {
        $register = function (callable $handler) use ($path, $secure) {
            // Resolve the secure flag from the docblock too, so the standalone
            // route table mirrors the Router's auth_required for this path.
            $routeSecure = $secure;
            if (!$routeSecure) {
                try {
                    $ref = new \ReflectionFunction($handler);
                    $doc = $ref->getDocComment();
                    if ($doc !== false && preg_match('/@secured\b/i', $doc)) {
                        $routeSecure = true;
                    }
                } catch (\Throwable) {
                    // Not a closure or reflection failed — leave public.
                }
            }
            $this->_handlers[$path] = ['handler' => $handler, 'secure' => $routeSecure, 'auth_required' => $routeSecure];

            // Adapter: converts decorator-style to Router's (conn, data, event) style
            $adapter = function ($conn, $data, $event) use ($handler) {
                if ($event === 'open') {
                    $handler($conn);
                } elseif ($event === 'message') {
                    if ($conn->onMessage !== null) {
                        ($conn->onMessage)($data);
                    }
                } elseif ($event === 'close') {
                    if ($conn->onClose !== null) {
                        ($conn->onClose)();
                    }
                }
            };

            Router::websocket($path, $adapter, $routeSecure);
            return $handler;
        };

        if ($handler !== null) {
            $register($handler);
            return $this;
        }

        return $register;
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
        $this->ensureBackplane();
        // Deliver to LOCAL connections first (resilient — a dead client never
        // aborts delivery to the rest), then fan out to sibling instances.
        $this->deliverLocal($message, $excludeIds, $path, null);
        $exclude = (is_array($excludeIds) && count($excludeIds) === 1) ? $excludeIds[0] : null;
        if ($this->backplane !== null) {
            $this->backplane->publish($path !== null ? 'path' : 'all', $message, null, $path, $exclude);
        }
    }

    /**
     * Deliver a message to LOCAL connections only, resiliently. A failed write
     * detects + prunes the dead client and delivery continues to the rest.
     * Never publishes to the backplane (callers decide that).
     *
     * @param string[]|null $excludeIds Client IDs to skip
     * @param string|null   $path       Only deliver to clients on this path (null = all)
     * @param string|null   $room       Only deliver to members of this room (null = ignore)
     */
    private function deliverLocal(string $message, ?array $excludeIds, ?string $path, ?string $room): void
    {
        $frame = self::buildFrame($message);
        $targets = $room !== null ? ($this->rooms[$room] ?? []) : array_keys($this->clients);
        $dead = [];
        foreach ($targets as $id) {
            if (!isset($this->clients[$id])) {
                continue;
            }
            if ($excludeIds && in_array($id, $excludeIds, true)) {
                continue;
            }
            $client = $this->clients[$id];
            if ($path !== null && ($client['path'] ?? '/') !== $path) {
                continue;
            }
            if (!$this->safeWrite($client['socket'], $frame)) {
                $dead[] = $id;
            }
        }
        foreach ($dead as $id) {
            $this->disconnectClient($id);
        }
    }

    /**
     * Relay sink handed to the backplane manager: deliver a remote-originated
     * message to LOCAL connections only (never re-publishes — that would loop
     * the cluster). Dispatches by kind: room / path / all.
     */
    private function relayLocal(string $kind, ?string $room, ?string $path, ?string $exclude, string $message): void
    {
        $excludeIds = $exclude !== null ? [$exclude] : null;
        if ($kind === 'room') {
            if ($room !== null) {
                $this->deliverLocal($message, $excludeIds, null, $room);
            }
        } elseif ($kind === 'path') {
            if ($path !== null) {
                $this->deliverLocal($message, $excludeIds, $path, null);
            }
        } else { // 'all' (and anything unknown)
            $this->deliverLocal($message, $excludeIds, null, null);
        }
    }

    /** Lazily wire the backplane (idempotent, best-effort) on first broadcast. */
    private function ensureBackplane(): void
    {
        if ($this->backplane === null) {
            $this->backplane = new WebSocketBackplaneManager(
                fn(string $kind, ?string $room, ?string $path, ?string $exclude, string $message)
                    => $this->relayLocal($kind, $room, $path, $exclude, $message)
            );
        }
        $this->backplane->ensure();
    }

    /**
     * Write to a socket, returning false if the write failed (so the caller can
     * prune a dead/slow client). A partial/false fwrite means the peer is gone.
     */
    private function safeWrite($socket, string $data): bool
    {
        if (!is_resource($socket)) {
            return false;
        }
        $written = @fwrite($socket, $data);
        return $written !== false;
    }

    /**
     * Send a message to a specific client.
     *
     * @param string $clientId Client ID
     * @param string $message  Message to send
     */
    public function sendTo(string $clientId, string $message): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }
        $frame = self::buildFrame($message);
        // Resilient: a dead target is pruned, never silently left lingering.
        if (!$this->safeWrite($this->clients[$clientId]['socket'], $frame)) {
            $this->disconnectClient($clientId);
        }
    }

    // ── Rooms ──────────────────────────────────────────────────

    /**
     * Add a client to a named room.
     *
     * @param string $clientId Client ID
     * @param string $roomName Room name
     */
    public function joinRoom(string $clientId, string $roomName): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }
        if (!isset($this->rooms[$roomName])) {
            $this->rooms[$roomName] = [];
        }
        if (!in_array($clientId, $this->rooms[$roomName], true)) {
            $this->rooms[$roomName][] = $clientId;
            $this->clients[$clientId]['rooms'][] = $roomName;
        }
    }

    /**
     * Remove a client from a named room.
     *
     * @param string $clientId Client ID
     * @param string $roomName Room name
     */
    public function leaveRoom(string $clientId, string $roomName): void
    {
        if (isset($this->rooms[$roomName])) {
            $this->rooms[$roomName] = array_values(
                array_filter($this->rooms[$roomName], fn($id) => $id !== $clientId)
            );
            if (empty($this->rooms[$roomName])) {
                unset($this->rooms[$roomName]);
            }
        }
        if (isset($this->clients[$clientId])) {
            $this->clients[$clientId]['rooms'] = array_values(
                array_filter($this->clients[$clientId]['rooms'], fn($r) => $r !== $roomName)
            );
        }
    }

    /**
     * Return the list of client IDs in a room.
     *
     * @param string $roomName Room name
     * @return string[]
     */
    public function getRoomConnections(string $roomName): array
    {
        return $this->rooms[$roomName] ?? [];
    }

    /**
     * Return the number of clients in a room.
     *
     * @param string $roomName Room name
     * @return int
     */
    public function roomCount(string $roomName): int
    {
        return count($this->rooms[$roomName] ?? []);
    }

    /**
     * Return all room names a specific client belongs to.
     * Matches Python's conn.rooms property.
     *
     * @param string $clientId Client connection ID
     * @return string[]
     */
    public function getClientRooms(string $clientId): array
    {
        $result = [];
        foreach ($this->rooms as $roomName => $members) {
            if (in_array($clientId, $members, true)) {
                $result[] = $roomName;
            }
        }
        return $result;
    }

    /**
     * Broadcast a message to all clients in a room.
     *
     * @param string     $roomName   Room name
     * @param string     $message    Message to send
     * @param array|null $excludeIds Client IDs to exclude
     */
    public function broadcastToRoom(string $roomName, string $message, ?array $excludeIds = null): void
    {
        $this->ensureBackplane();
        // Local delivery first (resilient), then fan out — a room can span
        // instances, so each one delivers to its own members.
        $this->deliverLocal($message, $excludeIds, null, $roomName);
        $exclude = (is_array($excludeIds) && count($excludeIds) === 1) ? $excludeIds[0] : null;
        if ($this->backplane !== null) {
            $this->backplane->publish('room', $message, $roomName, null, $exclude);
        }
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

        // Wire the backplane up front so a clustered deployment relays inbound
        // sibling broadcasts even before this instance does its own broadcast.
        $this->ensureBackplane();

        while ($this->running) {
            $read = [$this->server];
            foreach ($this->clients as $client) {
                $read[] = $client['socket'];
            }
            $write = null;
            $except = null;

            $changed = @stream_select($read, $write, $except, 0, 200000);
            if ($changed === false) {
                // EINTR from signal handler — retry rather than tear down
                // the WS server. Same pattern as Server.php; killing the
                // loop on signal interrupt drops every connected client.
                if (\function_exists('pcntl_signal_dispatch')) {
                    \pcntl_signal_dispatch();
                }
                continue;
            }
            // Drain any cluster messages + reap idle connections on every
            // iteration (single-threaded: never block the select loop on the
            // backplane — poll() returns immediately when nothing is pending).
            $this->backplane?->poll();
            $this->reapIdle();
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
        $this->backplane?->close();
    }

    /**
     * Close connections whose last inbound frame is older than
     * TINA4_WS_IDLE_TIMEOUT seconds. Opt-in and non-breaking: 0/unset disables
     * the reaper entirely (current behaviour). Sweeps at most every second so a
     * busy loop doesn't re-scan every tick.
     *
     * @return int Number of connections reaped.
     */
    public function reapIdle(): int
    {
        $timeout = (float)(
            $_ENV['TINA4_WS_IDLE_TIMEOUT']
            ?? getenv('TINA4_WS_IDLE_TIMEOUT')
            ?: 0
        );
        if ($timeout <= 0) {
            return 0;
        }
        $now = microtime(true);
        // Throttle sweeps; reapIdle() runs on every loop iteration.
        if (($now - $this->lastReaperSweep) < 1.0) {
            return 0;
        }
        $this->lastReaperSweep = $now;

        $stale = [];
        foreach ($this->clients as $id => $client) {
            $last = $client['lastActivity'] ?? $client['connected_at'] ?? $now;
            if (($now - $last) > $timeout) {
                $stale[] = $id;
            }
        }
        foreach ($stale as $id) {
            $this->close($id, self::CLOSE_GOING_AWAY, 'idle timeout');
        }
        if (!empty($stale)) {
            Log::info('WebSocket idle reaper closed ' . count($stale) . ' connection(s)');
        }
        return count($stale);
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
     * Close a specific client connection with an optional code and reason.
     *
     * @param string $clientId Client ID to close
     * @param int    $code     WebSocket close code (default 1000)
     * @param string $reason   Human-readable close reason
     */
    public function close(string $clientId, int $code = 1000, string $reason = ''): void
    {
        if (!isset($this->clients[$clientId])) {
            return;
        }
        $payload = pack('n', $code) . $reason;
        $frame = self::buildFrame($payload, self::OP_CLOSE);
        $this->writeToSocket($this->clients[$clientId]['socket'], $frame);
        $this->disconnectClient($clientId);
    }

    /**
     * Return true if the upgrade request's Origin is permitted.
     *
     * Controlled by TINA4_WS_ALLOWED_ORIGINS — a comma-separated list of exact
     * origins (e.g. "https://app.example.com,https://admin.example.com").
     *
     * Empty/unset = allow ALL origins (current behaviour, non-breaking). When
     * the list is set, only requests whose Origin header exactly matches a
     * listed value are allowed, and a missing Origin header is rejected. The
     * header lookup is case-insensitive on the key (parseHttpHeaders lowercases
     * keys, but accept either form for callers that pass raw headers).
     *
     * @param array $headers Parsed HTTP headers
     */
    public static function originAllowed(array $headers): bool
    {
        $raw = trim((string)(
            $_ENV['TINA4_WS_ALLOWED_ORIGINS']
            ?? getenv('TINA4_WS_ALLOWED_ORIGINS')
            ?: ''
        ));
        if ($raw === '') {
            return true; // No allow-list configured — permit everything.
        }
        $allowed = array_filter(array_map('trim', explode(',', $raw)), fn($o) => $o !== '');
        if (empty($allowed)) {
            return true;
        }
        $origin = $headers['origin'] ?? $headers['Origin'] ?? null;
        return $origin !== null && in_array($origin, $allowed, true);
    }

    /**
     * Extract a bearer token from a WS upgrade handshake.
     *
     * Order (mirrors Python's ws_token()):
     *   1. The `Authorization: Bearer <jwt>` header — server/CLI/mobile clients.
     *   2. The `Sec-WebSocket-Protocol` subprotocol in the form
     *      `"bearer, <jwt>"` — the ONLY way a browser can pass a token, since
     *      `new WebSocket()` cannot set headers.
     *   3. A `?token=<jwt>` query param (parsed from the request path).
     *
     * Header keys arrive lowercased from parseHttpHeaders() but accept either
     * form for callers that pass raw headers.
     *
     * @param array       $headers     Parsed HTTP headers (lowercase keys)
     * @param string      $queryString Raw query string (the part after '?')
     * @param string|null $subprotocol Explicit Sec-WebSocket-Protocol value
     * @return string|null The token, or null when none was supplied.
     */
    public static function wsToken(array $headers, string $queryString = '', ?string $subprotocol = null): ?string
    {
        $auth = (string)($headers['authorization'] ?? $headers['Authorization'] ?? '');
        if (strtolower(substr($auth, 0, 7)) === 'bearer ') {
            $token = trim(substr($auth, 7));
            return $token !== '' ? $token : null;
        }

        $proto = $subprotocol
            ?? $headers['sec-websocket-protocol']
            ?? $headers['Sec-WebSocket-Protocol']
            ?? '';
        $parts = array_values(array_filter(array_map('trim', explode(',', (string)$proto)), fn($p) => $p !== ''));
        if (count($parts) >= 2 && strtolower($parts[0]) === 'bearer') {
            return $parts[1] !== '' ? $parts[1] : null;
        }

        if ($queryString !== '') {
            parse_str($queryString, $query);
            $token = $query['token'] ?? null;
            if (is_string($token) && $token !== '') {
                return $token;
            }
        }

        return null;
    }

    /**
     * Per-route WebSocket authentication, checked on the upgrade.
     *
     * A route is secured when `$route['auth_required']` (or `$route['secure']`)
     * is truthy — set by an `@secured` docblock on the WS handler or by an
     * imperative `Router::websocket($path, $handler, secure: true)`. Public
     * routes (the default) always pass, so this is non-breaking.
     *
     * A secured route needs a valid JWT via the Authorization header, the
     * `bearer` subprotocol, or `?token=`. Validation goes through the same
     * `Auth::validToken()` the HTTP routes use.
     *
     * Mirrors Python's ws_authorized() -> (payload, ok).
     *
     * @param array       $route       Matched WS route (carries auth_required/secure)
     * @param array       $headers     Parsed HTTP headers
     * @param string      $queryString Raw query string
     * @param string|null $subprotocol Explicit Sec-WebSocket-Protocol value
     * @return array{0: array<string,mixed>|null, 1: bool} [payload, ok]
     */
    public static function wsAuthorized(array $route, array $headers, string $queryString = '', ?string $subprotocol = null): array
    {
        $authRequired = $route['auth_required'] ?? $route['secure'] ?? false;
        if (!$authRequired) {
            return [null, true];
        }
        $token = self::wsToken($headers, $queryString, $subprotocol);
        if ($token === null) {
            return [null, false];
        }
        $payload = Auth::validToken($token);
        return [$payload, $payload !== null];
    }

    /**
     * Split a request path into [path, queryString]. The query string is the
     * portion after the first '?'. Mirrors the Python upgrade path, which reads
     * the query from headers['_path'].
     *
     * @return array{0: string, 1: string} [path, queryString]
     */
    public static function splitPathQuery(string $rawPath): array
    {
        $pos = strpos($rawPath, '?');
        if ($pos === false) {
            return [$rawPath, ''];
        }
        return [substr($rawPath, 0, $pos), substr($rawPath, $pos + 1)];
    }

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
     * When the client offered the `bearer` subprotocol (browser token transport)
     * pass $subprotocol='bearer' to echo it back as the accepted subprotocol via
     * a `Sec-WebSocket-Protocol: bearer` response header — mirrors Python/ASGI
     * accepting the subprotocol. Without it, omitting the header is correct.
     *
     * @param string      $key         Sec-WebSocket-Key
     * @param string|null $subprotocol Accepted subprotocol to echo (e.g. 'bearer')
     * @return string
     */
    public static function buildHandshakeResponse(string $key, ?string $subprotocol = null): string
    {
        $accept = self::computeAcceptKey($key);
        $response = "HTTP/1.1 101 Switching Protocols\r\n"
             . "Upgrade: websocket\r\n"
             . "Connection: Upgrade\r\n"
             . "Sec-WebSocket-Accept: {$accept}\r\n";
        if ($subprotocol !== null && $subprotocol !== '') {
            $response .= "Sec-WebSocket-Protocol: {$subprotocol}\r\n";
        }
        return $response . "\r\n";
    }

    /**
     * Return 'bearer' when the client offered the `bearer` subprotocol so the
     * handshake can echo it back, else null. The browser token transport is
     * `new WebSocket(url, ['bearer', token])`, which sends
     * `Sec-WebSocket-Protocol: bearer, <token>`.
     *
     * @param array       $headers     Parsed HTTP headers (lowercase keys)
     * @param string|null $subprotocol Explicit Sec-WebSocket-Protocol value
     */
    public static function acceptedSubprotocol(array $headers, ?string $subprotocol = null): ?string
    {
        $proto = $subprotocol
            ?? $headers['sec-websocket-protocol']
            ?? $headers['Sec-WebSocket-Protocol']
            ?? '';
        $parts = array_values(array_filter(array_map('trim', explode(',', (string)$proto)), fn($p) => $p !== ''));
        if (!empty($parts) && strtolower($parts[0]) === 'bearer') {
            return 'bearer';
        }
        return null;
    }

    /**
     * Parse HTTP headers from raw request data.
     *
     * @param string $data Raw HTTP request
     * @return array Headers (lowercase keys)
     */
    public static function parseHttpHeaders(string $data): array
    {
        // Stop at the blank line separating headers from body (RFC 9112 §2.2).
        // Without this guard, multipart body-part headers (e.g. a part's own
        // `Content-Type: application/pdf`) would overwrite the real request
        // `Content-Type: multipart/form-data; boundary=...`, breaking file
        // uploads on the stream-socket server (tina4-book#139).
        $headerEnd = strpos($data, "\r\n\r\n");
        $headerSection = $headerEnd !== false ? substr($data, 0, $headerEnd) : $data;
        $lines = explode("\r\n", $headerSection);
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
    public static function buildFrame(string $message, int $opcode = self::OP_TEXT): string
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

        // Origin allow-list (opt-in via TINA4_WS_ALLOWED_ORIGINS). Unset = allow
        // all, so this never breaks an existing deployment.
        if (!self::originAllowed($headers)) {
            @fwrite($socket, "HTTP/1.1 403 Forbidden\r\n\r\n");
            @fclose($socket);
            return;
        }

        // Per-route authentication. A WS route is PUBLIC by default; a secured
        // route (handler @secured docblock OR route(..., secure: true)) needs a
        // valid JWT supplied via Authorization header, the `bearer` subprotocol,
        // or ?token=. Checked AFTER the origin allow-list and BEFORE accepting
        // the handshake — a missing/invalid token rejects the upgrade with 401.
        // Mirrors Python's ws_authorized() in handle_connection().
        [$rawPath, $queryString] = self::splitPathQuery($headers['_path'] ?? '/');
        $route = $this->_handlers[$rawPath] ?? [];
        [$authPayload, $ok] = self::wsAuthorized($route, $headers, $queryString);
        if (!$ok) {
            @fwrite($socket, "HTTP/1.1 401 Unauthorized\r\n\r\n");
            @fclose($socket);
            return;
        }

        // Echo the `bearer` subprotocol back when the client offered it (browser
        // token transport), so the handshake completes for new WebSocket(url,
        // ['bearer', token]). Mirrors ASGI accept_subprotocol.
        $acceptProto = self::acceptedSubprotocol($headers);

        // Send handshake response
        $response = self::buildHandshakeResponse($wsKey, $acceptProto);
        @fwrite($socket, $response);

        stream_set_blocking($socket, false);

        $clientId = bin2hex(random_bytes(4));
        $peerName = stream_socket_get_name($socket, true);

        $this->clients[$clientId] = [
            'socket' => $socket,
            'ip' => $peerName ?: 'unknown',
            'connected_at' => time(),
            'lastActivity' => microtime(true),
            'buffer' => '',
            'path' => $rawPath,
            // Verified JWT payload on a @secured WS route, else null. Mirrors
            // Python's connection.auth.
            'auth' => $authPayload,
            'rooms' => [],
            'fragments' => '',
            'fragmentOpcode' => 0,
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
        $client['lastActivity'] = microtime(true); // mark activity for the idle reaper

        while (true) {
            $frame = self::decodeFrame($client['buffer']);
            if ($frame === null) {
                break;
            }

            $client['buffer'] = substr($client['buffer'], $frame['length']);

            switch ($frame['opcode']) {
                case self::OP_CONTINUATION:
                    // RFC 6455 §5.4 fragmentation: append to the buffered
                    // fragments started by a non-FIN TEXT/BINARY frame. Only
                    // dispatch once the FIN bit arrives, decoding under the
                    // ORIGINAL opcode (continuation frames carry no type).
                    $client['fragments'] .= $frame['payload'];
                    if ($frame['fin']) {
                        $full = $client['fragments'];
                        $client['fragments'] = '';
                        $client['fragmentOpcode'] = 0;
                        $this->dispatchMessage($clientId, $full);
                    }
                    break;

                case self::OP_TEXT:
                case self::OP_BINARY:
                    if ($frame['fin']) {
                        // Unfragmented message — dispatch immediately.
                        $this->dispatchMessage($clientId, $frame['payload']);
                    } else {
                        // Start of a fragmented message — buffer and wait for
                        // the continuation frames + FIN.
                        $client['fragmentOpcode'] = $frame['opcode'];
                        $client['fragments'] = $frame['payload'];
                    }
                    break;

                case self::OP_PING:
                    $pong = self::buildFrame($frame['payload'], self::OP_PONG);
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
        foreach ($this->clients[$clientId]['rooms'] as $roomName) {
            if (isset($this->rooms[$roomName])) {
                $this->rooms[$roomName] = array_values(
                    array_filter($this->rooms[$roomName], fn($id) => $id !== $clientId)
                );
                if (empty($this->rooms[$roomName])) {
                    unset($this->rooms[$roomName]);
                }
            }
        }
        // Guard the close: a pruned dead client's socket may already be closed,
        // and fclose() on a non-resource raises an uncatchable TypeError in
        // PHP 8 — which would crash the broadcast that is pruning it.
        if (is_resource($socket)) {
            @fclose($socket);
        }
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
     * Dispatch a fully-assembled message (single-frame OR reassembled from
     * fragments) to the 'message' handler.
     */
    private function dispatchMessage(string $clientId, string $payload): void
    {
        if (isset($this->handlers['message'])) {
            try {
                ($this->handlers['message'])($clientId, $payload);
            } catch (\Throwable $e) {
                $this->fireError($clientId, $e);
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
