<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * WIRE coverage for Response::stream() (Server-Sent Events) on BOTH transports.
 *
 * tests/SSETest.php builds every Response with `testing: true`, which returns
 * early before any header/emit work — so the twelve tests there prove the
 * in-memory chunk collector and NOTHING about what a client receives. That is
 * why SSE shipped 500ing on the built-in `tina4php serve`: the CLI banner echo
 * marks output as started for the whole process, so http_response_code() inside
 * stream() threw "headers already sent", and even with that silenced the echoed
 * chunks land on the SERVER's stdout (the socket server bypasses PHP's output
 * layer entirely) instead of on the client socket.
 *
 * These tests boot two REAL servers on real ports and read real bytes off a
 * real socket:
 *   - `php bin/tina4php serve` — the built-in Tina4\Server (raw stream_socket).
 *   - `php -S` with the scaffolded index.php — the App::handle() web-SAPI path.
 *
 * Both must answer 200 with the SSE headers and both streamed chunks, deliver
 * the chunks incrementally (not buffered to the end), and leave no
 * "headers already sent" / uncaught ErrorException behind in the server log.
 */
class SSEWireTest extends TestCase
{
    /** Chunk gap in the /audit/sse-slow route, in microseconds. */
    private const SLOW_GAP_MICROSECONDS = 700000;

    private static string $appDir = '';

    /** @var resource|null Built-in `tina4php serve` process. */
    private static $builtInProcess = null;
    private static int $builtInPort = 0;
    private static string $builtInLog = '';

    /** @var resource|null `php -S` (App::handle) process. */
    private static $sapiProcess = null;
    private static int $sapiPort = 0;
    private static string $sapiLog = '';

    public static function setUpBeforeClass(): void
    {
        self::$appDir = sys_get_temp_dir() . '/tina4_sse_wire_' . bin2hex(random_bytes(6));
        @mkdir(self::$appDir . '/src/routes', 0777, true);

        $autoload = dirname(__DIR__) . '/vendor/autoload.php';

        // A clean-room scaffolded app: the real framework, the real
        // convention-discovered route folder, no database, no app code.
        file_put_contents(self::$appDir . '/index.php', <<<PHP
        <?php
        require_once '{$autoload}';
        \$app = new \\Tina4\\App(basePath: __DIR__);
        \$app->handle();
        PHP);

        $gap = self::SLOW_GAP_MICROSECONDS;
        file_put_contents(self::$appDir . '/src/routes/sse.php', <<<PHP
        <?php
        \\Tina4\\Router::get('/audit/sse', function (\\Tina4\\Request \$request, \\Tina4\\Response \$response) {
            return \$response->stream(function () {
                yield "data: one\\n\\n";
                yield "data: two\\n\\n";
            });
        });

        // Same route, with a real gap between the chunks — proves the transport
        // flushes each chunk as it is yielded instead of buffering the lot.
        \\Tina4\\Router::get('/audit/sse-slow', function (\\Tina4\\Request \$request, \\Tina4\\Response \$response) {
            return \$response->stream(function () {
                yield "data: first\\n\\n";
                usleep({$gap});
                yield "data: second\\n\\n";
            });
        });

        // Non-SSE stream: the content type must be honoured on the wire too.
        \\Tina4\\Router::get('/audit/ndjson', function (\\Tina4\\Request \$request, \\Tina4\\Response \$response) {
            return \$response->stream(function () {
                yield "{\\"event\\":\\"start\\"}\\n";
                yield "{\\"event\\":\\"end\\"}\\n";
            }, 'application/x-ndjson');
        });

        // A source that raises mid-stream must keep what it already yielded and
        // end cleanly — no 500, no dead worker.
        \\Tina4\\Router::get('/audit/sse-boom', function (\\Tina4\\Request \$request, \\Tina4\\Response \$response) {
            return \$response->stream(function () {
                yield "data: before\\n\\n";
                throw new \\RuntimeException('generator blew up');
            });
        });

        // An app that also flushes the response itself. The transport must not
        // emit the body a second time.
        \\Tina4\\Router::get('/audit/sse-double', function (\\Tina4\\Request \$request, \\Tina4\\Response \$response) {
            \$streamed = \$response->stream(function () {
                yield "data: once\\n\\n";
            });
            \$streamed->send();
            return \$streamed;
        });

        // Plain (non-streamed) route — guards against the streaming branch
        // hijacking ordinary responses on either transport.
        \\Tina4\\Router::get('/audit/plain', function (\\Tina4\\Request \$request, \\Tina4\\Response \$response) {
            return \$response->json(['ok' => true]);
        });
        PHP);

        file_put_contents(
            self::$appDir . '/.env',
            "TINA4_DEBUG=false\nTINA4_OVERRIDE_CLIENT=true\nTINA4_NO_BROWSER=true\nTINA4_AUTO_MIGRATE=false\n"
        );

        self::$builtInLog = self::$appDir . '/builtin.log';
        self::$sapiLog = self::$appDir . '/sapi.log';

        self::$builtInPort = self::freePort();
        self::boot(
            [PHP_BINARY, dirname(__DIR__) . '/bin/tina4php', 'serve', '--port', (string)self::$builtInPort, '--no-browser'],
            self::$builtInLog,
            self::$builtInPort,
            'builtin'
        );

        self::$sapiPort = self::freePort();
        self::boot(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$sapiPort, self::$appDir . '/index.php'],
            self::$sapiLog,
            self::$sapiPort,
            'sapi'
        );
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$builtInProcess, self::$sapiProcess] as $process) {
            if (is_resource($process)) {
                // SIGKILL the process itself. The array form of proc_open puts no
                // shell in between, so a negative-pid (process group) kill would
                // take the test runner down with it.
                proc_terminate($process, SIGKILL);
                proc_close($process);
            }
        }
        self::$builtInProcess = null;
        self::$sapiProcess = null;

        if (self::$appDir !== '' && is_dir(self::$appDir)) {
            self::removeTree(self::$appDir);
        }
    }

    // ── real servers ────────────────────────────────────────────────────

    /** Reserve a free localhost TCP port by binding :0 and reading it back. */
    private static function freePort(): int
    {
        return \FreePort::get();
    }

    /**
     * Start a real server and wait until it answers a real request.
     *
     * @param string[] $command
     * @param string   $which    'builtin' or 'sapi' — which handle to publish
     */
    private static function boot(array $command, string $logFile, int $port, string $which): void
    {
        $environment = [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: sys_get_temp_dir(),
            // Boot the socket server without the Rust CLI supervising it.
            'TINA4_OVERRIDE_CLIENT' => 'true',
            'TINA4_DEBUG' => 'false',
            'TINA4_AUTO_MIGRATE' => 'false',
            'TINA4_NO_BROWSER' => 'true',
        ];

        $process = proc_open(
            $command,
            [1 => ['file', $logFile, 'w'], 2 => ['file', $logFile, 'a']],
            $pipes,
            self::$appDir,
            $environment
        );
        if (!is_resource($process)) {
            throw new RuntimeException('could not start ' . implode(' ', $command));
        }
        // Publish the handle BEFORE waiting: if the readiness loop gives up,
        // tearDownAfterClass still has something to kill and the server is not
        // orphaned into the background.
        if ($which === 'builtin') {
            self::$builtInProcess = $process;
        } else {
            self::$sapiProcess = $process;
        }

        for ($attempt = 0; $attempt < 200; $attempt++) {
            $raw = self::rawRequest($port, '/audit/plain', 2.0);
            if ($raw !== '' && str_contains($raw, 'HTTP/1.1')) {
                return;
            }
            usleep(50000);
        }

        throw new RuntimeException('server never came up on port ' . $port . ': ' . @file_get_contents($logFile));
    }

    /**
     * Issue a real HTTP/1.1 request on a raw socket and read the whole answer.
     *
     * Deliberately NOT file_get_contents()/curl: an SSE body has no
     * Content-Length, so the read must run to EOF and every byte the server
     * wrote (status line, headers, each flushed chunk) has to be observable.
     */
    private static function rawRequest(int $port, string $path, float $timeout = 10.0): string
    {
        $client = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, $timeout);
        if (!is_resource($client)) {
            return '';
        }
        fwrite($client, "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nAccept: text/event-stream\r\nConnection: close\r\n\r\n");
        stream_set_timeout($client, (int)ceil($timeout));

        $raw = '';
        while (!feof($client)) {
            $chunk = fread($client, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $raw .= $chunk;
        }
        fclose($client);
        return $raw;
    }

    /**
     * Read a response and record WHEN each marker first appeared, so
     * incremental delivery can be asserted without absolute timings.
     *
     * @param string[] $markers
     * @return array{raw: string, seen: array<string, float>}
     */
    private static function rawRequestTimed(int $port, string $path, array $markers, float $timeout = 10.0): array
    {
        $client = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, $timeout);
        if (!is_resource($client)) {
            return ['raw' => '', 'seen' => []];
        }
        fwrite($client, "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nAccept: text/event-stream\r\nConnection: close\r\n\r\n");
        stream_set_timeout($client, (int)ceil($timeout));

        $raw = '';
        $seen = [];
        $start = microtime(true);
        while (!feof($client)) {
            $chunk = fread($client, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $raw .= $chunk;
            foreach ($markers as $marker) {
                if (!isset($seen[$marker]) && str_contains($raw, $marker)) {
                    $seen[$marker] = microtime(true) - $start;
                }
            }
        }
        fclose($client);
        return ['raw' => $raw, 'seen' => $seen];
    }

    /**
     * Split a raw response into its status line, headers and body.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private static function parse(string $raw): array
    {
        $split = strpos($raw, "\r\n\r\n");
        $head = $split === false ? $raw : substr($raw, 0, $split);
        $body = $split === false ? '' : substr($raw, $split + 4);

        $lines = explode("\r\n", $head);
        $statusLine = array_shift($lines) ?? '';
        preg_match('#^HTTP/1\.\d (\d{3})#', $statusLine, $match);

        $headers = [];
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
            }
        }

        return ['status' => (int)($match[1] ?? 0), 'headers' => $headers, 'body' => $body];
    }

    private static function removeTree(string $dir): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    /** @return array<string, array{0: string}> */
    public static function transports(): array
    {
        return ['built-in tina4php serve' => ['builtin'], 'php -S / App::handle' => ['sapi']];
    }

    private function portFor(string $transport): int
    {
        return $transport === 'builtin' ? self::$builtInPort : self::$sapiPort;
    }

    private function logFor(string $transport): string
    {
        return $transport === 'builtin' ? self::$builtInLog : self::$sapiLog;
    }

    // ── POSITIVE: the SSE wire contract, on both transports ─────────────

    /**
     * THE D2 lock-in. Against the pre-fix code the built-in server answers
     * 500 with an ErrorException overlay ("http_response_code(): Cannot set
     * response code - headers already sent"), so status, content type and both
     * chunks all fail here.
     */
    #[DataProvider('transports')]
    public function testSseStreamsOverTheWire(string $transport): void
    {
        $raw = self::rawRequest($this->portFor($transport), '/audit/sse');
        $this->assertNotSame('', $raw, 'the server must answer on ' . $transport);

        $response = self::parse($raw);

        $this->assertSame(200, $response['status'], "SSE must be 200 on {$transport}; raw: " . substr($raw, 0, 400));
        $this->assertStringContainsString(
            'text/event-stream',
            $response['headers']['content-type'] ?? '',
            'Content-Type must be text/event-stream on ' . $transport
        );
        $this->assertStringContainsString('no-cache', $response['headers']['cache-control'] ?? '');
        $this->assertSame('no', $response['headers']['x-accel-buffering'] ?? '');

        $this->assertStringContainsString("data: one\n\n", $response['body']);
        $this->assertStringContainsString("data: two\n\n", $response['body']);
        $this->assertLessThan(
            strpos($response['body'], 'data: two'),
            strpos($response['body'], 'data: one'),
            'chunks must arrive in the order the generator yielded them'
        );
    }

    /**
     * NEGATIVE: nothing that looks like the pre-fix failure may reach the
     * client — no 500, no error overlay, no "headers already sent" text.
     */
    #[DataProvider('transports')]
    public function testSseResponseCarriesNoHeadersAlreadySentError(string $transport): void
    {
        $raw = self::rawRequest($this->portFor($transport), '/audit/sse');
        $response = self::parse($raw);

        $this->assertNotSame(500, $response['status'], 'SSE must not 500 on ' . $transport);
        $this->assertStringNotContainsString('headers already sent', $raw);
        $this->assertStringNotContainsString('ErrorException', $raw);
        $this->assertStringNotContainsString('Tina4 Error', $raw);
        $this->assertStringNotContainsString(
            'text/html',
            $response['headers']['content-type'] ?? '',
            'an HTML content type means an error page was served instead of the stream'
        );
    }

    /**
     * NEGATIVE: the server-side log must be clean too. On the App::handle()
     * path the pre-fix code streamed a plausible-looking body and THEN died
     * with an uncaught ErrorException at App.php:1312 (a second
     * http_response_code() after the chunks had been flushed) — invisible on
     * the wire, fatal to the request.
     */
    #[DataProvider('transports')]
    public function testStreamingLeavesNoErrorInTheServerLog(string $transport): void
    {
        self::rawRequest($this->portFor($transport), '/audit/sse');
        usleep(200000);

        $log = (string)@file_get_contents($this->logFor($transport));

        $this->assertStringNotContainsString('Cannot set response code', $log);
        $this->assertStringNotContainsString('Cannot modify header information', $log);
        $this->assertStringNotContainsString('Uncaught ErrorException', $log);
    }

    /**
     * The point of streaming: chunk one must reach the client BEFORE the
     * generator has finished. A transport that collects the generator and
     * writes once would see both markers land together.
     */
    #[DataProvider('transports')]
    public function testChunksAreDeliveredIncrementally(string $transport): void
    {
        $result = self::rawRequestTimed(
            $this->portFor($transport),
            '/audit/sse-slow',
            ['data: first', 'data: second']
        );

        $this->assertArrayHasKey('data: first', $result['seen'], 'first chunk never arrived: ' . substr($result['raw'], 0, 400));
        $this->assertArrayHasKey('data: second', $result['seen'], 'second chunk never arrived');

        // The route sleeps 0.7s between yields; require at least half of that
        // to have elapsed between the two arrivals so the assertion cannot be
        // satisfied by a single buffered write, while staying flake-proof.
        $gap = $result['seen']['data: second'] - $result['seen']['data: first'];
        $this->assertGreaterThan(
            (self::SLOW_GAP_MICROSECONDS / 1000000) / 2,
            $gap,
            'chunks arrived together — the transport buffered the stream instead of flushing it'
        );
    }

    /**
     * A non-SSE content type passed to stream() must survive to the wire.
     */
    #[DataProvider('transports')]
    public function testCustomStreamContentTypeReachesTheWire(string $transport): void
    {
        $response = self::parse(self::rawRequest($this->portFor($transport), '/audit/ndjson'));

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('application/x-ndjson', $response['headers']['content-type'] ?? '');
        $this->assertStringContainsString('"event":"start"', $response['body']);
        $this->assertStringContainsString('"event":"end"', $response['body']);
    }

    /**
     * NEGATIVE: a source that raises mid-stream keeps what it yielded and ends
     * cleanly — the client still gets 200 plus the first chunk, and the server
     * stays up for the next request.
     */
    #[DataProvider('transports')]
    public function testGeneratorRaisingMidStreamStillServesWhatItYielded(string $transport): void
    {
        $response = self::parse(self::rawRequest($this->portFor($transport), '/audit/sse-boom'));

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString("data: before\n\n", $response['body']);
        $this->assertStringNotContainsString('generator blew up', $response['body'], 'the failure must never leak to the client');

        // The worker survived: an ordinary request still answers.
        $after = self::parse(self::rawRequest($this->portFor($transport), '/audit/plain'));
        $this->assertSame(200, $after['status'], 'the server must still serve after a raising stream on ' . $transport);
    }

    /**
     * NEGATIVE: an app that flushes the stream itself must not get the body
     * twice. Under a web SAPI the second emit is blocked by the emitted flag;
     * under the socket server sendStream() is a no-op (pure CLI has no SAPI
     * response channel) and only the socket write reaches the client.
     */
    #[DataProvider('transports')]
    public function testAnExplicitSendDoesNotDuplicateTheStream(string $transport): void
    {
        $response = self::parse(self::rawRequest($this->portFor($transport), '/audit/sse-double'));

        $this->assertSame(200, $response['status'], 'raw body: ' . substr($response['body'], 0, 300));
        $this->assertSame(
            1,
            substr_count($response['body'], 'data: once'),
            'the streamed chunk must reach the client exactly once on ' . $transport
        );
    }

    /**
     * NEGATIVE: a plain JSON route is untouched by the streaming path — it
     * still gets a Content-Length and an application/json body.
     */
    #[DataProvider('transports')]
    public function testPlainResponsesAreUnaffected(string $transport): void
    {
        $response = self::parse(self::rawRequest($this->portFor($transport), '/audit/plain'));

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('application/json', $response['headers']['content-type'] ?? '');
        $this->assertSame(['ok' => true], json_decode(trim($response['body']), true));
        $this->assertArrayNotHasKey('x-accel-buffering', $response['headers'], 'a plain response must not carry SSE headers');
    }
}
