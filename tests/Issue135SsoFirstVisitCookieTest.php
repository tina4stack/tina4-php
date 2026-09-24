<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


declare(strict_types=1);

/**
 * tina4-python#135 parity: on a visitor's FIRST request, Sso::login() stored
 * the pending state (state, nonce, PKCE verifier) in a NEW session, but Python's
 * save stage skipped a new session whose all() was empty - and all() hides the
 * SSO keys - so no session cookie was sent and the IdP callback could not find
 * the pending state.
 *
 * PHP is not affected: Router::saveSessionAndSetCookie() emits the cookie for
 * any session id the browser does not already carry, and Session::save() keys
 * on its dirty flag, never on all(). This locks that in end to end, against the
 * lab's REAL Keycloak (the same realm SsoContractTest uses), through the
 * framework's own configuration-mounted /auth/login and /auth/callback routes,
 * served by Tina4's socket server and by a web SAPI (php -S):
 *
 *   1. a cookie-less first visit to /auth/login gets a 302 to the provider AND
 *      a tina4_session cookie;
 *   2. the provider's real login form is submitted, and it redirects back with
 *      a code and the state;
 *   3. /auth/callback WITH that cookie completes the sign-in (302 to return_to);
 *      the same callback WITHOUT it is refused (400 SSO_CALLBACK_FAILED) - the
 *      negative control proving the cookie is what carries the pending state.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Issue135SsoFirstVisitCookieTest extends TestCase
{
    private const ISSUER_DEFAULT = 'http://127.0.0.1:58080/realms/tina4-contract';
    private const REDIRECT_URI = 'http://127.0.0.1:7146/auth/callback';

    private string $projectDir = '';
    private ?TestServer $server = null;

    protected function setUp(): void
    {
        if (!getenv('TINA4_REQUIRE_OIDC')) {
            $this->markTestSkipped('[needs:oidc] real OIDC gate runs on the lab (set TINA4_REQUIRE_OIDC=1 with Keycloak on TINA4_TEST_OIDC_ISSUER)');
        }
        $this->projectDir = sys_get_temp_dir() . '/tina4-issue135-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir . '/src/routes', 0777, true);
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        file_put_contents(
            $this->projectDir . '/index.php',
            "<?php\nrequire {$autoload};\n"
            . "\$app = new \\Tina4\\App(basePath: __DIR__);\n"
            . "if (PHP_SAPI === 'cli' && isset(\$argv[1])) {\n"
            . "    \$app->run('127.0.0.1', (int) \$argv[1]);\n"
            . "} else {\n"
            . "    \$app->run();\n"
            . "}\n"
        );
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
        if ($this->projectDir !== '' && is_dir($this->projectDir)) {
            exec('rm -rf ' . escapeshellarg($this->projectDir));
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function entries(): array
    {
        return [
            'Tina4 socket server' => ['socket'],
            'web SAPI (php -S)' => ['web-sapi'],
        ];
    }

    private function serve(string $entry): string
    {
        $env = [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'TMPDIR' => sys_get_temp_dir(),
            'TINA4_DEBUG' => 'false',
            'TINA4_SECRET' => 'issue-135-php-secret-0123456789abcdef',
            'TINA4_OVERRIDE_CLIENT' => 'true',
            'TINA4_SUPPRESS' => 'true',
            'TINA4_NO_BROWSER' => 'true',
            'TINA4_AUTO_MIGRATE' => 'false',
            'TINA4_SSO_ISSUER' => getenv('TINA4_TEST_OIDC_ISSUER') ?: self::ISSUER_DEFAULT,
            'TINA4_SSO_CLIENT_ID' => 'tina4-app',
            'TINA4_SSO_CLIENT_SECRET' => 'tina4-secret',
            'TINA4_SSO_REDIRECT_URI' => self::REDIRECT_URI,
        ];
        $this->server = $entry === 'socket'
            ? TestServer::startScript($this->projectDir . '/index.php', [], $env, $this->projectDir)
            : TestServer::start($this->projectDir . '/index.php', $env, $this->projectDir);
        return $this->server->base();
    }

    /**
     * One request, redirects NOT followed.
     *
     * @return array{status: int, headers: list<string>, body: string}
     */
    private static function fetch(string $url, string $method = 'GET', array $headers = [], string $content = ''): array
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", [...$headers, 'Connection: close']),
            'content' => $content,
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 20,
        ]]);
        $body = @file_get_contents($url, false, $context);
        $lines = $http_response_header ?? [];
        preg_match('#^HTTP/\S+\s+(\d{3})#', $lines[0] ?? '', $m);
        return ['status' => (int) ($m[1] ?? 0), 'headers' => $lines, 'body' => (string) $body];
    }

    /** The first value of a response header, or null. */
    private static function header(array $response, string $name): ?string
    {
        foreach ($response['headers'] as $line) {
            if (stripos($line, $name . ':') === 0) {
                return trim(substr($line, strlen($name) + 1));
            }
        }
        return null;
    }

    /** Every Set-Cookie "name=value" pair in a response. */
    private static function cookies(array $response): array
    {
        $pairs = [];
        foreach ($response['headers'] as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                $pairs[] = trim(explode(';', substr($line, 11), 2)[0]);
            }
        }
        return $pairs;
    }

    /**
     * Sign in on the provider's real login form and return the query it
     * redirects back to the app with (code + state).
     */
    private function signInAtProvider(string $authorizeUrl): array
    {
        $page = self::fetch($authorizeUrl);
        preg_match('/<form[^>]+action="([^"]+)"[^>]*>/', $page['body'], $form);
        $this->assertNotEmpty($form[1] ?? null, 'the provider login form was not found');
        $posted = self::fetch(
            html_entity_decode($form[1], ENT_QUOTES | ENT_HTML5),
            'POST',
            ['Content-Type: application/x-www-form-urlencoded', 'Cookie: ' . implode('; ', self::cookies($page))],
            http_build_query(['username' => 'andre', 'password' => 'tina4-pass', 'credentialId' => ''])
        );
        $location = (string) self::header($posted, 'Location');
        $this->assertStringStartsWith(self::REDIRECT_URI, $location, 'the provider must redirect back to the callback');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('code', $query);
        $this->assertArrayHasKey('state', $query);
        return $query;
    }

    #[DataProvider('entries')]
    public function testFirstVisitLoginSendsTheSessionCookieAndTheCallbackCompletes(string $entry): void
    {
        $base = $this->serve($entry);

        $login = self::fetch($base . '/auth/login?return_to=%2Fdashboard');
        $this->assertSame(302, $login['status'], "{$entry}: /auth/login redirects to the provider");
        $sessionCookies = array_values(array_filter(
            self::cookies($login),
            static fn(string $pair): bool => str_starts_with($pair, 'tina4_session=')
        ));
        $this->assertCount(1, $sessionCookies,
            "{$entry}: a FIRST visit whose session holds only the SSO pending state must still get its session cookie");

        $query = $this->signInAtProvider((string) self::header($login, 'Location'));
        $callback = $base . '/auth/callback?' . http_build_query($query);

        $completed = self::fetch($callback, 'GET', ['Cookie: ' . $sessionCookies[0]]);
        $this->assertSame(302, $completed['status'],
            "{$entry}: the callback carrying the first-visit cookie must complete the sign-in: " . $completed['body']);
        $this->assertSame('/dashboard', self::header($completed, 'Location'));
    }

    /** Negative control: the SAME callback without the cookie cannot find the pending state. */
    public function testCallbackWithoutTheFirstVisitCookieIsRefused(): void
    {
        $base = $this->serve('socket');

        $login = self::fetch($base . '/auth/login');
        $query = $this->signInAtProvider((string) self::header($login, 'Location'));

        $refused = self::fetch($base . '/auth/callback?' . http_build_query($query));
        $this->assertSame(400, $refused['status']);
        $this->assertStringContainsString('SSO_CALLBACK_FAILED', $refused['body']);
    }
}
