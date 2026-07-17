<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;

/**
 * Security regression for tina4stack/tina4-php#174.
 *
 * Router::dispatch() starts the native PHP session so $_SESSION persists (the
 * #112 fix), but called session_start() WITHOUT session_set_cookie_params()
 * first. PHP's ini defaults are session.cookie_httponly=0 and
 * cookie_samesite="", so every app emitted a bare `PHPSESSID=...; path=/`:
 * readable by any XSS (session theft) and sent on cross-site requests (CSRF).
 * Tina4's own tina4_session cookie was correctly attributed ~25 lines below in
 * the same method - that asymmetry was the bug.
 *
 * This asserts the WIRE CONTRACT: a real `php -S` server, a real HTTP request,
 * and the actual Set-Cookie headers it emits. An in-process check of
 * session_get_cookie_params() would still pass if something later overrode the
 * params, so it would not prove the fix.
 */
class SessionCookieAttributesTest extends TestCase
{
    /** @var array{0: resource, 1: int}|null */
    private static $server = null;
    private static string $docRoot = '';

    public static function setUpBeforeClass(): void
    {
        self::$docRoot = sys_get_temp_dir() . '/tina4_issue174_' . getmypid();
        @mkdir(self::$docRoot, 0777, true);

        // A clean-room app: framework only, no .env, no database, no app code.
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        file_put_contents(self::$docRoot . '/index.php', <<<PHP
        <?php
        require_once '{$autoload}';
        \\Tina4\\Router::get('/', function (\\Tina4\\Request \$request, \\Tina4\\Response \$response) {
            return \$response('ok');
        });
        \$result = \\Tina4\\Router::dispatch(new \\Tina4\\Request(), new \\Tina4\\Response());
        echo \$result->content ?? '';
        PHP);

        self::$server = self::bootServer(self::$docRoot . '/index.php');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server[0]);
            proc_close(self::$server[0]);
        }
        @unlink(self::$docRoot . '/index.php');
        @rmdir(self::$docRoot);
    }

    /** Find a free TCP port by binding to :0 and reading back what the OS gave us. */
    private static function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        return (int)substr($name, strrpos($name, ':') + 1);
    }

    /**
     * Boot a real `php -S` server on a free port and wait until it accepts.
     *
     * @return array{0: resource, 1: int} The process handle and its port.
     */
    private static function bootServer(string $router): array
    {
        $port = self::freePort();
        $cmd = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg($router);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($router));
        for ($i = 0; $i < 100; $i++) {
            $client = @stream_socket_client("tcp://127.0.0.1:$port", $e1, $e2, 0.1);
            if ($client) {
                fclose($client);
                return [$proc, $port];
            }
            usleep(25000);
        }
        throw new RuntimeException('php -S did not come up on port ' . $port);
    }

    /**
     * @param string[] $headers Extra request header lines to send.
     * @return string[] Every Set-Cookie header line from a real GET /.
     */
    private function fetchSetCookies(array $headers = []): array
    {
        $http = ['method' => 'GET', 'ignore_errors' => true];
        if ($headers !== []) {
            $http['header'] = implode("\r\n", $headers);
        }
        $ctx = stream_context_create(['http' => $http]);
        $body = @file_get_contents('http://127.0.0.1:' . self::$server[1] . '/', false, $ctx);
        $this->assertNotFalse($body, 'the clean-room app must respond');
        $cookies = [];
        foreach ($http_response_header ?? [] as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                $cookies[] = trim(substr($line, strlen('Set-Cookie:')));
            }
        }
        return $cookies;
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

    public function testNativeSessionCookieIsHttpOnly(): void
    {
        $native = $this->cookieNamed($this->fetchSetCookies(), 'PHPSESSID');
        $this->assertNotNull($native, 'the native session cookie must be emitted');
        $this->assertMatchesRegularExpression(
            '/;\s*HttpOnly/i',
            $native,
            'PHPSESSID without HttpOnly is readable by any XSS (session theft) — ' . $native
        );
    }

    public function testNativeSessionCookieHasSameSite(): void
    {
        $native = $this->cookieNamed($this->fetchSetCookies(), 'PHPSESSID');
        $this->assertNotNull($native);
        $this->assertMatchesRegularExpression(
            '/;\s*SameSite=Lax/i',
            $native,
            'PHPSESSID without SameSite is sent on cross-site requests (CSRF) — ' . $native
        );
    }

    public function testNativeCookieMatchesTina4SessionCookieAttributes(): void
    {
        $cookies = $this->fetchSetCookies();
        $native = $this->cookieNamed($cookies, 'PHPSESSID');
        $tina4 = $this->cookieNamed($cookies, 'tina4_session');
        $this->assertNotNull($native);
        $this->assertNotNull($tina4, 'tina4_session must still be emitted');

        // The asymmetry between these two cookies in the same response WAS the bug.
        foreach (['HttpOnly', 'SameSite=Lax'] as $attr) {
            $pattern = '/;\s*' . preg_quote($attr, '/') . '/i';
            $this->assertMatchesRegularExpression($pattern, $tina4, "tina4_session must carry {$attr}");
            $this->assertMatchesRegularExpression($pattern, $native, "PHPSESSID must carry {$attr} too");
        }
    }

    public function testNativeCookieIsNotSecureOverPlainHttp(): void
    {
        // Secure over plain HTTP would make the cookie undeliverable — it must be
        // set only for HTTPS or SameSite=None (browsers reject None without Secure).
        $native = $this->cookieNamed($this->fetchSetCookies(), 'PHPSESSID');
        $this->assertNotNull($native);
        $this->assertDoesNotMatchRegularExpression(
            '/;\s*Secure/i',
            $native,
            'Secure must not be set on plain HTTP with the default SameSite=Lax — ' . $native
        );
    }

    /**
     * Security regression for tina4stack/tina4-php#175.
     *
     * TLS terminated at a proxy means the SAPI only ever sees the plaintext hop,
     * so $_SERVER['HTTPS'] is unset and both cookies went out without Secure on a
     * genuinely HTTPS request — leaving them sendable over plaintext, where an
     * active network attacker can strip TLS and capture them. Request::isSecure()
     * reads x-forwarded-proto, which Request already trusted for $request->url.
     *
     * @return string[] Both Set-Cookie lines behind a simulated TLS proxy.
     */
    private function fetchSetCookiesBehindTlsProxy(): array
    {
        return $this->fetchSetCookies(['X-Forwarded-Proto: https']);
    }

    public function testCookiesAreSecureBehindTlsTerminatingProxy(): void
    {
        $cookies = $this->fetchSetCookiesBehindTlsProxy();
        foreach (['PHPSESSID', 'tina4_session'] as $name) {
            $cookie = $this->cookieNamed($cookies, $name);
            $this->assertNotNull($cookie, "{$name} must be emitted");
            $this->assertMatchesRegularExpression(
                '/;\s*Secure/i',
                $cookie,
                "{$name} must carry Secure when x-forwarded-proto says the client used https, "
                    . 'otherwise it can be sent over plaintext and stripped — ' . $cookie
            );
        }
    }

    public function testForwardedProtoHttpDoesNotForceSecure(): void
    {
        // A proxy that terminates plain HTTP must not produce a Secure cookie —
        // that would make the cookie undeliverable to the client.
        $cookies = $this->fetchSetCookies(['X-Forwarded-Proto: http']);
        foreach (['PHPSESSID', 'tina4_session'] as $name) {
            $cookie = $this->cookieNamed($cookies, $name);
            $this->assertNotNull($cookie);
            $this->assertDoesNotMatchRegularExpression(
                '/;\s*Secure/i',
                $cookie,
                "{$name} must not be Secure when the client-facing scheme is http — " . $cookie
            );
        }
    }

    public function testForwardedProtoChainUsesClientFacingHop(): void
    {
        // Multi-proxy chains forward a comma-separated list; the FIRST entry is
        // the scheme the client actually used.
        $cookies = $this->fetchSetCookies(['X-Forwarded-Proto: https, http']);
        $native = $this->cookieNamed($cookies, 'PHPSESSID');
        $this->assertNotNull($native);
        $this->assertMatchesRegularExpression(
            '/;\s*Secure/i',
            $native,
            'the client-facing hop (https) decides the flag, not a later internal hop — ' . $native
        );
    }
}
