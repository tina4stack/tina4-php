<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
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
    /**
     * Seconds to wait for socket writability when the send buffer is full
     * before treating the client as gone. See writeFully().
     */
    private const WRITE_STALL_TIMEOUT = 5;

    /**
     * Seconds a graceful shutdown may take before whatever is still in flight
     * is force-closed, when TINA4_SHUTDOWN_TIMEOUT says nothing usable.
     *
     * 30 is the same number as Kubernetes' default terminationGracePeriodSeconds
     * and gunicorn's graceful_timeout, and is the same default under the same
     * env var name in all four Tina4 frameworks.
     */
    private const DEFAULT_SHUTDOWN_TIMEOUT = 30;

    /** @var resource|null Server socket */
    private $socket = null;

    /** @var array<int, resource> All connected client sockets */
    private array $clients = [];

    /** @var array<int, string> Read buffers keyed by socket resource ID */
    private array $buffers = [];

    /**
     * @var array<int, string> Raw socket peer address keyed by resource ID.
     *
     * Captured at accept() via stream_socket_get_name() so each request can
     * see its REAL TCP client even though stream_socket_server bypasses the
     * PHP SAPI (where $_SERVER['REMOTE_ADDR'] would otherwise be set). The
     * MCP loopback/remote authorisation gate relies on this — without it a
     * 0.0.0.0-bound debug box would treat every remote caller as loopback.
     */
    private array $peerNames = [];

    /**
     * @var self|null The running server instance.
     *
     * Set in start(). Lets in-request handlers (notably POST
     * /__dev/api/reload) reach the live WebSocket client registry and
     * broadcast directly — mirroring Python's module-level `_ws_manager`
     * which `_api_reload` broadcasts through. Single-process server, so a
     * single static handle is sufficient (and is null outside a running
     * server, e.g. PHPUnit).
     */
    private static ?self $instance = null;

    /** Return the running server instance, or null when none is running. */
    public static function getInstance(): ?self
    {
        return self::$instance;
    }

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

    /** @var bool True once a shutdown has begun — makes stop() idempotent under a repeated signal */
    private bool $shuttingDown = false;

    /**
     * True once start() has actually INSTALLED the SIGALRM handler.
     *
     * stop() arms pcntl_alarm() to bound the drain, but the handler that catches
     * that alarm is registered only inside start(). Calling stop() on a server
     * that was never started therefore lit a real 30-second fuse in the calling
     * process with NO handler attached, and SIGALRM's default action is to
     * terminate. In the PHPUnit parent that killed the entire run 30 seconds
     * later, at whatever unrelated test happened to be executing - exit 142
     * (128 + SIGALRM), no summary, no failing test named.
     */
    private bool $shutdownAlarmArmable = false;

    /** @var int Seconds the current drain may take, resolved from TINA4_SHUTDOWN_TIMEOUT */
    private int $shutdownTimeout = self::DEFAULT_SHUTDOWN_TIMEOUT;

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

    /** @var bool True if the most recent file-change scan saw a .php file change */
    private bool $phpChangeDetected = false;

    /** @var float Last time we scanned files for changes */
    private float $lastFileCheck = 0;

    /** @var float Seconds between file change scans */
    private float $fileCheckInterval = 1.0;

    /** @var string[] WebSocket connection IDs subscribed to reload events */
    private array $reloadSubscribers = [];

    /** @var array<string, array<string>> Rooms: roomName => [clientId, ...] */
    private array $wsRooms = [];

    /** @var WebSocketBackplaneManager|null Lazily wired on first WS broadcast. */
    private ?WebSocketBackplaneManager $wsBackplane = null;

    /** @var float Last idle-reaper sweep timestamp (WS connections). */
    private float $wsLastReaperSweep = 0.0;

    /** @var array<array{callback: callable, interval: float, lastRun: float}> Registered tick callbacks */
    private array $tickCallbacks = [];

    /**
     * @param string|null $host Host to bind to. If null, reads TINA4_HOST (default '0.0.0.0').
     * @param int    $port Port to listen on
     */
    public function __construct(?string $host = null, int $port = 7146)
    {
        if ($host === null || $host === '') {
            $envHost = DotEnv::getEnv('TINA4_HOST');
            $host = ($envHost !== null && $envHost !== '') ? $envHost : '0.0.0.0';
        }
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * Kill whatever process is listening on the given port.
     *
     * Uses lsof on macOS/Linux and netstat + taskkill on Windows.
     * Throws RuntimeException if the port cannot be freed.
     */
    private function freePort(int $port): void
    {
        echo "  Port {$port} in use — killing existing process...\n";

        if (PHP_OS_FAMILY === 'Windows') {
            $output = [];
            exec('netstat -ano', $output);
            $pid = null;
            foreach ($output as $line) {
                if (str_contains($line, ":{$port}") &&
                    (str_contains($line, 'LISTENING') || str_contains($line, 'ESTABLISHED'))) {
                    $parts = preg_split('/\s+/', trim($line));
                    $last = end($parts);
                    if (ctype_digit($last)) {
                        $pid = (int)$last;
                        break;
                    }
                }
            }
            if ($pid !== null) {
                exec("taskkill /PID {$pid} /F");
            } else {
                throw new \RuntimeException("Could not free port {$port}: no PID found");
            }
        } else {
            $pids = [];
            exec("lsof -ti :{$port}", $pids);
            if (empty($pids)) {
                // Nothing found — port may have freed itself
                return;
            }
            foreach ($pids as $pid) {
                $pid = trim($pid);
                if (ctype_digit($pid) && function_exists('posix_kill')) {
                    posix_kill((int)$pid, defined('SIGTERM') ? SIGTERM : 15);
                } elseif (ctype_digit($pid)) {
                    exec("kill -15 {$pid}");
                }
            }
        }

        // Give the OS a moment to reclaim the port
        usleep(500000);
        echo "  Port {$port} freed\n";
    }

    /**
     * Start the server event loop.
     * Blocks indefinitely, handling HTTP and WebSocket connections.
     */
    public function start(): void
    {
        // Require tina4 CLI (or TINA4_OVERRIDE_CLIENT=true in .env)
        $isManaged = in_array('--managed', $_SERVER['argv'] ?? [], true);
        if (!$isManaged && getenv('TINA4_OVERRIDE_CLIENT') !== 'true') {
            echo PHP_EOL;
            echo str_repeat('=', 60) . PHP_EOL;
            echo PHP_EOL;
            echo "  Tina4 must be started with the tina4 CLI:" . PHP_EOL;
            echo PHP_EOL;
            echo "    tina4 serve              (development)" . PHP_EOL;
            echo "    tina4 serve --production (production)" . PHP_EOL;
            echo PHP_EOL;
            echo "  Install: cargo install tina4" . PHP_EOL;
            echo "  Docs:    https://tina4.com" . PHP_EOL;
            echo PHP_EOL;
            echo "  To run directly, add to .env:" . PHP_EOL;
            echo "    TINA4_OVERRIDE_CLIENT=true" . PHP_EOL;
            echo PHP_EOL;
            echo str_repeat('=', 60) . PHP_EOL;
            echo PHP_EOL;
            exit(1);
        }

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
            // Port is in use — kill the occupying process and try once more
            $this->freePort($this->port);
            $this->socket = @stream_socket_server(
                "tcp://{$this->host}:{$this->port}",
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
                $context
            );
        }

        if (!$this->socket) {
            throw new \RuntimeException(
                "Could not free port {$this->port}: {$errstr} ({$errno})"
            );
        }

        stream_set_blocking($this->socket, false);
        $this->running = true;
        self::$instance = $this;
        $this->isDebug = DotEnv::isTruthy(DotEnv::getEnv('TINA4_DEBUG', 'false'));
        // Disable the internal file watcher when launched by the Rust CLI (--managed).
        // The Rust CLI owns file watching, SCSS compilation, and browser reload.
        // Running both causes double-reloads and SCSS recompile loops.
        $isManaged = in_array('--managed', $_SERVER['argv'] ?? [], true);
        $this->noReload = $isManaged || DotEnv::isTruthy(DotEnv::getEnv('TINA4_NO_RELOAD', 'false'));

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

        // Register signal handlers for graceful shutdown.
        //
        // SIGHUP is deliberately NOT trapped: the Rust CLI owns file watching
        // and production logs go to stdout, so neither Puma's log-reopen nor
        // gunicorn's config-reload use for SIGHUP is a Tina4 need.
        if (function_exists('pcntl_signal')) {
            // Deliver the handler at the next VM instruction boundary instead of
            // at the top of the accept loop. That is what lets the LISTENING
            // socket close WHILE a slow request is still being served: with
            // top-of-loop dispatch the listener stayed open for the rest of the
            // in-flight request, so every connection arriving in that window was
            // accepted into the kernel backlog and then RESET when the socket
            // finally closed — a transport error the client cannot tell apart
            // from a network fault. The request handler itself consults neither
            // flag, so it still runs to completion.
            if (function_exists('pcntl_async_signals')) {
                pcntl_async_signals(true);
            }
            if (defined('SIGTERM')) {
                pcntl_signal(SIGTERM, function () {
                    $this->stop('SIGTERM');
                });
            }
            if (defined('SIGINT')) {
                pcntl_signal(SIGINT, function () {
                    $this->stop('SIGINT');
                });
            }
            // Armed by stop() to bound the drain — see forceShutdown().
            if (defined('SIGALRM')) {
                pcntl_signal(SIGALRM, function () {
                    $this->forceShutdown();
                });
                $this->shutdownAlarmArmable = true;
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
                // false here is almost always EINTR — a signal (SIGCHLD,
                // SIGTERM, alarm, etc.) interrupted the syscall. Bailing
                // out of the accept loop on EINTR kills the server while
                // it's mid-request, which surfaces to the user as
                // "spinner appears, then dies, no answer". Retry instead.
                // pcntl_signal_dispatch() picks up any queued signals.
                if (\function_exists('pcntl_signal_dispatch')) {
                    \pcntl_signal_dispatch();
                }
                continue;
            }
            if ($changed === 0) {
                // Run registered tick callbacks (background tasks)
                $this->runTickCallbacks();

                // Single-threaded: drain any cluster broadcasts from the
                // backplane + reap idle WS connections on idle ticks. poll()
                // returns immediately when nothing is pending, so neither
                // blocks the accept loop.
                $this->wsBackplane?->poll();
                $this->reapIdleWebSocketClients();

                // Check for pending reload from Rust CLI (POST /__dev/api/reload)
                if (DevAdmin::$pendingReload) {
                    DevAdmin::$pendingReload = false;
                    $this->broadcastReload();
                }
                continue;
            }

            foreach ($read as $socket) {
                // A shutdown signal closes the listeners mid-sweep (see stop()),
                // and a handler can close its own client, so a handle chosen by
                // stream_select may already be gone by the time we reach it.
                if (!is_resource($socket)) {
                    continue;
                }
                if ($socket === $this->socket) {
                    // Accept new connection
                    $client = @stream_socket_accept($this->socket, 0, $peerName);
                    if ($client) {
                        stream_set_blocking($client, false);
                        $resourceId = (int)$client;
                        $this->clients[$resourceId] = $client;
                        $this->buffers[$resourceId] = '';
                        // Capture the raw TCP peer once at accept — the socket
                        // SAPI never sets $_SERVER['REMOTE_ADDR'], so this is
                        // the only place the real client address is available.
                        $this->peerNames[$resourceId] = self::peerIp($peerName);
                    }
                } elseif ($socket === $this->aiSocket) {
                    // Accept new AI port connection
                    $client = @stream_socket_accept($this->aiSocket, 0, $peerName);
                    if ($client) {
                        stream_set_blocking($client, false);
                        $resourceId = (int)$client;
                        $this->clients[$resourceId] = $client;
                        $this->buffers[$resourceId] = '';
                        $this->aiPortConnections[$resourceId] = true;
                        $this->peerNames[$resourceId] = self::peerIp($peerName);
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
     * Begin a graceful shutdown.
     *
     * Stops ACCEPTING first: the listening sockets close here rather than at the
     * end in cleanup(), so a connection arriving after this point gets a clean
     * CONNECTION REFUSED instead of being accepted into the kernel backlog and
     * then reset. Sockets that were already accepted are untouched, so a request
     * being served runs to completion and writes its whole response.
     *
     * The drain that follows is bounded by TINA4_SHUTDOWN_TIMEOUT seconds via
     * SIGALRM — see forceShutdown().
     *
     * Idempotent: a second signal while a shutdown is already under way is a
     * no-op, so an impatient `kill` cannot re-arm the alarm or double-close.
     *
     * @param string $reason What asked for the shutdown, named in the log line.
     */
    public function stop(string $reason = 'stop()'): void
    {
        if ($this->shuttingDown) {
            return;
        }
        $this->shuttingDown = true;
        $this->running = false;
        $this->shutdownTimeout = self::resolveShutdownTimeout();

        $this->closeListeners();

        Log::info(sprintf(
            'Graceful shutdown started (%s) — not accepting new connections, draining for up to %ds',
            $reason,
            $this->shutdownTimeout
        ));

        // Never arm a fuse we have no handler for. The SIGALRM handler is
        // installed by start(); without it the default action terminates the
        // process, so a stop() on a never-started server would kill its caller
        // shutdownTimeout seconds later.
        if ($this->shutdownAlarmArmable && function_exists('pcntl_alarm') && defined('SIGALRM')) {
            pcntl_alarm($this->shutdownTimeout);
        }
    }

    /**
     * Close the listening sockets so the OS stops accepting on our ports.
     *
     * Split out of cleanup() because it has to happen the instant a shutdown
     * signal is handled, while requests are still draining. Idempotent —
     * cleanup() calls it again and finds nothing left to close.
     */
    private function closeListeners(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
        if ($this->aiSocket !== null) {
            @fclose($this->aiSocket);
            $this->aiSocket = null;
        }
    }

    /**
     * Force-close whatever is still in flight when the drain runs out of time.
     *
     * Runs from the SIGALRM handler armed by stop(), so it fires even while a
     * request handler is still blocking: PHP serves a request synchronously, so
     * there is no point at which a running handler could be asked to stop
     * cooperatively. Exits 0 — the signal was handled, not fatal.
     */
    private function forceShutdown(): void
    {
        Log::warning(sprintf(
            'Graceful shutdown exceeded TINA4_SHUTDOWN_TIMEOUT=%ds — force-closing %d connection(s) still open',
            $this->shutdownTimeout,
            count($this->clients)
        ));

        $this->cleanup();
        exit(0);
    }

    /**
     * Seconds the drain may take, read from TINA4_SHUTDOWN_TIMEOUT.
     *
     * A value that is not a positive whole number of seconds is a configuration
     * mistake, so it warns and falls back to the default rather than silently
     * becoming 0 — pcntl_alarm(0) CANCELS the alarm, which would leave the drain
     * unbounded, the exact opposite of what the setting asked for.
     *
     * @return int Seconds, always >= 1.
     */
    private static function resolveShutdownTimeout(): int
    {
        $configured = DotEnv::getEnv('TINA4_SHUTDOWN_TIMEOUT');
        if ($configured === null || trim($configured) === '') {
            return self::DEFAULT_SHUTDOWN_TIMEOUT;
        }

        $seconds = filter_var(trim($configured), FILTER_VALIDATE_INT);
        if ($seconds === false || $seconds < 1) {
            Log::warning(sprintf(
                'TINA4_SHUTDOWN_TIMEOUT=%s is not a positive number of seconds — using %ds',
                $configured,
                self::DEFAULT_SHUTDOWN_TIMEOUT
            ));

            return self::DEFAULT_SHUTDOWN_TIMEOUT;
        }

        return $seconds;
    }

    /**
     * Dispatch a Request through the Tina4 Router and return a Response.
     *
     * Useful for testing and embedding — does not require an active socket
     * connection. Cross-framework parity with Python and Node.js.
     *
     * @param Request $request The request to handle
     * @return Response The response from the router
     */
    public function handle(Request $request): Response
    {
        return Router::dispatch($request, new Response());
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
    /**
     * Extract the bare host from a stream_socket_get_name() peer string.
     *
     * The peer name is "address:port" — IPv4 "127.0.0.1:54321", bracketed
     * IPv6 "[::1]:54321", or bare IPv6 "::1:54321" on some platforms. A port
     * is always appended, so stripping the final ":port" segment yields the
     * address in every case. Returns "" for a null/empty peer.
     */
    private static function peerIp(?string $peerName): string
    {
        if ($peerName === null || $peerName === '') {
            return '';
        }
        $peerName = trim($peerName);
        // Bracketed IPv6: [::1]:port
        if ($peerName[0] === '[') {
            $end = strpos($peerName, ']');
            return $end !== false ? substr($peerName, 1, $end - 1) : $peerName;
        }
        $lastColon = strrpos($peerName, ':');
        return $lastColon === false ? $peerName : substr($peerName, 0, $lastColon);
    }

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
        } elseif (str_contains($contentType, 'multipart/form-data') && $body !== '') {
            // PHP's SAPI layer doesn't run under stream_socket_server,
            // so $_FILES is always empty. Parse multipart manually.
            $parsed = self::parseMultipartBody($body, $contentType);
            $parsedBody = $parsed['fields'];
            $parsedFiles = $parsed['files'];
        }

        // Build Request object
        $files = $parsedFiles ?? [];
        // Raw TCP peer captured at accept() — the only trustworthy client
        // address under the socket SAPI. Feeds Request::$remoteIp (and the
        // MCP loopback/remote gate) so a remote caller can't be mistaken for
        // localhost on a 0.0.0.0 bind.
        $peerIp = $this->peerNames[(int)$client] ?? '';
        $request = new Request(
            method: strtoupper($method),
            path: $path,
            query: $queryParams,
            body: $parsedBody,
            headers: $this->buildHeaderArray($headers),
            files: !empty($files) ? $files : null,
            remoteIp: $peerIp,
        );

        // Populate PHP superglobals so user code that reads $_COOKIE, $_GET,
        // $_POST, $_SERVER, or calls header() works correctly under the built-in
        // socket server (stream_socket_server bypasses the PHP SAPI layer).
        self::populateSuperglobals(
            $method,
            $rawPath,
            $queryString,
            $queryParams,
            $parsedBody,
            $headers,
            $this->host,
            $this->port,
            $peerIp
        );

        // Suppress hot-reload script for AI port connections
        if ($this->isAiPortConnection($client)) {
            DevAdmin::$suppressReload = true;
        }

        // Dispatch through Tina4 Router
        $response = Router::dispatch($request, new Response());

        // Restore reload suppression flag
        DevAdmin::$suppressReload = false;

        // A streamed body (SSE) is written chunk-by-chunk as the generator
        // yields, not buffered into one payload. PHP's header()/echo output
        // layer is not connected to this socket at all, so the server has to
        // emit the stream itself — see streamToClient().
        if ($response->isStreaming()) {
            $this->streamToClient($client, $response);
            return;
        }

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
        // Emit cookies set via $response->cookie()
        $httpResponse .= self::cookieHeaderLines($response);
        $httpResponse .= "\r\n";
        $httpResponse .= $responseBody;

        // Write the full payload, tolerating a non-blocking send buffer.
        $this->writeFully($client, $httpResponse);

        if (!$keepAlive) {
            $this->removeClient($client);
        }
    }

    /**
     * Render the Set-Cookie header lines for a response.
     *
     * setcookie() writes into the SAPI header list, which is never sent on a
     * raw socket, so the socket server serialises $response->cookie() itself.
     *
     * @return string Zero or more "Set-Cookie: ...\r\n" lines.
     */
    private static function cookieHeaderLines(Response $response): string
    {
        $lines = '';
        foreach ($response->getCookies() as $name => $opts) {
            $cookie = urlencode($name) . '=' . urlencode($opts['value']);
            if (!empty($opts['expires'])) {
                $cookie .= '; Expires=' . gmdate('D, d M Y H:i:s T', $opts['expires']);
            }
            $cookie .= '; Path=' . ($opts['path'] ?? '/');
            if (!empty($opts['domain'])) {
                $cookie .= '; Domain=' . $opts['domain'];
            }
            if (!empty($opts['secure'])) {
                $cookie .= '; Secure';
            }
            if (!empty($opts['httponly'])) {
                $cookie .= '; HttpOnly';
            }
            if (!empty($opts['samesite'])) {
                $cookie .= '; SameSite=' . $opts['samesite'];
            }
            $lines .= "Set-Cookie: {$cookie}\r\n";
        }
        return $lines;
    }

    /**
     * Write a streamed (SSE) response to a client socket, chunk by chunk.
     *
     * The socket server owns the wire, so it emits the status line and headers
     * itself and then flushes every chunk the moment the generator yields it.
     * A streamed body has no known length up front: no Content-Length and no
     * chunked framing is sent, so the body is delimited by EOF — hence
     * `Connection: close` and closing the socket once the source is exhausted.
     * A client that has gone away shows up as a short write and ends the
     * stream, the socket-level equivalent of connection_aborted().
     *
     * @param resource $client Client socket (non-blocking)
     */
    private function streamToClient($client, Response $response): void
    {
        $statusCode = $response->getStatusCode();
        $headers = $response->getHeaders();

        // Length is unknown while streaming, and the body ends at EOF.
        unset($headers['Content-Length']);
        $headers['Connection'] = 'close';

        $head = "HTTP/1.1 {$statusCode} {$this->getStatusText($statusCode)}\r\n";
        foreach ($headers as $name => $value) {
            $head .= "{$name}: {$value}\r\n";
        }
        $head .= self::cookieHeaderLines($response);
        $head .= "\r\n";

        if ($this->writeFully($client, $head) === strlen($head)) {
            foreach ($response->streamChunks() as $chunk) {
                if ($chunk === '') {
                    continue;
                }
                if ($this->writeFully($client, $chunk) < strlen($chunk)) {
                    break; // client disconnected mid-stream
                }
            }
        }

        $this->removeClient($client);
    }

    /**
     * Write a payload in full to a non-blocking client socket.
     *
     * fwrite() on a non-blocking stream socket returns a SHORT count — or
     * exactly 0 (EAGAIN, "try again") — when the OS send buffer fills
     * (~200KB-1MB depending on platform). A `0` return is NOT a closed
     * socket: it means the buffer is momentarily full and the kernel has
     * not yet drained it onto the wire.
     *
     * Pre-v3.13.12 the loop treated `$n === 0` as fatal and `break`ed,
     * silently truncating any response larger than the send buffer. A
     * ~4MB attachment download returned 200 with the correct
     * Content-Length but only ~1-2MB of body, so nginx logged
     * "upstream prematurely closed connection while reading upstream"
     * and the browser showed a failed download. The cutoff varied run
     * to run because it depended on how much fit before the first 0.
     *
     * The fix: on `0`, block on writability (with a no-progress timeout)
     * and retry; only give up on a real error (`false`) or a client that
     * has genuinely stopped reading. substr() is bounded to 512KB chunks
     * so we don't recopy the entire remaining tail every iteration
     * (O(n) total instead of O(n^2)).
     *
     * @param resource $client Client socket (non-blocking)
     * @param string   $data   Full payload (headers + body)
     * @return int Bytes actually written
     */
    private function writeFully($client, string $data): int
    {
        $total = strlen($data);
        $written = 0;
        $chunkSize = 524288; // 512KB

        while ($written < $total) {
            $n = @fwrite($client, substr($data, $written, $chunkSize));

            if ($n === false) {
                // Real write error (socket closed / reset) — stop.
                break;
            }

            if ($n === 0) {
                // Send buffer full (EAGAIN). Wait for the kernel to drain
                // it before retrying. stream_select needs by-ref vars.
                // A 5s window with no writability means the peer is gone.
                $sr = [];
                $sw = [$client];
                $se = [];
                if (@stream_select($sr, $sw, $se, self::WRITE_STALL_TIMEOUT) === 0) {
                    break; // timed out waiting for writability — client gone
                }
                continue;
            }

            $written += $n;
        }

        return $written;
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

        // Origin allow-list (opt-in via TINA4_WS_ALLOWED_ORIGINS). Unset = allow
        // all so existing deployments are unaffected. Shared with the standalone
        // server via WebSocket::originAllowed().
        if (!WebSocket::originAllowed($headers)) {
            $this->sendHttpError($client, 403, 'Forbidden: Origin not allowed');
            return;
        }

        // The request path may carry a query string (?token=...). Split it so
        // the route is matched on the bare path and the query is available for
        // token extraction. Mirrors Python reading the query from _path.
        [$path, $queryString] = WebSocket::splitPathQuery($headers['_path'] ?? '/');

        // Match the WS route, extracting {param} values (e.g. /ws/rtc/{room},
        // /ws/chat/{channel}). Pattern matching + params mirror the HTTP router
        // and Python's Router.match_ws — the built-in server previously matched
        // WS routes by exact string equality only, so parameterised paths 404'd
        // and $connection->params was always empty.
        [$matchedRoute, $wsParams] = Router::matchWebSocket($path);

        if ($matchedRoute === null) {
            $this->sendHttpError($client, 404, 'No WebSocket handler registered for this path');
            return;
        }
        $matchedHandler = $matchedRoute['handler'];

        // Per-route authentication. A WS route is PUBLIC by default; a secured
        // route (the matched route carries auth_required, set by an @secured
        // handler docblock OR Router::websocket(..., secure: true)) needs a
        // valid JWT via the Authorization header, the `bearer` subprotocol, or
        // ?token=. Checked AFTER the origin allow-list and BEFORE accepting the
        // handshake — a missing/invalid token rejects the upgrade with 401.
        // Public routes always pass (non-breaking). Mirrors Python ws_authorized.
        [$authPayload, $ok] = WebSocket::wsAuthorized($matchedRoute, $headers, $queryString);
        if (!$ok) {
            $this->sendHttpError($client, 401, 'Unauthorized: WebSocket route requires a valid token');
            return;
        }

        // Echo the `bearer` subprotocol back when the client offered it (browser
        // token transport: new WebSocket(url, ['bearer', token])).
        $acceptProto = WebSocket::acceptedSubprotocol($headers);

        // Send handshake response
        $response = WebSocket::buildHandshakeResponse($wsKey, $acceptProto);
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
            'lastActivity' => microtime(true),
            'fragments' => '',
            'fragmentOpcode' => 0,
        ];
        $this->wsSocketMap[$resourceId] = $connectionId;

        // Create WebSocketConnection object and persist it for callback reuse.
        // The verified JWT payload (or null on a public route) is exposed as
        // $connection->auth — mirrors Python's connection.auth.
        $connection = new WebSocketConnection($connectionId, $path, $client, $this, '', $headers, $wsParams, $authPayload);
        $this->wsClients[$connectionId]['connection'] = $connection;

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
        $wsClient['lastActivity'] = microtime(true); // mark activity for the idle reaper

        while (true) {
            $frame = WebSocket::decodeFrame($wsClient['buffer']);
            if ($frame === null) {
                break;
            }

            $wsClient['buffer'] = substr($wsClient['buffer'], $frame['length']);

            switch ($frame['opcode']) {
                case WebSocket::OP_CONTINUATION:
                    // RFC 6455 §5.4 fragmentation: append to the fragments
                    // started by a non-FIN TEXT/BINARY frame; only dispatch
                    // once the FIN bit arrives (continuation frames carry no
                    // type, so the original opcode governs decoding).
                    $wsClient['fragments'] .= $frame['payload'];
                    if ($frame['fin']) {
                        $full = $wsClient['fragments'];
                        $wsClient['fragments'] = '';
                        $wsClient['fragmentOpcode'] = 0;
                        $this->dispatchWebSocketMessage($connectionId, $socket, $full);
                        // The handler may have closed the connection — the
                        // &$wsClient reference would then dangle, so stop.
                        if (!isset($this->wsClients[$connectionId])) {
                            return;
                        }
                    }
                    break;

                case WebSocket::OP_TEXT:
                case WebSocket::OP_BINARY:
                    if ($frame['fin']) {
                        // Unfragmented message — dispatch immediately.
                        $this->dispatchWebSocketMessage($connectionId, $socket, $frame['payload']);
                        if (!isset($this->wsClients[$connectionId])) {
                            return;
                        }
                    } else {
                        // Start of a fragmented message — buffer until FIN.
                        $wsClient['fragmentOpcode'] = $frame['opcode'];
                        $wsClient['fragments'] = $frame['payload'];
                    }
                    break;

                case WebSocket::OP_PING:
                    $pong = WebSocket::buildFrame($frame['payload'], WebSocket::OP_PONG);
                    @fwrite($socket, $pong);
                    break;

                case WebSocket::OP_PONG:
                    // Ignore unsolicited pongs
                    break;

                case WebSocket::OP_CLOSE:
                    // Echo close frame back
                    $closeFrame = WebSocket::buildFrame(
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
     * Dispatch a fully-assembled WebSocket message (single-frame OR reassembled
     * from continuation fragments) to its route handler.
     */
    private function dispatchWebSocketMessage(string $connectionId, $socket, string $payload): void
    {
        $wsClient = $this->wsClients[$connectionId] ?? null;
        if ($wsClient === null) {
            return;
        }
        // Reuse the persisted connection so route-style callbacks survive.
        $connection = $wsClient['connection']
            ?? new WebSocketConnection($connectionId, $wsClient['path'], $socket, $this);
        try {
            ($wsClient['handler'])($connection, $payload, 'message');
        } catch (\Throwable $e) {
            Log::error('WebSocket message handler error: ' . $e->getMessage());
        }
    }

    // ── Backplane (multi-instance scaling) ──────────────────────
    //
    // The RedisBackplane/NATSBackplane/factory already existed but were never
    // wired into a broadcast, so a broadcast only reached this process and
    // multi-instance scaling was dead. We now lazily wire the backplane on the
    // first broadcast: deliver to LOCAL connections, then publish an envelope
    // to the shared "tina4:ws" channel for sibling instances. Inbound sibling
    // messages are drained by the event-loop idle tick (poll()) and relayed to
    // LOCAL connections only (never re-published — that would loop the cluster).
    // See {@see WebSocketBackplaneManager} for the envelope shape + origin guard.

    /** Lazily wire the backplane (idempotent, best-effort) on first broadcast. */
    private function ensureWebSocketBackplane(): void
    {
        if ($this->wsBackplane === null) {
            $this->wsBackplane = new WebSocketBackplaneManager(
                fn(string $kind, ?string $room, ?string $path, ?string $exclude, string $message)
                    => $this->relayWebSocketLocal($kind, $room, $path, $exclude, $message)
            );
        }
        $this->wsBackplane->ensure();
    }

    /**
     * Relay sink for the backplane: deliver a remote-originated message to
     * LOCAL connections only, by kind. Never re-publishes.
     */
    private function relayWebSocketLocal(string $kind, ?string $room, ?string $path, ?string $exclude, string $message): void
    {
        if ($kind === 'room') {
            if ($room !== null) {
                $this->deliverToRoomLocal($room, $message, $exclude !== null ? [$exclude] : null);
            }
        } elseif ($kind === 'path') {
            $this->deliverWebSocketLocal($message, $path, $exclude);
        } else { // 'all' (and anything unknown)
            $this->deliverWebSocketLocal($message, null, $exclude);
        }
    }

    /**
     * Deliver to LOCAL clients (optionally path-filtered), resiliently: a
     * failed write detects + prunes the dead client and delivery continues to
     * the rest. One dead/slow client never aborts the broadcast. Never
     * publishes to the backplane.
     */
    private function deliverWebSocketLocal(string $message, ?string $path, ?string $excludeId): void
    {
        $frame = WebSocket::buildFrame($message);
        $dead = [];
        foreach ($this->wsClients as $id => $client) {
            if ($excludeId !== null && $id === $excludeId) {
                continue;
            }
            if ($path !== null && ($client['path'] ?? '/') !== $path) {
                continue;
            }
            if (!$this->safeWriteWebSocket($client['socket'], $frame)) {
                $dead[] = $id;
            }
        }
        foreach ($dead as $id) {
            $this->removeWebSocketClient($id);
        }
    }

    /**
     * Deliver to LOCAL members of a room, resiliently (prune dead clients).
     *
     * @param string[]|null $excludeIds
     */
    private function deliverToRoomLocal(string $roomName, string $message, ?array $excludeIds): void
    {
        $members = $this->wsRooms[$roomName] ?? [];
        $frame = WebSocket::buildFrame($message);
        $dead = [];
        foreach ($members as $clientId) {
            if ($excludeIds !== null && in_array($clientId, $excludeIds, true)) {
                continue;
            }
            if (!isset($this->wsClients[$clientId])) {
                continue;
            }
            if (!$this->safeWriteWebSocket($this->wsClients[$clientId]['socket'], $frame)) {
                $dead[] = $clientId;
            }
        }
        foreach ($dead as $id) {
            $this->removeWebSocketClient($id);
        }
    }

    /**
     * Write a frame to a client socket, returning false if the write fails (so
     * the caller prunes the dead client). A closed/invalid resource or a failed
     * fwrite both mean the peer is gone — one dead client never aborts a
     * broadcast to the rest.
     */
    private function safeWriteWebSocket($socket, string $frame): bool
    {
        if (!is_resource($socket)) {
            return false;
        }
        return @fwrite($socket, $frame) !== false;
    }

    /**
     * Reap WebSocket connections idle past TINA4_WS_IDLE_TIMEOUT seconds.
     * Opt-in/non-breaking: 0/unset disables the reaper. Throttled to one sweep
     * per second (this runs on every idle tick).
     *
     * @return int Number reaped.
     */
    public function reapIdleWebSocketClients(): int
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
        if (($now - $this->wsLastReaperSweep) < 1.0) {
            return 0;
        }
        $this->wsLastReaperSweep = $now;

        $stale = [];
        foreach ($this->wsClients as $id => $client) {
            $last = $client['lastActivity'] ?? $now;
            if (($now - $last) > $timeout) {
                $stale[] = $id;
            }
        }
        foreach ($stale as $id) {
            $this->sendWebSocketGoingAway($id, 'idle timeout');
            $this->removeWebSocketClient($id);
        }
        if (!empty($stale)) {
            Log::info('WebSocket idle reaper closed ' . count($stale) . ' connection(s)');
        }
        return count($stale);
    }

    /**
     * Broadcast a WebSocket message to all clients on a given path.
     *
     * Delivers to LOCAL connections first (resilient — a dead client never
     * aborts delivery to the rest), then publishes to sibling instances over
     * the backplane when TINA4_WS_BACKPLANE is configured.
     *
     * @param string      $message   Message to send
     * @param string|null $path      Only broadcast to clients on this path (null = all)
     * @param string|null $excludeId Connection ID to exclude
     */
    public function broadcastWebSocket(string $message, ?string $path = null, ?string $excludeId = null): void
    {
        $this->ensureWebSocketBackplane();
        $this->deliverWebSocketLocal($message, $path, $excludeId);
        $this->wsBackplane?->publish($path !== null ? 'path' : 'all', $message, null, $path, $excludeId);
    }

    /**
     * Join a WebSocket room.
     */
    public function joinRoom(string $clientId, string $roomName): void
    {
        if (!isset($this->wsRooms[$roomName])) {
            $this->wsRooms[$roomName] = [];
        }
        if (!in_array($clientId, $this->wsRooms[$roomName], true)) {
            $this->wsRooms[$roomName][] = $clientId;
        }
    }

    /**
     * Leave a WebSocket room.
     */
    public function leaveRoom(string $clientId, string $roomName): void
    {
        if (isset($this->wsRooms[$roomName])) {
            $this->wsRooms[$roomName] = array_values(array_filter(
                $this->wsRooms[$roomName],
                fn($id) => $id !== $clientId
            ));
            if (empty($this->wsRooms[$roomName])) {
                unset($this->wsRooms[$roomName]);
            }
        }
    }

    /**
     * Broadcast a message to all members of a room.
     *
     * Delivers to LOCAL room members first (resilient — prunes dead clients),
     * then fans out over the backplane: a room can span instances, so each one
     * delivers to its own members.
     */
    public function broadcastToRoom(string $roomName, string $message, ?array $excludeIds = null): void
    {
        $this->ensureWebSocketBackplane();
        $this->deliverToRoomLocal($roomName, $message, $excludeIds);
        $exclude = (is_array($excludeIds) && count($excludeIds) === 1) ? $excludeIds[0] : null;
        $this->wsBackplane?->publish('room', $message, $roomName, null, $exclude);
    }

    /**
     * Get rooms a client belongs to.
     */
    public function getClientRooms(string $clientId): array
    {
        $result = [];
        foreach ($this->wsRooms as $roomName => $members) {
            if (in_array($clientId, $members, true)) {
                $result[] = $roomName;
            }
        }
        return $result;
    }

    /**
     * Return the live WebSocketConnection objects currently in a room.
     *
     * Each carries its verified ->auth payload, so a handler can build a
     * presence roster (mirrors Python's WebSocketManager.get_room_connections).
     * Local members only — cross-instance presence rides the backplane.
     *
     * @return WebSocketConnection[]
     */
    public function getRoomConnections(string $roomName): array
    {
        $connections = [];
        foreach ($this->wsRooms[$roomName] ?? [] as $clientId) {
            $conn = $this->wsClients[$clientId]['connection'] ?? null;
            if ($conn !== null) {
                $connections[] = $conn;
            }
        }
        return $connections;
    }

    /**
     * Send RFC 6455 close code 1001 ("going away") to a live WebSocket client.
     *
     * The peer then knows the server is leaving and can reconnect deliberately,
     * instead of seeing the socket vanish as an abrupt transport error. Best
     * effort by design — a peer that has already gone must never abort the sweep
     * that is closing it.
     *
     * @param string $connectionId Connection to notify.
     * @param string $reason       Close reason carried in the frame payload.
     */
    private function sendWebSocketGoingAway(string $connectionId, string $reason): void
    {
        $socket = $this->wsClients[$connectionId]['socket'] ?? null;
        if (!is_resource($socket)) {
            return;
        }

        @fwrite($socket, WebSocket::buildFrame(
            pack('n', WebSocket::CLOSE_GOING_AWAY) . $reason,
            WebSocket::OP_CLOSE
        ));
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

        // Fire close event — reuse persisted connection so route-style callbacks survive
        $connection = $wsClient['connection']
            ?? new WebSocketConnection($connectionId, $wsClient['path'], $socket, $this);
        try {
            ($wsClient['handler'])($connection, null, 'close');
        } catch (\Throwable $e) {
            // Ignore errors in close handler
        }

        // Remove from all rooms
        foreach ($this->wsRooms as $roomName => $members) {
            $this->wsRooms[$roomName] = array_values(array_filter(
                $members,
                fn($id) => $id !== $connectionId
            ));
            if (empty($this->wsRooms[$roomName])) {
                unset($this->wsRooms[$roomName]);
            }
        }

        // Clean up
        unset($this->wsClients[$connectionId]);
        unset($this->wsSocketMap[$resourceId]);
        unset($this->clients[$resourceId]);
        unset($this->buffers[$resourceId]);
        unset($this->aiPortConnections[$resourceId]);
        unset($this->peerNames[$resourceId]);
        // Guard the close: a pruned dead client's socket may already be closed,
        // and fclose() on a non-resource raises an uncatchable TypeError in
        // PHP 8 — which would crash the very broadcast that is pruning it.
        if (is_resource($socket)) {
            @fclose($socket);
        }
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
        unset($this->peerNames[$resourceId]);
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
        $this->writeFully($client, $response);
        $this->removeClient($client);
    }

    /**
     * Build a clean header array from parsed headers (exclude internal keys).
     */
    /**
     * Populate PHP superglobals ($_GET, $_POST, $_COOKIE, $_SERVER, $_FILES)
     * for the current request. Idempotent and safe to call repeatedly across
     * the same persistent process — every call fully resets all keys that
     * could leak from a prior request.
     *
     * SECURITY: $_SERVER is process-global under stream_socket_server. Without
     * wiping HTTP_* keys from the previous request, headers like Authorization,
     * Cookie, If-None-Match, X-API-Key, etc. leak across requests — an
     * unauthenticated request following an authenticated one would observe
     * the previous user's bearer token. Reported by 24now / 24call-agent and
     * patched in 3.11.17 (with a backport to v2 main).
     *
     * Apps that read parsed headers via $request->headers were unaffected;
     * the leak only ever surfaced for code reading $_SERVER directly.
     *
     * @param string                $method       HTTP method (GET, POST, ...)
     * @param string                $rawPath      Original request URI (with query string)
     * @param string                $queryString  Query string portion (no leading ?)
     * @param array                 $queryParams  Parsed query parameters
     * @param array|string|null     $parsedBody   Parsed body (array for JSON/form, string otherwise)
     * @param array<string, string> $headers      Request headers (lowercase keys; _path/_method etc. ignored)
     * @param string                $host         Server host
     * @param int                   $port         Server port
     */
    public static function populateSuperglobals(
        string $method,
        string $rawPath,
        string $queryString,
        array $queryParams,
        $parsedBody,
        array $headers,
        string $host,
        int $port,
        string $remoteIp = ''
    ): void {
        // SECURITY: wipe all HTTP_* keys from the previous request before we
        // populate this one. $_SERVER survives across requests because
        // stream_socket_server runs one PHP process for the whole socket
        // lifetime — without this, every header sent by request N persists
        // into request N+1 unless N+1 happens to overwrite the same key.
        foreach (array_keys($_SERVER) as $__leakKey) {
            if (strncmp($__leakKey, 'HTTP_', 5) === 0) {
                unset($_SERVER[$__leakKey]);
            }
        }
        // Defense-in-depth: $_FILES is not populated by the socket server
        // (we use $request->files instead), but reset it anyway so user
        // code that reads $_FILES cannot accidentally see stale state.
        $_FILES = [];

        $_GET = $queryParams;
        $_POST = is_array($parsedBody) && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            ? $parsedBody
            : [];
        $_COOKIE = [];
        if (isset($headers['cookie'])) {
            foreach (explode(';', $headers['cookie']) as $cookiePair) {
                $cookiePair = trim($cookiePair);
                if ($cookiePair === '') continue;
                $eqPos = strpos($cookiePair, '=');
                if ($eqPos !== false) {
                    $_COOKIE[urldecode(substr($cookiePair, 0, $eqPos))] = urldecode(substr($cookiePair, $eqPos + 1));
                }
            }
        }

        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $rawPath;
        $_SERVER['QUERY_STRING'] = $queryString;
        $_SERVER['SERVER_NAME'] = $host;
        $_SERVER['SERVER_PORT'] = (string)$port;
        $_SERVER['HTTP_HOST'] = $headers['host'] ?? "{$host}:{$port}";
        // Real TCP peer captured at accept() (falls back to loopback only when
        // unknown). Previously hardcoded to 127.0.0.1, which made every client
        // look local — a security hazard for the MCP loopback gate on a
        // 0.0.0.0 bind. User code reading $_SERVER['REMOTE_ADDR'] now sees the
        // true client too.
        $_SERVER['REMOTE_ADDR'] = $remoteIp !== '' ? $remoteIp : '127.0.0.1';
        foreach ($headers as $hk => $hv) {
            if ($hk === '' || $hk[0] === '_') {
                continue;
            }
            $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $hk))] = $hv;
        }
    }

    /**
     * Parse multipart/form-data body into fields and files.
     *
     * The stream_socket_server bypasses PHP's SAPI layer so $_FILES is
     * always empty. This parses the raw body using boundary scanning.
     *
     * @return array{fields: array, files: array}
     */
    public static function parseMultipartBody(string $body, string $contentType): array
    {
        $fields = [];
        $files = [];

        // Extract boundary from Content-Type header
        $boundary = null;
        foreach (explode(';', $contentType) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 'boundary=')) {
                $boundary = trim(substr($part, 9), '"');
                break;
            }
        }

        if ($boundary === null) {
            return ['fields' => $fields, 'files' => $files];
        }

        $delimiter = "--{$boundary}";
        $closeDelimiter = "--{$boundary}--";

        // Split body on boundary
        $parts = explode($delimiter, $body);

        foreach ($parts as $part) {
            // Skip preamble and closing boundary
            $part = ltrim($part, "\r\n");
            if ($part === '' || $part === '--' || str_starts_with($part, '--')) {
                continue;
            }

            // Separate headers from content (double CRLF)
            $headerEnd = strpos($part, "\r\n\r\n");
            if ($headerEnd === false) {
                continue;
            }

            $headerSection = substr($part, 0, $headerEnd);
            $content = substr($part, $headerEnd + 4);

            // Remove trailing CRLF from content
            if (str_ends_with($content, "\r\n")) {
                $content = substr($content, 0, -2);
            }

            // Parse headers
            $name = null;
            $filename = null;
            $fileType = 'application/octet-stream';

            foreach (explode("\r\n", $headerSection) as $line) {
                if (stripos($line, 'Content-Disposition') !== false) {
                    if (preg_match('/name="([^"]+)"/', $line, $m)) {
                        $name = $m[1];
                    }
                    if (preg_match('/filename="([^"]*)"/', $line, $m)) {
                        $filename = $m[1];
                    }
                } elseif (stripos($line, 'Content-Type') !== false) {
                    $fileType = trim(explode(':', $line, 2)[1] ?? $fileType);
                }
            }

            if ($name === null) {
                continue;
            }

            if ($filename !== null) {
                // File upload — matches normaliseFiles() format in Request.php
                $files[$name] = [
                    'fieldName' => $name,
                    'filename' => $filename,
                    'type' => $fileType,
                    'content' => $content,  // raw binary
                    'size' => strlen($content),
                ];
            } else {
                // Regular form field
                $fields[$name] = $content;
            }
        }

        return ['fields' => $fields, 'files' => $files];
    }

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
     * RFC 7231 / RFC 9110 status reason phrases. We use this to write a
     * correct HTTP status line in the dev server — previously the server
     * wrote "HTTP/1.1 404 OK" for unknown codes which is malformed.
     *
     * @var array<int, string>
     */
    private const HTTP_REASON_PHRASES = [
        100 => 'Continue', 101 => 'Switching Protocols',
        200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No Content',
        206 => 'Partial Content',
        301 => 'Moved Permanently', 302 => 'Found', 303 => 'See Other',
        304 => 'Not Modified', 307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
        400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden',
        404 => 'Not Found', 405 => 'Method Not Allowed', 406 => 'Not Acceptable',
        409 => 'Conflict', 410 => 'Gone', 413 => 'Content Too Large',
        415 => 'Unsupported Media Type', 422 => 'Unprocessable Content',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error', 501 => 'Not Implemented',
        502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Timeout',
    ];

    /**
     * Return the canonical HTTP reason phrase for $status.
     *
     * Falls back to a sensible label when an exotic status is used. Never
     * returns an empty string — the HTTP/1.1 status line requires a phrase.
     */
    public static function httpReason(int $status): string
    {
        if (isset(self::HTTP_REASON_PHRASES[$status])) {
            return self::HTTP_REASON_PHRASES[$status];
        }
        return ($status >= 200 && $status < 300) ? 'OK' : 'Error';
    }

    /**
     * Get HTTP status text for a code.
     */
    private function getStatusText(int $code): string
    {
        return self::httpReason($code);
    }

    /**
     * Clean up all connections and close the server socket.
     *
     * Safe to call after stop() has already closed the listeners, and safe to
     * call twice — every step is guarded, so the timeout path (forceShutdown)
     * and the normal end-of-loop path share it.
     */
    private function cleanup(): void
    {
        // The drain is over; a pending alarm must not fire into a process that
        // has already finished shutting down.
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
        }

        // Tell every live WebSocket it is going away (RFC 6455 close code 1001)
        // before its socket disappears.
        foreach (array_keys($this->wsClients) as $id) {
            $this->sendWebSocketGoingAway($id, 'server shutting down');
            $this->removeWebSocketClient($id);
        }

        // Close all HTTP clients
        foreach ($this->clients as $client) {
            if (is_resource($client)) {
                @fclose($client);
            }
        }
        $this->clients = [];
        $this->buffers = [];
        $this->peerNames = [];
        $this->aiPortConnections = [];

        // Normally already closed by stop(); this covers the paths that reach
        // cleanup() without a signal.
        $this->closeListeners();

        // Tear down the WS backplane subscription, if any.
        $this->wsBackplane?->close();

        // Release the database connection so the engine sees a clean
        // disconnect instead of reaping an abandoned session. Resilient — a
        // driver that fails to close is logged, never fatal.
        App::closeDatabase();

        if (self::$instance === $this) {
            self::$instance = null;
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
     * Register an already-upgraded WebSocket client directly.
     *
     * Used by the live upgrade path and by tests/embedders that want to attach
     * a socket without driving the HTTP handshake. The handler defaults to a
     * no-op so a test can register a pair and broadcast to it.
     *
     * @param string        $connectionId Unique connection id
     * @param resource       $socket       The (upgraded) socket resource
     * @param string        $path         WebSocket route path
     * @param callable|null $handler      Route handler ($conn, $data, $event)
     */
    public function registerWebSocketClient(string $connectionId, $socket, string $path = '/', ?callable $handler = null): void
    {
        $handler ??= function ($conn, $data, $event) {};
        $this->wsClients[$connectionId] = [
            'socket' => $socket,
            'path' => $path,
            'buffer' => '',
            'id' => $connectionId,
            'handler' => $handler,
            'lastActivity' => microtime(true),
            'fragments' => '',
            'fragmentOpcode' => 0,
        ];
        if (is_resource($socket)) {
            $this->wsSocketMap[(int)$socket] = $connectionId;
        }
    }

    /**
     * Inject a backplane manager (test seam — lets a test attach a
     * {@see WebSocketBackplaneManager} wired to a fake bus, exactly as
     * ensureWebSocketBackplane() would in production).
     */
    public function setWebSocketBackplane(WebSocketBackplaneManager $manager): void
    {
        $this->wsBackplane = $manager;
    }

    /** Return the wired backplane manager (or null). Test/introspection seam. */
    public function getWebSocketBackplane(): ?WebSocketBackplaneManager
    {
        return $this->wsBackplane;
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

    // ── Tick Callbacks (background tasks) ────────────────────────

    /**
     * Register a callback to run periodically on idle server ticks.
     * Matches Python's threading.Thread(target=fn, daemon=True) pattern
     * but runs cooperatively in the event loop.
     *
     * @param callable $callback  Function to call (no arguments)
     * @param float    $interval  Minimum seconds between invocations (default: 1.0)
     */
    public function onTick(callable $callback, float $interval = 1.0): void
    {
        $this->tickCallbacks[] = [
            'callback' => $callback,
            'interval' => $interval,
            'lastRun' => 0.0,
        ];
    }

    /**
     * Stop a registered tick callback and DEREGISTER it.
     *
     * Matching is by identity on the callable, so only the FIRST registration of
     * that exact callable is removed and no sibling task is disturbed. Safe to
     * call from inside a tick callback — including one stopping itself.
     *
     * Idempotent — stopping an already-stopped callback is a safe no-op.
     *
     * @param  callable $callback The exact callable passed to onTick()
     * @return bool True if a callback was removed, false if none matched
     */
    public function stopTick(callable $callback): bool
    {
        foreach ($this->tickCallbacks as $key => $tick) {
            if ($tick['callback'] === $callback) {
                // unset() WITHOUT reindexing. runTickCallbacks() iterates this
                // array by reference, and PHP skips an unset element that it has
                // not reached yet — but renumbering the array mid-sweep would
                // shift every later entry onto a different key and run the wrong
                // callback. Leaving a hole is what keeps a mid-sweep stop safe.
                unset($this->tickCallbacks[$key]);
                return true;
            }
        }

        return false;
    }

    /**
     * Number of REGISTERED tick callbacks (stopped ones are already removed).
     *
     * @return int Count of currently-registered background tasks
     */
    public function tickCallbackCount(): int
    {
        return count($this->tickCallbacks);
    }

    /**
     * Run any due tick callbacks. Called from the idle branch of the event loop.
     * Includes a safety timeout — if a callback takes longer than its interval,
     * a warning is logged to help developers identify blocking code.
     */
    private function runTickCallbacks(): void
    {
        $now = microtime(true);
        foreach ($this->tickCallbacks as &$tick) {
            if ($now - $tick['lastRun'] >= $tick['interval']) {
                $tick['lastRun'] = $now;
                $start = microtime(true);
                try {
                    ($tick['callback'])();
                } catch (\Throwable $e) {
                    Log::error('Background task error: ' . $e->getMessage());
                }
                $elapsed = microtime(true) - $start;
                if ($elapsed > $tick['interval']) {
                    Log::warning(sprintf(
                        'Background task took %.2fs (interval: %.2fs) — this blocks the server. '
                        . 'Use non-blocking calls (e.g. $queue->pop() instead of $queue->consume()).',
                        $elapsed, $tick['interval']
                    ));
                }
            }
        }
        unset($tick);
    }

    // ── Hot Reload ─────────────────────────────────────────────────

    /**
     * Scan watched directories for file changes.
     * Compares filemtime() against stored map. Returns true if anything changed
     * and sets $this->phpChangeDetected to true if any .php file was among the
     * changes (used by onFilesChanged() to decide whether a full restart is needed).
     */
    private function detectFileChanges(): bool
    {
        $dirs = ['src', 'migrations'];
        $extensions = ['php', 'twig', 'html', 'scss', 'css', 'js', 'json'];
        $changed = false;
        $this->phpChangeDetected = false;

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
                    if ($ext === 'php') {
                        $this->phpChangeDetected = true;
                    }
                    Log::info("Hot reload: {$path} changed");
                }
                $this->fileMtimes[$path] = $mtime;
            }
        }

        return $changed;
    }

    /**
     * Called when file changes are detected.
     *
     * For template/CSS/JS/HTML edits: just broadcast a reload signal to the
     * browser. Frond re-reads templates in dev mode and static files are
     * served from disk each request, so nothing else is needed — and touching
     * the router would blow away the dev toolbar injection path.
     *
     * For .php file edits: log a warning. PHP cannot safely re-evaluate a
     * previously-included file (classes cannot be redeclared), and
     * include_once on an already-included file is a no-op, so the only
     * correct way to pick up PHP changes is a full process restart.
     */
    private function onFilesChanged(): void
    {
        if ($this->phpChangeDetected) {
            Log::warning(
                "Hot reload: .php file changed — PHP code changes require a full server restart. " .
                "Template/CSS/JS edits hot-reload automatically."
            );
        }

        // Notify all reload subscribers via WebSocket
        $this->broadcastReload();
    }

    /**
     * Send a reload signal to all connected dev clients via WebSocket.
     *
     * Emits the same {type, file, mtime} payload shape as the
     * POST /__dev/api/reload handler so both the toolbar client and the
     * dev-admin dashboard react identically. This path covers the idle-tick
     * fallback ($pendingReload) and the internal file watcher
     * (onFilesChanged) — file/mtime come from the last reported reload.
     */
    private function broadcastReload(): void
    {
        $message = json_encode([
            'type' => 'reload',
            'file' => DevAdmin::getReloadFile(),
            'mtime' => DevAdmin::getReloadMtime(),
        ]);
        $this->broadcastWebSocket($message, '/__dev_reload');
        Log::info("Hot reload: browser refresh sent to " . count($this->wsClients) . " client(s)");
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
