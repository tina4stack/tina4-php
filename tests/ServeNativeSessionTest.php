<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
 */

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tina4\PortTakeover;

/**
 * PHP's native session has to work under `tina4 serve` itself, not only under a
 * server that prints nothing.
 *
 * The CLI SAPI hardcodes output_buffering=0, so the first `echo` a long-running
 * server process makes marks headers as sent for the rest of its life.
 *
 * Before any request arrived, three things were echoed:
 *   - `serve` echoed its banner;
 *   - outside dev mode, it echoed a port-takeover notice;
 *   - Tina4\Server echoed its Test Port line.
 *
 * So Router::startNativeSession() found headers_sent() true on every request and
 * never called session_start(). The result:
 *   - $_SESSION was lost between requests;
 *   - no PHPSESSID cookie was ever sent;
 *   - session_regenerate_id() answered 500.
 *
 * App::run() did the same with its own banner. Tests that booted the server with
 * TINA4_SUPPRESS=true printed nothing, so none of them could see it. That console
 * output now goes through Server::console(), a write to the STDOUT stream that
 * bypasses PHP's output layer.
 *
 * Everything here runs the real entry points, with no mocks:
 *   - `php bin/tina4php serve` in a project shaped like the scaffold;
 *   - App::run() with its banner on.
 * Each flow is the one from tina4stack/tina4-php#253: establish a session,
 * regenerate its id, and read it back.
 */
class ServeNativeSessionTest extends TestCase
{
    private const SECRET = 'tina4-php-test-suite-secret-0123456789abcdef';

    private static string $appDir = '';

    /** @var array<string, resource> every server this test started, by log file, stopped in tearDown */
    private array $processes = [];

    /** @var \Socket[] listening sockets this test holds open, closed in tearDown */
    private array $heldPorts = [];

    public static function setUpBeforeClass(): void
    {
        self::$appDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tina4_serve_native_session_' . bin2hex(random_bytes(6));
        @mkdir(self::$appDir . '/src/routes', 0777, true);
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';

        // The entry file `tina4 init php` scaffolds: no output buffer of its own.
        file_put_contents(self::$appDir . '/index.php', <<<PHP
        <?php
        require_once '{$autoload}';
        \$app = new \\Tina4\\App(basePath: __DIR__);
        \$app->handle();
        PHP);

        // The two routes from #253, plus a probe that says where output started.
        file_put_contents(self::$appDir . '/src/routes/session.php', <<<'PHP'
        <?php
        \Tina4\Router::get('/probe', function ($request, $response) {
            $sent = headers_sent($file, $line);
            return $response(['headers_sent' => $sent ? basename((string)$file) . ':' . $line : false]);
        });

        \Tina4\Router::post('/regen', function ($request, $response) {
            session_regenerate_id(true);
            $_SESSION['hit'] = ($_SESSION['hit'] ?? 0) + 1;
            return $response(['id' => session_id(), 'hit' => $_SESSION['hit']]);
        })->noAuth();

        \Tina4\Router::get('/whoami', function ($request, $response) {
            return $response(['id' => session_id(), 'hit' => $_SESSION['hit'] ?? null]);
        });

        // A native session is kept, and its cookie sent, only once the request
        // has put something in $_SESSION.
        \Tina4\Router::get('/start', function ($request, $response) {
            $_SESSION['started'] = true;
            return $response(['id' => session_id(), 'hit' => $_SESSION['hit'] ?? null]);
        });
        PHP);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$appDir !== '' && is_dir(self::$appDir)) {
            self::removeTree(self::$appDir);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if (!is_resource($process)) {
                continue;
            }
            // The array form of proc_open runs php itself, no shell, so these
            // signals reach the server and nothing else.
            proc_terminate($process, 15);
            $wait = microtime(true) + 5;
            while (proc_get_status($process)['running'] && microtime(true) < $wait) {
                usleep(50000);
            }
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
        }
        $this->processes = [];
        foreach ($this->heldPorts as $socket) {
            socket_close($socket);
        }
        $this->heldPorts = [];
    }

    /** @return array<string, array{bool, bool, list<string>}> debug, fork per request, serve flags */
    public static function serveModes(): array
    {
        // The tina4 CLI always launches `tina4php serve --managed`: in debug mode
        // the reload socket is on and the framework's own file watcher is off.
        // Run bare, both are on unless --no-reload. Windows has no pcntl_fork,
        // so there the forking rows run as one process too.
        $modes = [];
        foreach (['fork per request' => true, 'one process' => false] as $process => $fork) {
            $modes["debug on, tina4 CLI, {$process}"] = [true, $fork, ['--managed']];
            $modes["debug on, bare with live reload, {$process}"] = [true, $fork, []];
            $modes["debug on, bare with --no-reload, {$process}"] = [true, $fork, ['--no-reload']];
            $modes["debug off, tina4 CLI, {$process}"] = [false, $fork, ['--managed']];
        }
        return $modes;
    }

    /**
     * `tina4 serve` must print its banner and still leave headers unsent. With
     * debug on it also prints the Test Port line, and outside debug mode any
     * notice from the port-takeover check. The native session must then start,
     * survive a mid-request regenerate, and never reach a second client.
     */
    #[DataProvider('serveModes')]
    public function testServeStartsTheNativeSessionAndKeepsItThroughARegenerate(bool $debug, bool $fork, array $flags): void
    {
        $port = \FreePort::get();
        $log = $this->serve($port, $debug, $fork, $flags);
        $console = (string)file_get_contents($log);

        $this->assertStringContainsString('Tina4 PHP v', $console, 'the serve banner must still reach the console');
        if ($debug) {
            $this->assertStringContainsString('Test Port:', $console, 'the Test Port line must still reach the console');
        }

        $this->assertNoOutputStarted($port, $log);
        $this->assertTheSessionSurvivesARegenerate($port, $log);
    }

    /**
     * With debug on, the Test Port is the main port plus 1000. When something
     * else holds it, the server says so and carries on without it. That notice
     * must leave the session working too.
     */
    public function testServeTestPortSkippedNoticeLeavesTheNativeSessionWorking(): void
    {
        $port = $this->portWhoseTestPortIsHeld();
        $log = $this->serve($port, debug: true, fork: true);

        $this->assertStringContainsString('Test Port: SKIPPED', (string)file_get_contents($log), 'the server must have found its Test Port taken');
        $this->assertNoOutputStarted($port, $log);
        $this->assertTheSessionSurvivesARegenerate($port, $log);
    }

    /**
     * App::run() prints its own banner, the one TINA4_SUPPRESS=true hides.
     * With the banner on, the #253 fixture must still keep its session.
     */
    public function testAppRunStartsTheNativeSessionWithItsBannerOn(): void
    {
        $port = \FreePort::get();
        $log = $this->bootFixture($port);

        $this->assertStringContainsString('Tina4 PHP v', (string)file_get_contents($log), 'the App::run banner must still reach the console');
        $this->assertTheSessionSurvivesARegenerate($port, $log);
    }

    /**
     * When the port it was given is taken, App::run() says so and moves to the
     * next free one. That notice must leave the session working too.
     */
    public function testAppRunPortInUseNoticeLeavesTheNativeSessionWorking(): void
    {
        $taken = $this->heldPort();
        $log = $this->bootFixture($taken, waitForReady: false);
        $deadline = microtime(true) + 15.0;
        $moved = null;
        while ($moved === null && microtime(true) < $deadline) {
            if (preg_match('/Port \d+ is in use, using port (\d+) instead/', (string)@file_get_contents($log), $match)) {
                $moved = (int)$match[1];
                break;
            }
            usleep(50000);
        }
        $this->assertNotNull($moved, 'App::run() never printed its port-in-use notice: ' . @file_get_contents($log));
        $this->waitForReady($moved, $log);

        $this->assertTheSessionSurvivesARegenerate($moved, $log);
    }

    /**
     * Taking a port back from a stale Tina4 dev server prints a "Reclaimed port"
     * warning. The new server's native session must survive it.
     */
    public function testReclaimingThePortFromAStaleServerLeavesTheNativeSessionWorking(): void
    {
        if (PortTakeover::inContainer()) {
            $this->markTestSkipped('[needs:no-container] port takeover never signals anything inside a container');
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('[needs:os=posix] port takeover stops the stale server with a POSIX signal');
        }

        $port = \FreePort::get();
        $stale = $this->processes[$this->serve($port, debug: true, fork: true)];   // the stale server
        $log = $this->serve($port, debug: true, fork: true, waitForReady: false); // reclaims its port

        // Until the stale server is gone it can answer for the new one, so wait
        // for the reclaim, then for the stale process to exit, then for readiness.
        $deadline = microtime(true) + 15.0;
        while (!str_contains((string)@file_get_contents($log), 'Reclaimed port') && microtime(true) < $deadline) {
            usleep(50000);
        }
        $this->assertStringContainsString('Reclaimed port', (string)@file_get_contents($log), 'the second server must have taken the port back');
        while (proc_get_status($stale)['running'] && microtime(true) < $deadline) {
            usleep(50000);
        }
        $this->assertFalse(proc_get_status($stale)['running'], 'the stale server must have been stopped');
        $this->waitForReady($port, $log);
        $this->assertNoOutputStarted($port, $log);
        $this->assertTheSessionSurvivesARegenerate($port, $log);
    }

    // ── the flow ────────────────────────────────────────────────────────

    private function assertNoOutputStarted(int $port, string $log): void
    {
        $probe = $this->request($port, 'GET', '/probe');
        $this->assertSame(200, $probe['status'], "GET /probe; console: " . @file_get_contents($log));
        $this->assertFalse(
            $probe['json']['headers_sent'] ?? null,
            'the server process has already sent output, so PHP refuses its native sessions: '
            . $probe['body'] . '; console: ' . @file_get_contents($log)
        );
    }

    /** The #253 flow: establish, regenerate mid-request, read back; then a second client. */
    private function assertTheSessionSurvivesARegenerate(int $port, string $log): void
    {
        $context = '; console: ' . @file_get_contents($log);

        $first = $this->request($port, 'GET', '/start');
        $this->assertSame(200, $first['status'], 'GET /start' . $context);
        $firstSessionId = $first['sessionCookie'];
        $this->assertNotNull($firstSessionId, 'the first request must set a PHPSESSID cookie: ' . $first['head'] . $context);
        $this->assertSame(['id' => $firstSessionId, 'hit' => null], $first['json'], 'the cookie must carry the id of a new, empty session' . $context);

        $regenerated = $this->request($port, 'POST', '/regen', $firstSessionId);
        $this->assertSame(200, $regenerated['status'], 'POST /regen must not fail: ' . $regenerated['body'] . $context);
        $regeneratedSessionId = $regenerated['sessionCookie'];
        $this->assertNotNull($regeneratedSessionId, 'a regenerated id must be sent to the client: ' . $regenerated['head'] . $context);
        $this->assertNotSame($firstSessionId, $regeneratedSessionId, 'session_regenerate_id() must rotate the id' . $context);
        $this->assertSame(['id' => $regeneratedSessionId, 'hit' => 1], $regenerated['json'], $context);

        $after = $this->request($port, 'GET', '/whoami', $regeneratedSessionId);
        $this->assertSame(['id' => $regeneratedSessionId, 'hit' => 1], $after['json'], 'the regenerated session must survive to the next request' . $context);

        // In one process $_SESSION is a process global: a client with no cookie
        // must still get a session of its own, never the previous client's.
        $stranger = $this->request($port, 'GET', '/whoami');
        $this->assertIsArray($stranger['json'], 'GET /whoami from a second client' . $context);
        $this->assertArrayHasKey('hit', $stranger['json'], $context);
        $this->assertNull($stranger['json']['hit'], 'a second client must not see the first client\'s session' . $context);
        $this->assertNotSame($regeneratedSessionId, $stranger['json']['id'] ?? null, 'a second client must get its own session id' . $context);
    }

    // ── real servers ────────────────────────────────────────────────────

    /**
     * Start `php bin/tina4php serve` in the scaffold-shaped app and wait until it
     * answers. By default it is launched the way the tina4 CLI launches it.
     *
     * @param list<string> $flags
     */
    private function serve(int $port, bool $debug, bool $fork, array $flags = ['--managed'], bool $waitForReady = true): string
    {
        $log = self::$appDir . DIRECTORY_SEPARATOR . "serve-{$port}-" . bin2hex(random_bytes(3)) . '.log';
        $environment = $this->environment([
            'TINA4_DEBUG' => $debug ? 'true' : 'false',
            'TINA4_SERVE_FORK' => $fork ? 'true' : 'false',
        ]);
        $this->start(
            [PHP_BINARY, dirname(__DIR__) . '/bin/tina4php', 'serve', ...$flags, '--host', '127.0.0.1', '--port', (string)$port, '--no-browser'],
            $log,
            self::$appDir,
            $environment
        );
        if ($waitForReady) {
            $this->waitForReady($port, $log);
        }
        return $log;
    }

    /** Start the #253 fixture, which boots App::run(), with its banner ON. */
    private function bootFixture(int $port, bool $waitForReady = true): string
    {
        $log = self::$appDir . DIRECTORY_SEPARATOR . "app-run-{$port}-" . bin2hex(random_bytes(3)) . '.log';
        $this->start(
            [PHP_BINARY, __DIR__ . '/fixtures/session_regenerate_native_server.php', (string)$port],
            $log,
            self::$appDir,
            $this->environment(['TINA4_DEBUG' => 'false', 'TINA4_SUPPRESS' => 'false'])
        );
        if ($waitForReady) {
            $this->waitForReady($port, $log);
        }
        return $log;
    }

    /** A port that this test now holds. */
    private function heldPort(): int
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $port = \FreePort::get();
            if ($this->hold($port)) {
                return $port;
            }
        }
        $this->fail('found no free port to hold');
    }

    /** A free port whose Test Port, the port plus 1000, this test now holds. */
    private function portWhoseTestPortIsHeld(): int
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $port = \FreePort::get();
            if ($this->hold($port + 1000)) {
                return $port;
            }
        }
        $this->fail('found no free port whose Test Port was free to hold');
    }

    /**
     * Hold *port* the way another program would.
     *
     * Not with stream_socket_server(): PHP sets SO_REUSEADDR on its listeners,
     * and on Windows that lets the server under test bind the same port anyway,
     * so it never finds the port in use. An ext-sockets listener sets no
     * SO_REUSEADDR and holds the port on Linux and Windows alike.
     */
    private function hold(int $port): bool
    {
        if (!function_exists('socket_create')) {
            $this->markTestSkipped('[needs:ext=sockets] holding a port against the server needs a listener without SO_REUSEADDR');
        }
        $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            return false;
        }
        if (!@socket_bind($socket, '127.0.0.1', $port) || !@socket_listen($socket)) {
            socket_close($socket);
            return false;
        }
        $this->heldPorts[] = $socket;
        return true;
    }

    /**
     * The runner's environment without its own TINA4_ settings, plus these.
     *
     * @param array<string, string> $overrides
     */
    private function environment(array $overrides): array
    {
        $environment = array_filter(getenv(), fn($key) => !str_starts_with($key, 'TINA4_'), ARRAY_FILTER_USE_KEY);
        return array_merge($environment, [
            'TINA4_OVERRIDE_CLIENT' => 'true',   // the socket server without the Rust CLI
            'TINA4_SECRET' => self::SECRET,
            'TINA4_AUTO_MIGRATE' => 'false',
            'TINA4_NO_BROWSER' => 'true',
        ], $overrides);
    }

    /** @param string[] $command */
    private function start(array $command, string $log, string $cwd, array $environment): void
    {
        // Every descriptor is a real file, so the server never holds the runner's
        // own stdin or stdout. Windows has no /dev/null.
        $nullDevice = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $process = proc_open($command, [0 => ['file', $nullDevice, 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $cwd, $environment);
        if (!is_resource($process)) {
            throw new RuntimeException('could not start ' . implode(' ', $command));
        }
        // Keep the handle BEFORE waiting, so tearDown can stop a server that never came up.
        $this->processes[$log] = $process;
    }

    /**
     * Wait for a real response from the server that writes $log. The kernel
     * queues a connection before the server accepts, so only a status line
     * proves it is answering. Any status will do: the #253 fixture has no
     * /probe route and answers 404.
     */
    private function waitForReady(int $port, string $log): void
    {
        $deadline = microtime(true) + 20.0;
        while (microtime(true) < $deadline) {
            if (!proc_get_status($this->processes[$log])['running']) {
                $this->fail("the server exited before it answered on port {$port}: " . @file_get_contents($log));
            }
            $raw = $this->raw($port, "GET /probe HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n", 2.0);
            if (str_starts_with($raw, 'HTTP/')) {
                return;
            }
            usleep(50000);
        }
        $this->fail("the server never answered on port {$port}: " . @file_get_contents($log));
    }

    /**
     * One real HTTP/1.1 request on a raw socket.
     *
     * @return array{status: int, head: string, body: string, json: mixed, sessionCookie: ?string}
     */
    private function request(int $port, string $method, string $path, ?string $sessionId = null): array
    {
        $request = "{$method} {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n";
        if ($sessionId !== null) {
            $request .= "Cookie: PHPSESSID={$sessionId}\r\n";
        }
        if ($method === 'POST') {
            $request .= "Content-Type: application/json\r\nContent-Length: 2\r\n\r\n{}";
        } else {
            $request .= "\r\n";
        }
        $raw = $this->raw($port, $request, 10.0);
        [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        preg_match('#^HTTP/1\.1 (\d{3})#', $head, $status);
        $cookie = preg_match('/^Set-Cookie:\s*PHPSESSID=([^;\r\n]+)/mi', $head, $match) ? $match[1] : null;

        return [
            'status' => isset($status[1]) ? (int)$status[1] : 0,
            'head' => $head,
            'body' => $body,
            'json' => json_decode($body, true),
            'sessionCookie' => $cookie,
        ];
    }

    private function raw(int $port, string $request, float $timeout): string
    {
        $client = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, $timeout);
        if (!is_resource($client)) {
            return '';
        }
        stream_set_timeout($client, (int)ceil($timeout));
        fwrite($client, $request);
        $response = '';
        $deadline = microtime(true) + $timeout;
        while (!feof($client) && microtime(true) < $deadline) {
            $chunk = fread($client, 65536);
            if ($chunk === false) {
                break;
            }
            $response .= $chunk;
            if ($chunk === '' && (stream_get_meta_data($client)['timed_out'] ?? false)) {
                break;
            }
        }
        fclose($client);
        return $response;
    }

    private static function removeTree(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
