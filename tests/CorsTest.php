<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Middleware\CorsMiddleware;

class CorsTest extends TestCase
{
    public function testDefaultWildcardOrigin(): void
    {
        $cors = new CorsMiddleware();

        $headers = $cors->getHeaders('http://example.com');

        $this->assertEquals('*', $headers['Access-Control-Allow-Origin']);
    }

    public function testDefaultAllowedMethods(): void
    {
        $cors = new CorsMiddleware();

        $headers = $cors->getHeaders();

        $this->assertEquals('GET,POST,PUT,PATCH,DELETE,OPTIONS', $headers['Access-Control-Allow-Methods']);
    }

    public function testDefaultAllowedHeaders(): void
    {
        $cors = new CorsMiddleware();

        $headers = $cors->getHeaders();

        $this->assertEquals('Content-Type,Authorization,X-Request-ID', $headers['Access-Control-Allow-Headers']);
    }

    public function testSpecificOriginsAllowed(): void
    {
        $cors = new CorsMiddleware(origins: 'http://localhost:3000,http://example.com');

        $headers = $cors->getHeaders('http://example.com');

        $this->assertEquals('http://example.com', $headers['Access-Control-Allow-Origin']);
    }

    public function testSpecificOriginsDisallowed(): void
    {
        $cors = new CorsMiddleware(origins: 'http://localhost:3000,http://example.com');

        $headers = $cors->getHeaders('http://evil.com');

        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $headers);
    }

    public function testPreflightDetection(): void
    {
        $cors = new CorsMiddleware();

        $this->assertTrue($cors->isPreflight('OPTIONS'));
        $this->assertTrue($cors->isPreflight('options'));
        $this->assertFalse($cors->isPreflight('GET'));
        $this->assertFalse($cors->isPreflight('POST'));
    }

    public function testHandleReturnsHeadersAndPreflightFlag(): void
    {
        $cors = new CorsMiddleware();

        $result = $cors->handle('OPTIONS', 'http://example.com');

        $this->assertTrue($result['preflight']);
        $this->assertArrayHasKey('Access-Control-Allow-Origin', $result['headers']);
    }

    public function testHandleNonPreflight(): void
    {
        $cors = new CorsMiddleware();

        $result = $cors->handle('GET', 'http://example.com');

        $this->assertFalse($result['preflight']);
        $this->assertArrayHasKey('Access-Control-Allow-Origin', $result['headers']);
    }

    public function testMaxAgeHeader(): void
    {
        $cors = new CorsMiddleware(maxAge: 3600);

        $headers = $cors->getHeaders();

        $this->assertEquals('3600', $headers['Access-Control-Max-Age']);
    }

    public function testVaryHeaderForSpecificOrigins(): void
    {
        $cors = new CorsMiddleware(origins: 'http://example.com');

        $headers = $cors->getHeaders('http://example.com');

        $this->assertArrayHasKey('Vary', $headers);
        $this->assertEquals('Origin', $headers['Vary']);
    }

    public function testNoVaryHeaderForWildcard(): void
    {
        $cors = new CorsMiddleware();

        $headers = $cors->getHeaders('http://example.com');

        $this->assertArrayNotHasKey('Vary', $headers);
    }

    public function testCustomMethods(): void
    {
        $cors = new CorsMiddleware(methods: 'GET,POST');

        $this->assertEquals('GET,POST', $cors->getAllowedMethods());
    }

    public function testCustomHeaders(): void
    {
        $cors = new CorsMiddleware(headers: 'X-Custom-Header');

        $this->assertEquals('X-Custom-Header', $cors->getAllowedHeaders());
    }

    public function testGetAllowedOrigins(): void
    {
        $cors = new CorsMiddleware(origins: 'http://localhost:3000');

        $this->assertEquals('http://localhost:3000', $cors->getAllowedOrigins());
    }

    public function testNullRequestOriginWithSpecificOrigins(): void
    {
        $cors = new CorsMiddleware(origins: 'http://example.com');

        $headers = $cors->getHeaders(null);

        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $headers);
    }

    // -- Credentials header ---------------------------------------------------

    public function testCredentialsHeaderForSpecificOrigin(): void
    {
        $cors = new CorsMiddleware(origins: 'http://app.com', credentials: true);

        $headers = $cors->getHeaders('http://app.com');

        $this->assertArrayHasKey('Access-Control-Allow-Credentials', $headers);
        $this->assertEquals('true', $headers['Access-Control-Allow-Credentials']);
    }

    public function testNoCredentialsHeaderForWildcard(): void
    {
        $cors = new CorsMiddleware(credentials: true);

        $headers = $cors->getHeaders('http://example.com');

        // Credentials must not be sent with wildcard origin
        $this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $headers);
    }

    // -- Multiple origins matching -------------------------------------------

    public function testMultipleOriginsMatch(): void
    {
        $cors = new CorsMiddleware(origins: 'https://a.com,https://b.com');

        $headers = $cors->getHeaders('https://b.com');
        $this->assertEquals('https://b.com', $headers['Access-Control-Allow-Origin']);

        $headers2 = $cors->getHeaders('https://c.com');
        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $headers2);
    }

    // -- Preflight case insensitive ------------------------------------------

    public function testPreflightCaseInsensitive(): void
    {
        $cors = new CorsMiddleware();
        $this->assertTrue($cors->isPreflight('OPTIONS'));
        $this->assertTrue($cors->isPreflight('Options'));
        $this->assertTrue($cors->isPreflight('options'));
    }

    // -- Default max age -----------------------------------------------------

    public function testDefaultMaxAge(): void
    {
        $cors = new CorsMiddleware();
        $headers = $cors->getHeaders();
        $this->assertArrayHasKey('Access-Control-Max-Age', $headers);
    }

    // -- Custom max age zero -------------------------------------------------

    public function testMaxAgeZero(): void
    {
        $cors = new CorsMiddleware(maxAge: 0);
        $headers = $cors->getHeaders();
        $this->assertEquals('0', $headers['Access-Control-Max-Age']);
    }

    // -- Default headers include X-Request-ID --------------------------------

    public function testDefaultHeadersIncludeXRequestId(): void
    {
        $cors = new CorsMiddleware();
        $headers = $cors->getHeaders();
        $this->assertStringContainsString('X-Request-ID', $headers['Access-Control-Allow-Headers']);
    }

    // -- Handle returns correct structure for preflight ----------------------

    public function testHandlePreflightStructure(): void
    {
        $cors = new CorsMiddleware();
        $result = $cors->handle('OPTIONS', 'http://example.com');

        $this->assertArrayHasKey('preflight', $result);
        $this->assertArrayHasKey('headers', $result);
        $this->assertTrue($result['preflight']);
        $this->assertArrayHasKey('Access-Control-Allow-Methods', $result['headers']);
        $this->assertArrayHasKey('Access-Control-Allow-Headers', $result['headers']);
        $this->assertArrayHasKey('Access-Control-Max-Age', $result['headers']);
    }

    // -- Handle non-preflight includes origin --------------------------------

    public function testHandleNonPreflightIncludesOrigin(): void
    {
        $cors = new CorsMiddleware();
        $result = $cors->handle('POST', 'http://example.com');

        $this->assertFalse($result['preflight']);
        $this->assertEquals('*', $result['headers']['Access-Control-Allow-Origin']);
    }

    // -- Wildcard origin with any request origin -----------------------------

    public function testWildcardOriginWithAnyRequestOrigin(): void
    {
        $cors = new CorsMiddleware();
        $this->assertEquals('*', $cors->getHeaders('https://anything.example.com')['Access-Control-Allow-Origin']);
        $this->assertEquals('*', $cors->getHeaders('http://localhost:3000')['Access-Control-Allow-Origin']);
    }

    // -- beforeCors() static path origin matching (issue #105) ---------------

    /**
     * beforeCors() must echo back a single matching origin, not a comma-separated list.
     */
    public function testBeforeCorsEchoesMatchedOrigin(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://app.example.com';
        putenv('TINA4_CORS_ORIGINS=https://app.example.com,https://other.example.com');

        $request = $this->createRequestStub('GET');
        $response = new \Tina4\Response();

        CorsMiddleware::beforeCors($request, $response);

        $headers = $response->getHeaders();
        $this->assertEquals('https://app.example.com', $headers['Access-Control-Allow-Origin'] ?? null);
        $this->assertEquals('Origin', $headers['Vary'] ?? null);

        putenv('TINA4_CORS_ORIGINS');
        unset($_SERVER['HTTP_ORIGIN']);
    }

    /**
     * beforeCors() must not set Access-Control-Allow-Origin for an unrecognised origin.
     */
    public function testBeforeCorsBlocksUnknownOrigin(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.com';
        putenv('TINA4_CORS_ORIGINS=https://app.example.com');

        $request = $this->createRequestStub('GET');
        $response = new \Tina4\Response();

        CorsMiddleware::beforeCors($request, $response);

        $headers = $response->getHeaders();
        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $headers);

        putenv('TINA4_CORS_ORIGINS');
        unset($_SERVER['HTTP_ORIGIN']);
    }

    /**
     * beforeCors() with wildcard config must always set Access-Control-Allow-Origin: *.
     */
    public function testBeforeCorsWildcardSetsAsterisk(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://anyone.example.com';
        putenv('TINA4_CORS_ORIGINS=*');

        $request = $this->createRequestStub('GET');
        $response = new \Tina4\Response();

        CorsMiddleware::beforeCors($request, $response);

        $headers = $response->getHeaders();
        $this->assertEquals('*', $headers['Access-Control-Allow-Origin'] ?? null);
        $this->assertArrayNotHasKey('Vary', $headers);

        putenv('TINA4_CORS_ORIGINS');
        unset($_SERVER['HTTP_ORIGIN']);
    }

    /**
     * beforeCors() must never dump the raw comma-separated list into the header.
     */
    public function testBeforeCorsNeverSetsCommaList(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://app.example.com';
        putenv('TINA4_CORS_ORIGINS=https://app.example.com,https://other.example.com');

        $request = $this->createRequestStub('GET');
        $response = new \Tina4\Response();

        CorsMiddleware::beforeCors($request, $response);

        $headers = $response->getHeaders();
        $origin = $headers['Access-Control-Allow-Origin'] ?? '';
        $this->assertStringNotContainsString(',', $origin, 'Access-Control-Allow-Origin must be a single origin, not a list');

        putenv('TINA4_CORS_ORIGINS');
        unset($_SERVER['HTTP_ORIGIN']);
    }

    // -- Helper --------------------------------------------------------------

    private function createRequestStub(string $method): \Tina4\Request
    {
        return new \Tina4\Request(method: $method);
    }
}
