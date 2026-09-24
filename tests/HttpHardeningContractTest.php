<?php declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * HTTP hardening contract (ADR-0068) - the PHP runner for
 * tina4-documentation/plan/v3/fixtures/http_hardening_contract.json.
 *
 * Two defects in the same area:
 *
 *  - Response::header(), cookie() and redirect() accepted CR, LF and NUL. Under
 *    php-fpm PHP's own header() refuses them, but Tina4\Server (`tina4 serve`)
 *    writes "{$name}: {$value}\r\n" to the socket itself, so a request value
 *    that reached a header could end it and start another.
 *  - The raw-socket server's request limits were mostly right but not the
 *    ADR-0068 contract: a complete head past TINA4_MAX_REQUEST_HEADER was
 *    served, a non-numeric Content-Length was read as 0, Transfer-Encoding:
 *    chunked was ignored (the body arrived empty and its bytes were parsed as
 *    the next request), and the rejections had no security headers.
 *
 * NO MOCKS. The response cases drive the real Response object (pure logic, no
 * collaborator). The server cases boot a real `tina4 serve` raw-socket server in
 * a child process and talk to it over a real loopback socket, reading the
 * response head byte for byte - a real HTTP client would fold or hide exactly
 * the lines this is about. Resident memory is read from the operating system.
 *
 * Mutation-proved: remove the call-site check in Response and the refusal cases
 * go red; remove the writer check in Server::renderHeaderBlock and the
 * direct-append case goes red; remove the declared-length checks in
 * Server::frameHttpRequest and the memory case goes red.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tina4\Response;

final class HttpHardeningContractTest extends TestCase
{
    private const LIMIT = 1048576;        // TINA4_MAX_UPLOAD_SIZE for every server here
    private const HEADER_LIMIT = 8192;    // TINA4_MAX_REQUEST_HEADER
    private const IDLE_SECONDS = 3;       // TINA4_REQUEST_TIMEOUT

    private const SECURITY_HEADERS = [
        'x-frame-options' => 'SAMEORIGIN',
        'x-content-type-options' => 'nosniff',
        'content-security-policy' => "default-src 'self'",
        'referrer-policy' => 'strict-origin-when-cross-origin',
        'x-xss-protection' => '0',
        'permissions-policy' => 'camera=(), microphone=(), geolocation=()',
    ];

    private const ROUTES = <<<'PHP'
<?php
\Tina4\Router::post("/upload", function ($request, $response) {
    return $response->json(['size' => strlen($request->rawBody), 'body' => $request->rawBody]);
})->noAuth();

\Tina4\Router::get("/hello", function ($request, $response) {
    return $response->json(['ok' => true]);
});

\Tina4\Router::get("/redirect", function ($request, $response) {
    return $response->redirect($request->query['to'] ?? '/');
});

\Tina4\Router::get("/echo-header", function ($request, $response) {
    return $response->header('X-Echo', $request->query['v'] ?? '')->json(['ok' => true]);
});

\Tina4\Router::get("/cookie-path", function ($request, $response) {
    return $response->cookie('pref', 'v', ['path' => $request->query['v'] ?? '/'])->json(['ok' => true]);
});

\Tina4\Router::get("/cookies", function ($request, $response) {
    return $response->cookie('first', 'one')->cookie('second', 'two', ['path' => '/app'])->json(['ok' => true]);
});

\Tina4\Router::get("/direct-append", function ($request, $response) {
    // Bypasses header() on purpose - the PHP spelling of appending to the
    // header list directly. The server's writer must still refuse it.
    (\Closure::bind(function () {
        $this->headers['X-Direct'] = "a\r\nX-Injected: yes";
    }, $response, \Tina4\Response::class))();
    return $response->json(['ok' => true]);
});
PHP;

    /** @var array{proc: resource, port: int, dir: string}|null */
    private static ?array $server = null;

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            self::stopServer(self::$server);
            self::$server = null;
        }
    }

    // ── server plumbing ─────────────────────────────────────────────────────

    /** @return array{proc: resource, port: int, dir: string} */
    private static function bootServer(array $extraEnv = []): array
    {
        $dir = \TempPath::dir('tina4_hardening_');
        $port = \FreePort::get();
        mkdir($dir . '/src/routes', 0755, true);
        file_put_contents($dir . '/src/routes/hardening.php', self::ROUTES);
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        file_put_contents($dir . '/index.php', <<<PHP
<?php
require '{$autoload}';
\$app = new \\Tina4\\App(__DIR__);
\$app->run('127.0.0.1', {$port});
PHP);
        file_put_contents($dir . '/.env', "TINA4_OVERRIDE_CLIENT=true\nTINA4_NO_BROWSER=true\n");

        // The environment the server sees is pinned: nothing the lab or the
        // developer exported may move a limit or a security header under test.
        $env = getenv();
        foreach (['TINA4_CSP', 'TINA4_FRAME_OPTIONS', 'TINA4_REFERRER_POLICY', 'TINA4_PERMISSIONS_POLICY',
                     'TINA4_HSTS', 'TINA4_MAX_REQUEST_BODY', 'TINA4_SERVE_WORKERS'] as $unset) {
            unset($env[$unset]);
        }
        $env = array_merge($env, [
            'TINA4_DEBUG' => 'false',
            'TINA4_MAX_UPLOAD_SIZE' => (string)self::LIMIT,
            'TINA4_MAX_REQUEST_HEADER' => (string)self::HEADER_LIMIT,
            'TINA4_REQUEST_TIMEOUT' => (string)self::IDLE_SECONDS,
        ], $extraEnv);

        $proc = proc_open(
            [PHP_BINARY, 'index.php'],
            [1 => ['file', $dir . '/server.log', 'w'], 2 => ['file', $dir . '/server.log', 'a']],
            $pipes,
            $dir,
            $env
        );
        self::assertIsResource($proc, 'could not start the test server');
        $server = ['proc' => $proc, 'port' => $port, 'dir' => $dir];

        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
            if ($sock) {
                fclose($sock);
                return $server;
            }
            usleep(150000);
        }
        self::stopServer($server);
        self::fail("the test server never accepted on port {$port} - log: " . @file_get_contents($dir . '/server.log'));
    }

    /** @param array{proc: resource, port: int, dir: string} $server */
    private static function stopServer(array $server): void
    {
        if (!is_resource($server['proc'])) {
            return;
        }
        $pid = proc_get_status($server['proc'])['pid'] ?? 0;
        proc_terminate($server['proc'], SIGTERM);
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline && (proc_get_status($server['proc'])['running'] ?? false)) {
            usleep(50000);
        }
        if ($pid > 0 && (proc_get_status($server['proc'])['running'] ?? false)) {
            @posix_kill($pid, SIGKILL);
        }
        @proc_close($server['proc']);
    }

    private function port(): int
    {
        self::$server ??= self::bootServer();
        return self::$server['port'];
    }

    private function serverLog(): string
    {
        $log = (string)@file_get_contents(self::$server['dir'] . '/server.log');
        foreach (glob(self::$server['dir'] . '/logs/*.log') ?: [] as $file) {
            $log .= (string)file_get_contents($file);
        }
        return $log;
    }

    /**
     * Send raw bytes, read until the server closes (or $timeout passes), and
     * parse what came back.
     *
     * @return array{status: ?int, headers: array<string, list<string>>, lines: list<string>, body: string, raw: string}
     */
    private function exchange(string $raw, float $timeout = 8.0, ?int $port = null): array
    {
        $sock = @stream_socket_client('tcp://127.0.0.1:' . ($port ?? $this->port()), $errno, $errstr, 5);
        $this->assertIsResource($sock, "could not connect: {$errstr}");
        stream_set_timeout($sock, (int)ceil($timeout));
        for ($offset = 0; $offset < strlen($raw);) {
            $wrote = @fwrite($sock, substr($raw, $offset, 65536));
            if ($wrote === false || $wrote === 0) {
                break;   // the server answered and stopped reading
            }
            $offset += $wrote;
        }
        $received = '';
        while (!feof($sock)) {
            $chunk = @fread($sock, 65536);
            if ($chunk === false || ($chunk === '' && (stream_get_meta_data($sock)['timed_out'] ?? false))) {
                break;
            }
            $received .= $chunk;
        }
        fclose($sock);
        return self::parse($received);
    }

    /** @return array{status: ?int, headers: array<string, list<string>>, lines: list<string>, body: string, raw: string} */
    private static function parse(string $raw): array
    {
        $answer = ['status' => null, 'headers' => [], 'lines' => [], 'body' => '', 'raw' => $raw];
        $headEnd = strpos($raw, "\r\n\r\n");
        if ($headEnd === false) {
            return $answer;
        }
        $lines = explode("\r\n", substr($raw, 0, $headEnd));
        $statusLine = array_shift($lines);
        $answer['status'] = (int)(explode(' ', $statusLine)[1] ?? 0);
        $answer['lines'] = $lines;
        foreach ($lines as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $answer['headers'][strtolower(trim($name))][] = trim($value);
        }
        $answer['body'] = substr($raw, $headEnd + 4);
        return $answer;
    }

    private static function one(array $answer, string $name): ?string
    {
        return $answer['headers'][$name][0] ?? null;
    }

    private static function describe(array $answer): string
    {
        return ' - got: ' . json_encode(substr($answer['raw'], 0, 600));
    }

    private static function postHead(string $extra): string
    {
        return "POST /upload HTTP/1.1\r\nHost: 127.0.0.1\r\nContent-Type: application/octet-stream\r\n{$extra}\r\n";
    }

    private static function body413(int $bytes): string
    {
        return '{"error":"Request body (' . $bytes . ' bytes) exceeds TINA4_MAX_UPLOAD_SIZE (' . self::LIMIT . ' bytes)"}';
    }

    private function assertServing(?int $port = null): void
    {
        $answer = $this->exchange("GET /hello HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n", 8.0, $port);
        $this->assertSame(200, $answer['status'], 'the server must still serve a normal request' . self::describe($answer));
    }

    /** Assert $action throws InvalidArgumentException with exactly $message. */
    private function assertRefusedWith(string $message, callable $action): void
    {
        try {
            $action();
        } catch (\InvalidArgumentException $refusal) {
            $this->assertSame($message, $refusal->getMessage());
            return;
        }
        $this->fail("expected the refusal '{$message}', but the value was accepted");
    }

    // ── Response: call-site refusal (pure logic, the real Response object) ──

    /** @return array<string, array{string}> */
    public static function unsafeHeaderValues(): array
    {
        return ['bare CR' => ["a\rb"], 'bare LF' => ["a\nb"], 'NUL' => ["a\0b"], 'CR LF' => ["a\r\nX-Other: 1"]];
    }

    #[DataProvider('unsafeHeaderValues')]
    public function testAHeaderValueContainingCrLfOrNulIsRefused(string $bad): void
    {
        $expected = 'Invalid character in header content ["X-Test"]';
        $response = new Response(testing: true);
        $this->assertRefusedWith($expected, fn () => $response->header('X-Test', $bad));
        $this->assertRefusedWith($expected, fn () => $response->add_header('X-Test', $bad));
        $this->assertRefusedWith($expected, fn () => $response->withHeaders(['X-Fine' => 'ok', 'X-Test' => $bad]));
        $this->assertSame([], $response->getHeaders(), 'a refused header - or a refused set - stores nothing');
    }

    /** @return array<string, array{string, string}> */
    public static function nonTokenHeaderNames(): array
    {
        // The name is quoted as a JSON string literal - byte-identical to
        // Python's json.dumps, so these expectations are written out, not derived.
        return [
            'space' => ['X Test', '"X Test"'],
            'colon' => ['X:Test', '"X:Test"'],
            'empty' => ['', '""'],
            'CR LF' => ["X\r\nTest", '"X\r\nTest"'],
            'non-ASCII' => ['Tëst', '"T' . chr(92) . 'u00ebst"'],   // Python: json.dumps('Tëst')
            'parentheses' => ['X(Test)', '"X(Test)"'],
        ];
    }

    #[DataProvider('nonTokenHeaderNames')]
    public function testAHeaderNameThatIsNotATokenIsRefused(string $bad, string $quoted): void
    {
        $this->assertRefusedWith(
            "Header name must be a valid HTTP token [{$quoted}]",
            fn () => (new Response(testing: true))->header($bad, 'value')
        );
    }

    public function testARedirectLocationContainingCrOrLfIsRefused(): void
    {
        foreach (["/next\r\nX-Other: 1", "/next\n", "/next\0"] as $bad) {
            $response = new Response(testing: true);
            $this->assertRefusedWith('Invalid character in header content ["Location"]', fn () => $response->redirect($bad));
            $this->assertNull($response->getHeader('Location'), 'a refused redirect sets no Location');
            $this->assertSame(200, $response->getStatusCode(), 'a refused redirect leaves the status alone');
        }

        $contentType = 'Invalid character in header content ["Content-Type"]';
        $this->assertRefusedWith($contentType, fn () => (new Response(testing: true))('x', 200, "text/plain\r\nX-Other: 1"));
        $this->assertRefusedWith($contentType, fn () => (new Response(testing: true))->send('x', 200, "text/plain\nX: 1"));
        $this->assertRefusedWith($contentType, fn () => (new Response(testing: true))->stream(fn () => yield 'x', "text/event-stream\r\nX: 1"));

        $dir = \TempPath::dir('tina4_download_');
        $target = $dir . "/report\r\n.txt";
        file_put_contents($target, 'report');
        $this->assertRefusedWith($contentType, fn () => (new Response(testing: true))->file($target, "text/plain\nX: 1"));
        $this->assertRefusedWith(
            'Invalid character in header content ["Content-Disposition"]',
            fn () => (new Response(testing: true))->file($target, 'text/plain', true)
        );
    }

    public function testNormalHeadersAndRedirectsStillWork(): void
    {
        $response = (new Response(testing: true))
            ->header('X-Plain', 'a value, with; punctuation = fine')
            ->header('X-Tab', "a\tb")
            ->header('X-Utf8', 'café')
            ->withHeaders(['Cache-Control' => 'no-store', 'X-Custom_Name.v2' => 'ok']);
        $this->assertSame('a value, with; punctuation = fine', $response->getHeader('X-Plain'));
        $this->assertSame("a\tb", $response->getHeader('X-Tab'), 'a horizontal tab is legal inside a field value');
        $this->assertSame('café', $response->getHeader('X-Utf8'));
        $this->assertSame('ok', $response->getHeader('X-Custom_Name.v2'));

        $redirected = (new Response(testing: true))->redirect('/login?next=/a%20b&x=%0D%0A', 303);
        $this->assertSame(303, $redirected->getStatusCode());
        $this->assertSame('/login?next=/a%20b&x=%0D%0A', $redirected->getHeader('Location'),
            'a percent-encoded CR/LF is inert text and passes untouched');

        $dir = \TempPath::dir('tina4_download_');
        file_put_contents($dir . '/report.txt', 'report');
        $downloaded = (new Response(testing: true))->file($dir . '/report.txt', 'text/plain', true);
        $this->assertSame('attachment; filename="report.txt"', $downloaded->getHeader('Content-Disposition'));

        // And end to end: an ordinary value reaches the wire exactly as set.
        $answer = $this->exchange("GET /echo-header?v=plain%20value HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
        $this->assertSame(200, $answer['status'], self::describe($answer));
        $this->assertSame('plain value', self::one($answer, 'x-echo'), self::describe($answer));
        $answer = $this->exchange("GET /redirect?to=%2Fnext%3Fa%3D1 HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
        $this->assertSame(302, $answer['status'], self::describe($answer));
        $this->assertSame('/next?a=1', self::one($answer, 'location'), self::describe($answer));
    }

    // ── cookies ─────────────────────────────────────────────────────────────

    public function testACookieNameValueOrAttributeThatCouldInjectIsRefused(): void
    {
        foreach (['a b' => '"a b"', 'a=b' => '"a=b"', 'a;b' => '"a;b"', '' => '""', "a\r\nb" => '"a\r\nb"', 'a,b' => '"a,b"'] as $name => $quoted) {
            $this->assertRefusedWith(
                "Cookie name must be a valid HTTP token [{$quoted}]",
                fn () => (new Response(testing: true))->cookie((string)$name, 'v')
            );
        }

        $expected = 'Invalid character in cookie content ["sid"]';
        foreach (['v; Domain=example.com', "v\r\nX-Other: 1", "v\n", "v\0"] as $badValue) {
            $response = new Response(testing: true);
            $this->assertRefusedWith($expected, fn () => $response->cookie('sid', $badValue));
            $this->assertSame([], $response->getCookies(), 'a refused cookie is not stored');
        }
        foreach ([
            ['path' => '/; Domain=example.com'],
            ['samesite' => "Lax\r\nX: 1"],
            ['domain' => "example.com\nX: 1"],
            ['path' => "/x\0"],
        ] as $attributes) {
            $response = new Response(testing: true);
            $this->assertRefusedWith($expected, fn () => $response->cookie('sid', 'v', $attributes));
            $this->assertSame([], $response->getCookies(), 'a cookie with a refused attribute is not stored');
        }

        // And end to end: a cookie attribute built from the request is refused,
        // so the request cannot add a header line of its own.
        $answer = $this->exchange("GET /cookie-path?v=/app%0D%0AX-Injected:%20yes HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
        $this->assertSame(500, $answer['status'], self::describe($answer));
        $this->assertArrayNotHasKey('x-injected', $answer['headers'], self::describe($answer));
    }

    public function testMultipleSetCookieHeadersAllReachTheClient(): void
    {
        $response = (new Response(testing: true))->cookie('first', 'one')->cookie('second', 'two', ['path' => '/app']);
        $lines = $response->cookieHeaderLines();
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('first=one; Path=/', $lines[0]);
        $this->assertStringStartsWith('second=two; Path=/app', $lines[1]);

        $answer = $this->exchange("GET /cookies HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
        $this->assertSame(200, $answer['status'], self::describe($answer));
        $cookies = $answer['headers']['set-cookie'] ?? [];
        $this->assertNotEmpty(array_filter($cookies, fn ($c) => str_starts_with($c, 'first=one; Path=/;')), self::describe($answer));
        $this->assertNotEmpty(array_filter($cookies, fn ($c) => str_starts_with($c, 'second=two; Path=/app;')), self::describe($answer));
    }

    // ── the built-in server ─────────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function injectedSuffixes(): array
    {
        return [
            'CR LF' => ['%0D%0AX-Injected:%20yes'],
            'bare LF' => ['%0AX-Injected:%20yes'],
            'bare CR' => ['%0DX-Injected:%20yes'],
            'NUL' => ['%00X-Injected:%20yes'],
        ];
    }

    /**
     * The exploit shape the audit found: a query value passed to redirect() or
     * header(). Before the fix the server wrote the CR/LF and the client read an
     * X-Injected header the application never set.
     */
    #[DataProvider('injectedSuffixes')]
    public function testTheRouteRefusalHoldsEndToEnd(string $suffix): void
    {
        foreach (['/redirect?to=/next', '/echo-header?v=abc'] as $route) {
            $answer = $this->exchange("GET {$route}{$suffix} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
            $this->assertSame(500, $answer['status'], "{$route}: the refusal surfaces as a server error" . self::describe($answer));
            $this->assertArrayNotHasKey('x-injected', $answer['headers'], "{$route}: a request-controlled header reached the wire" . self::describe($answer));
            foreach ($answer['lines'] as $line) {
                $this->assertStringNotContainsString("\0", $line, "{$route}: a NUL reached the wire");
                $this->assertStringNotContainsString("\r", $line, "{$route}: a bare CR reached the wire");
            }
        }
    }

    public function testTheBuiltInServerRefusesToWriteAnUnsafeHeader(): void
    {
        $answer = $this->exchange("GET /direct-append HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n");
        $this->assertSame(500, $answer['status'], self::describe($answer));
        $this->assertSame('{"error":"Invalid response header"}', $answer['body'], self::describe($answer));
        $this->assertArrayNotHasKey('x-injected', $answer['headers'], self::describe($answer));
        $this->assertArrayNotHasKey('x-direct', $answer['headers'], self::describe($answer));
        $this->assertSame('close', self::one($answer, 'connection'), self::describe($answer));
        usleep(200000);
        $this->assertStringContainsString('X-Direct', $this->serverLog(), 'the refusal is logged naming the header');
        $this->assertServing();
    }

    public function testADeclaredContentLengthOverTheCapIsRefusedBeforeTheBodyIsRead(): void
    {
        $declared = 50 * 1048576;
        $started = microtime(true);
        $answer = $this->exchange(self::postHead("Content-Length: {$declared}\r\n"));
        $elapsed = microtime(true) - $started;
        $this->assertSame(413, $answer['status'], self::describe($answer));
        $this->assertSame(self::body413($declared), $answer['body'], self::describe($answer));
        // Answered at once, not after waiting for (or timing out on) the body.
        $this->assertLessThan(self::IDLE_SECONDS - 1, $elapsed, sprintf('answered after %.1fs', $elapsed));
        $this->assertServing();
    }

    public function testAnOversizedDeclaredBodyDoesNotGrowServerMemory(): void
    {
        // Its own server, and TINA4_MAX_REQUEST_BODY lifted out of the way, so
        // TINA4_MAX_UPLOAD_SIZE is the only thing that can hold the line.
        $server = self::bootServer(['TINA4_MAX_REQUEST_BODY' => (string)(1024 * 1048576)]);
        $sockets = [];
        try {
            $this->assertServing($server['port']);
            usleep(500000);
            $pid = (int)proc_get_status($server['proc'])['pid'];
            $before = self::rssMegabytes($pid);
            $this->assertGreaterThan(0, $before, "could not read the server's RSS");

            $block = str_repeat('a', 8 * 1048576);
            for ($i = 0; $i < 6; $i++) {
                $sock = @stream_socket_client("tcp://127.0.0.1:{$server['port']}", $errno, $errstr, 5);
                $this->assertIsResource($sock, "could not connect: {$errstr}");
                stream_set_blocking($sock, false);
                $sockets[] = $sock;
                @fwrite($sock, self::postHead('Content-Length: ' . (256 * 1048576) . "\r\n"));
                // Push as much of the block as the server will take in 0.5s.
                $until = microtime(true) + 0.5;
                for ($offset = 0; $offset < strlen($block) && microtime(true) < $until;) {
                    $wrote = @fwrite($sock, substr($block, $offset, 262144));
                    if ($wrote === false) {
                        break;
                    }
                    $offset += $wrote;
                    if ($wrote === 0) {
                        usleep(10000);
                    }
                }
            }
            usleep(1000000);
            $during = self::rssMegabytes($pid);
            $grew = $during - $before;
            // Six clients each pushed up to 8MB against a 1MB cap, declaring
            // 256MB. A server that buffered toward the declared length holds
            // all of it (48MB+).
            $this->assertLessThan(16, $grew, sprintf('server grew %.1fMB (%.1f -> %.1f)', $grew, $before, $during));
        } finally {
            foreach ($sockets as $sock) {
                @fclose($sock);
            }
            self::stopServer($server);
        }
    }

    private static function rssMegabytes(int $pid): float
    {
        $out = trim((string)shell_exec('ps -o rss= -p ' . $pid));
        return $out === '' ? 0.0 : ((int)$out) / 1024;
    }

    public function testAChunkedBodyOverTheCapIsRefusedAsItArrives(): void
    {
        $piece = sprintf("%x\r\n", 65536) . str_repeat('a', 65536) . "\r\n";
        $answer = $this->exchange(self::postHead("Transfer-Encoding: chunked\r\n") . str_repeat($piece, 32) . "0\r\n\r\n");
        $this->assertSame(413, $answer['status'], self::describe($answer));
        $this->assertSame(self::body413(17 * 65536), $answer['body'], self::describe($answer));
        $this->assertServing();
    }

    public function testAChunkedBodyUnderTheCapIsDecodedAndServed(): void
    {
        $answer = $this->exchange(self::postHead("Transfer-Encoding: chunked\r\nConnection: close\r\n")
            . "5;name=value\r\nhello\r\n6\r\n world\r\n0\r\nX-Trailer: t\r\n\r\n");
        $this->assertSame(200, $answer['status'], self::describe($answer));
        $this->assertSame(['size' => 11, 'body' => 'hello world'], json_decode($answer['body'], true), self::describe($answer));
    }

    public function testABodyUnderTheCapIsStillServed(): void
    {
        foreach ([0, 1000, self::LIMIT] as $size) {
            $answer = $this->exchange(self::postHead("Content-Length: {$size}\r\nConnection: close\r\n") . str_repeat('b', $size));
            $this->assertSame(200, $answer['status'], "size {$size}" . self::describe($answer));
            $this->assertSame($size, json_decode($answer['body'], true)['size'] ?? null);
        }

        // Only the Content-Length field counts: the old reader matched the text
        // "content-length:" anywhere in the head, so this was refused 413.
        $lookalike = $this->exchange(self::postHead("X-Content-Length: 999999999\r\nContent-Length: 3\r\nConnection: close\r\n") . 'abc');
        $this->assertSame(200, $lookalike['status'], self::describe($lookalike));
        $this->assertSame(3, json_decode($lookalike['body'], true)['size'] ?? null);
    }

    /** @return array<string, array{string}> */
    public static function invalidContentLengths(): array
    {
        return [
            'letters' => ["Content-Length: abc\r\n"],
            'negative' => ["Content-Length: -1\r\n"],
            'plus sign' => ["Content-Length: +5\r\n"],
            'two numbers' => ["Content-Length: 1 2\r\n"],
            'hex' => ["Content-Length: 0x10\r\n"],
            'empty' => ["Content-Length: \r\n"],
            'disagreeing pair' => ["Content-Length: 5\r\nContent-Length: 6\r\n"],
            // Refused even when they agree (maintainer ruling on ADR-0068,
            // the same in all four): node:http's parser refuses any pair, and a
            // second Content-Length is itself a smuggling signal.
            'agreeing pair' => ["Content-Length: 5\r\nContent-Length: 5\r\n"],
        ];
    }

    #[DataProvider('invalidContentLengths')]
    public function testAnInvalidContentLengthAnswers400(string $header): void
    {
        $answer = $this->exchange(self::postHead($header) . 'hello');
        $this->assertSame(400, $answer['status'], self::describe($answer));
        $this->assertSame('{"error":"Invalid Content-Length"}', $answer['body'], self::describe($answer));
        $this->assertServing();
    }

    /**
     * Maintainer ruling on ADR-0068: a second Content-Length is refused even
     * when it agrees with the first - the same in all four frameworks
     * (node:http's parser refuses any pair). Two framing headers are how a
     * request is smuggled past a proxy that reads the other one.
     */
    public function testTwoContentLengthHeadersAnswer400EvenWhenTheyAgree(): void
    {
        $answer = $this->exchange(self::postHead("Content-Length: 5\r\nContent-Length: 5\r\n") . 'hello');
        $this->assertSame(400, $answer['status'], self::describe($answer));
        $this->assertSame('{"error":"Invalid Content-Length"}', $answer['body'], self::describe($answer));
        $this->assertServing();
    }

    /** @return array<string, array{string}> */
    public static function invalidTransferEncodings(): array
    {
        return [
            'with Content-Length' => [self::postHead("Content-Length: 5\r\nTransfer-Encoding: chunked\r\n") . "0\r\n\r\n"],
            'gzip' => [self::postHead("Transfer-Encoding: gzip\r\n") . 'hello'],
            'gzip, chunked' => [self::postHead("Transfer-Encoding: gzip, chunked\r\n") . "0\r\n\r\n"],
            'bad chunk size' => [self::postHead("Transfer-Encoding: chunked\r\n") . "zz\r\nhello\r\n0\r\n\r\n"],
            'bad chunk end' => [self::postHead("Transfer-Encoding: chunked\r\n") . "5\r\nhelloXX0\r\n\r\n"],
        ];
    }

    #[DataProvider('invalidTransferEncodings')]
    public function testConflictingContentLengthAndTransferEncodingAnswer400(string $raw): void
    {
        $answer = $this->exchange($raw);
        $this->assertSame(400, $answer['status'], self::describe($answer));
        $this->assertSame('{"error":"Invalid Transfer-Encoding"}', $answer['body'], self::describe($answer));
        $this->assertServing();
    }

    /** @return array<string, array{string}> */
    public static function malformedHeads(): array
    {
        return [
            'LF in the request line' => ["GET /hello\nX HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n"],
            'bare LF in a field' => ["GET /hello HTTP/1.1\r\nHost: 127.0.0.1\r\nX-A: a\nb\r\n\r\n"],
            'bare CR in a field' => ["GET /hello HTTP/1.1\r\nHost: 127.0.0.1\r\nX-A: a\rb\r\n\r\n"],
            'NUL in a field' => ["GET /hello HTTP/1.1\r\nHost: 127.0.0.1\r\nX-A: a\0b\r\n\r\n"],
        ];
    }

    #[DataProvider('malformedHeads')]
    public function testARequestHeadWithABareLineFeedAnswers400(string $raw): void
    {
        $answer = $this->exchange($raw);
        $this->assertSame(400, $answer['status'], self::describe($answer));
        $this->assertSame('{"error":"Malformed request head"}', $answer['body'], self::describe($answer));
        $this->assertServing();
    }

    /** @return array<string, array{string}> */
    public static function oversizedHeads(): array
    {
        return [
            // Complete: its blank line arrives in the same packet as the excess.
            'complete' => ["GET /hello HTTP/1.1\r\nHost: 127.0.0.1\r\nX-Big: " . str_repeat('a', 9000) . "\r\n\r\n"],
            'never terminated' => ["GET /hello HTTP/1.1\r\nHost: 127.0.0.1\r\nX-Big: " . str_repeat('a', 200000)],
        ];
    }

    #[DataProvider('oversizedHeads')]
    public function testAnOversizedHeaderBlockAnswers431(string $raw): void
    {
        $answer = $this->exchange($raw);
        $this->assertSame(431, $answer['status'], self::describe($answer));
        $this->assertSame(
            '{"error":"Request header fields exceed TINA4_MAX_REQUEST_HEADER (' . self::HEADER_LIMIT . ' bytes)"}',
            $answer['body'],
            self::describe($answer)
        );
        $this->assertServing();
    }

    public function testAStalledPartialRequestAnswers408(): void
    {
        foreach ([self::postHead("Content-Length: 10\r\n") . 'abc', "GET /hello HTTP/1.1\r\nHost: 127.0.0.1\r\n"] as $partial) {
            $started = microtime(true);
            $answer = $this->exchange($partial, self::IDLE_SECONDS + 5);
            $elapsed = microtime(true) - $started;
            $this->assertSame(408, $answer['status'], self::describe($answer));
            $this->assertSame('{"error":"Request timed out before it was complete"}', $answer['body'], self::describe($answer));
            $this->assertGreaterThanOrEqual(self::IDLE_SECONDS - 0.5, $elapsed);
            $this->assertLessThan(self::IDLE_SECONDS + 3, $elapsed, sprintf('%.1fs', $elapsed));
        }
        // A connection that never sent a byte has no request to answer.
        $silent = $this->exchange('', self::IDLE_SECONDS + 5);
        $this->assertSame('', $silent['raw'], self::describe($silent));
        $this->assertServing();
    }

    public function testTheServerKeepsServingAfterEveryRejection(): void
    {
        $rejections = [
            self::postHead("Content-Length: abc\r\n"),
            self::postHead('Content-Length: ' . (self::LIMIT + 1) . "\r\n"),
            "GET /hello HTTP/1.1\r\nX-Big: " . str_repeat('a', 20000) . "\r\n\r\n",
            "GET /hello\nX HTTP/1.1\r\n\r\n",
            self::postHead("Transfer-Encoding: gzip\r\n"),
            "GET /direct-append HTTP/1.1\r\n\r\n",
        ];
        for ($round = 0; $round < 3; $round++) {
            foreach ($rejections as $raw) {
                $answer = $this->exchange($raw);
                $this->assertContains($answer['status'], [400, 413, 431, 500], self::describe($answer));
            }
        }
        $this->assertServing();
        $this->assertTrue(proc_get_status(self::$server['proc'])['running'] ?? false, 'the server process died');
        $log = $this->serverLog();
        foreach (['PHP Fatal error', 'PHP Warning', 'Uncaught'] as $leak) {
            $this->assertStringNotContainsString($leak, $log, "the server logged {$leak}");
        }
    }

    public function testATransportRejectionCarriesTheJsonBodyAndSecurityHeaders(): void
    {
        $cases = [
            [self::postHead('Content-Length: ' . (self::LIMIT + 1) . "\r\n"), 413, self::body413(self::LIMIT + 1)],
            [self::postHead("Content-Length: x\r\n"), 400, '{"error":"Invalid Content-Length"}'],
            ["GET / HTTP/1.1\r\nX-Big: " . str_repeat('a', 9000) . "\r\n\r\n", 431,
                '{"error":"Request header fields exceed TINA4_MAX_REQUEST_HEADER (' . self::HEADER_LIMIT . ' bytes)"}'],
            ["GET /direct-append HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n", 500, '{"error":"Invalid response header"}'],
        ];
        foreach ($cases as [$raw, $status, $body]) {
            $answer = $this->exchange($raw);
            $this->assertSame($status, $answer['status'], self::describe($answer));
            $this->assertSame($body, $answer['body'], self::describe($answer));
            $this->assertSame('application/json', self::one($answer, 'content-type'), self::describe($answer));
            $this->assertSame((string)strlen($body), self::one($answer, 'content-length'), self::describe($answer));
            $this->assertSame('close', self::one($answer, 'connection'), self::describe($answer));
            foreach (self::SECURITY_HEADERS as $name => $value) {
                $this->assertSame($value, self::one($answer, $name), "{$name}" . self::describe($answer));
            }
            $this->assertArrayNotHasKey('strict-transport-security', $answer['headers'], self::describe($answer));
        }
    }
}
