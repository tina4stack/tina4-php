<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Middleware\RateLimiter;
use Tina4\Request;
use Tina4\Response;
use Tina4\TrustedProxy;

/**
 * Feature 11 (rate limiter) - the client key must not be attacker-controlled.
 *
 * ADR-0019. X-Forwarded-For is written by whoever sends it, so reading it
 * unconditionally let any client pick its own rate-limit bucket, and let it
 * pick SOMEONE ELSE'S. Case names match tina4-python/tests/test_trusted_proxy.py,
 * tina4-ruby/spec/trusted_proxy_spec.rb and tina4-nodejs/test/trustedProxy.test.ts.
 *
 * These build a REAL Request and drive a REAL RateLimiter. No doubles.
 */
class TrustedProxyTest extends TestCase
{
    /** The peer the fixture presents, so trust is controlled by listing it or not. */
    private const TEST_PEER = '127.0.0.1';

    private ?string $previousEnv = null;

    protected function setUp(): void
    {
        $this->previousEnv = $_ENV['TINA4_TRUSTED_PROXIES'] ?? null;
        $this->setTrusted(null);
    }

    protected function tearDown(): void
    {
        $this->setTrusted($this->previousEnv);
    }

    private function setTrusted(?string $value): void
    {
        if ($value === null) {
            unset($_ENV['TINA4_TRUSTED_PROXIES'], $_SERVER['TINA4_TRUSTED_PROXIES']);
            putenv('TINA4_TRUSTED_PROXIES');
        } else {
            $_ENV['TINA4_TRUSTED_PROXIES'] = $value;
            putenv("TINA4_TRUSTED_PROXIES={$value}");
        }
        TrustedProxy::reset();
    }

    private function clientIp(array $headers, string $peer = self::TEST_PEER): string
    {
        return (new Request(
            method: 'GET',
            path: '/trusted-proxy-probe',
            headers: $headers,
            remoteIp: $peer,
        ))->ip;
    }

    /** Six requests, each claiming to come from $forwardedFor($i). */
    private function statuses(callable $forwardedFor): array
    {
        $limiter = new RateLimiter(3, 60);
        $out = [];
        for ($i = 0; $i < 6; $i++) {
            $ip = $this->clientIp(['X-Forwarded-For' => $forwardedFor($i)]);
            $response = new Response();
            [, $response] = $limiter->apply(new Request(method: 'GET', path: '/p', ip: $ip), $response);
            $out[] = $response->getStatusCode();
        }
        return $out;
    }

    public function testRateLimitIgnoresForwardedForFromAnUntrustedPeer(): void
    {
        // No TINA4_TRUSTED_PROXIES: the header is noise, the peer is the client.
        // A rotating X-Forwarded-For must NOT buy extra requests.
        $statuses = $this->statuses(fn(int $i): string => "203.0.113.{$i}");
        $this->assertSame(
            [200, 200, 200, 429, 429, 429],
            $statuses,
            'rotating X-Forwarded-For bypassed the rate limiter - the client chose its own bucket'
        );
    }

    public function testRateLimitHonoursForwardedForFromATrustedProxy(): void
    {
        // The positive twin: once the peer IS a declared proxy, per-client
        // bucketing must still work, or the fix would just break real deployments.
        $this->setTrusted(self::TEST_PEER);
        $statuses = $this->statuses(fn(int $i): string => "203.0.113.{$i}");
        $this->assertSame(
            [200, 200, 200, 200, 200, 200],
            $statuses,
            'behind a declared trusted proxy, distinct clients must get distinct buckets'
        );
    }

    public function testRateLimitForgedForwardedForCannotStarveAnotherClient(): void
    {
        $victim = '198.51.100.7';
        $this->assertFalse(
            TrustedProxy::isTrusted($victim),
            'the victim address must not be trusted for this test to mean anything'
        );
        // The attacker's forged traffic lands in the PEER's bucket, never the
        // victim's, because the header is not consulted at all.
        $this->assertSame(self::TEST_PEER, $this->clientIp(['X-Forwarded-For' => $victim]));
    }

    public function testTrustedProxyMatchesAnExactAddress(): void
    {
        $this->setTrusted('192.168.1.5');
        $this->assertTrue(TrustedProxy::isTrusted('192.168.1.5'));
        $this->assertFalse(TrustedProxy::isTrusted('192.168.1.6'));
    }

    public function testTrustedProxyMatchesACidrRange(): void
    {
        $this->setTrusted('10.0.0.0/8');
        $this->assertTrue(TrustedProxy::isTrusted('10.4.5.6'));
        $this->assertFalse(TrustedProxy::isTrusted('11.4.5.6'));
    }

    public function testTrustedProxyMatchesAnIpv6AddressAndRange(): void
    {
        $this->setTrusted('::1, fd00::/8');
        $this->assertTrue(TrustedProxy::isTrusted('::1'));
        $this->assertTrue(TrustedProxy::isTrusted('fd12:3456::9'));
        $this->assertFalse(TrustedProxy::isTrusted('2001:db8::1'));
    }

    public function testTrustedProxyMatchesAnIpv4MappedIpv6Peer(): void
    {
        // Dual-stack listeners hand out ::ffff:10.0.0.1 routinely. If that did
        // not match 10.0.0.0/8 the operator's allow-list would silently miss.
        $this->setTrusted('10.0.0.0/8');
        $this->assertTrue(TrustedProxy::isTrusted('::ffff:10.0.0.1'));
    }

    public function testTrustedProxyIsEmptyByDefault(): void
    {
        $this->assertSame([], TrustedProxy::networks());
        $this->assertFalse(TrustedProxy::isTrusted('10.0.0.1'));
    }

    public function testTrustedProxyIgnoresAMalformedEntry(): void
    {
        // A typo must not take the whole allow-list down with it.
        $this->setTrusted('10.0.0.0/8, not-an-ip, ::1');
        $this->assertTrue(TrustedProxy::isTrusted('10.1.2.3'));
        $this->assertTrue(TrustedProxy::isTrusted('::1'));
        $this->assertFalse(TrustedProxy::isTrusted('192.168.0.1'));
    }

    public function testClientIpTakesTheRightmostUntrustedHop(): void
    {
        // A client can PREPEND to X-Forwarded-For; the proxy appends. So the
        // leftmost entry is attacker-controlled even behind a real proxy.
        $this->setTrusted(self::TEST_PEER);
        $this->assertSame('5.6.7.8', $this->clientIp(['X-Forwarded-For' => '1.2.3.4, 5.6.7.8']));
    }

    public function testClientIpSkipsHopsThatAreThemselvesTrustedProxies(): void
    {
        $this->setTrusted(self::TEST_PEER . ', 5.6.7.8');
        $this->assertSame('1.2.3.4', $this->clientIp(['X-Forwarded-For' => '1.2.3.4, 5.6.7.8']));
    }

    public function testClientIpIsThePeerWhenThePeerIsNotTrusted(): void
    {
        $this->assertSame(
            '198.51.100.1',
            $this->clientIp(['X-Forwarded-For' => '1.2.3.4'], '198.51.100.1')
        );
    }

    public function testClientIpFallsBackToXRealIpBehindATrustedProxy(): void
    {
        $this->setTrusted(self::TEST_PEER);
        $this->assertSame('9.9.9.9', $this->clientIp(['X-Real-IP' => '9.9.9.9']));
    }

    public function testClientIpIgnoresXRealIpFromAnUntrustedPeer(): void
    {
        $this->assertSame(
            '198.51.100.1',
            $this->clientIp(['X-Real-IP' => '9.9.9.9'], '198.51.100.1')
        );
    }
}
