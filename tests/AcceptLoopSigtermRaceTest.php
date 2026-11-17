<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A SIGTERM that lands while the accept loop builds its read set must still
 * drain and exit 0 (tina4-php#362's CI failure).
 *
 * The server traps signals with pcntl_async_signals(true), so the SIGTERM
 * handler runs at any opcode boundary. When it ran after acceptLoop() had put
 * the listeners into $read but before stream_select() was called, stop()
 * closed those listeners and stream_select() was handed closed resources. In
 * PHP 8 that is a TypeError, which `@` does not suppress, so the server died
 * with exit 255 instead of draining and exiting 0.
 *
 * No mocks and no hook in shipped code. The window is the userland loop that
 * collects the raw TCP service's sockets into $read, so the test widens it the
 * honest way: a real server holding many real, idle TCP device connections.
 * Each round boots that server, connects the devices, SIGTERMs it at a jittered
 * moment and requires exit 0. One round lands in the window only some of the
 * time (measured on Linux, php 8.4 + pcntl: 32 of 1250 rounds, about 2.6%), so
 * the test runs enough rounds that the unfixed server fails it with near
 * certainty: red in 5 of 5 runs without the fix, green in 5 of 5 with it.
 */
class AcceptLoopSigtermRaceTest extends TestCase
{
    /** Idle device connections per round: they make the read-set build slow. */
    private const CONNECTIONS = 300;

    /** SIGTERM rounds. Each is one real server boot. */
    private const ROUNDS = 250;

    /** @var resource|null */
    private $proc = null;

    /** @var resource[] */
    private array $clients = [];

    protected function tearDown(): void
    {
        $this->closeClients();
        if (is_resource($this->proc)) {
            @proc_terminate($this->proc, defined('SIGKILL') ? SIGKILL : 9);
            @proc_close($this->proc);
            $this->proc = null;
        }
    }

    public function testSigtermWhileTheReadSetIsBuiltStillExitsZero(): void
    {
        if (!defined('SIGTERM') || !function_exists('proc_terminate') || !function_exists('pcntl_async_signals')) {
            $this->markTestSkipped('[needs:runtime=posix-signals] SIGTERM/pcntl unavailable here');
        }

        $failures = [];
        for ($round = 1; $round <= self::ROUNDS; $round++) {
            [$exit, $log] = $this->sigtermRound($round);
            if ($exit !== 0) {
                $failures[] = "round {$round}: exit " . var_export($exit, true) . "\n" . $log;
            }
        }

        $this->assertSame(
            [],
            $failures,
            count($failures) . ' of ' . self::ROUNDS . ' SIGTERMs did not exit 0'
        );
    }

    /** @return array{0:int|null,1:string} the server's exit code and its log */
    private function sigtermRound(int $round): array
    {
        $appDir = \TempPath::dir('tina4_accept_race_');
        $httpPort = \FreePort::get();
        do {
            $tcpPort = \FreePort::get();
        } while ($tcpPort === $httpPort);
        @mkdir($appDir . '/src/routes', 0755, true);
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';

        file_put_contents($appDir . '/index.php', <<<PHP
<?php
require '{$autoload}';
\\Tina4\\Tcp::service({$tcpPort}, [
    'name' => 'devices',
    'on_data' => function (\\Tina4\\TcpConnection \$conn, string \$data) { \$conn->send(\$data); },
]);
\$app = new \\Tina4\\App(__DIR__);
\$app->run('127.0.0.1', {$httpPort});
PHP);
        file_put_contents(
            $appDir . '/.env',
            "TINA4_OVERRIDE_CLIENT=true\nTINA4_NO_BROWSER=true\nTINA4_SUPPRESS=true\n"
            . "TINA4_DEBUG=false\nTINA4_SERVE_FORK=false\nTINA4_SHUTDOWN_TIMEOUT=10\n"
        );

        $log = $appDir . '/server.log';
        $this->proc = proc_open(
            [PHP_BINARY, 'index.php'],
            [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
            $pipes,
            $appDir
        );
        $this->assertIsResource($this->proc, 'could not start the test server');

        $this->connectDevices($tcpPort, $log);

        // Jitter so the signal lands at a different point of the loop each round.
        usleep(random_int(0, 2000));
        proc_terminate($this->proc, SIGTERM);

        $exit = null;
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->proc);
            if ($status['running'] === false) {
                $exit = $status['exitcode'];
                break;
            }
            usleep(20000);
        }
        $this->closeClients();
        @proc_close($this->proc);
        $this->proc = null;

        return [$exit, (string) @file_get_contents($log)];
    }

    /** Open CONNECTIONS idle device connections and wait until the server holds them all. */
    private function connectDevices(int $tcpPort, string $log): void
    {
        $deadline = microtime(true) + 20;
        while (count($this->clients) < self::CONNECTIONS && microtime(true) < $deadline) {
            $client = @stream_socket_client("tcp://127.0.0.1:{$tcpPort}", $errno, $errstr, 0.5);
            if ($client === false) {
                usleep(50000);
                continue;
            }
            $this->clients[] = $client;
        }
        $this->assertCount(
            self::CONNECTIONS,
            $this->clients,
            'could not connect every device -- log: ' . @file_get_contents($log)
        );

        // The last connection is accepted once the server echoes over it.
        $last = end($this->clients);
        fwrite($last, "ping\n");
        stream_set_timeout($last, 10);
        $this->assertSame("ping\n", fread($last, 5), 'the server never served the last device');
    }

    private function closeClients(): void
    {
        foreach ($this->clients as $client) {
            if (is_resource($client)) {
                @fclose($client);
            }
        }
        $this->clients = [];
    }
}
