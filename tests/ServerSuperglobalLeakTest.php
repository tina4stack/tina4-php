<?php

/**
 * Regression tests for the $_SERVER HTTP_* leak across requests under the
 * built-in stream_socket_server (Tina4\Server).
 *
 * Bug: $_SERVER is process-global across the long-running socket server.
 * Prior to 3.11.17, populateSuperglobals only ADDED HTTP_* keys for the
 * current request — it never removed keys from the previous request.
 * Result: an unauthenticated request following an authenticated one would
 * see the previous user's bearer token in $_SERVER['HTTP_AUTHORIZATION'].
 *
 * Reported by: 24now / 24call-agent.
 * Fixed in: 3.11.17 (with v2 backport).
 */

use PHPUnit\Framework\TestCase;
use Tina4\Server;

class ServerSuperglobalLeakTest extends TestCase
{
    protected function setUp(): void
    {
        // Snapshot — we restore in tearDown so we don't bleed state into other tests
        $_SERVER = [];
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
    }

    private function populate(array $headers, string $method = 'GET', $body = null, array $query = []): void
    {
        Server::populateSuperglobals(
            method: $method,
            rawPath: '/path',
            queryString: '',
            queryParams: $query,
            parsedBody: $body,
            headers: $headers,
            host: 'localhost',
            port: 7145,
        );
    }

    public function testAuthorizationHeaderDoesNotLeakAcrossRequests(): void
    {
        // Request 1 — authenticated
        $this->populate(['authorization' => 'Bearer leaked-AAA', 'host' => 'localhost:7145']);
        $this->assertSame('Bearer leaked-AAA', $_SERVER['HTTP_AUTHORIZATION']);

        // Request 2 — anonymous, no Authorization header sent
        $this->populate(['host' => 'localhost:7145']);
        $this->assertArrayNotHasKey(
            'HTTP_AUTHORIZATION',
            $_SERVER,
            'Authorization from request 1 leaked into request 2 — security regression'
        );
    }

    public function testCookieHeaderDoesNotLeakAcrossRequests(): void
    {
        $this->populate(['cookie' => 'session=abc123', 'host' => 'localhost:7145']);
        $this->assertSame('session=abc123', $_SERVER['HTTP_COOKIE']);

        $this->populate(['host' => 'localhost:7145']);
        $this->assertArrayNotHasKey('HTTP_COOKIE', $_SERVER);
    }

    public function testIfNoneMatchHeaderDoesNotLeak(): void
    {
        $this->populate(['if-none-match' => '"etag-v1"', 'host' => 'localhost:7145']);
        $this->assertSame('"etag-v1"', $_SERVER['HTTP_IF_NONE_MATCH']);

        $this->populate(['host' => 'localhost:7145']);
        $this->assertArrayNotHasKey('HTTP_IF_NONE_MATCH', $_SERVER);
    }

    public function testCustomHeadersDoNotLeak(): void
    {
        $this->populate([
            'x-api-key'        => 'sk-secret',
            'x-tenant'         => 'acme-corp',
            'x-forwarded-for'  => '203.0.113.42',
            'host'             => 'localhost:7145',
        ]);
        $this->assertSame('sk-secret',     $_SERVER['HTTP_X_API_KEY']);
        $this->assertSame('acme-corp',     $_SERVER['HTTP_X_TENANT']);
        $this->assertSame('203.0.113.42',  $_SERVER['HTTP_X_FORWARDED_FOR']);

        $this->populate(['host' => 'localhost:7145']);
        $this->assertArrayNotHasKey('HTTP_X_API_KEY', $_SERVER);
        $this->assertArrayNotHasKey('HTTP_X_TENANT', $_SERVER);
        $this->assertArrayNotHasKey('HTTP_X_FORWARDED_FOR', $_SERVER);
    }

    public function testNonHttpServerKeysAreRetained(): void
    {
        // First request seeds CLI-style state
        $_SERVER['SCRIPT_NAME'] = 'index.php';
        $_SERVER['DOCUMENT_ROOT'] = '/app';

        $this->populate(['authorization' => 'Bearer X', 'host' => 'localhost:7145']);

        // Non-HTTP_ keys are NOT wiped — we only target HTTP_*
        $this->assertSame('index.php', $_SERVER['SCRIPT_NAME']);
        $this->assertSame('/app',      $_SERVER['DOCUMENT_ROOT']);
        // And the new request's REQUEST_METHOD / SERVER_NAME ARE set
        $this->assertSame('GET',       $_SERVER['REQUEST_METHOD']);
        $this->assertSame('localhost', $_SERVER['SERVER_NAME']);
    }

    public function testCurrentRequestHeadersOverwritePrevious(): void
    {
        // Both requests send Authorization but with DIFFERENT tokens —
        // request 2's token must win, not be merged with or appended to request 1's.
        $this->populate(['authorization' => 'Bearer user-A', 'host' => 'localhost:7145']);
        $this->assertSame('Bearer user-A', $_SERVER['HTTP_AUTHORIZATION']);

        $this->populate(['authorization' => 'Bearer user-B', 'host' => 'localhost:7145']);
        $this->assertSame('Bearer user-B', $_SERVER['HTTP_AUTHORIZATION']);
    }

    public function testFilesSuperglobalIsResetPerRequest(): void
    {
        // Simulate stale $_FILES (e.g. from a previous SAPI cycle or test pollution)
        $_FILES = [
            'avatar' => ['name' => 'leaked.png', 'tmp_name' => '/tmp/leaked', 'size' => 100],
        ];

        $this->populate(['host' => 'localhost:7145']);

        $this->assertSame([], $_FILES, '$_FILES must be reset on every request');
    }

    public function testGetAndPostFullyReassignedNotMerged(): void
    {
        $this->populate(
            ['host' => 'localhost:7145'],
            method: 'POST',
            body:   ['name' => 'Alice', 'role' => 'admin'],
            query:  ['debug' => '1'],
        );
        $this->assertSame(['debug' => '1'], $_GET);
        $this->assertSame(['name' => 'Alice', 'role' => 'admin'], $_POST);

        // Second request — different shape; verify no fields from prior request remain
        $this->populate(
            ['host' => 'localhost:7145'],
            method: 'POST',
            body:   ['email' => 'bob@example.com'],
            query:  [],
        );
        $this->assertSame([], $_GET);
        $this->assertSame(['email' => 'bob@example.com'], $_POST);
        $this->assertArrayNotHasKey('name', $_POST);
        $this->assertArrayNotHasKey('role', $_POST);
    }

    public function testCookieSuperglobalIsResetWhenHeaderAbsent(): void
    {
        $this->populate(['cookie' => 'a=1; b=2', 'host' => 'localhost:7145']);
        $this->assertSame(['a' => '1', 'b' => '2'], $_COOKIE);

        $this->populate(['host' => 'localhost:7145']);
        $this->assertSame([], $_COOKIE);
    }

    public function testEmptyHeaderKeysAreIgnored(): void
    {
        // Defensive — malformed headers shouldn't blow up populateSuperglobals
        $this->populate([
            ''       => 'should-be-ignored',
            '_path'  => '/internal',
            '_method' => 'GET',
            'host'   => 'localhost:7145',
        ]);
        $this->assertArrayNotHasKey('HTTP_', $_SERVER);
        $this->assertArrayNotHasKey('HTTP__PATH', $_SERVER);
        $this->assertArrayNotHasKey('HTTP__METHOD', $_SERVER);
    }
}
