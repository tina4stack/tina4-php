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

/**
 * HEAD Content-Length ON THE WIRE (RFC 9110 s9.3.2 / RFC 7230 s3.3.2).
 *
 * The sibling HeadNoBodyConformanceTest drives Router::dispatch in-process and
 * reads a Response whose headers are an assoc array - which can neither hold a
 * duplicate header nor show what the SOCKET SERVER finally writes. The real
 * failure lives in the transport: Server.php recomputed Content-Length from the
 * already-stripped (empty) HEAD body, so every routed HEAD went out as
 * `Content-Length: 0` instead of the length the GET would have sent. A strict
 * proxy also rejects the DUPLICATE Content-Length shape (two differing values)
 * with 502. Both are only visible on the raw wire, so this boots the real
 * built-in server and reads bytes off a socket.
 *
 * NO MOCKS: a real `tina4php serve` child, a real socket, reaped in a finally.
 * Same intent as the Python ASGI-header-list and Node raw-socket HEAD tests.
 */
class HeadWireContentLengthTest extends TestCase
{
    private const SECRET = 'head-wire-conformance-secret-0123456789abcdef';

    /** @return array{0:int,1:int} [status... ] not used; returns [count, length] */
    private function rawHead(int $port, string $method, string $path): array
    {
        $fp = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 5);
        $this->assertNotFalse($fp, "could not connect: {$errstr}");
        fwrite($fp, "{$method} {$path} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nConnection: close\r\n\r\n");
        stream_set_timeout($fp, 5);
        $raw = '';
        while (!feof($fp)) {
            $chunk = fread($fp, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $raw .= $chunk;
        }
        fclose($fp);
        [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        $count = preg_match_all('/^content-length:/im', $head);
        preg_match('/^content-length:\s*(\d+)/im', $head, $m);
        $length = isset($m[1]) ? (int) $m[1] : -1;
        return [$count, $length, strlen($body)];
    }

    public function testHeadOverTheWireIsOneContentLengthEqualToGet(): void
    {
        $dir = sys_get_temp_dir() . '/tina4_head_wire_' . uniqid('', true);
        mkdir($dir . '/src/routes', 0755, true);
        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        $cli = realpath(__DIR__ . '/../bin/tina4php');
        file_put_contents($dir . '/index.php', "<?php\nrequire_once '{$autoload}';\n\$app = new \\Tina4\\App(basePath: __DIR__);\n\$app->handle();\n");
        file_put_contents(
            $dir . '/src/routes/version.php',
            "<?php\n\\Tina4\\Router::get('/api/version', function (\$request, \$response) {\n"
            . "    return \$response->json(['version' => '3.13.143', 'padding' => str_repeat('x', 80)]);\n});\n"
        );
        $port = \FreePort::get();
        $env = array_filter(getenv(), fn($k) => !str_starts_with($k, 'TINA4_'), ARRAY_FILTER_USE_KEY);
        $env = array_merge($env, [
            'TINA4_NO_BROWSER' => 'true', 'TINA4_SECRET' => self::SECRET,
            'TINA4_SERVE_FORK' => 'false', 'TINA4_NO_TAKEOVER' => 'true',
            'TINA4_OVERRIDE_CLIENT' => 'true', 'TINA4_NO_AI_PORT' => 'true',
        ]);
        $log = $dir . '/serve.log';
        $cmd = 'exec ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cli)
            . ' serve --host 127.0.0.1 --port ' . $port . ' --no-browser';
        $process = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $dir, $env);
        $this->assertIsResource($process);
        try {
            $ready = false;
            $deadline = microtime(true) + 20;
            while (microtime(true) < $deadline) {
                if (!proc_get_status($process)['running']) {
                    $this->fail('serve exited early: ' . @file_get_contents($log));
                }
                $ctx = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 2]]);
                if (@file_get_contents("http://127.0.0.1:{$port}/api/version", false, $ctx) !== false) {
                    $ready = true;
                    break;
                }
                usleep(100000);
            }
            $this->assertTrue($ready, 'server never served /api/version: ' . @file_get_contents($log));

            [$getCount, $getLength] = $this->rawHead($port, 'GET', '/api/version');
            $this->assertSame(1, $getCount, 'GET emitted more than one Content-Length');
            $this->assertGreaterThan(0, $getLength, 'GET Content-Length should be the body size');

            [$headCount, $headLength, $headBodyBytes] = $this->rawHead($port, 'HEAD', '/api/version');
            $this->assertSame(1, $headCount, "HEAD emitted {$headCount} Content-Length headers (a strict proxy 502s a duplicate)");
            $this->assertSame($getLength, $headLength, "HEAD Content-Length ({$headLength}) must equal the GET length ({$getLength}), not 0");
            $this->assertSame(0, $headBodyBytes, 'HEAD must carry no body');
        } finally {
            proc_terminate($process, 15);
            $wait = microtime(true) + 5;
            while (proc_get_status($process)['running'] && microtime(true) < $wait) {
                usleep(50000);
            }
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            proc_close($process);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}
