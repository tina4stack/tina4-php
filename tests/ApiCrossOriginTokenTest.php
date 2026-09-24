<?php
/*
Copyright (c) 2026 Code Infinity
SPDX-License-Identifier: MPL-2.0
This Source Code Form is subject to the terms of the Mozilla Public
License, v. 2.0. If a copy of the MPL was not distributed with this
file, You can obtain one at https://mozilla.org/MPL/2.0/.
*/

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
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
    public function testFinalTargetCredentialsOnEveryHttpPath(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'tina4-origin-');
        try {
            foreach (['token', 'headers', 'jar'] as $credentials) {
                $api = new Api(self::$baseA,
                    authHeader: $credentials === 'token' ? 'Bearer synthetic-origin-token' : '',
                    headers: $credentials === 'headers' ? ['aUtHoRiZaTiOn' => 'Bearer synthetic-header', 'cOoKiE' => 'session=synthetic-header'] : [],
                    cookies: $credentials === 'jar');
                if ($credentials === 'jar') { $api->get('/set-cookie'); }
                foreach (['get', 'post', 'upload', 'download', 'stream'] as $method) {
                    foreach ([true, false] as $offOrigin) {
                        $path = $offOrigin ? self::$baseB . '/echo-headers' : '/echo-headers';
                        if ($method === 'download') {
                            $result = $api->download($path, $file);
                            $this->assertSame(200, $result['http_code']);
                            $body = json_decode(file_get_contents($file), true);
                        } elseif ($method === 'stream') {
                            $body = json_decode(implode('', iterator_to_array($api->streamBytes($path))), true);
                        } elseif ($method === 'upload') {
                            $body = $api->upload($path, fileBytes: 'payload', filename: 'sample.txt')['body'];
                        } else { $body = $api->$method($path)['body']; }
                        if ($offOrigin) {
                            $this->assertArrayNotHasKey('authorization', $body, $method . ' leaked auth');
                            $this->assertArrayNotHasKey('cookie', $body, $method . ' leaked cookies');
                        } else {
                            $this->assertArrayHasKey($credentials === 'jar' ? 'cookie' : 'authorization', $body);
                        }
                    }
                }
                $headers = ['aUtHoRiZaTiOn' => 'Bearer synthetic-override', 'cOoKiE' => 'session=synthetic-override'];
                $upload = $api->upload(self::$baseB . '/echo-headers', headers: $headers, fileBytes: 'payload')['body'];
                $stream = json_decode(implode('', iterator_to_array($api->streamBytes(self::$baseB . '/echo-headers', ['headers' => $headers]))), true);
                foreach ([$upload, $stream] as $body) {
                    $this->assertArrayNotHasKey('authorization', $body);
                    $this->assertArrayNotHasKey('cookie', $body);
                }
            }
        } finally { @unlink($file); }
    }

}
