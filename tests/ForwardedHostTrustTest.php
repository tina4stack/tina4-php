<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Medium security finding F6 — X-Forwarded-Host must only be honoured when the
 * raw socket peer is a trusted proxy (TINA4_TRUSTED_PROXIES, ADR-0019).
 *
 * An untrusted client can otherwise forge X-Forwarded-Host and control the
 * absolute request->url the app builds — the base for password-reset links,
 * cache keys and open-redirect targets. The existing trusted-proxy gate covered
 * X-Forwarded-For only; this pins the same rule for the host. Case names match
 * the sibling regressions in tina4-nodejs/test/forwardedHostTrust.test.ts,
 * tina4-python/tests/test_forwarded_host_trust.py and
 * tina4-ruby/spec/forwarded_host_trust_spec.rb.
 *
 * Real Request objects with a real socket peer (127.0.0.1). No mocks.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Request;
use Tina4\TrustedProxy;

class ForwardedHostTrustTest extends TestCase
{
    private const TEST_PEER = '127.0.0.1';

    protected function tearDown(): void
    {
        unset($_ENV['TINA4_TRUSTED_PROXIES']);
        putenv('TINA4_TRUSTED_PROXIES');
        TrustedProxy::reset();
    }

    private function setTrusted(?string $value): void
    {
        if ($value === null) {
            unset($_ENV['TINA4_TRUSTED_PROXIES']);
            putenv('TINA4_TRUSTED_PROXIES');
        } else {
            $_ENV['TINA4_TRUSTED_PROXIES'] = $value;
            putenv("TINA4_TRUSTED_PROXIES={$value}");
        }
        TrustedProxy::reset();
    }

    private function requestUrl(string $forwardedHost): string
    {
        return (new Request(
            method: 'GET',
            path: '/reset-link',
            headers: ['host' => '127.0.0.1', 'x-forwarded-host' => $forwardedHost],
            remoteIp: self::TEST_PEER,
        ))->url;
    }

    public function testForwardedHostIgnoredFromAnUntrustedPeer(): void
    {
        $this->setTrusted(null);
        $url = $this->requestUrl('evil.com');
        $this->assertStringNotContainsString(
            'evil.com',
            $url,
            'forged X-Forwarded-Host leaked into request->url'
        );
    }

    public function testForwardedHostHonouredFromATrustedProxy(): void
    {
        $this->setTrusted('127.0.0.1/8');
        $url = $this->requestUrl('app.example.com');
        $this->assertStringContainsString(
            'app.example.com',
            $url,
            'forwarded host not honoured behind a trusted proxy'
        );
    }
}
