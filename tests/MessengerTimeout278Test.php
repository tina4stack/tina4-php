<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

/**
 * Regression for tina4-php#278: the SMTP send timeout is configurable.
 *
 * No mocks: a REAL php child accepts the TCP connection and then says nothing,
 * so send() blocks waiting for the SMTP greeting. At the 30 s default that is a
 * 30 s hold; with a 1 s timeout the send must fail in about a second. The
 * wall-clock is the instrument.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Messenger;

class MessengerTimeout278Test extends TestCase
{
    /** @var resource|null */
    private $proc = null;
    private int $port = 0;

    protected function setUp(): void
    {
        // A free port: bind, read it, release it for the child to take.
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->port = (int)explode(':', stream_socket_get_name($probe, false))[1];
        fclose($probe);

        // A server that accepts and never replies.
        $code = '$s=stream_socket_server("tcp://127.0.0.1:' . $this->port . '");$h=[];'
            . 'while(true){$c=@stream_socket_accept($s,-1);if($c){$h[]=$c;}}';
        $this->proc = proc_open([PHP_BINARY, '-r', $code], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $c = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $e1, $e2, 0.5);
            if ($c) {
                fclose($c);
                return;
            }
            usleep(50000);
        }
        $this->fail('silent server never came up');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->proc)) {
            proc_terminate($this->proc, defined('SIGKILL') ? SIGKILL : 9);
            proc_close($this->proc);
        }
    }

    public function testConstructorTimeoutBoundsTheSend(): void
    {
        $messenger = new Messenger(host: '127.0.0.1', port: $this->port, fromAddress: 'app@localhost', encryption: 'none', timeout: 1);
        $start = microtime(true);
        $result = $messenger->send('someone@localhost', 'test', 'hello');
        $elapsed = microtime(true) - $start;

        $this->assertFalse($result['success']);
        $this->assertLessThan(8, $elapsed, "a 1s timeout should fail fast, not hold ~30s (took {$elapsed}s)");
    }

    public function testEnvTimeoutBoundsTheSend(): void
    {
        putenv('TINA4_MAIL_TIMEOUT=1');
        try {
            $messenger = new Messenger(host: '127.0.0.1', port: $this->port, fromAddress: 'app@localhost', encryption: 'none');
            $start = microtime(true);
            $result = $messenger->send('someone@localhost', 'test', 'hello');
            $elapsed = microtime(true) - $start;
            $this->assertFalse($result['success']);
            $this->assertLessThan(8, $elapsed, "TINA4_MAIL_TIMEOUT=1 should fail fast (took {$elapsed}s)");
        } finally {
            putenv('TINA4_MAIL_TIMEOUT');
        }
    }
}
