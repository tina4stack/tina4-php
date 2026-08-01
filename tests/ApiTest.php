<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Tests for Tina4\Api — the zero-dependency HTTP client.
 *
 * Mirrors the spirit of the Python master's tests/test_api.py: constructor
 * surface (base-url strip, timeout, auth header, bearer/basic/headers kwargs),
 * the auth setters, URL/path building, and — the new feature — opt-in
 * retry/backoff.
 *
 * NO MOCKS. The retry tests used to subclass Api and override the protected
 * `attempt()` seam to replay canned result arrays; they now drive a REAL
 * scripted HTTP server (tests/fixtures/api_retry_server.php) over REAL sockets.
 * The server owns its own listener so it can also ABORT a connection with zero
 * bytes, which is how the transport-failure retry branch is reached for real
 * rather than by handing the client a `http_code => null` array. Attempt counts
 * come from the SERVER's own hit counters, so they measure requests that
 * actually crossed the wire. A tiny retryBackoff keeps the exponential sleeps
 * negligible.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Api;

class ApiTest extends TestCase
{
    /** The one real scripted server shared by every retry case in this class. */
    private static ?TestServer $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = TestServer::startScript(__DIR__ . '/fixtures/api_retry_server.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    /**
     * An Api pointed at the real server, plus the unique scenario key its
     * requests will be counted under.
     *
     * @return array{0: Api, 1: string, 2: string} [api, path, key]
     */
    private function scenario(string $behaviour, int $maxRetries): array
    {
        $key = $behaviour . '-' . bin2hex(random_bytes(6));
        $api = new Api(
            self::$server->base(),
            maxRetries: $maxRetries,
            retryBackoff: 0.01
        );

        return [$api, "/scripted?b={$behaviour}&k={$key}", $key];
    }

    /**
     * How many requests the REAL server saw for a scenario key. This is a live
     * HTTP read against the same server, not a counter kept inside the client.
     */
    private function serverHits(string $key): int
    {
        $probe = new Api(self::$server->base());
        $result = $probe->get('/hits', ['k' => $key]);
        $this->assertSame(200, $result['http_code'], 'the hit-count endpoint must answer');

        return (int)$result['body']['hits'];
    }

    /** Read a private/protected property for assertion. */
    private function prop(object $obj, string $name): mixed
    {
        $ref = new \ReflectionProperty(Api::class, $name);
        return $ref->getValue($obj);
    }

    // -- Constructor ---------------------------------------------------------

    public function testDefaultInstance(): void
    {
        $api = new Api();
        $this->assertSame('', $this->prop($api, 'baseUrl'));
        $this->assertSame('', $this->prop($api, 'authHeader'));
        $this->assertSame(30, $this->prop($api, 'timeout'));
    }

    public function testBaseUrlStripsTrailingSlash(): void
    {
        $api = new Api('https://api.example.com/');
        $this->assertSame('https://api.example.com', $this->prop($api, 'baseUrl'));
    }

    public function testBaseUrlStripsMultipleTrailingSlashes(): void
    {
        $api = new Api('https://api.example.com///');
        $this->assertSame('https://api.example.com', $this->prop($api, 'baseUrl'));
    }

    public function testCustomTimeout(): void
    {
        $api = new Api('https://api.example.com', timeout: 60);
        $this->assertSame(60, $this->prop($api, 'timeout'));
    }

    public function testAuthHeaderPassedIn(): void
    {
        $api = new Api('https://api.example.com', 'Bearer abc123');
        $this->assertSame('Bearer abc123', $this->prop($api, 'authHeader'));
    }

    public function testBearerTokenKwarg(): void
    {
        $api = new Api('https://api.example.com', bearerToken: 'sk-abc');
        $this->assertSame('Bearer sk-abc', $this->prop($api, 'authHeader'));
    }

    public function testBasicAuthKwargs(): void
    {
        $api = new Api('https://api.example.com', username: 'user', password: 'pass');
        $expected = 'Basic ' . base64_encode('user:pass');
        $this->assertSame($expected, $this->prop($api, 'authHeader'));
    }

    public function testBearerKwargWinsOverBasic(): void
    {
        $api = new Api('https://api.example.com', bearerToken: 'tok', username: 'u', password: 'p');
        $this->assertSame('Bearer tok', $this->prop($api, 'authHeader'));
    }

    public function testHeadersKwarg(): void
    {
        $api = new Api('https://api.example.com', headers: ['X-Tenant' => 'acme']);
        $this->assertSame(['X-Tenant' => 'acme'], $this->prop($api, 'headers'));
    }

    public function testVerifySslFalseEqualsIgnoreSsl(): void
    {
        $api = new Api('https://self-signed.local', verifySSL: false);
        $this->assertTrue($this->prop($api, 'ignoreSSL'));
    }

    public function testIgnoreSslDefaultsFalse(): void
    {
        $api = new Api('https://api.example.com');
        $this->assertFalse($this->prop($api, 'ignoreSSL'));
    }

    public function testRetryDefaultsOff(): void
    {
        $api = new Api('https://api.example.com');
        $this->assertSame(0, $this->prop($api, 'maxRetries'));
        $this->assertSame(0.5, $this->prop($api, 'retryBackoff'));
    }

    public function testRetryKwargsStored(): void
    {
        $api = new Api('https://api.example.com', maxRetries: 3, retryBackoff: 0.25);
        $this->assertSame(3, $this->prop($api, 'maxRetries'));
        $this->assertSame(0.25, $this->prop($api, 'retryBackoff'));
    }

    public function testNegativeMaxRetriesClampedToZero(): void
    {
        $api = new Api('https://api.example.com', maxRetries: -5);
        $this->assertSame(0, $this->prop($api, 'maxRetries'));
    }

    // -- Auth setters --------------------------------------------------------

    public function testSetBasicAuth(): void
    {
        $api = new Api();
        $api->setBasicAuth('user', 'pass');
        $expected = 'Basic ' . base64_encode('user:pass');
        $this->assertSame($expected, $this->prop($api, 'authHeader'));
    }

    public function testSetBearerToken(): void
    {
        $api = new Api();
        $api->setBearerToken('mytoken');
        $this->assertSame('Bearer mytoken', $this->prop($api, 'authHeader'));
    }

    public function testSetBearerOverwritesBasic(): void
    {
        $api = new Api();
        $api->setBasicAuth('user', 'pass');
        $api->setBearerToken('tok');
        $this->assertSame('Bearer tok', $this->prop($api, 'authHeader'));
    }

    public function testAddHeaders(): void
    {
        $api = new Api();
        $api->addHeaders(['X-Custom' => 'value1']);
        $this->assertSame(['X-Custom' => 'value1'], $this->prop($api, 'headers'));
    }

    public function testAddHeadersMerges(): void
    {
        $api = new Api();
        $api->addHeaders(['X-One' => '1']);
        $api->addHeaders(['X-Two' => '2']);
        $headers = $this->prop($api, 'headers');
        $this->assertSame('1', $headers['X-One']);
        $this->assertSame('2', $headers['X-Two']);
    }

    public function testAddHeadersOverwritesExisting(): void
    {
        $api = new Api();
        $api->addHeaders(['X-Key' => 'old']);
        $api->addHeaders(['X-Key' => 'new']);
        $headers = $this->prop($api, 'headers');
        $this->assertSame('new', $headers['X-Key']);
    }

    // -- URL / path building -------------------------------------------------

    private function buildUrl(Api $api, string $path): string
    {
        $ref = new \ReflectionMethod(Api::class, 'buildUrl');
        return $ref->invoke($api, $path);
    }

    public function testUrlWithPath(): void
    {
        $api = new Api('https://api.example.com');
        $this->assertSame('https://api.example.com/users', $this->buildUrl($api, '/users'));
    }

    public function testUrlStripsLeadingSlash(): void
    {
        $api = new Api('https://api.example.com');
        $this->assertSame('https://api.example.com/users', $this->buildUrl($api, 'users'));
    }

    public function testUrlEmptyPathReturnsBase(): void
    {
        $api = new Api('https://api.example.com');
        $this->assertSame('https://api.example.com', $this->buildUrl($api, ''));
    }

    public function testUrlAbsoluteUrlPassthrough(): void
    {
        $api = new Api('https://api.example.com');
        $this->assertSame('https://other.com/path', $this->buildUrl($api, 'https://other.com/path'));
    }

    public function testUrlHttpPassthrough(): void
    {
        $api = new Api('https://api.example.com');
        $this->assertSame('http://other.com/data', $this->buildUrl($api, 'http://other.com/data'));
    }

    // -- Retry / backoff (real sockets, real server) -------------------------

    public function testDefaultMakesSingleAttemptOn503(): void
    {
        // maxRetries defaults to 0 → exactly ONE request reaches the server
        // even though the 503 it answers with IS in the retryable set.
        [$api, $path, $key] = $this->scenario('always-503', maxRetries: 0);

        $result = $api->get($path);

        $this->assertSame(503, $result['http_code']);
        $this->assertSame('HTTP 503', $result['error']);
        $this->assertSame(1, $this->serverHits($key), 'NEGATIVE: no retry by default');
    }

    public function test503ThenSuccessRecoversInTwoAttempts(): void
    {
        [$api, $path, $key] = $this->scenario('down-then-ok', maxRetries: 2);

        $result = $api->get($path);

        $this->assertSame(200, $result['http_code'], 'POSITIVE: recovered after retry');
        $this->assertTrue($result['body']['ok']);
        $this->assertSame(2, $result['body']['hit'], 'the SECOND request is the one that succeeded');
        $this->assertSame(2, $this->serverHits($key));
    }

    public function testTransportErrorThenSuccessRecovers(): void
    {
        // A REAL transport failure: the server accepts the first connection and
        // closes it with zero bytes, so fopen() fails and http_code is null —
        // the retryable "no response at all" branch.
        [$api, $path, $key] = $this->scenario('abort-then-ok', maxRetries: 3);

        $result = $api->get($path);

        $this->assertSame(200, $result['http_code']);
        $this->assertSame(2, $result['body']['hit']);
        $this->assertSame(2, $this->serverHits($key));
    }

    public function testTransportErrorIsNotRetriedWhenRetriesAreOff(): void
    {
        // NEGATIVE control for the case above: the same real abort, with
        // retries off, surfaces as a single failed attempt.
        [$api, $path, $key] = $this->scenario('abort-then-ok', maxRetries: 0);

        $result = $api->get($path);

        $this->assertNull($result['http_code'], 'a real aborted connection has no status');
        $this->assertNull($result['body']);
        $this->assertStringContainsString('unable to connect', (string)$result['error']);
        $this->assertSame(1, $this->serverHits($key), 'NEGATIVE: no retry by default');
    }

    public function testRetriesExhaustAndReturnLast503(): void
    {
        // maxRetries=2 → 3 requests on the wire, all 503 → returns the last 503.
        [$api, $path, $key] = $this->scenario('always-503', maxRetries: 2);

        $result = $api->get($path);

        $this->assertSame(503, $result['http_code']);
        $this->assertSame(3, $result['body']['hit']);
        $this->assertSame(3, $this->serverHits($key));
    }

    public function test404IsNotRetried(): void
    {
        // A 4xx (other than 429) is NOT retryable — single request.
        [$api, $path, $key] = $this->scenario('always-404', maxRetries: 3);

        $result = $api->get($path);

        $this->assertSame(404, $result['http_code']);
        $this->assertSame(1, $this->serverHits($key), 'NEGATIVE: 4xx is not retried');
    }

    public function test429IsRetried(): void
    {
        // 429 (rate limit) IS in the retry set.
        [$api, $path, $key] = $this->scenario('throttle-then-ok', maxRetries: 1);

        $result = $api->get($path);

        $this->assertSame(200, $result['http_code']);
        $this->assertSame(2, $this->serverHits($key));
    }

    public function testSuccessfulResponseIsNotRetried(): void
    {
        // A 2xx returns immediately even when retries are enabled.
        [$api, $path, $key] = $this->scenario('always-200', maxRetries: 3);

        $result = $api->get($path);

        $this->assertSame(200, $result['http_code']);
        $this->assertTrue($result['body']['ok']);
        $this->assertSame(1, $result['body']['hit']);
        $this->assertSame(1, $this->serverHits($key));
    }
}
