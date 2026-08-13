<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Server — Custom HTTP server with WebSocket support, for development.
 * Replaces `php -S`. Uses stream_socket_server + stream_select. Zero external
 * dependencies.
 *
 * CONCURRENT CONNECTIONS, AND - where the OS allows it - CONCURRENT EXECUTION.
 * The sockets are non-blocking and many connections are multiplexed through one
 * stream_select, so hundreds can be OPEN at once. Multiplexed I/O is not the
 * same property as concurrent HANDLING though, and the two used to be confused
 * here: processHttpBuffer() dispatched the route handler INLINE, so while a
 * handler ran the loop was not in stream_select and nothing else advanced.
 *
 * A handler that BLOCKED therefore froze the whole server for its duration.
 * Measured on 3.13.94 with `sleep(10)` at the top of a route: the slow route
 * answered correctly at 10.008s, and a trivial route requested one second later
 * took 8.999s instead of its usual 0.007s. In a browser that reads as "the
 * server is dead", because the favicon, the dev-toolbar poll and every asset
 * queue behind it. Nothing was hung and nothing timed out - it was strictly
 * serial, which is why the reporter saw "no response" from a route that was in
 * fact working perfectly.
 *
 * FORK PER REQUEST fixes that, and it is ON by default wherever pcntl and posix
 * exist - Linux and macOS, which is every platform this dev server is used on.
 * handleHttp() forks before dispatch: the child owns the accepted socket, runs
 * the handler, writes the response and dies; the parent drops its copy of the
 * client and goes straight back to stream_select. Same measurement after the
 * change: the trivial route answers in 0.004s while the 10s sleep is still
 * running, and the slow route still returns correctly at 10.006s.
 *
 * WHAT STAYS IN THE PARENT, and why /__dev is deliberately NOT forked. DevAdmin
 * keeps its message log and request inspector in PROCESS-STATIC arrays
 * (DevAdmin::$messages, DevAdmin::$requests), and the WebSocket registry
 * ($wsClients, used by the dev-reload broadcast) lives in this parent process.
 * A child's writes to those go to a copy that dies with it. shouldForkRequest()
 * therefore keeps every /__dev path in the parent, so the dashboard still fills
 * and hot reload still fires. A WebSocket upgrade never reaches the fork at all
 * - handleHttp() hands it to handleWebSocketUpgrade() and returns long before
 * that point - so $wsClients is only ever touched by this process. Application
 * routes have no such shared state; a child that mutates a static array was
 * already not sharing it with the next request.
 *
 * A forked child must not run the parent's shutdown work. endRequestChild()
 * leaves via posix_kill(SIGKILL) rather than exit(), because exit() runs every
 * registered shutdown function and destructor in a process that shares the
 * PARENT's file descriptors - see that method for what breaks. This is not
 * theoretical: the same trap was measured earlier in this codebase when 60
 * pcntl_fork children each ran the test bootstrap's temp-sandbox reaper and
 * deleted the directory the parent was still using. Ruby's at_exit + fork
 * behaves identically.
 *
 * SIGCHLD is reaped with a WNOHANG waitpid loop so a long-running dev session
 * cannot accumulate zombies (measured: 0 after the pair above).
 *
 * TINA4_SERVE_FORK=false restores the old serial behaviour. It is an escape
 * hatch, not a mode: reach for it when a debugger, a profiler or an xdebug
 * session needs every request in one pid. Where pcntl or posix is absent
 * (Windows, or a build without them) the server stays serial with no config,
 * exactly as before, and everything in the paragraph above about a blocking
 * handler applies again.
 *
 * Python's server still has the old property (asyncio, one loop: 9.004s under
 * the same test) and is the parity item owed here. Ruby's never did, because
 * Puma runs handlers on threads (0.0005s). PHP has no threads, and Fibers do
 * not help: sleep() never yields, so a Fiber blocks its thread exactly the same
 * way. Fork is the only mechanism PHP actually offers.
 *
 * Blocking work still belongs off the request path: `$app->background()` for
 * periodic work, `Tina4\Queue` for jobs. In production run behind php-fpm
 * (`tina4 serve --production`), which uses a real worker pool.
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
     * Seconds a client may stay silent before its connection is closed, when
     * TINA4_REQUEST_TIMEOUT says nothing usable. 0 disables the reaper.
     *
     * This bounds slowloris: a peer that opens a connection and dribbles, or
     * stops entirely, no longer holds a slot forever. It also closes idle
     * keep-alive connections, which is normal and what every other HTTP server
     * does (nginx keepalive_timeout defaults to 75s).
     */
    private const DEFAULT_REQUEST_TIMEOUT = 30;

    /**
     * Bytes of request headers accepted before answering 431, when
     * TINA4_MAX_REQUEST_HEADER says nothing usable.
     *
     * The read path appends to a string with no ceiling, so without this a
     * client streaming an endless header block grows it until the process dies.
     * 64KB is roughly what nginx (large_client_header_buffers) and Apache
     * (LimitRequestFieldSize) allow.
     */
    private const DEFAULT_MAX_REQUEST_HEADER = 65536;

    /**
     * Bytes of request BODY accepted before answering 413, when
     * TINA4_MAX_REQUEST_BODY says nothing usable. Matches the documented
     * TINA4_MAX_UPLOAD_SIZE default; this is the raw-socket layer beneath it.
     */
    private const DEFAULT_MAX_REQUEST_BODY = 10485760;

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

    /**
     * Serve each request in a forked child, so a blocking handler cannot freeze
     * the server. Resolved once at start(); false where pcntl is unavailable.
     */
    private bool $forkPerRequest = false;

    /** True inside a forked request child, so it exits instead of looping. */
    private bool $inRequestChild = false;

    /** True in the pool supervisor, which serves nothing. */
    private bool $isPoolParent = false;

    /** True in a pool worker, which serves everything. */
    private bool $isPoolWorker = false;

    /** @var array<int, true> Live worker pids, in the supervisor only. */
    private array $workerPids = [];

    /** Requests this worker has served, for TINA4_SERVE_MAX_REQUESTS. */
    private int $requestsServed = 0;

    /** Requests a worker serves before it is recycled; 0 disables. */
    private int $maxRequestsPerWorker = 0;

    /** @var array<int, resource> All connected client sockets */
    private array $clients = [];

    /** @var array<int, string> Read buffers keyed by socket resource ID */
    private array $buffers = [];

    /**
     * @var array<int, float> Last time each HTTP client sent us anything.
     *
     * Only WebSocket clients had this before, so an HTTP connection that opened
     * and never finished its request kept its slot and its buffer for the life
     * of the process. See reapIdleHttpClients().
     */
    private array $httpActivity = [];

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

    /** @var int Seconds of client silence tolerated; 0 disables the reaper. */
    private int $requestTimeout = self::DEFAULT_REQUEST_TIMEOUT;

    /** @var int Header bytes accepted before 431. */
    private int $maxRequestHeader = self::DEFAULT_MAX_REQUEST_HEADER;

    /** @var int Body bytes accepted before 413. */
    private int $maxRequestBody = self::DEFAULT_MAX_REQUEST_BODY;

    /**
     * @var int Running per-chunk upload cap (TINA4_MAX_UPLOAD_SIZE). Enforced on
     * the ACTUAL bytes received as they arrive, so a chunked or under-declared
     * over-size body is refused before it is buffered whole - the declared
     * Content-Length guard above cannot see that case.
     */
    private int $maxUploadSize = self::DEFAULT_MAX_REQUEST_BODY;

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
     * @param int    $port Port to listen on (default: 7145, matching the documented default in
     *   bin/tina4php and App::resolveBindPort — every real caller passes an explicit port, so this
     *   default only matters to a direct `new Server()`, but it must not disagree — DUALPORT-DEC-02).
     */
    public function __construct(?string $host = null, int $port = 7145)
    {
        if ($host === null || $host === '') {
            $envHost = DotEnv::getEnv('TINA4_HOST');
            $host = ($envHost !== null && $envHost !== '') ? $envHost : '0.0.0.0';
        }
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * Reclaim *port* from a stale Tina4 dev server via the shared, guarded path.
     *
     * This is the runtime bind-failure fallback. It used to SIGTERM whatever held
     * the port with NONE of the CLI's guards -- no identity check, no container
     * guard, no PID-safety filter -- so a foreign holder (another dev server, a
     * database) was killed on any bind failure. It now routes through the SAME
     * identity-checked helper the CLI uses (TAKEOVER-DEC-02), so only a
     * PID-file-confirmed Tina4 dev server is ever signalled.
     *
     * @throws \RuntimeException when the port is held by a non-Tina4 process (or
     *   takeover is opted out / disabled outside dev), so the bind fails loudly
     *   with a clear message instead of killing an innocent process.
     */
    private function freePort(int $port): void
    {
        $result = PortTakeover::takeOverPort(
            $port,
            PortTakeover::isDev(),
            PortTakeover::noTakeoverOptedOut()
        );
        if ($result['status'] === PortTakeover::KILLED) {
            echo "  {$result['message']}\n";
            return;
        }
        if (in_array($result['status'], PortTakeover::REFUSALS, true)) {
            throw new \RuntimeException($result['message']);
        }
        // NOTHING / container: nothing to reclaim -- let the real bind decide.
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
        // Record THIS process as the Tina4 dev server on the main port, so a
        // later `tina4 serve` can identify it as reclaimable (TAKEOVER-DEC-01).
        PortTakeover::writePidfile($this->port);
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
                    // stream_socket_server() returns FALSE on failure, not null.
                    // $this->aiSocket must stay exactly null (its declared default)
                    // on a skip, never false: acceptLoop()'s read-set builder tests
                    // `$this->aiSocket !== null`, and false !== null is true, so an
                    // unreset false was fed straight into stream_select() as an
                    // invalid stream resource -- crashing the WHOLE server (base
                    // port included) the instant the accept loop next ran. A busy
                    // AI port must warn and skip, never take the base port down
                    // with it (the opposite of takeover, feature 129).
                    $this->aiSocket = null;
                    echo "  Test Port: SKIPPED (port {$this->aiPort} in use)\n";
                }
            } catch (\Throwable $e) {
                $this->aiSocket = null;
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
            // Reap finished request children. Without this every served request
            // leaves a zombie and the process table fills. A handler is used
            // rather than SIG_IGN because SIG_IGN also discards the exit status
            // of proc_open()/exec() children an APPLICATION starts, which would
            // silently break any code that reads their return code.
            if (defined('SIGCHLD')) {
                pcntl_signal(SIGCHLD, static function () {
                    while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
                        // drain
                    }
                });
            }
        }

        $this->forkPerRequest = $this->resolveForkPerRequest();
        $this->requestTimeout = self::resolveLimit('TINA4_REQUEST_TIMEOUT', self::DEFAULT_REQUEST_TIMEOUT, true);
        $this->maxRequestHeader = self::resolveLimit('TINA4_MAX_REQUEST_HEADER', self::DEFAULT_MAX_REQUEST_HEADER);
        $this->maxRequestBody = self::resolveLimit('TINA4_MAX_REQUEST_BODY', self::DEFAULT_MAX_REQUEST_BODY);
        $this->maxUploadSize = self::resolveLimit('TINA4_MAX_UPLOAD_SIZE', self::DEFAULT_MAX_REQUEST_BODY);
        $this->maxRequestsPerWorker = self::resolveLimit('TINA4_SERVE_MAX_REQUESTS', 0, true);


        // ONE process or MANY. TINA4_SERVE_WORKERS=1 (the default) keeps the
        // single-process server every existing deployment already runs; more
        // than one pre-forks a pool of long-lived workers that each accept on
        // this same listening socket.
        $workers = $this->resolveWorkerCount();
        if ($workers > 1) {
            $this->runWorkerPool($workers);
            $this->cleanup();
            return;
        }

        $this->acceptLoop();
        $this->cleanup();
    }

    /**
     * Accept and serve until stopped.
     *
     * Extracted from start() so a pool worker can run the same loop the
     * single-process server runs. There is deliberately ONE accept loop: a
     * second implementation for workers would drift from this one, and the
     * whole point of the pool is that a worker serves requests identically.
     */
    private function acceptLoop(): void
    {
            while ($this->running) {
                // Dispatch pending signals
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }

                // Recycle this worker once it has served its quota. Checked here,
            // between connections, so an in-flight response is never cut off.
            // The supervisor replaces it immediately, which is what makes this
            // safe: the pool never drops below strength.
            if ($this->isPoolWorker
                && $this->maxRequestsPerWorker > 0
                && $this->requestsServed >= $this->maxRequestsPerWorker
            ) {
                break;
            }

            // A forked request child must NEVER reach this loop. It has
                // already dropped its listening socket, so $read would start with
                // null and stream_select() would die with "supplied argument is
                // not a valid stream resource" - taking the process with it.
                //
                // This is DEFENSIVE, and honestly labelled as such: an intermittent
                // crash with exactly that signature was seen in the concurrency
                // suite, but its cause is NOT established. The obvious suspect was
                // an exception unwinding past handleHttp()'s two endRequestChild()
                // calls; that was tested and is wrong - the framework catches a
                // throwing handler, answers 500, and the child exits normally
                // (measured: /boom -> 500, then /fast -> 200, server healthy).
                //
                // The guard stays because the invariant is true regardless of how
                // a child might get here, and it costs one boolean per iteration.
                // Do not treat it as a fix for the flake until the flake is
                // reproduced.
                if ($this->inRequestChild) {
                    $this->endRequestChild();
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
                    $this->reapIdleHttpClients();

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
                            $this->httpActivity[$resourceId] = microtime(true);
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
                            $this->httpActivity[$resourceId] = microtime(true);
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
                            $this->httpActivity[$resourceId] = microtime(true);
                            if (!$this->enforceRequestLimits($socket)) {
                                continue;   // over the cap: answered and closed
                            }
                            $this->processHttpBuffer($socket);
                        }
                    }
                }
            }
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
        // Drop our identity marker so a later takeover does not match a dead PID.
        PortTakeover::removePidfile($this->port);

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

        // Recycle bookkeeping. Counted here, at the top, so EVERY request the
        // worker accepted is counted - including ones that end early. A leak
        // does not care how the request finished.
        if ($this->isPoolWorker) {
            $this->requestsServed++;
        }

        // ── Serve this request in a child, so a blocking handler cannot freeze
        // the server ────────────────────────────────────────────────────────
        //
        // Everything below this point may block for as long as the handler
        // wants: sleep(), a slow query, a remote call. In one process that
        // stalls stream_select and therefore every other connection. Measured
        // before this existed: sleep(10) in one route made a trivial route take
        // 8.999s instead of 0.007s.
        //
        // The fork is here, not at accept(), because the two things that MUST
        // stay in the parent are only knowable once the request is parsed: a
        // WebSocket upgrade (handled and returned above) and /__dev (see
        // shouldForkRequest).
        if ($this->shouldForkRequest($path)) {
            $pid = @pcntl_fork();
            if ($pid > 0) {
                // Parent: the child owns this connection now. Closing our
                // descriptor does NOT close the connection - the child holds
                // its own copy - it just stops us reading a socket somebody
                // else is answering on.
                $this->removeClient($client);
                return;
            }
            if ($pid === 0) {
                $this->inRequestChild = true;
                // Children must not run the parent's signal handlers: a SIGINT
                // to the process group would otherwise have every in-flight
                // child try to run stop() on the parent's socket list.
                if (function_exists('pcntl_signal') && defined('SIGTERM')) {
                    pcntl_signal(SIGTERM, SIG_DFL);
                    pcntl_signal(SIGINT, SIG_DFL);
                    if (defined('SIGCHLD')) {
                        pcntl_signal(SIGCHLD, SIG_DFL);
                    }
                }
                $this->dropInheritedSockets($client);
            }
            // $pid === -1 (fork failed, e.g. process limit): fall through and
            // serve it in the parent. A slow response beats a dropped one.
        }

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
            $this->endRequestChild();
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

        $this->endRequestChild();
    }

    /**
     * Should this request be served in its own process?
     *
     * WebSocket upgrades never reach here - handleHttp() returns before the
     * fork - so the parent keeps every live socket and hot reload still works.
     *
     * /__dev is excluded on purpose. Those routes READ AND WRITE parent state:
     * DevAdmin::$pendingReload is set by POST /__dev/api/reload and consumed by
     * the accept loop, and the dashboard reports on the parent's own sockets. A
     * child would set the flag on a copy that is discarded a millisecond later,
     * so live reload would stop working with nothing to show for it. These
     * requests never block for long, so serving them inline costs nothing.
     */
    private function shouldForkRequest(string $path): bool
    {
        if (!$this->forkPerRequest) {
            return false;
        }
        if (str_starts_with($path, '/__dev')) {
            return false;
        }
        return true;
    }

    /**
     * Decide once whether per-request forking is available.
     *
     * ON by default wherever the platform can do it, which is Linux and macOS.
     * Windows has no fork, so it keeps the previous serial behaviour and the
     * blocking-handler caveat with it - there is no way to give it the same
     * guarantee from PHP.
     *
     * TINA4_SERVE_FORK=false is an escape hatch for the one case forking is
     * genuinely wrong: a handler that mutates process state a LATER request is
     * meant to see (an in-memory cache or session store built inside the
     * server). That state now dies with the child.
     */
    private function resolveForkPerRequest(): bool
    {
        $raw = DotEnv::getEnv('TINA4_SERVE_FORK');
        if ($raw !== null && $raw !== '') {
            if (!DotEnv::isTruthy($raw)) {
                return false;
            }
        }

        return function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('posix_kill')
            && function_exists('posix_getpid');
    }

    /**
     * How many worker processes to pre-fork. 1 means the single-process server.
     *
     * Default is 1, so nothing any existing deployment does changes. The pool
     * is opt-in because it is not free: each worker is a separate process with
     * its OWN copy of anything the framework keeps in a static, and three of
     * those matter.
     *
     *   - DevAdmin's message log and request inspector. The dashboard would
     *     show you one worker's traffic and nothing else.
     *   - DevAdmin::$pendingReload, set by POST /__dev/api/reload and read by
     *     the accept loop. One worker would reload; the rest would not.
     *   - $wsClients. A WebSocket lives in whichever worker accepted it, so a
     *     broadcast reaches that worker's clients only, unless
     *     TINA4_WS_BACKPLANE is configured to carry it between them.
     *
     * None of that matters in production, where TINA4_DEBUG is false and the
     * dashboard is off. All of it matters in development, so the pool REFUSES
     * to start in debug mode rather than quietly breaking the dashboard and
     * hot reload - a developer would blame the framework, correctly.
     */
    private function resolveWorkerCount(): int
    {
        $workers = self::resolveLimit('TINA4_SERVE_WORKERS', 1);
        if ($workers <= 1) {
            return 1;
        }

        if (!$this->canForkWorkers()) {
            Log::warning(
                'TINA4_SERVE_WORKERS is set but pcntl/posix are unavailable, so a worker '
                . 'pool cannot be started - serving from one process instead'
            );
            return 1;
        }

        if ($this->isDebug) {
            Log::warning(
                'TINA4_SERVE_WORKERS is ignored while TINA4_DEBUG is true: the dev '
                . 'dashboard, hot reload and the WebSocket registry are per-process, and '
                . 'a pool would silently break all three. Serving from one process.'
            );
            return 1;
        }

        return $workers;
    }

    /** Everything the pool needs from the OS. */
    private function canForkWorkers(): bool
    {
        return function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('pcntl_signal')
            && function_exists('posix_kill')
            && function_exists('posix_getpid');
    }

    /**
     * Pre-fork $count workers and supervise them until shutdown.
     *
     * The classic Unix pre-fork model, and the same one php-fpm, nginx, Puma
     * and Gunicorn use: the parent binds the listening socket ONCE, forks
     * workers that inherit it, and every worker runs accept() on that same
     * socket. The kernel decides which worker gets each connection, so there is
     * no dispatcher to become a bottleneck and no socket passing.
     *
     * This replaces fork-per-request for pooled deployments, and that is the
     * whole point: fork-per-request paid a fork() for every single request,
     * which measured 893 req/s against php-fpm's 1526 on the same box. A worker
     * is forked once and serves thousands.
     *
     * The parent serves NOTHING. It waits, restarts a worker that dies, and
     * forwards shutdown. Keeping it out of the request path means a wedged
     * request can never take the supervisor with it.
     */
    private function runWorkerPool(int $count): void
    {
        $this->isPoolParent = true;

        // The parent must not run the per-request fork path, and neither must
        // the workers: the pool IS the concurrency now.
        $this->forkPerRequest = false;

        // TAKE BACK SIGCHLD. start() installs a handler that blind-drains every
        // exited child with waitpid(-1, WNOHANG) - correct for fork-per-request,
        // where the children are throwaway request handlers nobody waits for,
        // and fatal here: it consumed each worker's death before the supervisor
        // loop below could see it, so nothing was ever respawned.
        //
        // Measured before this line existed: kill one worker of four and the
        // pool stays at three forever; run 60 requests with
        // TINA4_SERVE_MAX_REQUESTS=5 and the pool drains to ZERO while still
        // looking alive.
        //
        // SIG_DFL leaves an exited worker as a zombie until the supervisor's own
        // pcntl_waitpid() collects it, which is exactly the handoff we want.
        // There are no request children to reap here - forkPerRequest is off.
        if (function_exists('pcntl_signal') && defined('SIGCHLD')) {
            pcntl_signal(SIGCHLD, SIG_DFL);
        }

        for ($i = 0; $i < $count; $i++) {
            $this->spawnWorker();
        }

        Log::info(sprintf('Worker pool: %d workers on %s:%d', $count, $this->host, $this->port));

        while ($this->running) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // Reap and replace. A worker that dies - crashed, OOM-killed, or
            // recycled at TINA4_SERVE_MAX_REQUESTS - is replaced immediately,
            // so the pool stays at strength without operator involvement.
            while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
                unset($this->workerPids[$pid]);
                if ($this->running) {
                    $this->spawnWorker();
                }
            }

            usleep(100000);   // 100ms: the parent has nothing to be fast about
        }

        $this->stopWorkers();
    }

    /**
     * Fork one worker, which runs the ordinary accept loop and never returns.
     */
    private function spawnWorker(): void
    {
        $pid = @pcntl_fork();

        if ($pid > 0) {
            $this->workerPids[$pid] = true;
            return;
        }

        if ($pid < 0) {
            Log::error('Could not fork a pool worker; the pool is running under strength');
            return;
        }

        // ── child ──────────────────────────────────────────────────────────
        $this->isPoolParent = false;
        $this->isPoolWorker = true;
        $this->workerPids = [];
        $this->requestsServed = 0;

        // Default signal disposition: the parent decides when the pool stops,
        // and forwards the signal. A worker inheriting the parent's handler
        // would try to supervise a pool it does not own.
        if (defined('SIGTERM')) {
            pcntl_signal(SIGTERM, function () { $this->running = false; });
            pcntl_signal(SIGINT, SIG_DFL);
            if (defined('SIGCHLD')) {
                pcntl_signal(SIGCHLD, SIG_DFL);
            }
        }

        $this->acceptLoop();
        $this->cleanup();
        exit(0);
    }

    /**
     * Stop every worker, then wait for them.
     *
     * SIGTERM first so a worker finishes what it is holding - the accept loop
     * checks $running between connections, and the drain in stop() bounds how
     * long that may take. SIGKILL only for anything still alive after the
     * grace period, because a stuck worker must not hold the port open for the
     * next deploy.
     */
    private function stopWorkers(): void
    {
        foreach (array_keys($this->workerPids) as $pid) {
            @posix_kill($pid, SIGTERM);
        }

        $deadline = microtime(true) + $this->shutdownTimeout;
        while ($this->workerPids !== [] && microtime(true) < $deadline) {
            while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
                unset($this->workerPids[$pid]);
            }
            usleep(50000);
        }

        foreach (array_keys($this->workerPids) as $pid) {
            Log::warning(sprintf('Worker %d did not stop within %ds - killing it', $pid, $this->shutdownTimeout));
            @posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
            unset($this->workerPids[$pid]);
        }
    }

    /**
     * Resolve a numeric limit from the environment, falling back to a default.
     *
     * A non-numeric or negative value is refused with a named warning rather
     * than silently becoming 0, because 0 means "no limit" for two of these
     * three and a typo must never be the thing that disables a DoS guard.
     *
     * @param bool $zeroAllowed True when 0 is a legitimate "disabled" value.
     */
    private static function resolveLimit(string $name, int $default, bool $zeroAllowed = false): int
    {
        $raw = DotEnv::getEnv($name);
        if ($raw === null || trim($raw) === '') {
            return $default;
        }
        if (!is_numeric($raw)) {
            Log::warning(sprintf('%s=%s is not a number - using %d', $name, $raw, $default));
            return $default;
        }
        $value = (int)$raw;
        if ($value < 0 || ($value === 0 && !$zeroAllowed)) {
            Log::warning(sprintf('%s=%s is not a usable limit - using %d', $name, $raw, $default));
            return $default;
        }
        return $value;
    }

    /**
     * Close HTTP connections that have gone silent past TINA4_REQUEST_TIMEOUT.
     *
     * WebSocket clients are skipped: they are long-lived by design and have
     * their own reaper on TINA4_WS_IDLE_TIMEOUT. Everything else is a request
     * in flight, and a request in flight that has stopped speaking is either a
     * dead peer or a slowloris. Neither deserves a slot.
     *
     * A partial request gets a 408 first, so a merely slow client learns why;
     * a connection with nothing buffered is closed silently, because there is
     * no request to answer.
     */
    private function reapIdleHttpClients(): void
    {
        if ($this->requestTimeout <= 0) {
            return;
        }
        $now = microtime(true);
        foreach ($this->httpActivity as $resourceId => $last) {
            if (isset($this->wsSocketMap[$resourceId])) {
                continue;   // WebSockets have their own idle policy
            }
            if (($now - $last) < $this->requestTimeout) {
                continue;
            }
            $socket = $this->clients[$resourceId] ?? null;
            if (!is_resource($socket)) {
                unset($this->httpActivity[$resourceId]);
                continue;
            }
            if (($this->buffers[$resourceId] ?? '') !== '') {
                $this->sendHttpError($socket, 408, 'Request timed out before it was complete');
            }
            $this->removeClient($socket);
        }
    }

    /**
     * Refuse a request that is growing past what we agreed to read.
     *
     * The read path appends to $buffers with no ceiling of its own, and
     * processHttpBuffer() returns "not enough data yet" without looking at the
     * size, so between them a client could grow one string until the process
     * died. This is the ceiling.
     *
     * Headers are capped before they are complete (431) and the body is capped
     * from the declared Content-Length as soon as the headers ARE complete
     * (413), so an oversized upload is refused on its first packet rather than
     * after we have buffered all of it.
     *
     * @return bool False when the connection was answered and closed.
     */
    private function enforceRequestLimits($client): bool
    {
        $resourceId = (int)$client;
        $buffer = $this->buffers[$resourceId] ?? '';
        $headerEnd = strpos($buffer, "\r\n\r\n");

        if ($headerEnd === false) {
            if (strlen($buffer) > $this->maxRequestHeader) {
                $this->sendHttpError($client, 431, 'Request header fields too large');
                $this->removeClient($client);
                return false;
            }
            return true;
        }

        if ($this->maxRequestBody > 0
            && preg_match('/content-length:\s*(\d+)/i', substr($buffer, 0, $headerEnd), $m)
            && (int)$m[1] > $this->maxRequestBody
        ) {
            $this->sendHttpError($client, 413, 'Request body too large');
            $this->removeClient($client);
            return false;
        }

        // Running per-chunk counter: refuse the moment the ACTUAL body bytes
        // received exceed TINA4_MAX_UPLOAD_SIZE, regardless of - or in the
        // absence of - a declared Content-Length. This closes the chunked /
        // under-declared over-size bypass the declared-length check above
        // cannot see, and it fires as the bytes arrive rather than after the
        // whole body is buffered. Parity with Python/Node, which run the same
        // running counter in their body readers.
        if ($this->maxUploadSize > 0
            && (strlen($buffer) - ($headerEnd + 4)) > $this->maxUploadSize
        ) {
            $this->sendHttpError($client, 413, 'Request body too large');
            $this->removeClient($client);
            return false;
        }

        return true;
    }

    /**
     * Close every socket a forked child inherited but does not own.
     *
     * A child gets a COPY of every open descriptor, and a socket stays open
     * until the LAST copy closes. Two things break if it keeps them:
     *
     * 1. The LISTENING sockets. The parent closing its copy on SIGTERM does not
     *    free the port while any child still holds one - the kernel keeps the
     *    listen queue, so a connection arriving after shutdown is ACCEPTED and
     *    then RESET instead of getting a clean CONNECTION REFUSED, and the port
     *    stays bound until the last child exits. Both symptoms were measured on
     *    the first version of the fork: GracefulShutdownTest saw
     *    "accepted-then-reset" and reported port 39785 still held after the run.
     *
     * 2. Every OTHER in-flight client. The parent answers and closes those, but
     *    the peer sees no FIN while this child holds a copy, so an unrelated
     *    request hangs for as long as this one runs - which is precisely the
     *    blocking the fork exists to remove.
     *
     * @param resource $keep The accepted socket this child is answering on.
     */
    private function dropInheritedSockets($keep): void
    {
        foreach ($this->clients as $id => $client) {
            if ($client === $keep) {
                continue;   // the one we are answering on stays registered
            }
            if (is_resource($client)) {
                @fclose($client);
            }
            unset($this->clients[$id], $this->buffers[$id]);
        }

        foreach (['socket', 'aiSocket'] as $listener) {
            if (is_resource($this->{$listener})) {
                @fclose($this->{$listener});
            }
            $this->{$listener} = null;
        }
    }

    /**
     * End a forked request child, immediately and without cleanup.
     *
     * SIGKILL to self rather than exit(), and that is deliberate. exit() runs
     * destructors and shutdown functions, and this process shares the parent's
     * file descriptors: a database destructor here would send a real QUIT on
     * the connection the PARENT is still using, killing a session it believes
     * it owns. The response bytes are already in the kernel's send buffer by
     * this point (writeFully returned), and killing a process does not discard
     * what the kernel has accepted, so the client still gets its answer.
     */
    private function endRequestChild(): void
    {
        if (!$this->inRequestChild) {
            return;
        }
        posix_kill(posix_getpid(), SIGKILL);
    }

    /**
     * Render the Set-Cookie header lines for a response.
     *
     * setcookie() writes into the SAPI header list, which is never sent on a
     * raw socket, so the socket server serialises $response->cookie() itself
     * — via the one shared value-builder, Response::cookieHeaderLines(), so a
     * cookie set twice renders identically here and through TestClient
     * (feature 131, TC-DEC-02).
     *
     * @return string Zero or more "Set-Cookie: ...\r\n" lines.
     */
    private static function cookieHeaderLines(Response $response): string
    {
        $lines = '';
        foreach ($response->cookieHeaderLines() as $cookie) {
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
        unset($this->httpActivity[$resourceId]);
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
        unset($this->httpActivity[$resourceId]);
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
                $descriptor = [
                    'fieldName' => $name,
                    'filename' => $filename,
                    'type' => $fileType,
                    'content' => $content,  // raw binary
                    'size' => strlen($content),
                ];
                // Repeated field name -> collect ALL descriptors into a list;
                // never silently keep only the last (the multi-file data-loss
                // bug). A single occurrence stays a plain descriptor. A single
                // descriptor carries a 'filename' key; a list of them does not.
                if (isset($files[$name])) {
                    if (isset($files[$name]['filename'])) {
                        $files[$name] = [$files[$name], $descriptor];
                    } else {
                        $files[$name][] = $descriptor;
                    }
                } else {
                    $files[$name] = $descriptor;
                }
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
        408 => 'Request Timeout',
        409 => 'Conflict', 410 => 'Gone', 413 => 'Content Too Large',
        415 => 'Unsupported Media Type', 422 => 'Unprocessable Content',
        429 => 'Too Many Requests', 431 => 'Request Header Fields Too Large',
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
