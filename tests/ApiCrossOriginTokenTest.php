<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Medium security finding F5 — the configured Authorization token must never be
 * sent to a host other than the client's configured base origin.
 *
 * A path that is itself an absolute off-origin URL (e.g. get("http://evil/x"))
 * previously reused the base client's Authorization header, leaking a bearer
 * token to an attacker-chosen host. The cross-origin strip already existed for
 * REDIRECT following (see ApiTransferTest); this pins it for the initial request
 * target too. Only same-origin requests carry the token. Case names match the
 * sibling regressions in tina4-nodejs/test/apiCrossOriginToken.test.ts,
 * tina4-python/tests/test_api_cross_origin_token.py and
 * tina4-ruby/spec/api_cross_origin_token_spec.rb.
 *
 * NO MOCKS — two genuine PHP built-in servers on two free ports (= two origins),
 * every case a real socket round-trip. The router's /echo-headers endpoint
 * returns the headers the origin actually received.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Api;

class ApiCrossOriginTokenTest extends TestCase
{
    private static TestServer $serverA;
    private static TestServer $serverB;
    private static string $baseA;
    private static string $baseB;

    public static function setUpBeforeClass(): void
    {
        $router = __DIR__ . '/fixtures/api_transfer_server.php';
        self::$serverA = TestServer::start($router);
        self::$serverB = TestServer::start($router);
        self::$baseA = self::$serverA->base();
        self::$baseB = self::$serverB->base();
    }

    public static function tearDownAfterClass(): void
    {
        self::$serverA->stop();
        self::$serverB->stop();
    }

    public function testAbsoluteOffOriginPathDoesNotLeakTheToken(): void
    {
        // Base is origin A with a bearer token; the path is an absolute URL on
        // origin B. The token must NOT be handed to origin B.
        $api = new Api(self::$baseA, 'Bearer SECRETTOKEN');
        $result = $api->get(self::$baseB . '/echo-headers');

        $this->assertSame(200, $result['http_code']);
        $received = $result['body'];
        $this->assertArrayNotHasKey(
            'authorization',
            $received,
            'bearer token leaked to an off-origin absolute URL'
        );
    }

    public function testSameOriginRequestStillCarriesTheToken(): void
    {
        $api = new Api(self::$baseA, 'Bearer SECRETTOKEN');
        $result = $api->get('/echo-headers');

        $this->assertSame(200, $result['http_code']);
        $received = $result['body'];
        $this->assertSame(
            'Bearer SECRETTOKEN',
            $received['authorization'] ?? null,
            'same-origin request lost its Authorization header'
        );
    }
}
