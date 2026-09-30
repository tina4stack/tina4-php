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
 */

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for tina4stack/tina4-php#253.
 *
 * Under the raw-socket server (`tina4 serve`, PHP_SAPI === "cli"), a request
 * that rotates the native session id MID-REQUEST — session_regenerate_id(true),
 * the standard session-fixation defence on login — never sent the NEW id to the
 * client. Router::finishNativeSession() re-emitted the native session cookie
 * only when the session was NEW at the start of the request, a flag computed
 * BEFORE the handler ran. A regenerate happens AFTER that, so no Set-Cookie went
 * out: the browser kept the old id and the regenerated session (holding the
 * app's auth state) could not be found next request. Login worked, then the
 * very next page reported "session expired".
 *
 * The fix compares the session id at the END of the request against the id the
 * client SENT, so a brand-new session AND a mid-request regenerate both re-emit.
 *
 * This is proven over the REAL Tina4\Server raw socket, no mocks — a raw socket
 * engages no PHP SAPI header mechanism, so headers_sent() stays false and
 * session_regenerate_id() genuinely rotates the id (it is a no-op once headers
 * are sent, which is why php -S / Apache / FPM were unaffected and only the
 * socket server broke).
 *
 * Mutation proof: restore the old start-time gate in finishNativeSession()
 *   -    if (session_id() !== (self::$routerNativeSessionIncomingId ?? '')) {
 *   +    if (self::$routerNativeSessionIsNew) {   // + its start-time assignment
 * and testRegenerateMidRequestReEmitsTheNewSessionCookie fails: step 2 carries
 * no Set-Cookie and step 3 reports hit=null (session lost).
 */
class SessionRegenerateNativeCookieTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/session_regenerate_native_server.php';

    /**
     * The whole point of #253: a mid-request session_regenerate_id() under the
     * socket server must send the NEW id, and the session must survive to the
     * next request.
     */
    public function testRegenerateMidRequestReEmitsTheNewSessionCookieAndSurvives(): void
    {
        $env = getenv();
        $env['TINA4_OVERRIDE_CLIENT'] = 'true';
        $env['TINA4_SUPPRESS'] = 'true';
        $env['TINA4_AUTO_MIGRATE'] = 'false';
        $env['TINA4_DEBUG'] = 'false';
        $server = \TestServer::startScript(self::FIXTURE, env: $env);
        try {
            // 1) Establish a native session. GET /whoami emits PHPSESSID=<A>.
            $first = $this->rawRequest('127.0.0.1', $server->port, 'GET', '/whoami');
            $this->assertSame('200', substr($first['status'], 0, 3), "log: {$server->log()}");
            $idA = $this->sessionIdFromCookies($first['setCookies']);
            $this->assertNotNull(
                $idA,
                "the first request must emit a native session cookie; got: "
                . implode(' | ', $first['setCookies']) . "; log: {$server->log()}"
            );

            // 2) Regenerate the id mid-request, carrying <A>. The response MUST
            //    carry Set-Cookie: PHPSESSID=<B> where <B> is the new id in the
            //    body. This is the exact assertion the bug fails.
            $regen = $this->rawRequest(
                '127.0.0.1',
                $server->port,
                'POST',
                '/regen',
                '{}',
                ["Cookie: PHPSESSID={$idA}"]
            );
            $this->assertSame('200', substr($regen['status'], 0, 3), "log: {$server->log()}");
            $regenBody = json_decode($regen['body'], true);
            $idBInBody = $regenBody['id'] ?? null;
            $this->assertNotNull($idBInBody, "regen body had no id; body: {$regen['body']}");
            $this->assertSame(1, $regenBody['hit'] ?? null, "regen must have written hit=1; body: {$regen['body']}");
            $this->assertNotSame($idA, $idBInBody, 'regenerate must have produced a genuinely new id');

            $idBEmitted = $this->sessionIdFromCookies($regen['setCookies']);
            $this->assertNotNull(
                $idBEmitted,
                'the regenerated id was NOT sent to the client — the exact #253 defect; '
                . 'got Set-Cookie: ' . implode(' | ', $regen['setCookies']) . "; log: {$server->log()}"
            );
            $this->assertSame(
                $idBInBody,
                $idBEmitted,
                'the emitted PHPSESSID must be the regenerated id from the body'
            );

            // 3) The browser now carries <B>. The regenerated session (hit=1)
            //    must be found — not an empty session under a lost id.
            $whoami = $this->rawRequest(
                '127.0.0.1',
                $server->port,
                'GET',
                '/whoami',
                null,
                ["Cookie: PHPSESSID={$idBEmitted}"]
            );
            $whoamiBody = json_decode($whoami['body'], true);
            $this->assertSame(
                1,
                $whoamiBody['hit'] ?? null,
                'the regenerated session must survive to the next request (hit=1); '
                . "body: {$whoami['body']}; log: {$server->log()}"
            );
            $this->assertSame(
                $idBEmitted,
                $whoamiBody['id'] ?? null,
                'the next request must run under the regenerated id'
            );
        } finally {
            $server->stop();
        }
    }

    // ── raw socket HTTP client ───────────────────────────────────────────────

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

    /** The PHPSESSID value from a set of Set-Cookie lines, or null. */
    private function sessionIdFromCookies(array $cookies): ?string
    {
        foreach ($cookies as $c) {
            if (stripos($c, 'PHPSESSID=') === 0) {
                $pair = explode(';', $c, 2)[0];
                $value = explode('=', $pair, 2)[1] ?? '';
                return $value !== '' ? $value : null;
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
