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
 * The retry tests use a tiny ScriptedApi subclass that overrides the protected
 * `attempt()` network seam to replay a scripted sequence of result arrays
 * (mirroring how the Python suite patches `_open`). No real wire traffic, and a
 * tiny retryBackoff keeps the exponential sleeps negligible.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Api;

/**
 * Api subclass whose single-attempt seam returns scripted responses instead of
 * touching the network. Counts attempts so retry behaviour can be asserted.
 */
class ScriptedApi extends Api
{
    /** @var array<int, array> Queue of result arrays to return, one per attempt. */
    public array $scripted = [];
    public int $attempts = 0;

    public function __construct(array $scripted, int $maxRetries = 0, float $retryBackoff = 0.01)
    {
        parent::__construct('https://api.example.com', maxRetries: $maxRetries, retryBackoff: $retryBackoff);
        $this->scripted = $scripted;
    }

    protected function attempt(string $method = 'GET', string $path = '', mixed $body = null, string $contentType = 'application/json'): array
    {
        $this->attempts++;
        // Pop the next scripted response; if exhausted, reuse the last one
        // (defensive — every test queues enough entries for max attempts).
        $next = array_shift($this->scripted);
        if ($next === null) {
            throw new \RuntimeException('ScriptedApi ran out of scripted responses');
        }
        return $next;
    }
}

class ApiTest extends TestCase
{
    /** Build a standardized result array shaped like Api::attempt() returns. */
    private function makeResult(?int $httpCode, mixed $body = null, ?string $error = null): array
    {
        return [
            'http_code' => $httpCode,
            'body' => $body,
            'headers' => [],
            'error' => $error,
        ];
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

    // -- Retry / backoff -----------------------------------------------------

    public function testDefaultMakesSingleAttemptOn503(): void
    {
        // max_retries defaults to 0 → exactly ONE attempt even on a 503.
        $api = new ScriptedApi(
            [$this->makeResult(503, 'down', 'HTTP 503')],
            maxRetries: 0
        );
        $result = $api->get('/x');
        $this->assertSame(503, $result['http_code']);
        $this->assertSame(1, $api->attempts, 'NEGATIVE: no retry by default');
    }

    public function test503ThenSuccessRecoversInTwoAttempts(): void
    {
        $api = new ScriptedApi(
            [
                $this->makeResult(503, 'down', 'HTTP 503'),
                $this->makeResult(200, ['ok' => true]),
            ],
            maxRetries: 2
        );
        $result = $api->get('/x');
        $this->assertSame(200, $result['http_code'], 'POSITIVE: recovered after retry');
        $this->assertSame(['ok' => true], $result['body']);
        $this->assertSame(2, $api->attempts);
    }

    public function testTransportErrorThenSuccessRecovers(): void
    {
        // http_code null is a transport error → retryable.
        $api = new ScriptedApi(
            [
                $this->makeResult(null, null, 'Request failed: unable to connect'),
                $this->makeResult(200, 'ok'),
            ],
            maxRetries: 3
        );
        $result = $api->get('/x');
        $this->assertSame(200, $result['http_code']);
        $this->assertSame(2, $api->attempts);
    }

    public function testRetriesExhaustAndReturnLast503(): void
    {
        // maxRetries=2 → 3 attempts total, all 503 → returns the last 503.
        $api = new ScriptedApi(
            [
                $this->makeResult(503, 'down', 'HTTP 503'),
                $this->makeResult(503, 'down', 'HTTP 503'),
                $this->makeResult(503, 'down', 'HTTP 503'),
            ],
            maxRetries: 2
        );
        $result = $api->get('/x');
        $this->assertSame(503, $result['http_code']);
        $this->assertSame(3, $api->attempts);
    }

    public function test404IsNotRetried(): void
    {
        // A 4xx (other than 429) is NOT retryable — single attempt.
        $api = new ScriptedApi(
            [$this->makeResult(404, 'missing', 'HTTP 404')],
            maxRetries: 3
        );
        $result = $api->get('/x');
        $this->assertSame(404, $result['http_code']);
        $this->assertSame(1, $api->attempts, 'NEGATIVE: 4xx is not retried');
    }

    public function test429IsRetried(): void
    {
        // 429 (rate limit) IS in the retry set.
        $api = new ScriptedApi(
            [
                $this->makeResult(429, 'slow down', 'HTTP 429'),
                $this->makeResult(200, 'ok'),
            ],
            maxRetries: 1
        );
        $result = $api->get('/x');
        $this->assertSame(200, $result['http_code']);
        $this->assertSame(2, $api->attempts);
    }

    public function testSuccessfulResponseIsNotRetried(): void
    {
        // A 2xx returns immediately even when retries are enabled.
        $api = new ScriptedApi(
            [
                $this->makeResult(200, 'ok'),
                $this->makeResult(200, 'should-not-be-reached'),
            ],
            maxRetries: 3
        );
        $result = $api->get('/x');
        $this->assertSame(200, $result['http_code']);
        $this->assertSame('ok', $result['body']);
        $this->assertSame(1, $api->attempts);
    }
}
