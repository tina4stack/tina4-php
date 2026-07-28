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
    /** */
    private static ?TestServer $server = null;
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

        self::$server = TestServer::start(self::$docRoot . '/index.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        @unlink(self::$docRoot . '/index.php');
        @rmdir(self::$docRoot);
    }



    /**
     * @param string[] $headers Extra request headers, e.g. a proxy's X-Forwarded-Proto.
     * @return string[] Every Set-Cookie header line from a real GET /.
     */
    private function fetchSetCookies(array $headers = []): array
    {
        $opts = ['method' => 'GET', 'ignore_errors' => true];
        if ($headers !== []) {
            $opts['header'] = implode("\r\n", $headers);
        }
        $ctx = stream_context_create(['http' => $opts]);
        $body = @file_get_contents('http://127.0.0.1:' . self::$server->port . '/', false, $ctx);
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

    // ---- tina4-php#175: Secure behind a TLS-terminating proxy ----------------

    /**
     * THE #175 lock-in: a proxy-terminated HTTPS request must yield Secure.
     *
     * nginx/HAProxy/ALB/Cloudflare terminate TLS and forward plain HTTP, so
     * $_SERVER['HTTPS'] is unset on exactly the deployments that ARE encrypted.
     * Both cookies used to read that flag alone and concluded "plain HTTP", so
     * neither got Secure and both stayed sendable over cleartext. This drives
     * the reporter's exact repro: real server, real X-Forwarded-Proto header,
     * real Set-Cookie lines.
     */
    public function testProxyTerminatedHttpsMarksBothCookiesSecure(): void
    {
        $cookies = $this->fetchSetCookies(['X-Forwarded-Proto: https']);
        foreach (['PHPSESSID', 'tina4_session'] as $name) {
            $cookie = $this->cookieNamed($cookies, $name);
            $this->assertNotNull($cookie, "{$name} must be emitted");
            $this->assertMatchesRegularExpression(
                '/;\s*Secure/i',
                $cookie,
                "{$name} must be Secure when X-Forwarded-Proto says the client is on "
                . "https; without it the session cookie is sendable over cleartext - " . $cookie
            );
        }
    }

    /**
     * A proxy chain appends each hop ("https, http"); the FIRST is the client's.
     */
    public function testForwardedProtoChainUsesTheClientFacingHop(): void
    {
        $cookies = $this->fetchSetCookies(['X-Forwarded-Proto: https, http']);
        $cookie = $this->cookieNamed($cookies, 'tina4_session');
        $this->assertNotNull($cookie);
        $this->assertMatchesRegularExpression(
            '/;\s*Secure/i',
            $cookie,
            'the first hop is the client-facing scheme, so this is an https request - ' . $cookie
        );
    }

    /**
     * The negative: plain HTTP with no proxy header must NOT get Secure.
     *
     * Secure on a genuinely-http response would stop the browser returning the
     * cookie at all, breaking every session. This guards the fix from
     * over-reaching into "always Secure".
     */
    public function testPlainHttpDoesNotGetSecure(): void
    {
        $cookies = $this->fetchSetCookies();
        foreach (['PHPSESSID', 'tina4_session'] as $name) {
            $cookie = $this->cookieNamed($cookies, $name);
            $this->assertNotNull($cookie);
            $this->assertDoesNotMatchRegularExpression(
                '/;\s*Secure/i',
                $cookie,
                "{$name} must NOT be Secure on plain http or the browser never sends "
                . "it back and the session silently breaks - " . $cookie
            );
        }
    }

    /**
     * X-Forwarded-Proto: http (proxy present, client NOT on TLS) stays insecure.
     */
    public function testForwardedProtoHttpDoesNotGetSecure(): void
    {
        $cookies = $this->fetchSetCookies(['X-Forwarded-Proto: http']);
        $cookie = $this->cookieNamed($cookies, 'tina4_session');
        $this->assertNotNull($cookie);
        $this->assertDoesNotMatchRegularExpression('/;\s*Secure/i', $cookie, $cookie);
    }
}
