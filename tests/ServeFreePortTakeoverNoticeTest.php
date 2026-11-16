<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

namespace Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `tina4php serve` must only say a port is in use when something holds it.
 *
 * PortTakeover::takeOverPort() used to refuse outside dev mode, and when takeover
 * was opted out, before it looked for a holder. So with debug off (or --no-kill)
 * every start printed "Port N is in use ..." on a port nothing held, and a
 * production operator was told a free port was taken.
 *
 * Each case starts the real CLI in a scaffold-shaped app, waits until it answers,
 * and reads what it printed. The held-port case is the control: the notice must
 * still appear when the port really is taken.
 */
class ServeFreePortTakeoverNoticeTest extends TestCase
{
    private const SECRET = 'tina4-php-test-suite-secret-0123456789abcdef';

    private string $appDir = '';

    /** @var resource[] */
    private array $processes = [];

    /** @var \Socket[] */
    private array $heldPorts = [];

    protected function setUp(): void
    {
        $this->appDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tina4_serve_free_port_' . bin2hex(random_bytes(6));
        @mkdir($this->appDir . '/src/routes', 0777, true);
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        file_put_contents($this->appDir . '/index.php', <<<PHP
        <?php
        require_once '{$autoload}';
        \$app = new \\Tina4\\App(basePath: __DIR__);
        \$app->handle();
        PHP);
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if (!is_resource($process)) {
                continue;
            }
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
        if ($this->appDir !== '' && is_dir($this->appDir)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->appDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->appDir);
        }
    }

    public function testServeWithDebugOffSaysNothingAboutAFreePort(): void
    {
        $port = \FreePort::get();
        $log = $this->serve($port, ['TINA4_DEBUG' => 'false']);
        $this->waitForReady($port, $log);

        $this->assertStringNotContainsString('is in use', (string)file_get_contents($log));
    }

    public function testServeWithNoKillSaysNothingAboutAFreePort(): void
    {
        $port = \FreePort::get();
        $log = $this->serve($port, ['TINA4_DEBUG' => 'true'], ['--no-kill']);
        $this->waitForReady($port, $log);

        $this->assertStringNotContainsString('is in use', (string)file_get_contents($log));
    }

    public function testServeWithDebugOffStillRefusesAHeldPort(): void
    {
        $port = $this->heldPort();
        $log = $this->serve($port, ['TINA4_DEBUG' => 'false']);

        $deadline = microtime(true) + 20.0;
        while (microtime(true) < $deadline && !str_contains((string)@file_get_contents($log), 'is in use')) {
            usleep(50000);
        }
        $this->assertStringContainsString(
            "Port {$port} is in use; takeover is disabled outside dev mode",
            (string)file_get_contents($log)
        );
    }

    /** @param list<string> $flags */
    private function serve(int $port, array $overrides, array $flags = []): string
    {
        $log = $this->appDir . DIRECTORY_SEPARATOR . "serve-{$port}.log";
        $environment = array_filter(getenv(), fn($key) => !str_starts_with($key, 'TINA4_'), ARRAY_FILTER_USE_KEY);
        $environment = array_merge($environment, [
            'TINA4_OVERRIDE_CLIENT' => 'true',
            'TINA4_SECRET' => self::SECRET,
            'TINA4_AUTO_MIGRATE' => 'false',
            'TINA4_NO_BROWSER' => 'true',
            'TINA4_SERVE_FORK' => 'false',
        ], $overrides);
        $command = [PHP_BINARY, dirname(__DIR__) . '/bin/tina4php', 'serve', ...$flags,
            '--host', '127.0.0.1', '--port', (string)$port, '--no-browser', '--no-reload'];
        // Windows has no /dev/null.
        $nullDevice = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        $process = proc_open($command, [0 => ['file', $nullDevice, 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $this->appDir, $environment);
        if (!is_resource($process)) {
            throw new RuntimeException('could not start ' . implode(' ', $command));
        }
        $this->processes[] = $process;
        return $log;
    }

    private function waitForReady(int $port, string $log): void
    {
        $process = end($this->processes);
        $deadline = microtime(true) + 20.0;
        while (microtime(true) < $deadline) {
            if (!proc_get_status($process)['running']) {
                $this->fail("the server exited before it answered on port {$port}: " . @file_get_contents($log));
            }
            $client = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 1.0);
            if (is_resource($client)) {
                stream_set_timeout($client, 2);
                fwrite($client, "GET / HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
                $head = (string)fread($client, 16);
                fclose($client);
                if (str_starts_with($head, 'HTTP/')) {
                    return;
                }
            }
            usleep(50000);
        }
        $this->fail("the server never answered on port {$port}: " . @file_get_contents($log));
    }

    /**
     * A port this test holds with an ext-sockets listener: no SO_REUSEADDR, so the
     * server under test finds it taken on Linux and Windows alike.
     */
    private function heldPort(): int
    {
        if (!function_exists('socket_create')) {
            $this->markTestSkipped('[needs:ext=sockets] holding a port needs a listener without SO_REUSEADDR');
        }
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $port = \FreePort::get();
            $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if ($socket !== false && @socket_bind($socket, '127.0.0.1', $port) && @socket_listen($socket)) {
                $this->heldPorts[] = $socket;
                return $port;
            }
            if ($socket !== false) {
                socket_close($socket);
            }
        }
        $this->fail('found no free port to hold');
    }
}
