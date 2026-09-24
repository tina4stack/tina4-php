<?php

namespace Tina4;

use PHPUnit\Framework\TestCase;

/**
 * SSRF guard (ADR-0084) - the PHP runner for ssrf_guard_contract.json.
 *
 * tests/fixtures/ssrf_guard_contract.json is a copy of
 * tina4-documentation/plan/v3/fixtures/ssrf_guard_contract.json. Every address
 * in the fixture is fed to the real classifier; the request cases drive the real
 * Api client and the real Push sender against a REAL loopback listener started
 * with php -S - no mocks. 127.0.0.1 is blocked by default, so the listener is
 * reached via the explicit allow-list; the opt-out has a positive twin.
 */
final class SsrfGuardContractTest extends TestCase
{
    /** @var array<string,mixed> */
    private static array $fixture;

    /** @var string|false */
    private $savedOptOut;

    public static function setUpBeforeClass(): void
    {
        self::$fixture = json_decode(
            (string)file_get_contents(__DIR__ . '/fixtures/ssrf_guard_contract.json'),
            true
        );
    }

    protected function setUp(): void
    {
        $this->savedOptOut = getenv(Ssrf::ALLOW_PRIVATE_ENV);
        // Default (blocked) for this suite; the bootstrap turns it on globally.
        putenv(Ssrf::ALLOW_PRIVATE_ENV);
    }

    protected function tearDown(): void
    {
        if ($this->savedOptOut === false) {
            putenv(Ssrf::ALLOW_PRIVATE_ENV);
        } else {
            putenv(Ssrf::ALLOW_PRIVATE_ENV . '=' . $this->savedOptOut);
        }
    }

    // ── SSRF-CLASSIFY ────────────────────────────────────────────────────────

    public function testBlocksLoopbackByDefault(): void
    {
        $this->assertTrue(Ssrf::isBlockedAddress('127.0.0.1'));
        $this->assertTrue(Ssrf::isBlockedAddress('::1'));
    }

    public function testBlocksCloudMetadataAddress(): void
    {
        $this->assertTrue(Ssrf::isBlockedAddress('169.254.169.254'));
    }

    public function testBlocksPrivateAndCgnatRanges(): void
    {
        foreach (self::$fixture['addresses'] as $case) {
            $this->assertSame(
                $case['blocked'],
                Ssrf::isBlockedAddress($case['ip']),
                $case['ip']
            );
        }
    }

    public function testAllowsAPublicAddress(): void
    {
        $this->assertFalse(Ssrf::isBlockedAddress('8.8.8.8'));
        $this->assertFalse(Ssrf::isBlockedAddress('2606:4700:4700::1111'));
    }

    public function testRejectsANonHttpScheme(): void
    {
        foreach (self::$fixture['schemes'] as $case) {
            if ($case['allowed']) {
                continue;
            }
            try {
                Ssrf::guardUrl($case['scheme'] . '://example.com/x');
                $this->fail("scheme {$case['scheme']} should be refused");
            } catch (SsrfError $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    // ── SSRF-REQUEST ─────────────────────────────────────────────────────────

    public function testApiBlocksRequestToLoopbackByDefault(): void
    {
        $server = \TestServer::start(__DIR__ . '/fixtures/ssrf_ok_server.php');
        try {
            $result = (new Api())->get("http://127.0.0.1:{$server->port}/");
            $this->assertNull($result['http_code']);
            $this->assertStringContainsString(Ssrf::ALLOW_PRIVATE_ENV, (string)$result['error']);
        } finally {
            $server->stop();
        }
    }

    public function testApiAllowsRequestWithOptOut(): void
    {
        $server = \TestServer::start(__DIR__ . '/fixtures/ssrf_ok_server.php');
        try {
            putenv(Ssrf::ALLOW_PRIVATE_ENV . '=true');
            $result = (new Api())->get("http://127.0.0.1:{$server->port}/");
            $this->assertSame(200, $result['http_code']);

            // and the explicit allow-list works with the opt-out OFF
            putenv(Ssrf::ALLOW_PRIVATE_ENV);
            $allowed = (new Api('', allowHosts: ['127.0.0.1']))->get("http://127.0.0.1:{$server->port}/");
            $this->assertSame(200, $allowed['http_code']);
        } finally {
            $server->stop();
        }
    }

    public function testApiBlocksRedirectHopToPrivate(): void
    {
        $server = \TestServer::start(__DIR__ . '/fixtures/ssrf_redirect_server.php');
        try {
            // The loopback listener is allowed by the allow-list; its 302 target
            // (169.254.169.254) is NOT, so it is refused at the hop.
            $result = (new Api('', allowHosts: ['127.0.0.1']))->get("http://127.0.0.1:{$server->port}/");
            $this->assertNull($result['http_code']);
            $this->assertStringContainsString('169.254.169.254', (string)$result['error']);
        } finally {
            $server->stop();
        }
    }

    public function testWebPushBlockedToPrivateEndpointUnlessOptedIn(): void
    {
        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('[needs:absent-ext=openssl] Web Push requires ext-openssl');
        }
        $keys = Push::generateVapidKeys();
        $subscription = [
            'endpoint' => 'http://169.254.169.254/push/AAA',
            'keys' => [
                'p256dh' => Push::generateVapidKeys()['publicKey'],
                'auth' => 'AAAAAAAAAAAAAAAAAAAAAA',
            ],
        ];
        $push = new Push('mailto:ops@example.com', $keys['publicKey'], $keys['privateKey']);
        try {
            $push->send($subscription, ['title' => 'hi']);
            $this->fail('push to a private endpoint should be refused');
        } catch (PushError $e) {
            $this->assertStringContainsString(Ssrf::ALLOW_PRIVATE_ENV, $e->getMessage());
        }
    }
}
