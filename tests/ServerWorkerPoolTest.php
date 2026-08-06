<?php declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * THE PRE-FORKED WORKER POOL.
 *
 * TINA4_SERVE_WORKERS > 1 makes the parent bind once, fork N long-lived
 * workers that all accept on that same socket, and supervise them. It replaces
 * fork-per-request for pooled deployments, where paying a fork() per request
 * measured 893 req/s against php-fpm's 1526 on the same box.
 *
 * What these lock in, and why each one is here rather than assumed:
 *
 *   - requests are really served by SEVERAL processes, not one. A pool that
 *     forks and then serves everything from the parent would look identical
 *     from the outside and be worth nothing.
 *   - a worker that DIES is replaced, because a pool that silently shrinks to
 *     zero is worse than no pool.
 *   - TINA4_SERVE_MAX_REQUESTS really recycles a worker.
 *   - the pool REFUSES to start in debug mode, because the dashboard, hot
 *     reload and the WebSocket registry are all per-process.
 *   - shutdown takes the workers with it and frees the port.
 *
 * NO MOCKS: a real server, real forks, real sockets. The property under test is
 * the process topology, and only real processes have one.
 */

use PHPUnit\Framework\TestCase;

class ServerWorkerPoolTest extends TestCase
{
    private const WORKERS = 4;

    private string $appDir = '';
    /** @var resource|null */
    private $proc = null;
    private int $port = 0;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('ext-pcntl/ext-posix are required for a worker pool');
        }
        $this->appDir = \TempPath::dir('tina4_pool_');
    }

    protected function tearDown(): void
    {
        $this->stopServer();
    }

    private function stopServer(): void
    {
        if (!is_resource($this->proc)) {
            return;
        }
        $status = proc_get_status($this->proc);
        if (($status['running'] ?? false) && function_exists('posix_kill')) {
            // Kill the GROUP: the supervisor has workers of its own.
            @posix_kill(-$status['pid'], SIGTERM);
            @posix_kill($status['pid'], SIGTERM);
        }
        proc_terminate($this->proc, SIGTERM);
        @proc_close($this->proc);
        $this->proc = null;
    }

    /**
     * Boot a server. $env adds or overrides .env lines.
     *
     * @param array<string, string> $env
     */
    private function startServer(array $env = []): int
    {
        $port = \FreePort::get();

        mkdir($this->appDir . '/src/routes', 0755, true);
        file_put_contents($this->appDir . '/src/routes/pool.php', <<<'PHP'
<?php
// Reports the pid that served it. That is the whole instrument: with a pool,
// repeated calls must come back with more than one distinct pid.
\Tina4\Router::get("/pid", function ($request, $response) {
    return $response((string)getmypid(), 200, "text/plain");
});
PHP);

        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        file_put_contents($this->appDir . '/index.php', <<<PHP
<?php
require '{$autoload}';
\$app = new \\Tina4\\App(__DIR__);
\$app->run('127.0.0.1', {$port});
PHP);

        $env = array_merge([
            'TINA4_OVERRIDE_CLIENT' => 'true',
            'TINA4_NO_BROWSER'      => 'true',
            'TINA4_DEBUG'           => 'false',
            'TINA4_SERVE_WORKERS'   => (string)self::WORKERS,
        ], $env);
        $lines = '';
        foreach ($env as $k => $v) {
            $lines .= "{$k}={$v}\n";
        }
        file_put_contents($this->appDir . '/.env', $lines);

        $this->proc = proc_open(
            [PHP_BINARY, 'index.php'],
            [1 => ['file', $this->appDir . '/server.log', 'w'], 2 => ['file', $this->appDir . '/server.log', 'a']],
            $pipes,
            $this->appDir
        );
        $this->assertIsResource($this->proc, 'could not start the test server');

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
        $this->fail('the pool never accepted on port ' . $port
            . ' - log: ' . @file_get_contents($this->appDir . '/server.log'));
    }

    private function get(string $path, float $timeout = 10.0): ?string
    {
        $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]);
        $body = @file_get_contents("http://127.0.0.1:{$this->port}{$path}", false, $ctx);
        return $body === false ? null : $body;
    }

    /**
     * The supervisor's live child processes, straight from the OS.
     *
     * More honest than inferring the pool from response bodies: this is the
     * process table, so a pool that had silently shrunk cannot hide behind a
     * worker that happens to still answer.
     *
     * @return string[]
     */
    private function liveWorkerPids(): array
    {
        $status = proc_get_status($this->proc);
        $supervisor = (int)($status['pid'] ?? 0);
        if ($supervisor <= 0) {
            return [];
        }
        $out = (string)@shell_exec('pgrep -P ' . $supervisor . ' 2>/dev/null');
        return array_values(array_filter(array_map('trim', explode("\n", $out))));
    }

    /** @return string[] Distinct pids seen over $n requests. */
    private function collectPids(int $n): array
    {
        $seen = [];
        for ($i = 0; $i < $n; $i++) {
            $pid = $this->get('/pid');
            if ($pid !== null && $pid !== '') {
                $seen[$pid] = true;
            }
        }
        return array_keys($seen);
    }

    // ── POSITIVE ────────────────────────────────────────────────────────────

    /**
     * Requests are served by MORE THAN ONE process.
     *
     * This is the test the whole feature stands on. Anything less - "it starts",
     * "it answers" - a single-process server also satisfies.
     */
    public function testRequestsAreServedBySeveralWorkerProcesses(): void
    {
        $this->startServer();
        $this->get('/pid');   // warm the pool

        $pids = $this->collectPids(60);

        $this->assertNotEmpty($pids, 'the pool served nothing');
        $this->assertGreaterThan(
            1,
            count($pids),
            'every request came back from the SAME pid, so nothing is pooled: '
            . implode(',', $pids)
        );
        $this->assertLessThanOrEqual(
            self::WORKERS,
            count($pids),
            'more distinct pids than workers were configured, so something is still '
            . 'forking per request: ' . implode(',', $pids)
        );
    }

    // ── NEGATIVE / CONTROL ──────────────────────────────────────────────────

    /**
     * The control that stops the test above passing for the wrong reason.
     *
     * "Several distinct pids" alone does NOT prove a pool - fork-per-request
     * produces a fresh pid for EVERY request and would sail through it. The
     * discriminator is the SIZE of the set: a pool of N reuses N pids no matter
     * how many requests you send; fork-per-request grows without bound.
     *
     * Measured on this machine over 25 requests: WORKERS=1 gives 25 distinct
     * pids, WORKERS=1 with TINA4_SERVE_FORK=false gives 1, and WORKERS=4 gives
     * 4. This asserts the first of those, because it is the one that would have
     * made the positive test meaningless.
     */
    public function testWithoutThePoolEveryRequestGetsItsOwnProcess(): void
    {
        $this->startServer(['TINA4_SERVE_WORKERS' => '1']);

        $n = 25;
        $pids = $this->collectPids($n);

        $this->assertGreaterThan(
            self::WORKERS,
            count($pids),
            'with the pool off and fork-per-request on, the pid set must grow with the '
            . 'request count - if it does not, the positive test is not measuring a pool'
        );
    }

    /**
     * The other half of the control: one process really can mean one pid.
     *
     * Turning BOTH the pool and per-request forking off must give exactly one
     * pid, which pins the two mechanisms apart.
     */
    public function testWithNeitherPoolNorForkEveryRequestSharesOneProcess(): void
    {
        $this->startServer(['TINA4_SERVE_WORKERS' => '1', 'TINA4_SERVE_FORK' => 'false']);

        $this->assertCount(
            1,
            $this->collectPids(25),
            'with no pool and no per-request fork the server must serve from one process'
        );
    }

    /**
     * A pool must never quietly shrink.
     *
     * A worker is killed outright - SIGKILL, no chance to clean up, the way a
     * real crash or an OOM kill arrives. The supervisor has to notice and
     * replace it, or any crash-inducing bug drains the pool to nothing while
     * the server still looks alive.
     */
    public function testAWorkerThatDiesIsReplaced(): void
    {
        $this->startServer();
        $this->collectPids(10);   // let every worker take a turn

        $before = $this->liveWorkerPids();
        $this->assertCount(
            self::WORKERS,
            $before,
            'the pool did not start at strength: ' . implode(',', $before)
        );

        $victim = (int)$before[0];
        posix_kill($victim, SIGKILL);

        $replaced = false;
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            $now = $this->liveWorkerPids();
            if (count($now) === self::WORKERS && !in_array((string)$victim, $now, true)) {
                $replaced = true;
                break;
            }
            usleep(200000);
        }

        $this->assertTrue(
            $replaced,
            'the supervisor did not replace worker ' . $victim . ' - the pool now has '
            . implode(',', $this->liveWorkerPids())
        );
        $this->assertNotEmpty($this->collectPids(10), 'the pool stopped serving after a worker died');
    }

    /**
     * TINA4_SERVE_MAX_REQUESTS recycles a worker.
     *
     * With a low cap, sustained traffic must keep producing pids that were not
     * in the original set. A cap that did nothing would keep the first N pids
     * forever.
     */
    public function testWorkersAreRecycledAfterTheRequestCap(): void
    {
        $this->startServer(['TINA4_SERVE_MAX_REQUESTS' => '5']);

        $first = $this->liveWorkerPids();
        $this->assertCount(self::WORKERS, $first, 'the pool did not start at strength');

        // Well past 5 requests per worker.
        $this->collectPids(60);
        usleep(500000);

        $later = $this->liveWorkerPids();
        $this->assertCount(self::WORKERS, $later, 'the pool did not stay at strength while recycling');
        $this->assertNotEmpty(
            array_diff($later, $first),
            'every original worker pid is still alive after 60 requests with a cap of 5, '
            . 'so TINA4_SERVE_MAX_REQUESTS is not recycling anything'
        );
    }

    /**
     * Debug mode must refuse the pool rather than break the dashboard.
     *
     * DevAdmin's log, the reload flag and the WebSocket registry are all
     * per-process. A pool in debug mode would show one worker's traffic, reload
     * one worker, and broadcast to one worker's sockets - each of which reads
     * as a framework bug to the developer hitting it.
     */
    public function testThePoolIsRefusedInDebugMode(): void
    {
        $this->startServer(['TINA4_DEBUG' => 'true']);

        $this->assertSame(
            [],
            $this->liveWorkerPids(),
            'TINA4_DEBUG=true must not pre-fork a pool: the dashboard, hot reload and '
            . 'the WebSocket registry are all per-process'
        );
        $log = (string)@file_get_contents($this->appDir . '/server.log');
        $this->assertStringContainsString(
            'TINA4_SERVE_WORKERS is ignored',
            $log,
            'the refusal must be said out loud, or an operator will think the pool is on'
        );
    }

    // ── SHUTDOWN ────────────────────────────────────────────────────────────

    /**
     * Stopping the supervisor stops the workers and frees the port.
     *
     * A pool that left orphans behind would hold the port and break the next
     * deploy - the same class of defect as a child holding the listening
     * socket.
     */
    public function testShutdownTakesEveryWorkerWithIt(): void
    {
        $port = $this->startServer();
        $this->assertGreaterThan(1, count($this->collectPids(30)), 'need a real pool first');

        $this->stopServer();

        $freed = false;
        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            $probe = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
            if ($probe) {
                fclose($probe);
                $freed = true;
                break;
            }
            usleep(250000);
        }

        $this->assertTrue(
            $freed,
            "port {$port} was still held after the supervisor stopped, so at least one "
            . 'worker outlived it'
        );
    }
}
