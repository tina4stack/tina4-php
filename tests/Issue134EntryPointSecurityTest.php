<?php

declare(strict_types=1);

/**
 * tina4-python#134 parity: run() attached the security-header middleware and
 * (with TINA4_CSRF=true) CsrfMiddleware, while the alternate server entry
 * asgi() attached neither - so an app served by uvicorn shipped no security
 * headers and accepted cross-site forged writes.
 *
 * PHP's equivalents are App::run() (Tina4's own socket server in the CLI),
 * App::run()/handle() under a web SAPI (PHP-FPM, Apache, php -S) and
 * App::__invoke() (what the Swoole, RoadRunner and PSR-7 adapters call). All
 * three reach App::start(), which attaches both middlewares, before
 * Router::dispatch(). This locks that in: every entry, launched in its own real
 * php process, sends the same security headers on a route response and refuses
 * a POST that carries no form token when TINA4_CSRF=true.
 *
 * The negative control runs the same POST with TINA4_CSRF unset and gets the
 * auth gate's 401 instead, which proves the 403 is CSRF enforcement and not
 * something else refusing the request.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SecurityEntryProject.php';

class Issue134EntryPointSecurityTest extends TestCase
{
    private ?SecurityEntryProject $project = null;

    protected function setUp(): void
    {
        $this->project = new SecurityEntryProject();
    }

    protected function tearDown(): void
    {
        $this->project?->destroy();
        $this->project = null;
    }

    /**
     * Every PHP entry point that serves a request.
     *
     * @return array<string, array{0: string}>
     */
    public static function entries(): array
    {
        return [
            'App::run() socket server (CLI)' => ['socket'],
            'App::run()/handle() under a web SAPI (php -S)' => ['web-sapi'],
            'App::__invoke() (Swoole / RoadRunner / PSR-7)' => ['invoke'],
        ];
    }

    /**
     * One request through the named entry.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function send(string $entry, bool $csrf, string $method, string $path, string $body = ''): array
    {
        if ($entry === 'invoke') {
            return $this->project->invoke(
                ['method' => $method, 'path' => $path, 'headers' => ['content-type' => 'application/json'], 'body' => $body],
                $csrf
            );
        }
        return SecurityEntryProject::request($this->project->serve($entry, $csrf) . $path, $method, $body);
    }

    /** Instrument check: each entry really is the server it claims to be. */
    public function testEachEntryIsServedByTheSapiItNames(): void
    {
        $sapis = [];
        foreach (['socket', 'web-sapi', 'invoke'] as $entry) {
            $sapis[$entry] = json_decode($this->send($entry, false, 'GET', '/sapi')['body'], true)['sapi'] ?? null;
        }
        // 'cli' for the socket server proves run() bound Tina4's own Server and
        // did not fall back to spawning `php -S` (which would report cli-server).
        $this->assertSame(['socket' => 'cli', 'web-sapi' => 'cli-server', 'invoke' => 'cli'], $sapis);
    }

    #[DataProvider('entries')]
    public function testEveryEntrySendsTheSecurityHeadersOnARouteResponse(string $entry): void
    {
        $response = $this->send($entry, false, 'GET', '/page');

        $this->assertSame(200, $response['status'], "{$entry}: GET /page");
        foreach (SecurityEntryProject::HEADERS as $header) {
            $this->assertArrayHasKey($header, $response['headers'], "{$entry}: GET /page must carry {$header}");
        }
        $this->assertSame("default-src 'self'", $response['headers']['content-security-policy']);
        $this->assertSame('nosniff', $response['headers']['x-content-type-options']);
    }

    #[DataProvider('entries')]
    public function testEveryEntryEnforcesCsrfWhenEnabled(string $entry): void
    {
        $response = $this->send($entry, true, 'POST', '/api/transfer', '{"amount": 100}');

        $this->assertSame(403, $response['status'],
            "{$entry}: with TINA4_CSRF=true a write with no form token must be refused");
        $this->assertStringContainsString('CSRF_INVALID', $response['body'], "{$entry}: refused BY the CSRF middleware");
        $this->assertStringNotContainsString('moved', $response['body'], "{$entry}: the write route must not have run");
    }

    /**
     * tina4-python's asgi() also skipped the configured SSO route mount that
     * run() does. PHP mounts it in App::start(), which every entry reaches:
     * with TINA4_SSO_* set, /auth/login on each entry redirects to the real
     * provider (the lab's Keycloak, the SsoContractTest realm).
     */
    #[DataProvider('entries')]
    public function testEveryEntryServesTheConfiguredSsoRoutes(string $entry): void
    {
        if (!getenv('TINA4_REQUIRE_OIDC')) {
            $this->markTestSkipped('[needs:oidc] real OIDC gate runs on the lab (set TINA4_REQUIRE_OIDC=1 with Keycloak on TINA4_TEST_OIDC_ISSUER)');
        }
        $issuer = getenv('TINA4_TEST_OIDC_ISSUER') ?: 'http://127.0.0.1:58080/realms/tina4-contract';
        $sso = [
            'TINA4_SSO_ISSUER' => $issuer,
            'TINA4_SSO_CLIENT_ID' => 'tina4-app',
            'TINA4_SSO_CLIENT_SECRET' => 'tina4-secret',
            'TINA4_SSO_REDIRECT_URI' => 'http://127.0.0.1:7146/auth/callback',
        ];

        $response = $entry === 'invoke'
            ? $this->project->invoke(['method' => 'GET', 'path' => '/auth/login'], false, $sso)
            : SecurityEntryProject::request($this->project->serve($entry, false, $sso) . '/auth/login');

        $this->assertSame(302, $response['status'], "{$entry}: GET /auth/login must be the mounted SSO route: " . $response['body']);
        $this->assertStringStartsWith($issuer . '/protocol/openid-connect/auth?', $response['headers']['location'] ?? '',
            "{$entry}: /auth/login must redirect to the provider's authorization endpoint");
    }

    #[DataProvider('entries')]
    public function testWithoutCsrfTheSameWriteReachesTheAuthGateInstead(string $entry): void
    {
        $response = $this->send($entry, false, 'POST', '/api/transfer', '{"amount": 100}');

        $this->assertSame(401, $response['status'],
            "{$entry}: with TINA4_CSRF unset the request passes CSRF and stops at the auth gate");
        $this->assertStringNotContainsString('CSRF_INVALID', $response['body']);
    }
}
