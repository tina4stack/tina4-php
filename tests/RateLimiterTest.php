<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Middleware\RateLimiter;

class RateLimiterTest extends TestCase
{
    public function testFirstRequestAllowed(): void
    {
        $limiter = new RateLimiter(limit: 5, window: 60);

        $result = $limiter->check('127.0.0.1');

        $this->assertTrue($result['allowed']);
        $this->assertEquals(200, $result['status']);
    }

    public function testRateLimitHeaders(): void
    {
        $limiter = new RateLimiter(limit: 10, window: 60);

        $result = $limiter->check('127.0.0.1');

        $this->assertArrayHasKey('X-RateLimit-Limit', $result['headers']);
        $this->assertArrayHasKey('X-RateLimit-Remaining', $result['headers']);
        $this->assertEquals('10', $result['headers']['X-RateLimit-Limit']);
        $this->assertEquals('9', $result['headers']['X-RateLimit-Remaining']);
    }

    public function testRateLimitExceeded(): void
    {
        $limiter = new RateLimiter(limit: 3, window: 60);

        // Make 3 requests (should all be allowed)
        $limiter->check('127.0.0.1');
        $limiter->check('127.0.0.1');
        $limiter->check('127.0.0.1');

        // 4th request should be denied
        $result = $limiter->check('127.0.0.1');

        $this->assertFalse($result['allowed']);
        $this->assertEquals(429, $result['status']);
        $this->assertEquals('0', $result['headers']['X-RateLimit-Remaining']);
        $this->assertArrayHasKey('Retry-After', $result['headers']);
    }

    public function testRetryAfterHeader(): void
    {
        $limiter = new RateLimiter(limit: 1, window: 30);

        $limiter->check('127.0.0.1');
        $result = $limiter->check('127.0.0.1');

        $this->assertFalse($result['allowed']);
        $retryAfter = (int)$result['headers']['Retry-After'];
        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(30, $retryAfter);
    }

    public function testDifferentIpsTrackedSeparately(): void
    {
        $limiter = new RateLimiter(limit: 2, window: 60);

        // Fill up IP1
        $limiter->check('10.0.0.1');
        $limiter->check('10.0.0.1');
        $result1 = $limiter->check('10.0.0.1');

        // IP2 should still be allowed
        $result2 = $limiter->check('10.0.0.2');

        $this->assertFalse($result1['allowed']);
        $this->assertTrue($result2['allowed']);
    }

    public function testRemainingDecrementsCorrectly(): void
    {
        $limiter = new RateLimiter(limit: 5, window: 60);

        $r1 = $limiter->check('127.0.0.1');
        $r2 = $limiter->check('127.0.0.1');
        $r3 = $limiter->check('127.0.0.1');

        $this->assertEquals('4', $r1['headers']['X-RateLimit-Remaining']);
        $this->assertEquals('3', $r2['headers']['X-RateLimit-Remaining']);
        $this->assertEquals('2', $r3['headers']['X-RateLimit-Remaining']);
    }

    public function testGetLimit(): void
    {
        $limiter = new RateLimiter(limit: 100, window: 30);

        $this->assertEquals(100, $limiter->getLimit());
    }

    public function testGetWindow(): void
    {
        $limiter = new RateLimiter(limit: 100, window: 30);

        $this->assertEquals(30, $limiter->getWindow());
    }

    public function testGetRequestCount(): void
    {
        $limiter = new RateLimiter(limit: 10, window: 60);

        $this->assertEquals(0, $limiter->getRequestCount('127.0.0.1'));

        $limiter->check('127.0.0.1');
        $limiter->check('127.0.0.1');

        $this->assertEquals(2, $limiter->getRequestCount('127.0.0.1'));
    }

    public function testResetClearsAllData(): void
    {
        $limiter = new RateLimiter(limit: 10, window: 60);

        $limiter->check('127.0.0.1');
        $limiter->check('127.0.0.1');
        $limiter->reset();

        $this->assertEquals(0, $limiter->getRequestCount('127.0.0.1'));
    }

    public function testCleanupRemovesExpiredEntries(): void
    {
        $limiter = new RateLimiter(limit: 10, window: 60);

        $limiter->check('127.0.0.1');
        $limiter->cleanup();

        // Should still have the entry since it's within the window
        $this->assertEquals(1, $limiter->getRequestCount('127.0.0.1'));
    }
}
