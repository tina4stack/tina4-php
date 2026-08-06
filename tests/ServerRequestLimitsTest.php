<?php declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * A CLIENT MUST NOT BE ABLE TO HOLD A SLOT, OR GROW A BUFFER, FOREVER.
 *
 * Two defects, both measured in the code before this file existed, and both
 * reachable from the network because the server binds 0.0.0.0 by default - a
 * developer's laptop is on the LAN, not on localhost.
 *
 *   1. NO READ OR IDLE TIMEOUT. lastActivity was tracked for WebSocket clients
 *      only. An HTTP connection that opened and never finished its request kept
 *      its slot and its buffer for the life of the process. That is slowloris.
 *
 *   2. NO CAP ON THE BUFFER. processHttpBuffer() returned "not enough data yet"
 *      with no size check, and the read path appended unconditionally:
 *
 *          $this->buffers[$id] = ($this->buffers[$id] ?? '') . $data;
 *
 *      A client streaming an endless header block grew that string until the
 *      process died.
 *
 * NO MOCKS: a real server process, real sockets, a real clock. The property
 * under test is what the server does to a hostile peer over TCP, and nothing
 * short of a hostile peer over TCP can show it.
 */

use PHPUnit\Framework\TestCase;

class ServerRequestLimitsTest extends TestCase
{
    /** Seconds of silence the test server tolerates before closing a connection. */
    private const REQUEST_TIMEOUT = 2;

    /** Header bytes the test server accepts before answering 431. */
    private const MAX_HEADER = 16384;

    /** Body bytes the test server accepts before answering 413. */
    private const MAX_BODY = 65536;

    private string $appDir = '';
    /** @var resource|null */
    private $proc = null;
    private int $port = 0;

    protected function setUp(): void
    {
        $this->appDir = \TempPath::dir('tina4_limits_');
        $this->startServer();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->proc)) {
            $status = proc_get_status($this->proc);
            if (($status['running'] ?? false) && function_exists('posix_kill')) {
                @posix_kill($status['pid'], SIGTERM);
            }
            proc_terminate($this->proc, SIGTERM);
            @proc_close($this->proc);
            $this->proc = null;
        }
    }

    private function startServer(): void
    {
        $port = \FreePort::get();
        mkdir($this->appDir . '/src/routes', 0755, true);
        file_put_contents($this->appDir . '/src/routes/limits.php', <<<'PHP'
<?php
\Tina4\Router::get("/ok", function ($request, $response) {
    return $response("ok", 200);
});
PHP);

        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        file_put_contents($this->appDir . '/index.php', <<<PHP
<?php
require '{$autoload}';
\$app = new \\Tina4\\App(__DIR__);
\$app->run('127.0.0.1', {$port});
PHP);

        file_put_contents($this->appDir . '/.env',
            "TINA4_OVERRIDE_CLIENT=true\nTINA4_NO_BROWSER=true\nTINA4_DEBUG=false\n"
            . 'TINA4_REQUEST_TIMEOUT=' . self::REQUEST_TIMEOUT . "\n"
            . 'TINA4_MAX_REQUEST_HEADER=' . self::MAX_HEADER . "\n"
            . 'TINA4_MAX_REQUEST_BODY=' . self::MAX_BODY . "\n"
        );

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
                return;
            }
            usleep(150000);
        }
        $this->fail('the test server never accepted on port ' . $port
            . ' - log: ' . @file_get_contents($this->appDir . '/server.log'));
    }

    /** @return resource */
    private function connect()
    {
        $sock = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 5);
        $this->assertIsResource($sock, "could not connect: {$errstr}");
        return $sock;
    }

    // ── THE INSTRUMENT ──────────────────────────────────────────────────────

    public function testANormalRequestStillWorks(): void
    {
        $body = @file_get_contents("http://127.0.0.1:{$this->port}/ok");
        $this->assertSame('ok', $body, 'the server must serve a normal request, or nothing below means anything');
    }

    // ── SLOWLORIS ───────────────────────────────────────────────────────────

    /**
     * A connection that never completes its request must be closed.
     *
     * The request line and one header go out, and then nothing - no terminating
     * blank line, ever. Before the fix the server held this connection, and its
     * buffer, until the process died. Holding a few hundred of these is the
     * whole attack.
     */
    public function testAHalfOpenRequestIsClosedByTheServer(): void
    {
        $sock = $this->connect();
        fwrite($sock, "GET /ok HTTP/1.1\r\nHost: 127.0.0.1\r\n");   // deliberately unterminated

        // Wait past the timeout, then read. A server that closed the connection
        // gives EOF; one that is still holding it gives nothing and blocks.
        stream_set_timeout($sock, self::REQUEST_TIMEOUT + 6);
        $start = microtime(true);
        $read = stream_get_contents($sock);
        $elapsed = microtime(true) - $start;
        $info = stream_get_meta_data($sock);
        fclose($sock);

        $this->assertFalse(
            $info['timed_out'] ?? false,
            "the server never closed a half-open request after {$elapsed}s. A client that "
            . 'connects and stops sending can hold a slot and a buffer forever, which is '
            . 'slowloris.'
        );
        $this->assertLessThan(
            self::REQUEST_TIMEOUT + 5,
            $elapsed,
            'the connection was closed, but far later than TINA4_REQUEST_TIMEOUT asked for'
        );
        // Either a clean 408 or a bare close is acceptable; silence is not.
        if ($read !== '' && $read !== false) {
            $this->assertStringContainsString('408', $read, 'if the server answers, it should say 408');
        }
    }

    // ── HEADER FLOOD ────────────────────────────────────────────────────────

    /**
     * Headers past the cap must be refused, not buffered.
     *
     * This sends well past TINA4_MAX_REQUEST_HEADER without ever terminating the
     * header block. Before the fix every byte was appended to an unbounded
     * string, so the only limit was the process memory limit.
     */
    public function testHeadersPastTheCapAreRefused(): void
    {
        $sock = $this->connect();
        fwrite($sock, "GET /ok HTTP/1.1\r\nHost: 127.0.0.1\r\n");

        $filler = "X-Filler: " . str_repeat('A', 4000) . "\r\n";
        $sent = 0;
        $refused = false;
        // Push past the cap. A server that caps stops reading and answers or
        // closes, so the write fails or the read returns something.
        for ($i = 0; $i < 40; $i++) {
            $wrote = @fwrite($sock, $filler);
            if ($wrote === false || $wrote === 0) {
                $refused = true;
                break;
            }
            $sent += $wrote;
            if ($sent > self::MAX_HEADER * 2) {
                break;
            }
        }

        stream_set_timeout($sock, 8);
        $read = stream_get_contents($sock);
        $info = stream_get_meta_data($sock);
        fclose($sock);

        $answered = is_string($read) && $read !== '';
        $closed = !($info['timed_out'] ?? false);

        $this->assertTrue(
            $refused || $answered || $closed,
            'the server accepted ' . $sent . ' bytes of unterminated headers without '
            . 'refusing, answering or closing. The read buffer is unbounded, so a client '
            . 'can grow it until the process dies.'
        );
        if ($answered) {
            $this->assertMatchesRegularExpression(
                '/ (431|400|413) /',
                $read,
                'a refused header flood should be 431 (or 400/413), got: ' . substr($read, 0, 120)
            );
        }
    }

    /**
     * An oversized body is refused with 413 on the DECLARED Content-Length,
     * before the bytes are read.
     *
     * The framework already did this - Server.php answers 413 from the declared
     * length on the first packet rather than buffering and then objecting - but
     * nothing asserted it. The header flood above was covered and the body was
     * not, so the half of the DoS surface that carries the payload had no gate.
     *
     * Node and Python both shipped the opposite bug (measure the body, THEN
     * refuse), which is exactly what this pins PHP against acquiring.
     */
    public function testAnOversizedBodyIsRefusedWith413(): void
    {
        $sock = @stream_socket_client("tcp://127.0.0.1:{$this->port}", $errno, $errstr, 5);
        $this->assertIsResource($sock, "could not connect: $errstr");

        $declared = self::MAX_BODY * 4;
        $request = "POST /nope HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Content-Type: application/octet-stream\r\n"
            . "Content-Length: {$declared}\r\n"
            . "\r\n";
        fwrite($sock, $request);
        // Deliberately send only a sliver of the declared body. A server that
        // waits for all of it before deciding would hang here; one that reads
        // the declared length answers immediately.
        fwrite($sock, str_repeat('a', 512));

        stream_set_timeout($sock, 8);
        $read = stream_get_contents($sock);
        fclose($sock);

        $this->assertNotSame('', (string)$read, 'the server never answered an oversized body');
        $this->assertMatchesRegularExpression(
            '/ 413 /',
            $read,
            'an oversized body should be 413, got: ' . substr((string)$read, 0, 160)
        );
    }

    /**
     * The pair for the test above: a body comfortably UNDER the cap is served
     * normally. A server that refused every POST would pass the 413 test and be
     * useless.
     */
    public function testABodyUnderTheCapIsStillAccepted(): void
    {
        $sock = @stream_socket_client("tcp://127.0.0.1:{$this->port}", $errno, $errstr, 5);
        $this->assertIsResource($sock, "could not connect: $errstr");

        $body = str_repeat('a', 1024);
        $this->assertLessThan(self::MAX_BODY, strlen($body), 'this control must stay under the cap');
        $request = "POST /nope HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Content-Type: application/octet-stream\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "\r\n" . $body;
        fwrite($sock, $request);

        stream_set_timeout($sock, 8);
        $read = stream_get_contents($sock);
        fclose($sock);

        $this->assertNotSame('', (string)$read, 'the server never answered a legal body');
        $this->assertDoesNotMatchRegularExpression(
            '/ 413 /',
            $read,
            'a body under the cap must not be refused, got: ' . substr((string)$read, 0, 160)
        );
    }

    // ── NEGATIVE CONTROL ────────────────────────────────────────────────────

    /**
     * The pair that stops the two tests above passing for the wrong reason.
     *
     * A large but LEGAL header block, comfortably under the cap, must still be
     * served. A server that simply closed every connection carrying big headers
     * would satisfy the tests above and be useless.
     */
    public function testALargeButLegalHeaderBlockIsStillServed(): void
    {
        $filler = str_repeat('B', 2000);
        $request = "GET /ok HTTP/1.1\r\nHost: 127.0.0.1\r\nX-Legal: {$filler}\r\nConnection: close\r\n\r\n";
        $this->assertLessThan(self::MAX_HEADER, strlen($request), 'this control must stay under the cap');

        $sock = $this->connect();
        fwrite($sock, $request);
        stream_set_timeout($sock, 8);
        $read = stream_get_contents($sock);
        fclose($sock);

        $this->assertStringContainsString(
            '200',
            (string)$read,
            'a legal request with large-but-under-cap headers must still be served'
        );
    }
}
