<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Lock-in tests for TestServer and for the invariant it exists to protect.
 *
 * The bug these pin down: a `php -S` spawned by a test outlived the test run and
 * kept the runner's output pipe open, so `phpunit | tee` never returned. The
 * suite's own clock said 77 seconds; the pipeline sat for 41 minutes with five
 * servers still listening at PPID 1.
 *
 * No mocks anywhere: these start a real server on a real port, speak real HTTP to
 * it, and then assert on the real socket and the real process table.
 */
final class TestServerContractTest extends TestCase
{
    /** The router used for the live server tests — any script that answers is fine. */
    private static function router(): string
    {
        return __DIR__ . '/fixtures/api_transfer_server.php';
    }

    // ── 1. it boots, and it answers ─────────────────────────────────────────

    public function testStartBootsAServerThatAnswersHttp(): void
    {
        $server = TestServer::start(self::router());
        try {
            // Speak raw HTTP so ANY status counts as "the server is alive". Asserting
            // on a specific route would couple this test to whatever the fixture
            // happens to serve, and a 404 is still a working server.
            $sock = @stream_socket_client('tcp://127.0.0.1:' . $server->port, $e1, $e2, 2.0);
            self::assertNotFalse($sock, "could not connect — log: {$server->log()}");
            fwrite($sock, "GET / HTTP/1.0\r\nHost: 127.0.0.1\r\n\r\n");
            $statusLine = (string)fgets($sock);
            fclose($sock);

            self::assertStringStartsWith(
                'HTTP/',
                $statusLine,
                "server did not speak HTTP — log: {$server->log()}"
            );
        } finally {
            $server->stop();
        }
    }

    // ── 2. NEGATIVE: stop() actually frees the port ──────────────────────────

    public function testStopLeavesNothingListeningOnThePort(): void
    {
        $server = TestServer::start(self::router());
        $port = $server->port;

        // Prove it WAS listening, so the assertion below cannot pass vacuously.
        $before = @stream_socket_client("tcp://127.0.0.1:{$port}", $e1, $e2, 0.3);
        self::assertNotFalse($before, "server never listened on {$port}");
        fclose($before);

        $server->stop();

        $after = @stream_socket_client("tcp://127.0.0.1:{$port}", $e3, $e4, 0.3);
        if ($after !== false) {
            fclose($after);
        }
        self::assertFalse(
            $after,
            "port {$port} is STILL accepting after stop() — the server leaked, which is "
            . 'exactly the failure that wedged the suite pipeline for 41 minutes'
        );
    }

    // ── 3. NEGATIVE: the process we hold is php, not a /bin/sh wrapper ───────

    public function testTheTrackedProcessIsPhpItselfNotAShellWrapper(): void
    {
        if (!is_dir('/proc') && stripos(PHP_OS_FAMILY, 'darwin') === false) {
            self::markTestSkipped('needs a POSIX process table to inspect');
        }

        $server = TestServer::start(self::router());
        try {
            self::assertGreaterThan(0, $server->pid, 'no pid was captured for the server');

            $cmdline = trim((string)@shell_exec('ps -o command= -p ' . (int)$server->pid . ' 2>/dev/null'));
            self::assertNotSame('', $cmdline, "pid {$server->pid} was not in the process table");

            // This is the whole point of the `exec ` prefix: without it proc_open's
            // /bin/sh may stay alive as the tracked process, proc_terminate signals
            // the SHELL, and `php -S` is orphaned to PID 1.
            self::assertStringContainsString(
                '-S 127.0.0.1:' . $server->port,
                $cmdline,
                'the tracked pid is not the php server itself (a shell wrapper survived), '
                . "so proc_terminate would signal the wrong process. cmdline: {$cmdline}"
            );
        } finally {
            $server->stop();
        }
    }

    // ── 4. stop() is idempotent ──────────────────────────────────────────────

    public function testStopIsIdempotent(): void
    {
        $server = TestServer::start(self::router());
        $server->stop();
        $server->stop(); // must not warn, throw, or block
        self::assertTrue(true);
    }

    // ── 5. NEGATIVE, source invariant: no test may respawn the old bug ───────

    /**
     * Every test that boots a long-running server must go through TestServer.
     *
     * A hand-rolled proc_open for a `php -S` is how the leak got in, four separate
     * times, in two different shapes:
     *   - fd 1/2 left UNSPECIFIED  -> the child inherits phpunit's stdout and holds
     *                                 the runner's pipe open
     *   - fd 1/2 as ['pipe','w']   -> nobody drains it, the 64KB buffer fills, the
     *                                 server blocks, and proc_close() blocks too
     *
     * This test reads the suite's own source so a NEW test cannot reintroduce
     * either shape without turning this red. It deliberately only looks at
     * server spawns (`-S `), not the short-lived CLI invocations, which exit on
     * their own and hold nothing.
     */
    public function testNoTestSpawnsAServerOutsideTestServer(): void
    {
        $offenders = [];
        foreach (glob(__DIR__ . '/*.php') ?: [] as $file) {
            if (basename($file) === 'TestServer.php' || basename($file) === basename(__FILE__)) {
                continue;
            }
            $src = (string)file_get_contents($file);
            if (!str_contains($src, 'proc_open')) {
                continue;
            }
            // Only care about spawns that start a SERVER. The `-S` flag is built into
            // a $cmd variable on an earlier line than the proc_open() call, so match
            // the file as a whole rather than the call site — an earlier version of
            // this check looked inside proc_open(...) and silently matched nothing,
            // which made it pass while four offenders were still there.
            if (!str_contains($src, '-S 127.0.0.1')) {
                continue;
            }
            $offenders[] = basename($file);
        }

        self::assertSame(
            [],
            $offenders,
            "these tests boot a `php -S` with a raw proc_open instead of TestServer::start(), "
            . 'which is how the orphaned-server / wedged-pipeline bug was introduced: '
            . implode(', ', $offenders)
        );
    }
}
