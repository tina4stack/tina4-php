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
 * Security headers on every response, from every entry point (ADR-0066).
 *
 * The runner for the three ADR-0066 invariants in
 * tina4-documentation/plan/v3/fixtures/securityheaders_contract.json; the
 * Python, Ruby and Node suites carry the same case names.
 *
 *  - static files, the 404 and 405 fallbacks and the framework's own refusals
 *    carry the canonical security header set (tina4-python#137);
 *  - a header a route already set is kept - only missing headers are filled in;
 *  - every PHP entry point - App::run() in the CLI (Tina4's own socket server),
 *    App::run()/handle() under a web SAPI (php -S, PHP-FPM, Apache) and
 *    App::__invoke() (Swoole, RoadRunner, PSR-7) - attaches the security headers
 *    and CSRF and mounts the configured SSO routes (tina4-python#134).
 *
 * NO MOCKS: every response comes from the framework in its own real php
 * process (SecurityEntryProject), over a real socket where the entry has one,
 * and the SSO mount is proven against the lab's real Keycloak realm.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SecurityEntryProject.php';

class SecurityHeadersEveryResponseContractTest extends TestCase
{
    private const CANONICAL = ['x-frame-options', 'x-content-type-options', 'content-security-policy',
        'referrer-policy', 'x-xss-protection', 'permissions-policy'];
    private const ROUTE_CSP = "default-src 'none'; img-src 'self'";

    private ?SecurityEntryProject $project = null;

    protected function setUp(): void
    {
        $this->project = new SecurityEntryProject();
        file_put_contents($this->project->dir . '/src/routes/own_headers.php', "<?php\n"
            . "\\Tina4\\Router::get('/own-headers', function (\$request, \$response) {\n"
            . "    return \$response->header('Content-Security-Policy', " . var_export(self::ROUTE_CSP, true) . ")\n"
            . "        ->header('X-Frame-Options', 'DENY')->html('<p>shaped by the route</p>');\n"
            . "});\n");
    }

    protected function tearDown(): void
    {
        $this->project?->destroy();
        $this->project = null;
    }

    /** @return array<string, array{0: string}> */
    public static function entries(): array
    {
        return [
            'App::run() socket server (CLI)' => ['socket'],
            'App::run()/handle() under a web SAPI (php -S)' => ['web-sapi'],
            'App::__invoke() (Swoole / RoadRunner / PSR-7)' => ['invoke'],
        ];
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function send(string $entry, bool $csrf, string $method, string $path, array $extra = []): array
    {
        if ($entry === 'invoke') {
            return $this->project->invoke(
                ['method' => $method, 'path' => $path, 'headers' => ['content-type' => 'application/json'],
                    'body' => $method === 'GET' ? '' : '{}'],
                $csrf,
                $extra
            );
        }
        return SecurityEntryProject::request($this->project->serve($entry, $csrf, $extra) . $path, $method,
            $method === 'GET' ? '' : '{}');
    }

    private function assertCarriesTheSet(string $entry, string $label, array $response, array $statuses): void
    {
        $this->assertContains($response['status'], $statuses, "{$entry}: {$label} answered {$response['status']}");
        $missing = array_values(array_diff(self::CANONICAL, array_keys($response['headers'])));
        $this->assertSame([], $missing, "{$entry}: {$label} ({$response['status']}) went out without these headers");
        $this->assertSame('nosniff', $response['headers']['x-content-type-options']);
    }

    #[DataProvider('entries')]
    public function testAStaticFileCarriesTheSecurityHeaders(string $entry): void
    {
        $this->assertCarriesTheSet($entry, 'a static file', $this->send($entry, false, 'GET', '/app.js'), [200]);
    }

    #[DataProvider('entries')]
    public function testA404CarriesTheSecurityHeaders(string $entry): void
    {
        $this->assertCarriesTheSet($entry, 'the 404', $this->send($entry, false, 'GET', '/nowhere'), [404]);
    }

    #[DataProvider('entries')]
    public function testA405CarriesTheSecurityHeaders(string $entry): void
    {
        $this->assertCarriesTheSet($entry, 'the 405', $this->send($entry, false, 'PUT', '/page'), [405]);
    }

    #[DataProvider('entries')]
    public function testARefusalCarriesTheSecurityHeaders(string $entry): void
    {
        $this->assertCarriesTheSet($entry, 'the CSRF refusal', $this->send($entry, true, 'POST', '/api/transfer'), [403]);
        $this->assertCarriesTheSet($entry, 'the auth refusal', $this->send($entry, false, 'POST', '/api/transfer'), [401]);
    }

    #[DataProvider('entries')]
    public function testAHeaderTheRouteAlreadySetIsNotOverwritten(string $entry): void
    {
        $response = $this->send($entry, false, 'GET', '/own-headers');
        $this->assertSame(200, $response['status']);
        $this->assertSame(self::ROUTE_CSP, $response['headers']['content-security-policy'] ?? null, "{$entry}: the route's own CSP was overwritten");
        $this->assertSame('DENY', $response['headers']['x-frame-options'] ?? null, "{$entry}: the route's own X-Frame-Options was overwritten");
        $this->assertSame('nosniff', $response['headers']['x-content-type-options'] ?? null, "{$entry}: a header the route did not set was not filled in");
    }

    #[DataProvider('entries')]
    public function testEveryEntryPointAttachesTheSecurityHeadersAndCsrf(string $entry): void
    {
        $this->assertCarriesTheSet($entry, 'a routed page', $this->send($entry, true, 'GET', '/page'), [200]);
        $forged = $this->send($entry, true, 'POST', '/api/transfer');
        $this->assertSame(403, $forged['status'], "{$entry}: a write with no form token was not refused by CSRF");
        $this->assertStringContainsString('CSRF_INVALID', $forged['body']);
    }

    #[DataProvider('entries')]
    public function testEveryEntryPointMountsTheSsoRoutes(string $entry): void
    {
        if (!getenv('TINA4_REQUIRE_OIDC')) {
            $this->markTestSkipped('[needs:oidc] real OIDC gate runs on the lab (set TINA4_REQUIRE_OIDC=1 with Keycloak on TINA4_TEST_OIDC_ISSUER)');
        }
        $issuer = getenv('TINA4_TEST_OIDC_ISSUER') ?: 'http://127.0.0.1:58080/realms/tina4-contract';
        $response = $this->send($entry, false, 'GET', '/auth/login', [
            'TINA4_SSO_ISSUER' => $issuer,
            'TINA4_SSO_CLIENT_ID' => 'tina4-app',
            'TINA4_SSO_CLIENT_SECRET' => 'tina4-secret',
            'TINA4_SSO_REDIRECT_URI' => 'http://127.0.0.1:7146/auth/callback',
        ]);
        $this->assertSame(302, $response['status'], "{$entry}: GET /auth/login is not the mounted SSO route: {$response['body']}");
        $this->assertStringStartsWith($issuer . '/protocol/openid-connect/auth?', $response['headers']['location'] ?? '');
    }
}
