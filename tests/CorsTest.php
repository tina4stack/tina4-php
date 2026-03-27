<?php

/**
 * Tina4 - This is not a 4ramework.
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
}
