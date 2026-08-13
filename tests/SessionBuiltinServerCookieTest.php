<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Real-bug audit (3.13.99): the built-in server's FIRST-TIME session cookie.
 *
 * CONFIRMED: Tina4\Server (the raw-socket engine `tina4 serve` boots) never
 * triggers PHP's headers_sent() — a raw socket engages no real PHP SAPI
 * header-sending mechanism at all — so Router::emitSessionCookie()'s
 * `headers_sent() || $result->isTesting()` condition was FALSE for a
 * Server-driven Response, taking the native setcookie() branch, which writes
 * into a void nothing reads back under that engine. A first-time session
 * login under `tina4 serve` emitted NO Set-Cookie at all: session auth was
 * silently broken on the framework's own recommended dev/prod server.
 * Feature 131 (TestClient) found and documented this gap but deliberately
 * left it out of scope.
 *
 * FIX: Response gained a narrowly-scoped `rawSocket` constructor flag,
 * independent of `testing` (reusing `testing` would also change
 * Response::stream()'s SSE materialisation, which Server drives itself via
 * chunked socket writes — see Response::__construct's docblock).
 * Router::emitSessionCookie()'s condition became
 * `headers_sent() || $result->isTesting() || $result->isRawSocket()`.
 * Tina4\Server sets `rawSocket: true` on both Response objects it
 * constructs (the real per-connection dispatch, and the public `handle()`
 * embedding method). App::__invoke() (Apache/nginx/FPM/php -S) never sets
 * it, so headers_sent() stays the ONLY signal there — unchanged.
 *
 * THREE REAL contexts, no mocks, real sockets:
 *   (a) Tina4\Server — the bug this fixes. First-time Set-Cookie + a replay
 *       resumes the session.
 *   (b) `php -S` — a real SAPI, unaffected by the fix (Router::dispatch()
 *       called directly, mirrors SessionCookieNameTest.php). Regression
 *       check: byte-identical before/after.
 *   (c) App::handle()'s Apache/nginx/FPM-style tail, which ALSO reads
 *       $response->getHeaders() and calls header() — proves no DUPLICATE
 *       Set-Cookie now that Tina4\Server can attach one to the Response too
 *       (App::__invoke() never sets rawSocket, so this path's behaviour is
 *       provably unchanged — verified empirically, not just by inspection).
 *
 * Mutation-proved (context a): reverting Response::isRawSocket()/the
 * Server.php `rawSocket: true` constructions back to a bare `new Response()`
 * reproduces the exact defect — 200 OK, ZERO Set-Cookie lines. Restored.
 *
 * Same case name in all four (tina4-documentation/plan/v3/fixtures/session_contract.json):
 *   - first_time_session_cookie_is_emitted_and_a_replay_resumes_it
 */
class SessionBuiltinServerCookieTest extends TestCase
{
    /** @var array<int, resource> processes spawned this test, reaped in tearDown */
    private array $spawned = [];

    protected function tearDown(): void
    {
        foreach ($this->spawned as $proc) {
            if (is_resource($proc)) {
                @proc_terminate($proc, 9);
                @proc_close($proc);
            }
        }
        $this->spawned = [];
    }

    // ── (a) Tina4\Server — the real bug ──────────────────────────────────────

    public function testFirstTimeSessionCookieOverRawSocketServerAndAReplayResumesIt(): void
    {
        $env = getenv();
        $env['TINA4_OVERRIDE_CLIENT'] = 'true';
        $env['TINA4_SUPPRESS'] = 'true';
        $env['TINA4_AUTO_MIGRATE'] = 'false';
        $env['TINA4_DEBUG'] = 'false';
        $server = \TestServer::startScript(__DIR__ . '/fixtures/session_builtin_server_cookie_server.php', env: $env);
        try {
            $login = $this->rawRequest('127.0.0.1', $server->port, 'POST', '/login', "{}");
            $this->assertSame('200', substr($login['status'], 0, 3), "login must succeed; log: {$server->log()}");
            $this->assertNotEmpty(
                $login['setCookies'],
                'a first-time session write over the REAL Tina4\\Server raw socket must emit a '
                . "Set-Cookie - this is the exact defect this fix closes; log: {$server->log()}"
            );

            $tina4Cookie = $this->cookieNamed($login['setCookies'], 'tina4_session');
            $this->assertNotNull($tina4Cookie, 'no tina4_session cookie among: ' . implode(' | ', $login['setCookies']));
            $cookiePair = explode(';', $tina4Cookie, 2)[0];

            $whoami = $this->rawRequest('127.0.0.1', $server->port, 'GET', '/whoami', null, ["Cookie: {$cookiePair}"]);
            $whoamiDecoded = json_decode($whoami['body'], true);
            $this->assertSame(
                'abc',
                $whoamiDecoded['token'] ?? null,
                'replaying the first-time cookie must RESUME the session (token=abc); got: ' . $whoami['body']
            );
        } finally {
            $server->stop();
        }
    }

    // ── (b) php -S — real SAPI, must be unchanged ────────────────────────────

    public function testPhpBuiltinDevServerStillEmitsExactlyOneTina4SessionCookie(): void
    {
        $server = \TestServer::start(__DIR__ . '/fixtures/session_builtin_server_cookie_php_s.php');
        try {
            $login = $this->rawRequest('127.0.0.1', $server->port, 'POST', '/login', "{}");
            $this->assertSame('200', substr($login['status'], 0, 3));

            $tina4Cookies = array_values(array_filter(
                $login['setCookies'],
                static fn($c) => str_starts_with($c, 'tina4_session=')
            ));
            $this->assertCount(
                1,
                $tina4Cookies,
                'php -S must keep emitting exactly ONE tina4_session Set-Cookie (unaffected by the '
                . 'Tina4\\Server fix); got: ' . implode(' | ', $login['setCookies'])
            );

            $cookiePair = explode(';', $tina4Cookies[0], 2)[0];
            $whoami = $this->rawRequest('127.0.0.1', $server->port, 'GET', '/whoami', null, ["Cookie: {$cookiePair}"]);
            $whoamiDecoded = json_decode($whoami['body'], true);
            $this->assertSame('abc', $whoamiDecoded['token'] ?? null, 'got: ' . $whoami['body']);
        } finally {
            $server->stop();
        }
    }

    // ── (c) App::handle() Apache/FPM-style tail — no duplicate ───────────────

    public function testAppHandleApacheStyleTailEmitsNoDuplicateSessionCookie(): void
    {
        $server = \TestServer::start(__DIR__ . '/fixtures/session_builtin_server_cookie_apphandle.php');
        try {
            $login = $this->rawRequest('127.0.0.1', $server->port, 'POST', '/login', "{}");
            $this->assertSame('200', substr($login['status'], 0, 3), "log: {$server->log()}");

            $tina4Cookies = array_values(array_filter(
                $login['setCookies'],
                static fn($c) => str_starts_with($c, 'tina4_session=')
            ));
            $this->assertCount(
                1,
                $tina4Cookies,
                'App::handle()\'s Apache/FPM-style tail (which ALSO reads $response->getHeaders() and '
                . 'calls header()) must not double-emit the session cookie now that Tina4\\Server can '
                . 'attach one to the Response too; got: ' . implode(' | ', $login['setCookies'])
            );
        } finally {
            $server->stop();
        }
    }

    // ── raw socket HTTP client (captures every header, incl. duplicates) ────

    /**
     * @param string[] $extraHeaders
     * @return array{status: string, body: string, setCookies: string[]}
     */
    private function rawRequest(
        string $host,
        int $port,
        string $method,
        string $path,
        ?string $body = null,
        array $extraHeaders = []
    ): array {
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 5.0);
        $this->assertIsResource($socket, "could not connect to {$host}:{$port}: {$errstr} ({$errno})");

        $lines = ["{$method} {$path} HTTP/1.1", "Host: {$host}:{$port}", 'Connection: close'];
        foreach ($extraHeaders as $h) {
            $lines[] = $h;
        }
        if ($body !== null) {
            $lines[] = 'Content-Type: application/json';
            $lines[] = 'Content-Length: ' . strlen($body);
        }
        $request = implode("\r\n", $lines) . "\r\n\r\n" . ($body ?? '');
        fwrite($socket, $request);

        $raw = $this->readUntilClose($socket, 5.0);
        fclose($socket);

        [$head, $bodyText] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        $headerLines = explode("\r\n", $head);
        $status = trim((string)preg_replace('#^HTTP/\d\.\d\s+#', '', array_shift($headerLines) ?: ''));

        $setCookies = [];
        foreach ($headerLines as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                $setCookies[] = trim(substr($line, strlen('Set-Cookie:')));
            }
        }

        return ['status' => $status, 'body' => $bodyText, 'setCookies' => $setCookies];
    }

    private function cookieNamed(array $cookies, string $name): ?string
    {
        foreach ($cookies as $c) {
            if (stripos($c, $name . '=') === 0) {
                return $c;
            }
        }
        return null;
    }

    private function readUntilClose($socket, float $timeoutSeconds): string
    {
        stream_set_blocking($socket, false);
        $deadline = microtime(true) + $timeoutSeconds;
        $buffer = '';
        while (microtime(true) < $deadline) {
            $chunk = @fread($socket, 65536);
            if ($chunk === false) {
                break;
            }
            if ($chunk !== '') {
                $buffer .= $chunk;
                continue;
            }
            if (feof($socket)) {
                break;
            }
            usleep(10_000);
        }

        return $buffer;
    }
}
