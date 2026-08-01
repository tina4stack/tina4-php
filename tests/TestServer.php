<?php

declare(strict_types=1);

// Global namespace, like PgTestEnv and AppTestSupport: this repo maps PSR-4
// Tina4\ -> Tina4/, so a Tina4\Tests\... class here would never autoload (the
// same path-derivation trap documented for Tina4/Bootstrap/DocStore.php).
// tests/bootstrap.php requires this file, so every test can just use it.

/**
 * A `php -S` server for a test, that reliably dies and never wedges the runner.
 *
 * Nine test classes each hand-rolled proc_open + proc_terminate, and between them
 * repeated three mistakes. Two of them make a `phpunit | tee` pipeline hang
 * FOREVER even though phpunit itself finished and printed its summary — observed
 * as a 41-minute "hang" on a suite whose own clock said 77 seconds, with five
 * `php -S` processes still listening at PPID 1:
 *
 *   1. Not redirecting fd 1/2 at all. proc_open then hands the child phpunit's
 *      OWN stdout/stderr, so the spawned server holds the write end of the
 *      runner's pipe and `tee` never sees EOF.
 *
 *   2. Redirecting fd 1/2 to a ['pipe','w'] that nobody ever reads. `php -S`
 *      logs every request, so the 64KB pipe buffer fills, the server BLOCKS on
 *      write, and proc_close() then blocks waiting for a process that can no
 *      longer make progress.
 *
 *   3. Relying on proc_terminate() alone. proc_open runs a STRING command through
 *      `/bin/sh -c`. Whether that shell exec-replaces itself is shell- and
 *      platform-dependent: on macOS/PHP 8.5 it does, so terminate kills php and
 *      nothing leaks; on the Ubuntu 24.04 / PHP 8.3 lab box it did not, so
 *      SIGTERM hit the shell while `php -S` kept running and was reparented to
 *      PID 1.
 *
 * The fixes, in order of how much they buy:
 *
 *   * The command starts with `exec`, so the shell is REPLACED by php on every
 *     platform. proc_get_status()['pid'] is then the real server and
 *     proc_terminate() always signals it. This removes mistake 3 at the root
 *     rather than compensating for it, and needs no process-group kill (a
 *     negative-pid kill is a blunt instrument: the child is in the runner's own
 *     process group, so the group id it would target is not reliably ours).
 *
 *   * fd 0, 1 and 2 all point at real files. The child can always write, and it
 *     never touches a descriptor the runner cares about. The log goes to a temp
 *     file rather than /dev/null so a failing test can still show what the
 *     server said — see log().
 *
 *   * stop() escalates TERM -> KILL and then waits for the PORT to actually
 *     close, because a server mid-request can outlive a single TERM. "The
 *     process handle went away" is not proof the socket did.
 *
 * A shutdown handler stops anything still running, so even a fatal error or a
 * skipped tearDownAfterClass cannot leave a listener behind.
 *
 * @see TestServerContractTest for the lock-in tests.
 */
final class TestServer
{
    /** @var list<self> every server started this process, for the shutdown net */
    private static array $started = [];

    private static bool $shutdownRegistered = false;

    private bool $stopped = false;

    /**
     * @param resource $proc
     */
    private function __construct(
        private $proc,
        public readonly int $pid,
        public readonly int $port,
        private readonly string $logFile,
    ) {
    }

    /**
     * Boot a `php -S` server on a free port and wait until it accepts.
     *
     * @param string                $router router/front-controller script to serve
     * @param array<string, string> $env    extra environment for the child (null = inherit)
     * @param string|null           $cwd    working directory (default: the router's directory)
     *
     * @throws \RuntimeException when the process cannot be started, or never accepts
     */
    public static function start(string $router, array $env = [], ?string $cwd = null): self
    {
        $port = self::freePort();

        // `exec` replaces the /bin/sh wrapper with php itself — see the class docblock.
        $cmd = 'exec ' . escapeshellarg(PHP_BINARY)
            . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg($router);

        return self::launch($cmd, $port, $env, $cwd ?? dirname($router));
    }

    /**
     * Boot a standalone PHP server SCRIPT (one that binds its own listener) on a
     * free port and wait until it accepts.
     *
     * `php -S` is an HTTP server and nothing else: it cannot abort a connection
     * without a response, so it cannot produce a real transport failure for a
     * client to retry. A script that owns its own `stream_socket_server` can —
     * and it is still a real listener speaking real HTTP over real sockets.
     * The port is passed as $argv[1].
     *
     * @param string                $script the server script to run
     * @param array<int, string>    $args   extra arguments, appended after the port
     * @param array<string, string> $env    extra environment for the child (null = inherit)
     * @param string|null           $cwd    working directory (default: the script's directory)
     *
     * @throws \RuntimeException when the process cannot be started, or never accepts
     */
    public static function startScript(string $script, array $args = [], array $env = [], ?string $cwd = null): self
    {
        $port = self::freePort();

        $cmd = 'exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . $port;
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg((string)$arg);
        }

        return self::launch($cmd, $port, $env, $cwd ?? dirname($script));
    }

    /**
     * Spawn a server command with the fd/reaping hygiene described on the class,
     * and block until the port accepts.
     *
     * @param array<string, string> $env
     *
     * @throws \RuntimeException when the process cannot be started, or never accepts
     */
    private static function launch(string $cmd, int $port, array $env, string $cwd): self
    {
        if (!self::$shutdownRegistered) {
            register_shutdown_function(static fn () => self::stopAll());
            self::$shutdownRegistered = true;
        }

        $log = tempnam(sys_get_temp_dir(), 'tina4-testserver-');
        if ($log === false) {
            throw new \RuntimeException('could not create a log file for the test server');
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ];

        $pipes = [];
        $proc = proc_open($cmd, $descriptors, $pipes, $cwd, $env ?: null);
        if (!is_resource($proc)) {
            throw new \RuntimeException("could not start a test server on port {$port}");
        }

        $status = proc_get_status($proc);
        $server = new self($proc, (int)($status['pid'] ?? 0), $port, $log);
        self::$started[] = $server;

        for ($i = 0; $i < 200; $i++) {
            $client = @stream_socket_client("tcp://127.0.0.1:{$port}", $e1, $e2, 0.1);
            if ($client !== false) {
                fclose($client);
                return $server;
            }
            usleep(25000);
        }

        $why = trim($server->log());
        $server->stop();
        throw new \RuntimeException(
            "the test server never accepted on port {$port}" . ($why !== '' ? ": {$why}" : '')
        );
    }

    /** Base URL of this server, e.g. http://127.0.0.1:54321 */
    public function base(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    /** Whatever the server wrote to stdout/stderr — useful when a test fails. */
    public function log(): string
    {
        return is_file($this->logFile) ? (string)file_get_contents($this->logFile) : '';
    }

    /**
     * Stop the server and prove the port is free. Idempotent.
     */
    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;

        if (is_resource($this->proc)) {
            @proc_terminate($this->proc);
        }

        // Escalate, then wait on the PORT rather than the handle: a server
        // mid-request can survive one TERM, and a closed handle is not a closed
        // socket. 2s total is far above the observed shutdown time.
        for ($i = 0; $i < 40 && $this->isListening(); $i++) {
            if ($i === 10 && is_resource($this->proc)) {
                @proc_terminate($this->proc, 9);
            }
            usleep(50000);
        }

        if (is_resource($this->proc)) {
            @proc_close($this->proc);
        }
        @unlink($this->logFile);
    }

    /** Stop every server started in this process. */
    public static function stopAll(): void
    {
        foreach (self::$started as $server) {
            $server->stop();
        }
        self::$started = [];
    }

    /** True while something still accepts on our port. */
    private function isListening(): bool
    {
        $client = @stream_socket_client("tcp://127.0.0.1:{$this->port}", $e1, $e2, 0.1);
        if ($client === false) {
            return false;
        }
        fclose($client);

        return true;
    }

    /** Reserve a free localhost TCP port. */
    private static function freePort(): int
    {
        return \FreePort::get();
    }
}
