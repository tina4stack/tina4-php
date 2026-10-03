<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A request that writes nothing to a session stores no session and sets no
 * session cookie.
 *
 * Every request used to create and store a Tina4 session, and start PHP's
 * native one, and set both cookies: static files, 404s and /health included,
 * so session storage grew with anonymous traffic. Session::save() wrote a
 * session minted for the request even when nothing had changed it, the router
 * sent a cookie for any id the client had not sent, and the router's
 * session_start() made PHP create a PHPSESSID file and cookie for every
 * visitor.
 *
 * Both servers are real: `php -S`, where PHP sends its own headers (as under
 * Apache/FPM), and Tina4\Server, where the router puts every cookie on the
 * Response. Each cell counts the files in both session stores and reads every
 * Set-Cookie line off the wire.
 */
class SessionAnonymousRequestTest extends TestCase
{
    private string $base;
    private string $tina4Store;
    private string $nativeStore;
    private ?\TestServer $server = null;

    protected function setUp(): void
    {
        $this->base = \TempPath::dir('tina4-anon-session-');
        mkdir($this->base . '/src/public', 0700, true);
        file_put_contents($this->base . '/src/public/hello.txt', 'static file');
        $this->tina4Store = $this->base . '/tina4-sessions';
        $this->nativeStore = $this->base . '/native-sessions';
        mkdir($this->tina4Store, 0700);
        mkdir($this->nativeStore, 0700);
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function boot(string $mode): \TestServer
    {
        $env = getenv();
        $env['TINA4_SESSION_PATH'] = $this->tina4Store;
        $env['TINA4_PHP_SESSION_PATH'] = $this->nativeStore;
        $env['TINA4_SESSION_BACKEND'] = 'file';
        $env['TINA4_OVERRIDE_CLIENT'] = 'true';
        $env['TINA4_SUPPRESS'] = 'true';
        $env['TINA4_AUTO_MIGRATE'] = 'false';
        $env['TINA4_DEBUG'] = 'false';
        $this->server = $mode === 'php -S'
            ? \TestServer::start(__DIR__ . '/fixtures/session_anonymous_request_php_s.php', $env, $this->base)
            : \TestServer::startScript(__DIR__ . '/fixtures/session_anonymous_request_server.php', env: $env, cwd: $this->base);
        return $this->server;
    }

    /** @return array<string, array{string, string}> */
    public static function requestsThatWriteNothing(): array
    {
        $cells = [];
        foreach (['php -S', 'Tina4\\Server'] as $mode) {
            foreach (['static file' => '/hello.txt', '404' => '/missing', 'health' => '/health',
                'route that never touches the session' => '/plain', 'route that reads the session' => '/read',
                'route that reads $_SESSION' => '/native-read',
                'route that deletes a key it never set' => '/delete-missing',
                'route that clears the session' => '/clear',
                'route that regenerates an empty session' => '/regenerate-empty',
                'route that reads a flash message' => '/read-flash'] as $what => $path) {
                $cells["{$mode}: {$what}"] = [$mode, $path];
            }
        }
        return $cells;
    }

    /** @return array<string, array{string}> */
    public static function servers(): array
    {
        return ['php -S' => ['php -S'], 'Tina4\\Server' => ['Tina4\\Server']];
    }

    #[DataProvider('requestsThatWriteNothing')]
    public function testARequestThatWritesNothingStoresNoSessionAndSetsNoCookie(string $mode, string $path): void
    {
        $server = $this->boot($mode);
        for ($i = 0; $i < 3; $i++) {
            $reply = $this->rawRequest($server->port, $path);
            $this->assertSame([], $reply['setCookies'], "{$path} must set no cookie; log: {$server->log()}");
        }
        $this->assertSame(0, $this->files($this->tina4Store), "{$path} must store no Tina4 session");
        $this->assertSame(0, $this->files($this->nativeStore), "{$path} must store no native session");
    }

    #[DataProvider('servers')]
    public function testAWriteStoresTheSessionAndAReplayResumesIt(string $mode): void
    {
        $server = $this->boot($mode);
        $write = $this->rawRequest($server->port, '/write');
        $cookie = $this->cookieNamed($write['setCookies'], 'tina4_session');
        $this->assertNotNull($cookie, 'a write must set the session cookie; got: ' . implode(' | ', $write['setCookies']));
        $this->assertCount(1, $write['setCookies'], 'exactly one cookie, tina4_session, sent once; got: ' . implode(' | ', $write['setCookies']));
        $this->assertSame(1, $this->files($this->tina4Store));
        $this->assertSame(0, $this->files($this->nativeStore));

        $read = $this->rawRequest($server->port, '/read', ['Cookie: ' . $cookie]);
        $this->assertSame('user=alice', $read['body']);
        $this->assertSame([], $read['setCookies'], 'a resumed session needs no new cookie');
        $this->assertSame(1, $this->files($this->tina4Store));
    }

    #[DataProvider('servers')]
    public function testANativeWriteStoresTheNativeSessionAndAReplayResumesIt(string $mode): void
    {
        $server = $this->boot($mode);
        $write = $this->rawRequest($server->port, '/native-write');
        $cookie = $this->cookieNamed($write['setCookies'], 'PHPSESSID');
        $this->assertNotNull($cookie, 'a $_SESSION write must set PHPSESSID; got: ' . implode(' | ', $write['setCookies']) . "; log: {$server->log()}");
        $this->assertCount(1, $write['setCookies'], 'exactly one cookie, PHPSESSID, sent once; got: ' . implode(' | ', $write['setCookies']));
        $this->assertSame(0, $this->files($this->tina4Store));

        $read = $this->rawRequest($server->port, '/native-read', ['Cookie: ' . $cookie]);
        $this->assertSame('native=set', $read['body']);
        $this->assertSame([], $read['setCookies'], 'a resumed native session needs no new cookie');
        $this->assertSame(1, $this->files($this->nativeStore));
    }

    #[DataProvider('servers')]
    public function testANativeSessionTheClientHoldsIsKeptWhenARequestEmptiesIt(string $mode): void
    {
        // Only a session minted for THIS request is dropped for being empty. One
        // the client already holds stays, as PHP keeps it: emptying $_SESSION is
        // not session_destroy().
        $server = $this->boot($mode);
        $cookie = $this->cookieNamed($this->rawRequest($server->port, '/native-write')['setCookies'], 'PHPSESSID');
        $this->assertNotNull($cookie, "log: {$server->log()}");
        $emptied = $this->rawRequest($server->port, '/native-empty', ['Cookie: ' . $cookie]);
        $this->assertSame('native emptied', $emptied['body']);
        $this->assertSame([], $emptied['setCookies']);
        $this->assertSame(1, $this->files($this->nativeStore), 'the session the client holds must be kept');
    }

    #[DataProvider('servers')]
    public function testAFormTokenBoundToTheNativeSessionKeepsThatSession(string $mode): void
    {
        // form_token() binds the token to session_id() when a native session is
        // active, and CsrfMiddleware refuses a post from any other id. A page
        // that renders a form writes nothing to $_SESSION, but its session must
        // be kept and its cookie sent, or the form can never be posted.
        $server = $this->boot($mode);
        $reply = $this->rawRequest($server->port, '/form');
        $payload = json_decode(base64_decode(strtr(explode('.', trim($reply['body']))[1] ?? '', '-_', '+/')), true);
        $bound = $payload['session_id'] ?? null;
        $cookie = $this->cookieNamed($reply['setCookies'], 'PHPSESSID');
        if ($bound === null) {
            // No native session under this server (none starts once headers are
            // sent): nothing is bound, so nothing needs keeping.
            $this->assertNull($cookie);
            $this->assertSame(0, $this->files($this->nativeStore));
            return;
        }
        $this->assertSame('PHPSESSID=' . $bound, $cookie, 'the session the token is bound to must be sent; got: ' . implode(' | ', $reply['setCookies']));
        $this->assertSame(1, $this->files($this->nativeStore), 'and kept');
    }

    #[DataProvider('servers')]
    public function testACookieTheStoreNeverIssuedGetsNoReplacementCookie(string $mode): void
    {
        $server = $this->boot($mode);
        $reply = $this->rawRequest($server->port, '/read', ['Cookie: tina4_session=' . bin2hex(random_bytes(16))]);
        $this->assertSame('user=-', $reply['body']);
        $this->assertSame([], $reply['setCookies'], 'a session that was never stored needs no cookie');
        $this->assertSame(0, $this->files($this->tina4Store));
    }

    /** @return array<string, array{string}> */
    public static function handlersThatEndTheRequestEarly(): array
    {
        return [
            'session_write_close()' => ['/native-write-close'],
            'exit after a redirect' => ['/native-write-exit'],
            'output flushed before the response' => ['/native-write-early-output'],
            'a nested dispatch before the write' => ['/native-write-after-nested-dispatch'],
        ];
    }

    #[DataProvider('handlersThatEndTheRequestEarly')]
    public function testANativeWriteStillGetsItsCookieWhenTheHandlerEndsTheRequestItsOwnWay(string $path): void
    {
        // The cookie is deferred until the request is known to have used the
        // session, so every way a request can end has to deliver it: a handler
        // that closes the session, exits, or has already sent output must not
        // leave a stored session its owner can never resume.
        $server = $this->boot('php -S');
        $reply = $this->rawRequest($server->port, $path);
        $cookie = $this->cookieNamed($reply['setCookies'], 'PHPSESSID');
        $this->assertNotNull($cookie, "{$path} wrote \$_SESSION and must set PHPSESSID; got: " . implode(' | ', $reply['setCookies']) . "; log: {$server->log()}");
        $this->assertCount(1, $reply['setCookies']);
        $this->assertSame(1, $this->files($this->nativeStore));

        $read = $this->rawRequest($server->port, '/native-read', ['Cookie: ' . $cookie]);
        $this->assertSame('native=set', $read['body'], 'the cookie must resume the session that was written');
    }

    public function testAnExitThatNeverUsedTheSessionLeavesNoSession(): void
    {
        $server = $this->boot('php -S');
        $reply = $this->rawRequest($server->port, '/exit-without-session-use');
        $this->assertSame('bye', $reply['body']);
        $this->assertSame([], $reply['setCookies']);
        $this->assertSame(0, $this->files($this->nativeStore), 'exit skips the router\'s end-of-request step; the session it started must still go');
    }

    public function testAHandlerThatRegeneratesTheIdAfterWritingSendsOneCookieNamingTheFinalId(): void
    {
        // The deferred start keeps session.use_cookies off, so PHP's own
        // session_regenerate_id() cannot send a second, different cookie.
        $server = $this->boot('php -S');
        $reply = $this->rawRequest($server->port, '/native-write-regenerate');
        $this->assertCount(1, $reply['setCookies'], 'one cookie; got: ' . implode(' | ', $reply['setCookies']));
        $cookie = $this->cookieNamed($reply['setCookies'], 'PHPSESSID');
        $this->assertNotNull($cookie);
        $this->assertSame('PHPSESSID=' . $reply['body'], $cookie, 'the cookie must name the id the session ended on');
        $read = $this->rawRequest($server->port, '/native-read', ['Cookie: ' . $cookie]);
        $this->assertSame('native=set', $read['body']);
    }

    public function testSavingAFreshSessionWritesNothingUntilItChanges(): void
    {
        $session = new \Tina4\Session('file', ['path' => $this->tina4Store]);
        $session->start();
        $this->assertTrue($session->isFresh());
        $this->assertTrue($session->save());
        $this->assertSame(0, $this->files($this->tina4Store), 'an unchanged, never-stored session writes nothing');

        $session->set('user', 'alice');
        $this->assertFalse($session->isFresh());
        $this->assertSame(1, $this->files($this->tina4Store), 'its first change writes it');
    }

    private function files(string $dir): int
    {
        return count(array_filter(scandir($dir) ?: [], static fn ($f) => $f !== '.' && $f !== '..'));
    }

    private function cookieNamed(array $cookies, string $name): ?string
    {
        foreach ($cookies as $c) {
            if (stripos($c, $name . '=') === 0) {
                return explode(';', $c, 2)[0];
            }
        }
        return null;
    }

    /**
     * @param string[] $extraHeaders
     * @return array{status: string, body: string, setCookies: string[]}
     */
    private function rawRequest(int $port, string $path, array $extraHeaders = []): array
    {
        $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 5.0);
        $this->assertIsResource($socket, "could not connect to 127.0.0.1:{$port}: {$errstr} ({$errno})");
        $lines = array_merge(["GET {$path} HTTP/1.1", "Host: 127.0.0.1:{$port}", 'Connection: close'], $extraHeaders);
        fwrite($socket, implode("\r\n", $lines) . "\r\n\r\n");

        stream_set_blocking($socket, false);
        $deadline = microtime(true) + 5.0;
        $raw = '';
        while (microtime(true) < $deadline) {
            $chunk = @fread($socket, 65536);
            if ($chunk === false) {
                break;
            }
            if ($chunk !== '') {
                $raw .= $chunk;
                continue;
            }
            if (feof($socket)) {
                break;
            }
            usleep(10_000);
        }
        fclose($socket);

        [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        $headerLines = explode("\r\n", $head);
        $status = trim((string)preg_replace('#^HTTP/\d\.\d\s+#', '', array_shift($headerLines) ?: ''));
        $setCookies = [];
        foreach ($headerLines as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                $setCookies[] = trim(substr($line, strlen('Set-Cookie:')));
            }
        }
        return ['status' => $status, 'body' => $body, 'setCookies' => $setCookies];
    }
}
