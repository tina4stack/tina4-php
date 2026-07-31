<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Feature 9 — graceful shutdown. The PHP quarter of the cross-framework
 * contract; the case names below are IDENTICAL in tina4-python, tina4-ruby and
 * tina4-nodejs on purpose, so the four suites can be read side by side.
 *
 * The contract:
 *   1. SIGTERM and SIGINT are trapped and run the same graceful shutdown.
 *   2. SIGHUP is NOT trapped — deliberate. The Rust CLI owns file watching and
 *      production logs go to stdout, so neither Puma's log-reopen nor
 *      gunicorn's config-reload use for SIGHUP is a Tina4 need.
 *   3. The listening socket stops accepting FIRST. A connection arriving after
 *      the signal gets a clean CONNECTION REFUSED — not a 503, not a TCP reset.
 *   4. An in-flight request runs to completion and its full response is written.
 *   5. The drain is bounded by TINA4_SHUTDOWN_TIMEOUT (default 30s).
 *   6. Exit code 0 on a handled signal.
 *
 * NO MOCKS. Every case spawns a REAL php process running the REAL Tina4 socket
 * server (tests/fixtures/graceful_shutdown_server.php), issues a REAL slow HTTP
 * request over a REAL socket, sends a REAL POSIX signal to that process, and
 * observes the REAL outcome and the REAL exit status. Nothing here calls a
 * handler function directly — that would test PHP's ability to call a closure,
 * not Tina4's ability to shut down.
 *
 * PROCESS HYGIENE. proc_open is given the ARRAY form, so there is no `sh -c`
 * layer and proc_get_status()['pid'] IS the php server. The exact pid is
 * signalled and, in teardown, escalated TERM -> KILL until the PORT is proven
 * free. The process GROUP is deliberately NOT signalled: with no shell in
 * between, the child sits in the TEST RUNNER's own group, so a negative-pid
 * kill would take phpunit down with it. fd 1 and 2 point at a real FILE — an
 * inherited descriptor makes a `phpunit | tee` pipeline hang forever even after
 * phpunit has printed its summary (see TestServer's docblock for the full
 * post-mortem).
 */

use PHPUnit\Framework\TestCase;
use Tina4\WebSocket;

class GracefulShutdownTest extends TestCase
{
    /** How long the /slow route really blocks, in seconds. */
    private const SLOW_SECONDS = 2.0;

    /** How far into the slow request the signal is sent. */
    private const SIGNAL_DELAY_SECONDS = 0.6;

    /** Upper bound on a graceful shutdown for the exit-status cases. */
    private const EXIT_WAIT_SECONDS = 15.0;

    /** @var resource|null */
    private $process = null;

    private int $serverPid = 0;

    private int $serverPort = 0;

    private string $stateFile = '';

    private string $logFile = '';

    /** @var array<string, mixed>|null Final status, cached the moment the process stops. */
    private ?array $finalStatus = null;

    /** @var list<int> Every port handed to a fixture server, re-checked in tearDownAfterClass. */
    private static array $portsUsed = [];

    protected function setUp(): void
    {
        if (!function_exists('posix_kill') || !function_exists('pcntl_signal')) {
            self::markTestSkipped('ext-posix and ext-pcntl are required to signal a real server');
        }
        $unique = bin2hex(random_bytes(6));
        $this->stateFile = sys_get_temp_dir() . '/tina4_shutdown_' . $unique . '.state';
        $this->logFile = sys_get_temp_dir() . '/tina4_shutdown_' . $unique . '.log';
    }

    protected function tearDown(): void
    {
        $this->reapServer();
        foreach ([$this->stateFile, $this->logFile, $this->stateFile . '.sqlite'] as $file) {
            if ($file !== '' && file_exists($file)) {
                @unlink($file);
            }
        }
    }

    // ── the nine contract cases ────────────────────────────────────────────

    /** SIGTERM lets the in-flight request finish */
    public function testSigtermLetsTheInFlightRequestFinish(): void
    {
        $this->assertInFlightRequestSurvives(SIGTERM);
    }

    /** SIGTERM stops accepting new connections */
    public function testSigtermStopsAcceptingNewConnections(): void
    {
        $this->startServer();
        $inFlight = $this->beginSlowRequest();
        usleep((int)(self::SIGNAL_DELAY_SECONDS * 1_000_000));

        $this->signalServer(SIGTERM);
        // Give the handler a beat to run, then knock on the door. The in-flight
        // request is still being served at this point, which is exactly the
        // window in which a listener left open accepts into the kernel backlog
        // and then resets the client.
        usleep(150000);
        $verdict = $this->probeNewConnection();

        $this->readResponse($inFlight);

        $this->assertStringStartsWith(
            'refused',
            $verdict,
            "a connection arriving after the signal must get a clean CONNECTION REFUSED; got: {$verdict}"
        );
    }

    /** SIGTERM exits with code 0 */
    public function testSigtermExitsWithCodeZero(): void
    {
        $this->assertSignalExitsCleanly(SIGTERM);
    }

    /** SIGTERM releases the listening port */
    public function testSigtermReleasesTheListeningPort(): void
    {
        $this->startServer();
        $port = $this->serverPort;
        $inFlight = $this->beginSlowRequest();
        usleep((int)(self::SIGNAL_DELAY_SECONDS * 1_000_000));
        $this->signalServer(SIGTERM);
        $this->readResponse($inFlight);
        $this->awaitExit();

        $rebound = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
        $this->assertIsResource(
            $rebound,
            "the port must be released on shutdown; rebinding {$port} failed: {$errstr} ({$errno})"
        );
        fclose($rebound);
    }

    /** SIGINT lets the in-flight request finish */
    public function testSigintLetsTheInFlightRequestFinish(): void
    {
        $this->assertInFlightRequestSurvives(SIGINT);
    }

    /** SIGINT exits with code 0 */
    public function testSigintExitsWithCodeZero(): void
    {
        $this->assertSignalExitsCleanly(SIGINT);
    }

    /**
     * SIGHUP is not trapped and terminates the process
     *
     * Pins the deliberate non-handling so nobody "fixes" it by accident: the
     * process must be gone AND killed BY the signal, not exited cleanly.
     */
    public function testSighupIsNotTrappedAndTerminatesTheProcess(): void
    {
        $this->startServer();
        $this->signalServer(SIGHUP);
        $status = $this->awaitExit();

        $this->assertTrue(
            (bool)$status['signaled'],
            'SIGHUP must kill the process outright, not run a handler; the process exited with code '
            . var_export($status['exitcode'], true)
        );
        $this->assertSame(
            SIGHUP,
            $status['termsig'],
            'the process must be terminated by SIGHUP itself'
        );
    }

    /**
     * a registered background task does not block shutdown
     *
     * PHP's background tasks are tick callbacks on the server event loop
     * (Server::onTick / Server::tickCallbackCount), registered through
     * App::background(). A live one must not keep the loop alive past a signal.
     */
    public function testARegisteredBackgroundTaskDoesNotBlockShutdown(): void
    {
        $this->startServer(flags: ['background']);

        // Prove the task is really registered on the real loop before signalling,
        // so a no-op fixture can never make this case pass vacuously.
        $ticked = false;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            if (str_contains($this->readState(), 'tick 1')) {
                $ticked = true;
                break;
            }
            usleep(50000);
        }
        $this->assertTrue($ticked, 'the real server must report exactly one registered tick callback: ' . $this->readState());

        $started = microtime(true);
        $this->signalServer(SIGTERM);
        $status = $this->awaitExit();
        $elapsed = microtime(true) - $started;

        $this->assertSame(0, $status['exitcode'], 'a background task must not change the exit code');
        $this->assertLessThan(
            5.0,
            $elapsed,
            "a registered background task must not hold the process open; shutdown took {$elapsed}s"
        );
    }

    /**
     * TINA4_SHUTDOWN_TIMEOUT bounds the drain
     *
     * Booted with TINA4_SHUTDOWN_TIMEOUT=1 against a 5s handler and signalled
     * mid-request: the process must exit in well under 5s. The in-flight request
     * is expected to be cut short here — that is the point of a bound.
     */
    public function testShutdownTimeoutBoundsTheDrain(): void
    {
        $this->startServer(slowSeconds: 5.0, env: ['TINA4_SHUTDOWN_TIMEOUT' => '1']);
        $inFlight = $this->beginSlowRequest();
        usleep((int)(self::SIGNAL_DELAY_SECONDS * 1_000_000));

        $started = microtime(true);
        $this->signalServer(SIGTERM);
        $status = $this->awaitExit();
        $elapsed = microtime(true) - $started;
        @fclose($inFlight);

        $this->assertLessThan(
            3.0,
            $elapsed,
            "TINA4_SHUTDOWN_TIMEOUT=1 must bound the drain of a 5s handler; the process took {$elapsed}s "
            . '(an unbounded drain waits for the whole handler)'
        );
        $this->assertSame(0, $status['exitcode'], 'a bounded shutdown still exits 0');
    }

    // ── the rest of the contract ───────────────────────────────────────────

    /**
     * A live WebSocket gets RFC 6455 close code 1001 ("going away").
     *
     * A real socket, a real handshake, a real signal, and the real bytes the
     * server puts on the wire — decoded here rather than trusted.
     */
    public function testALiveWebSocketIsToldItIsGoingAway(): void
    {
        $this->startServer();
        $webSocket = $this->openWebSocket();

        $this->signalServer(SIGTERM);
        $frame = $this->readUntilClose($webSocket, 8.0);
        @fclose($webSocket);
        $this->awaitExit();

        $this->assertNotSame('', $frame, 'the server must send a close frame, not just drop the socket');
        $this->assertSame(
            WebSocket::OP_CLOSE,
            ord($frame[0]) & 0x0F,
            'the first frame after the signal must be a close frame'
        );

        $payloadLength = ord($frame[1]) & 0x7F;
        $this->assertGreaterThanOrEqual(2, $payloadLength, 'a close frame must carry a close code');
        $closeCode = unpack('n', substr($frame, 2, 2))[1];
        $this->assertSame(
            WebSocket::CLOSE_GOING_AWAY,
            $closeCode,
            "a shutting-down server must send close code 1001 (going away); got {$closeCode}"
        );
    }

    /**
     * An invalid TINA4_SHUTDOWN_TIMEOUT warns and falls back to 30 — never a silent 0.
     *
     * The negative half of the bound: a rejected value must not become
     * pcntl_alarm(0), which CANCELS the alarm and leaves the drain unbounded,
     * nor an immediate force-close that truncates the in-flight response.
     */
    public function testAnInvalidShutdownTimeoutWarnsAndKeepsTheDefault(): void
    {
        $this->startServer(env: ['TINA4_SHUTDOWN_TIMEOUT' => '-5']);
        $inFlight = $this->beginSlowRequest();
        usleep((int)(self::SIGNAL_DELAY_SECONDS * 1_000_000));
        $this->signalServer(SIGTERM);
        $response = $this->readResponse($inFlight);
        $status = $this->awaitExit();
        $log = $this->readLog();

        $this->assertStringContainsString(
            'TINA4_SHUTDOWN_TIMEOUT=-5',
            $log,
            "the rejected value must be named in the warning; log: {$log}"
        );
        $this->assertStringContainsString(
            'using 30s',
            $log,
            "the warning must name the fallback it used; log: {$log}"
        );
        $this->assertStringContainsString(
            'slow-done',
            $response,
            'a rejected timeout must fall back to 30s, never silently to 0 — the drain still has to happen'
        );
        $this->assertSame(0, $status['exitcode']);
    }

    /**
     * The database connection is really closed during shutdown, and resiliently.
     *
     * SQLite keeps its -wal/-shm sidecars for exactly as long as a connection is
     * open, so the fixture's shutdown callback — which runs after the server's
     * cleanup() — can see from inside the live process whether the connection was
     * genuinely released rather than merely abandoned to process exit.
     */
    public function testTheDatabaseConnectionIsClosedDuringShutdown(): void
    {
        $this->startServer(flags: ['database']);
        $this->assertStringContainsString(
            'database-open',
            $this->readState(),
            'the fixture must really open a database before this proves anything'
        );

        $this->signalServer(SIGTERM);
        $status = $this->awaitExit();
        $state = $this->readState();

        $this->assertStringContainsString(
            'database-still-open no',
            $state,
            "shutdown must close the database connection, not leave it to process exit; state: {$state}"
        );
        $this->assertStringContainsString(
            'second-close-survived',
            $state,
            'closing an already-closed connection must be logged at worst, never fatal'
        );
        $this->assertSame(
            0,
            $status['exitcode'],
            'a database failure during shutdown must never change the exit code; log: ' . $this->readLog()
        );
    }

    /**
     * an embedded App without an event loop is still killable by SIGTERM
     *
     * The severest case in this file, and the reason App::registerSignalHandlers()
     * was deleted rather than merely bypassed. A handler installed at App
     * CONSTRUCTION suppresses the default terminate action, but its PHP callback
     * only ever runs from pcntl_signal_dispatch() — which the server event loop
     * calls and an embedder does not. The result was a process that IGNORED
     * SIGTERM outright: `kill` and `docker stop` became no-ops with no workaround
     * available to the embedder.
     *
     * This is the documented integration shape (App::__invoke for Swoole /
     * RoadRunner / FrankenPHP / ReactPHP) and the shape of the scaffolded
     * index.php, so it is not a hypothetical. Server::start() is the ONLY place
     * allowed to trap signals; anything that registers one earlier has to keep
     * this green.
     */
    public function testAnEmbeddedAppWithoutAnEventLoopIsStillKillableBySigterm(): void
    {
        $this->startEmbeddedApp();

        $started = microtime(true);
        $this->signalServer(SIGTERM);
        $status = $this->awaitExit(8.0, 'an embedded App with no event loop');
        $elapsed = microtime(true) - $started;

        $this->assertTrue(
            (bool)$status['signaled'],
            'an App with no event loop must still die on SIGTERM — it exited with code '
            . var_export($status['exitcode'], true)
            . ', which means a handler swallowed the signal and the process ran on'
        );
        $this->assertSame(
            SIGTERM,
            $status['termsig'],
            'the process must be terminated by SIGTERM itself'
        );
        $this->assertStringNotContainsString(
            'SURVIVED-SIGTERM',
            $this->readState(),
            'the embedded App outlived a SIGTERM and finished its loop — the process was unkillable'
        );
        $this->assertLessThan(
            3.0,
            $elapsed,
            "SIGTERM must take effect at once; the process lingered {$elapsed}s"
        );
    }

    /**
     * TINA4_DEFAULT_WEBSERVER is accepted and changes nothing in PHP.
     *
     * The env surface is uniform across all four frameworks, but this one only
     * SWITCHES anything where there is a third-party server to hand off to
     * (Python -> uvicorn, Ruby -> Puma). PHP always serves from its own built-in
     * server, so the setting is a genuine no-op here — and "no-op" has to mean
     * boots and serves exactly as before, not "ignored because boot failed".
     *
     * Lives with the server-lifecycle harness because it needs a REAL booted
     * Tina4 server, and this file owns the only one; duplicating the spawn/reap
     * machinery into an env-only test file would be the worse trade.
     */
    public function testDefaultWebserverEnvIsAcceptedAndStillServes(): void
    {
        // startServer() only returns once the real server has answered /ping,
        // so reaching this line already proves the setting did not break boot.
        $this->startServer(env: ['TINA4_DEFAULT_WEBSERVER' => 'TRUE']);

        $probe = @stream_socket_client("tcp://127.0.0.1:{$this->serverPort}", $errno, $errstr, 2.0);
        $this->assertIsResource($probe, "the server must still accept: {$errstr} ({$errno})");
        fwrite($probe, "GET /ping HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
        $answer = $this->readUntilClose($probe, 3.0);
        @fclose($probe);

        $this->assertStringContainsString('pong', $answer, 'the built-in server must serve exactly as before');

        $this->signalServer(SIGTERM);
        $status = $this->awaitExit();

        $this->assertSame(0, $status['exitcode'], 'shutdown is unaffected too');
        $this->assertStringNotContainsString(
            'TINA4_DEFAULT_WEBSERVER',
            $this->readLog(),
            'the setting must be accepted silently — never warned about or rejected as unknown'
        );
    }

    // ── shared assertions ──────────────────────────────────────────────────

    /** A request already being served must run to completion and write its whole response. */
    private function assertInFlightRequestSurvives(int $signal): void
    {
        $this->startServer();
        $inFlight = $this->beginSlowRequest();
        usleep((int)(self::SIGNAL_DELAY_SECONDS * 1_000_000));

        $started = microtime(true);
        $this->signalServer($signal);
        $response = $this->readResponse($inFlight);
        $elapsed = microtime(true) - $started;

        $this->assertStringContainsString(
            '200',
            substr($response, 0, 20),
            'the in-flight request must get its full 200 response, not a truncated one: '
            . substr($response, 0, 200)
        );
        $this->assertStringContainsString(
            'slow-done',
            $response,
            'the whole body must be written before the socket closes'
        );
        $this->assertStringContainsString(
            'slow-finished',
            $this->readState(),
            'the handler itself must run to completion — a signal must not truncate it (EINTR)'
        );
        $this->assertGreaterThan(
            self::SLOW_SECONDS - self::SIGNAL_DELAY_SECONDS - 0.3,
            $elapsed,
            'the response must arrive only after the handler really finished'
        );
    }

    /** A handled signal drains and exits 0 — never 128+signum. */
    private function assertSignalExitsCleanly(int $signal): void
    {
        $this->startServer();
        $inFlight = $this->beginSlowRequest();
        usleep((int)(self::SIGNAL_DELAY_SECONDS * 1_000_000));
        $this->signalServer($signal);
        $this->readResponse($inFlight);
        $status = $this->awaitExit();

        $this->assertFalse(
            (bool)$status['signaled'],
            'a trapped signal must be handled, not kill the process (termsig '
            . var_export($status['termsig'], true) . ')'
        );
        $this->assertSame(
            0,
            $status['exitcode'],
            'a handled signal drains and exits 0; server log: ' . $this->readLog()
        );
    }

    // ── real server plumbing ───────────────────────────────────────────────

    /**
     * Spawn the real Tina4 socket server and wait until it genuinely serves.
     *
     * @param float                 $slowSeconds How long /slow really blocks.
     * @param list<string>          $flags       Fixture flags (background, database).
     * @param array<string, string> $env         Extra environment for the child.
     */
    private function startServer(float $slowSeconds = self::SLOW_SECONDS, array $flags = [], array $env = []): void
    {
        $this->serverPort = self::freePort();
        self::$portsUsed[] = $this->serverPort;
        $this->spawnFixture(
            'graceful_shutdown_server.php',
            [(string)$this->serverPort, $this->stateFile, (string)$slowSeconds, implode(',', $flags)],
            $env
        );

        for ($attempt = 0; $attempt < 200; $attempt++) {
            $probe = @stream_socket_client("tcp://127.0.0.1:{$this->serverPort}", $errno, $errstr, 0.2);
            if ($probe !== false) {
                fwrite($probe, "GET /ping HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
                $answer = $this->readUntilClose($probe, 2.0);
                fclose($probe);
                if (str_contains($answer, 'pong')) {
                    return;
                }
            }
            usleep(25000);
        }

        $this->fail(
            "the real Tina4 server never served /ping on port {$this->serverPort}; log: " . $this->readLog()
        );
    }

    /**
     * Spawn a real App with NO server and NO event loop, and wait until it is up.
     *
     * No port is involved — the process under test never listens; the whole point
     * is that nothing ever pumps the signal queue.
     */
    private function startEmbeddedApp(): void
    {
        $this->spawnFixture('embedded_app_no_loop.php', [$this->stateFile, '10.0']);

        for ($attempt = 0; $attempt < 400; $attempt++) {
            if (str_contains($this->readState(), 'embedded-app-ready')) {
                return;
            }
            usleep(25000);
        }

        $this->fail('the embedded App never reached its loop; log: ' . $this->readLog());
    }

    /**
     * proc_open a fixture as a real child process, recording its pid.
     *
     * ARRAY form on purpose: it execs php directly with no `sh -c` layer, so
     * proc_get_status()['pid'] IS the php process and the signal really reaches
     * it. With the string form, sh is the child and signalling it can leave the
     * real process orphaned.
     *
     * @param string                $fixture Filename under tests/fixtures/.
     * @param list<string>          $args    Arguments passed after the script.
     * @param array<string, string> $env     Extra environment for the child.
     */
    private function spawnFixture(string $fixture, array $args, array $env = []): void
    {
        $environment = $env + [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: sys_get_temp_dir(),
            // Boot directly (no tina4 CLI in a test), keep it quiet, and keep
            // migrations and the AI port out of the picture.
            'TINA4_OVERRIDE_CLIENT' => 'true',
            'TINA4_SUPPRESS' => 'true',
            'TINA4_AUTO_MIGRATE' => 'false',
            'TINA4_DEBUG' => 'false',
        ];

        // fd 1 and 2 -> a real FILE. Never a pipe nobody reads (the child would
        // block once the 64KB buffer filled) and never the runner's own fds.
        $this->process = proc_open(
            array_merge([PHP_BINARY, __DIR__ . '/fixtures/' . $fixture], $args),
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $this->logFile, 'a'],
                2 => ['file', $this->logFile, 'a'],
            ],
            $pipes,
            dirname(__DIR__),
            $environment
        );
        $this->assertIsResource($this->process, "the {$fixture} process must start");

        $status = proc_get_status($this->process);
        $this->serverPid = (int)($status['pid'] ?? 0);
        $this->assertGreaterThan(0, $this->serverPid, "{$fixture} must report a pid");
    }

    /** Open a socket and write the slow request, leaving the response unread. */
    private function beginSlowRequest()
    {
        $socket = @stream_socket_client("tcp://127.0.0.1:{$this->serverPort}", $errno, $errstr, 2.0);
        $this->assertIsResource($socket, "could not start the slow request: {$errstr} ({$errno})");
        fwrite($socket, "GET /slow HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");

        // Wait until the server has really entered the handler, so the signal
        // always lands mid-request rather than racing the dispatch.
        for ($attempt = 0; $attempt < 200; $attempt++) {
            if (str_contains($this->readState(), 'slow-started')) {
                return $socket;
            }
            usleep(10000);
        }
        $this->fail('the slow request never reached the handler; log: ' . $this->readLog());
    }

    /**
     * Upgrade a real socket to a real WebSocket and verify the real handshake.
     *
     * @return resource The upgraded socket.
     */
    private function openWebSocket()
    {
        $socket = @stream_socket_client("tcp://127.0.0.1:{$this->serverPort}", $errno, $errstr, 2.0);
        $this->assertIsResource($socket, "could not open the WebSocket connection: {$errstr} ({$errno})");

        $key = base64_encode(random_bytes(16));
        fwrite(
            $socket,
            "GET /ws HTTP/1.1\r\n"
            . "Host: 127.0.0.1:{$this->serverPort}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n"
        );

        $handshake = '';
        $deadline = microtime(true) + 5.0;
        stream_set_blocking($socket, false);
        while (microtime(true) < $deadline && !str_contains($handshake, "\r\n\r\n")) {
            $chunk = @fread($socket, 4096);
            if ($chunk === false) {
                break;
            }
            $handshake .= $chunk;
            if ($chunk === '') {
                usleep(10000);
            }
        }

        $this->assertStringContainsString('101', $handshake, "the upgrade must succeed; got: {$handshake}");
        $this->assertStringContainsString(
            base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)),
            $handshake,
            'the server must answer with a real RFC 6455 Sec-WebSocket-Accept'
        );

        return $socket;
    }

    /** Read the slow response to EOF. */
    private function readResponse($socket): string
    {
        $body = $this->readUntilClose($socket, self::SLOW_SECONDS + 8.0);
        @fclose($socket);

        return $body;
    }

    /**
     * What does a brand-new connection get right now?
     *
     * Returns one of: "refused: …", "accepted-then-reset", "accepted-and-served",
     * or "accepted-and-answered: …". Naming the outcome rather than asserting a
     * bare boolean makes the failure message say what actually happened —
     * accept-then-RST and a clean refusal are very different bugs.
     */
    private function probeNewConnection(): string
    {
        $socket = @stream_socket_client("tcp://127.0.0.1:{$this->serverPort}", $errno, $errstr, 1.0);
        if ($socket === false) {
            return "refused: {$errstr} ({$errno})";
        }

        @fwrite($socket, "GET /ping HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
        $answer = $this->readUntilClose($socket, 2.0);
        @fclose($socket);

        if ($answer === '') {
            return 'accepted-then-reset';
        }
        if (str_contains($answer, 'pong')) {
            return 'accepted-and-served';
        }

        return 'accepted-and-answered: ' . str_replace("\r\n", ' ', substr($answer, 0, 120));
    }

    /** Drain a socket until the peer closes it or the deadline passes. */
    private function readUntilClose($socket, float $timeoutSeconds): string
    {
        stream_set_blocking($socket, false);
        $deadline = microtime(true) + $timeoutSeconds;
        $buffer = '';
        while (microtime(true) < $deadline) {
            $chunk = @fread($socket, 65536);
            if ($chunk === false) {
                break;
            }
            if ($chunk !== '') {
                $buffer .= $chunk;
                continue;
            }
            if (feof($socket)) {
                break;
            }
            usleep(10000);
        }

        return $buffer;
    }

    /** Send a REAL signal to the REAL server process. */
    private function signalServer(int $signal): void
    {
        $this->assertTrue(
            posix_kill($this->serverPid, $signal),
            "could not signal the server pid {$this->serverPid}"
        );
    }

    /**
     * Wait for the server to exit and return its FINAL status.
     *
     * proc_get_status() reports the real exitcode only on the first call after
     * the process stops, so the status is cached the moment running goes false.
     *
     * @return array<string, mixed>
     */
    private function awaitExit(
        float $timeoutSeconds = self::EXIT_WAIT_SECONDS,
        string $what = 'the server'
    ): array {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                $this->finalStatus = $status;
                return $status;
            }
            usleep(20000);
        }

        $this->fail(
            "{$what} did not exit within {$timeoutSeconds}s of the signal — the signal was swallowed; log: "
            . $this->readLog()
        );
    }

    /**
     * Kill anything still running and prove the PORT is free. Idempotent.
     *
     * The kill is UNCONDITIONAL — "the process handle looks dead" is not proof
     * the listener is, and a reap that only fires when the handle still looks
     * alive is how orphans survive a finished test. The exact pid is signalled
     * rather than the process GROUP: proc_open's array form puts no shell in
     * between, so the child sits in the TEST RUNNER's own group and a
     * negative-pid kill would take phpunit down with it. The array form is
     * chosen precisely so there is no wrapper that could die while the real
     * server lives on.
     */
    private function reapServer(): void
    {
        if (is_resource($this->process)) {
            if ($this->serverPid > 0) {
                @posix_kill($this->serverPid, SIGTERM);
            }
            for ($attempt = 0; $attempt < 60; $attempt++) {
                $status = proc_get_status($this->process);
                if (!$status['running']) {
                    break;
                }
                if ($attempt === 20 && $this->serverPid > 0) {
                    @posix_kill($this->serverPid, SIGKILL);
                }
                usleep(50000);
            }
            @proc_close($this->process);
        }
        $this->process = null;
        $this->finalStatus = null;

        // Verify the PORT, not the handle. Escalate once more if something is
        // still listening after the handle has gone.
        for ($attempt = 0; $attempt < 40 && $this->serverPort > 0; $attempt++) {
            if (!self::isListening($this->serverPort)) {
                break;
            }
            if ($attempt === 20 && $this->serverPid > 0) {
                @posix_kill($this->serverPid, SIGKILL);
            }
            usleep(50000);
        }
        $this->serverPort = 0;
        $this->serverPid = 0;
    }

    /**
     * Nothing this class started may still be listening when the class ends.
     *
     * A self-enforcing hygiene gate: every port handed to a fixture server is
     * remembered and re-checked here, so an orphaned listener fails the suite
     * instead of quietly holding a port for days.
     */
    public static function tearDownAfterClass(): void
    {
        $stillListening = array_values(array_filter(
            self::$portsUsed,
            static fn (int $port) => self::isListening($port)
        ));
        self::$portsUsed = [];

        self::assertSame(
            [],
            $stillListening,
            'these fixture server ports are still held after the suite: ' . implode(', ', $stillListening)
        );
    }

    /** True while something still accepts on a localhost port. */
    private static function isListening(int $port): bool
    {
        $probe = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 0.1);
        if ($probe === false) {
            return false;
        }
        fclose($probe);

        return true;
    }

    /** Everything the fixture recorded so far. */
    private function readState(): string
    {
        clearstatcache(true, $this->stateFile);

        return is_file($this->stateFile) ? (string)file_get_contents($this->stateFile) : '';
    }

    /** Everything the server wrote to stdout/stderr. */
    private function readLog(): string
    {
        clearstatcache(true, $this->logFile);

        return is_file($this->logFile) ? trim((string)file_get_contents($this->logFile)) : '';
    }

    /** Reserve a free localhost TCP port. */
    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name = (string)stream_socket_get_name($socket, false);
        fclose($socket);

        return (int)substr($name, strrpos($name, ':') + 1);
    }
}
