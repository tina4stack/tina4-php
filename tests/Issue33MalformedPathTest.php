<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * tina4stack/tina4-nodejs#33 — malformed-path process crash (parity lock-in).
 *
 * The Node worker crashed on `GET //` (also `///`, `/\`) because
 * `new URL(req.url, base)` threw `ERR_INVALID_URL` BEFORE the dispatch
 * try/catch — an unauthenticated remote DoS (scanners send `//` routinely).
 *
 * PHP is SAFE and needs NO code change: the path is an opaque string
 * (`$_SERVER['REQUEST_URI']` split on `?` in Request.php — no throwing URL
 * parser), and each request is isolated (PHP-FPM / php -S per-request). This
 * is the lock-in that proves it stays safe: a REAL server booted over TCP is
 * hit with raw malformed request lines and must answer a clean 4xx for each,
 * and — critically — must still serve a following normal request (the server
 * survived; no crash).
 *
 * No mock: a real `php -S` process running the real Tina4 request pipeline
 * (Request::fromGlobals -> Router::dispatch), driven over real sockets with
 * hand-written request lines so the exact malformed path reaches the server.
 */

use PHPUnit\Framework\TestCase;

class Issue33MalformedPathTest extends TestCase
{
    /** @var resource|null */
    private $proc = null;
    /** @var array<int,resource> */
    private array $pipes = [];
    private int $port = 0;
    private string $router = '';

    protected function setUp(): void
    {
        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        if ($autoload === false) {
            $this->markTestSkipped('vendor/autoload.php not found');
        }

        // Minimal real Tina4 app: one public GET route + the per-request handler
        // (Request::fromGlobals -> Router::dispatch -> emit). Exactly what a
        // PHP-FPM / php -S deployment runs for every request.
        $this->router = tempnam(sys_get_temp_dir(), 'tina4_33_') . '.php';
        file_put_contents($this->router, <<<PHP
        <?php
        require '{$autoload}';
        putenv('TINA4_SUPPRESS=true');
        putenv('TINA4_DEBUG=false');
        putenv('TINA4_AUTO_MIGRATE=false');
        \$app = new \\Tina4\\App(basePath: sys_get_temp_dir());
        \\Tina4\\Router::get('/probe', fn(\$req, \$res) => \$res->json(['ok' => true]));
        \$app->handle();
        PHP);

        $this->port = 7300 + (getmypid() % 600);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        $this->proc = @proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$this->port}", $this->router],
            $descriptors,
            $this->pipes
        );
        if (!is_resource($this->proc)) {
            @unlink($this->router);
            $this->markTestSkipped('could not start php -S');
        }

        // Wait until the server accepts connections.
        $up = false;
        for ($i = 0; $i < 100; $i++) {
            $c = @stream_socket_client("tcp://127.0.0.1:{$this->port}", $e1, $e2, 0.1);
            if ($c) {
                fclose($c);
                $up = true;
                break;
            }
            usleep(50000);
        }
        if (!$up) {
            $this->cleanup();
            $this->markTestSkipped("dev server did not come up on :{$this->port}");
        }
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        foreach ($this->pipes as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }
        $this->pipes = [];
        if (is_resource($this->proc)) {
            proc_terminate($this->proc);
            proc_close($this->proc);
            $this->proc = null;
        }
        if ($this->router !== '') {
            @unlink($this->router);
        }
    }

    /**
     * Send a raw request LINE (e.g. "GET //") over a fresh socket and return the
     * numeric HTTP status. A hand-written line is the only way to put a literal
     * `//` on the wire — file_get_contents / curl normalise it away.
     */
    private function rawStatus(string $requestLine): int
    {
        $c = @stream_socket_client("tcp://127.0.0.1:{$this->port}", $errno, $errstr, 2.0);
        $this->assertNotFalse($c, "could not connect to booted server: $errstr");
        fwrite($c, "{$requestLine} HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
        $raw = stream_get_contents($c);
        fclose($c);

        $this->assertNotSame('', (string) $raw, 'server closed the connection with no response (possible crash)');
        // Status line: "HTTP/1.1 <code> <reason>"
        $statusLine = strtok($raw, "\r\n");
        $this->assertMatchesRegularExpression('#^HTTP/1\.[01] \d{3}#', (string) $statusLine, "malformed status line: $statusLine");
        return (int) preg_replace('#^HTTP/1\.[01] (\d{3}).*$#', '$1', (string) $statusLine);
    }

    /**
     * The reported crash payloads must each return a clean 4xx (a 404 here) —
     * never a 5xx from a fatal, never a dropped connection.
     */
    public function testMalformedPathsReturn4xxAndDoNotFatal(): void
    {
        foreach (['GET //', 'GET ///', 'GET /\\'] as $line) {
            $status = $this->rawStatus($line);
            $this->assertGreaterThanOrEqual(400, $status, "[$line] must be a client error, got $status");
            $this->assertLessThan(500, $status, "[$line] must NOT be a 5xx (fatal), got $status");
        }
    }

    /**
     * The security property: after the malformed requests, the same long-lived
     * server must still serve a normal request. This is what the Node worker
     * failed to do — it crashed and stopped accepting connections.
     */
    public function testServerSurvivesAndStillServesAfterMalformedPaths(): void
    {
        // Hit it with the crash payloads first...
        $this->rawStatus('GET //');
        $this->rawStatus('GET /\\');
        // ...then a normal request must still succeed on a fresh connection.
        $this->assertSame(200, $this->rawStatus('GET /probe'), 'server must survive and keep serving');
    }
}
