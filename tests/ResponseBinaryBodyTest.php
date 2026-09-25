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
 *
 * A binary body sent with an EXPLICIT content type arrives byte for byte.
 *
 * The bug class (found in tina4-nodejs, checked here at parity): a byte body
 * given with a content type is re-encoded as text on the way out, so an image
 * or download route sends a broken file. One REAL server (App::run) in a child
 * process, real routes, a real socket; each route sends all 256 byte values
 * (0x00-0xFF) and the test compares the raw bytes received. No mocks.
 */

use PHPUnit\Framework\TestCase;

class ResponseBinaryBodyTest extends TestCase
{
    private static function allBytes(): string
    {
        return implode('', array_map('chr', range(0, 255)));
    }

    /** @return array{status:int, type:string, body:string} */
    private static function get(int $port, string $path): array
    {
        $context = stream_context_create(['http' => [
            'header' => "Accept-Encoding: identity\r\nConnection: close\r\n",
            'ignore_errors' => true,
            'timeout' => 10,
        ]]);
        $stream = fopen("http://127.0.0.1:{$port}{$path}", 'rb', false, $context);
        $headers = stream_get_meta_data($stream)['wrapper_data'] ?? [];
        $body = stream_get_contents($stream);
        fclose($stream);
        $status = (int)(explode(' ', $headers[0] ?? '')[1] ?? 0);
        $type = '';
        foreach ($headers as $line) {
            if (stripos($line, 'content-type:') === 0) {
                $type = trim(substr($line, strlen('content-type:')));
            }
        }
        return ['status' => $status, 'type' => $type, 'body' => (string)$body];
    }

    public function testBinaryBodiesArriveUnchangedThroughARealServer(): void
    {
        $port = \FreePort::get();
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/response_binary_body_server.php', (string)$port],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            dirname(__DIR__),
            [
                'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
                'HOME' => getenv('HOME') ?: sys_get_temp_dir(),
                'TINA4_OVERRIDE_CLIENT' => 'true',
                'TINA4_SUPPRESS' => 'true',
                'TINA4_AUTO_MIGRATE' => 'false',
                'TINA4_DEBUG' => 'false',
                'TINA4_SECRET' => 'tina4-php-test-suite-secret-0123456789abcdef',
            ]
        );
        $this->assertIsResource($process, 'the server process must start');

        try {
            $listening = false;
            for ($i = 0; $i < 400 && !$listening; $i++) {
                $socket = @fsockopen('127.0.0.1', $port, $errorNumber, $errorText, 0.2);
                if ($socket !== false) {
                    fclose($socket);
                    $listening = true;
                } else {
                    usleep(25000);
                }
            }
            $this->assertTrue($listening, 'the real server must listen');

            // Positive: every byte body with an explicit type arrives identical.
            foreach (['/bin/call' => 'image/png', '/bin/send' => 'application/octet-stream'] as $path => $contentType) {
                $reply = self::get($port, $path);
                $this->assertSame(200, $reply['status'], "{$path}: status");
                $this->assertSame($contentType, $reply['type'], "{$path}: content type");
                $this->assertSame(bin2hex(self::allBytes()), bin2hex($reply['body']), "{$path}: the 256 bytes 0x00-0xFF must arrive identical");
            }

            // Controls: the other branches still behave.
            $auto = self::get($port, '/bin/auto');
            $this->assertSame(bin2hex(self::allBytes()), bin2hex($auto['body']), 'no content type: the bytes arrive identical');
            $this->assertSame("h\u{e9}llo", self::get($port, '/bin/text')['body'], 'a string with an explicit type is sent as UTF-8 text');
            $json = self::get($port, '/bin/json');
            $this->assertSame('application/vnd.api+json', $json['type']);
            $this->assertSame('{"a":1}', $json['body'], 'an array with an explicit type is still JSON');
        } finally {
            proc_terminate($process, SIGKILL);
            proc_close($process);
        }
    }
}
