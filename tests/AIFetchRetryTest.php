<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Locks in the 503-retry added to Tina4\AI::fetchBytes() (3.13.100) — mirrors
 * tina4-python's tests/test_ai_fetch_retry.py and the equivalent Ruby/Node
 * specs. `install_skills()`/`installSkills()` fetches ~30 files from
 * raw.githubusercontent.com, which 503s intermittently under load (a freshly
 * cut release tag is "cold" on GitHub's CDN until it warms) — a single
 * transient blip must not abort the whole install.
 *
 * NO MOCKS: a REAL HTTP server (tests/fixtures/ai_fetch_retry_server.php)
 * owns its own socket and answers a scripted sequence — no monkeypatched
 * curl/file_get_contents/sleep. fetchBytes is PRIVATE, so it is invoked via
 * Reflection (the same pattern AIInstallerTest uses for writeOrMerge)
 * rather than exposed just for testing.
 */

use PHPUnit\Framework\TestCase;
use Tina4\AI;

class AIFetchRetryTest extends TestCase
{
    private static ?TestServer $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = TestServer::startScript(__DIR__ . '/fixtures/ai_fetch_retry_server.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    private function fetchBytes(string $url): ?string
    {
        $ref = new ReflectionClass(AI::class);
        $method = $ref->getMethod('fetchBytes');
        return $method->invoke(null, $url);
    }

    /** How many requests the REAL server saw for a path — a live HTTP read, not an in-process counter. */
    private function hits(string $path): int
    {
        $body = $this->fetchBytes(self::$server->base() . '/hits?path=' . urlencode($path));
        $this->assertNotNull($body, 'the hit-count endpoint must answer');
        $decoded = json_decode((string)$body, true);
        return (int)$decoded['hits'];
    }

    /**
     * POSITIVE: two transient 503s, then a 200 — fetchBytes must ride
     * through both and hand back the real body (not null).
     */
    public function testFetchRetriesTransient503ThenReturnsBody(): void
    {
        $data = $this->fetchBytes(self::$server->base() . '/skill');

        $this->assertSame('skill body', $data);
        $this->assertSame(3, $this->hits('/skill'), 'proves it actually retried twice, not just once');
    }

    /**
     * NEGATIVE: a permanent 404 must return null on the FIRST attempt — no
     * retry, no backoff sleep. If the loop wrongly retried a 404 this would
     * take at least 1s (the first backoff step) and hit the server more than
     * once; the elapsed-time bound is what makes this "fails fast" rather
     * than just a status check.
     */
    public function testFetchFastFailsPersistent404WithoutRetrying(): void
    {
        $start = microtime(true);
        $data = $this->fetchBytes(self::$server->base() . '/missing');
        $elapsed = microtime(true) - $start;

        $this->assertNull($data);
        $this->assertSame(1, $this->hits('/missing'), 'NEGATIVE: no retry on a permanent 404');
        $this->assertLessThan(1.0, $elapsed, 'fast — well under even a single 1s backoff sleep');
    }
}
