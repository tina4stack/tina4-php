<?php declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * A BLOCKING HANDLER MUST NOT FREEZE THE SERVER.
 *
 * Reported by a user on 3.13.94: `sleep(10)` at the top of a route, and the
 * route "does not respond". It did respond, correctly, at 10s. What did not
 * respond was EVERYTHING ELSE, because the server ran one loop and dispatched
 * the handler inline. Measured then: a trivial route took 8.999s instead of its
 * usual 0.007s while the slow one slept.
 *
 * Both halves are needed and neither is sufficient alone. The positive test
 * passes on a machine that is simply fast. The negative test - the same
 * measurement with TINA4_SERVE_FORK=false - proves the number moved BECAUSE of
 * the fork rather than because the request was cheap.
 *
 * NO MOCKS: a real server process, real sockets, a real sleep(), real wall
 * clock. The whole property under test is timing between processes, and nothing
 * short of two real connections can show it.
 */

use PHPUnit\Framework\TestCase;

class ServerConcurrentRequestsTest extends TestCase
{
    private string $appDir = '';
    /** @var resource|null */
    private $proc = null;
    private int $port = 0;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('ext-pcntl is not installed, so per-request forking cannot be exercised here');
        }
        $this->appDir = \TempPath::dir('tina4_concurrency_');
    }

    protected function tearDown(): void
    {
        $this->stopServer();
    }

    /** Boot a real Tina4 server with a fast route and a blocking one. */
    private function startServer(bool $fork, bool $debug = false): int
    {
        $port = FreePort::get();

        mkdir($this->appDir . '/src/routes', 0755, true);
        file_put_contents($this->appDir . '/src/routes/concurrency.php', <<<'PHP'
<?php
\Tina4\Router::get("/fast", function ($request, $response) {
    return $response("fast", 200);
});
\Tina4\Router::get("/blocking", function ($request, $response) {
    sleep(3);
    return $response("blocked", 200);
});
\Tina4\Router::get("/boom", function ($request, $response) {
    throw new \RuntimeException("handler exploded");
});
PHP);

        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        file_put_contents($this->appDir . '/index.php', <<<PHP
<?php
require '{$autoload}';
\$app = new \\Tina4\\App(__DIR__);
\$app->run('127.0.0.1', {$port});
PHP);

        // The port is an ARGUMENT to run(), not an env var: run() reads
        // TINA4_HOST but takes the port as a parameter defaulting to 7145. A
        // --port flag on index.php is parsed by nobody (only `tina4php serve`
        // reads argv), so the first version of this test silently bound 7145
        // and raced whatever else was on it.
        // TINA4_DEBUG gates the /__dev routes: with it false they are never
        // registered, so the reload test below asks a server that has no such
        // endpoint and reads the 404 as a broken fork. The timing tests keep it
        // off so toolbar injection cannot colour their measurements.
        $env = "TINA4_OVERRIDE_CLIENT=true\nTINA4_NO_BROWSER=true\n"
             . 'TINA4_DEBUG=' . ($debug ? 'true' : 'false') . "\n"
             . 'TINA4_SERVE_FORK=' . ($fork ? 'true' : 'false') . "\n";
        file_put_contents($this->appDir . '/.env', $env);

        $descriptors = [1 => ['file', $this->appDir . '/server.log', 'w'], 2 => ['file', $this->appDir . '/server.log', 'a']];
        $this->proc = proc_open(
            [PHP_BINARY, 'index.php'],
            $descriptors,
            $pipes,
            $this->appDir
        );
        $this->assertIsResource($this->proc, 'could not start the test server');

        // Wait for it to accept, rather than sleeping a guessed amount.
        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
            if ($sock) {
                fclose($sock);
                $this->port = $port;
                return $port;
            }
            usleep(150000);
        }
        $this->fail('the test server never accepted on port ' . $port
            . ' - log: ' . @file_get_contents($this->appDir . '/server.log'));
    }

    private function stopServer(): void
    {
        if (is_resource($this->proc)) {
            $status = proc_get_status($this->proc);
            if ($status['running'] ?? false) {
                // Kill the group: the server may have request children of its own.
                if (function_exists('posix_kill')) {
                    @posix_kill($status['pid'], SIGTERM);
                }
                proc_terminate($this->proc, SIGTERM);
            }
            @proc_close($this->proc);
            $this->proc = null;
        }
    }

    /** Seconds a GET took. -1 when it did not complete. */
    private function timeGet(string $path, float $timeout = 30.0): float
    {
        $start = microtime(true);
        $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]);
        $body = @file_get_contents("http://127.0.0.1:{$this->port}{$path}", false, $ctx);
        if ($body === false) {
            return -1.0;
        }
        return microtime(true) - $start;
    }

    /**
     * Fire /blocking in a real background process, so the foreground request
     * genuinely races it. curl is used rather than another PHP process because
     * it starts in milliseconds; the point is to be mid-sleep when the
     * foreground request lands.
     *
     * @return resource
     */
    private function startBlockingRequest()
    {
        $cmd = sprintf('curl -s -o /dev/null --max-time 30 http://127.0.0.1:%d/blocking', $this->port);
        $proc = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p);
        usleep(700000);   // let it reach the handler and start sleeping
        return $proc;
    }

    // ── POSITIVE ────────────────────────────────────────────────────────────

    public function testAFastRouteAnswersWhileAnotherRouteIsBlocking(): void
    {
        $this->startServer(fork: true);
        $this->assertGreaterThan(0, $this->timeGet('/fast'), 'the fast route must work at all');

        $blocking = $this->startBlockingRequest();
        $elapsed = $this->timeGet('/fast');
        if (is_resource($blocking)) {
            proc_close($blocking);
        }

        $this->assertGreaterThan(0, $elapsed, '/fast did not answer while /blocking was running');
        $this->assertLessThan(
            2.0,
            $elapsed,
            "/fast took {$elapsed}s while a route was sleeping 3s. It should be milliseconds: "
            . 'a blocking handler is freezing the whole server again.'
        );
    }

    // ── NEGATIVE ────────────────────────────────────────────────────────────

    /**
     * Without the fork the SAME measurement blocks. This is what proves the
     * positive test measures the fork: on a serial server /fast waits out the
     * sleep, which is exactly the reported defect.
     */
    public function testWithoutForkingTheSameRequestIsBlocked(): void
    {
        $this->startServer(fork: false);
        $this->assertGreaterThan(0, $this->timeGet('/fast'), 'the fast route must work at all');

        $blocking = $this->startBlockingRequest();
        $elapsed = $this->timeGet('/fast');
        if (is_resource($blocking)) {
            proc_close($blocking);
        }

        $this->assertGreaterThan(
            1.0,
            $elapsed,
            "/fast answered in {$elapsed}s with TINA4_SERVE_FORK=false. It should have been "
            . 'blocked behind the sleeping handler - if it is not, this pair is no longer '
            . 'measuring the fork and the positive test above proves nothing.'
        );
    }

    /**
     * A handler that THROWS must not take the server down with it.
     *
     * This locks in a property that already HOLDS - the framework catches the
     * throw, logs "Route error", answers 500, and the forked child exits
     * normally. Measured: /boom -> 500, then /fast -> 200, server healthy.
     *
     * Stated plainly because the comment here used to claim otherwise: this is
     * NOT a gate for the accept-loop child backstop in Server.php. That guard
     * was added on a theory that a throwing handler orphans a child, the theory
     * was tested by removing the guard, and this test passed anyway. It is kept
     * as a regression test for the 500-and-survive behaviour, which is worth
     * having on its own.
     */
    public function testAThrowingHandlerDoesNotKillTheServer(): void
    {
        $this->startServer(fork: true);
        $this->assertGreaterThan(0, $this->timeGet('/fast'), 'the fast route must work at all');

        $this->timeGet('/boom', 10.0);   // may 500, may drop - either is fine

        $this->assertGreaterThan(
            0,
            $this->timeGet('/fast'),
            'the server stopped answering after a handler threw. One bad request took '
            . 'down every other connection.'
        );
        $this->assertGreaterThan(
            0,
            $this->timeGet('/fast'),
            'the server answered once more and then died'
        );
    }

    // ── The parent must keep what it owns ───────────────────────────────────

    /**
     * /__dev is deliberately NOT forked: DevAdmin::$pendingReload is set by
     * POST /__dev/api/reload and read by the accept loop, so a child would set
     * it on a copy that is discarded and live reload would silently stop.
     */
    public function testDevReloadStillReachesTheParentProcess(): void
    {
        $this->startServer(fork: true, debug: true);

        $before = @file_get_contents("http://127.0.0.1:{$this->port}/__dev/api/mtime");
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 10, 'ignore_errors' => true]]);
        @file_get_contents("http://127.0.0.1:{$this->port}/__dev/api/reload", false, $ctx);
        $after = @file_get_contents("http://127.0.0.1:{$this->port}/__dev/api/mtime");

        $this->assertNotFalse($before, '/__dev/api/mtime did not answer');
        $this->assertNotFalse($after, '/__dev/api/mtime did not answer after the reload');
        $this->assertNotSame(
            $before,
            $after,
            'the reload counter did not move, so the reload was handled in a forked child '
            . 'whose state was discarded - hot reload is broken'
        );
    }
}
