<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Auth;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;
use Tina4\TestClient;

/**
 * Feature 29 - HTTP request model - shared cross-language contract (3.13.99).
 *
 * Four named cases, identical across Python/PHP/Ruby/Node
 * (plan/v3/fixtures/request_contract.json), each driven through the REAL
 * front controller (TestClient -> Router::dispatch) — no mocks, no
 * hand-invoked handlers.
 *
 *   route_param_not_shadowed_by_query  - REQ-PARAM-POLLUTION (security):
 *       params is route-only; a client ?id= can never shadow the route {id}.
 *       Already correct in PHP — locked in here under the shared fixture name.
 *   malformed_json_body_agreed_result  - REQ-BODY-DIVERGE: malformed JSON ->
 *       the raw string, in all four. Already correct in PHP.
 *   auth_middleware_sets_request_user  - the secure-by-default auth gate
 *       stashes the verified payload on request->user. Already correct in PHP.
 *   ip_honours_xff_only_from_trusted_proxy - DO NOT REGRESS: remoteIp is
 *       always the raw peer; ip honours X-Forwarded-For ONLY from a
 *       TINA4_TRUSTED_PROXIES peer (see tests/TrustedProxyTest.php for the
 *       deeper suite — this locks the existing algorithm, doesn't change it).
 */
class RequestContractTest extends TestCase
{
    protected function setUp(): void
    {
        Router::clear();
        putenv('TINA4_SECRET=request-contract-secret');
        $_ENV['TINA4_SECRET'] = 'request-contract-secret';

        Router::get('/__rq29/{id}', function (Request $request, Response $response) {
            return $response->json(['params' => $request->params, 'query' => $request->query]);
        });

        Router::post('/__rq29/body', function (Request $request, Response $response) {
            return $response->json(['body' => $request->body]);
        })->noAuth();

        Router::post('/__rq29/whoami', function (Request $request, Response $response) {
            return $response->json(['user' => $request->user]);
        });

        Router::get('/__rq29ip/probe', function (Request $request, Response $response) {
            return $response->json(['ip' => $request->ip, 'remoteIp' => $request->remoteIp]);
        });
    }

    protected function tearDown(): void
    {
        putenv('TINA4_SECRET');
        unset($_ENV['TINA4_SECRET']);
        putenv('TINA4_TRUSTED_PROXIES');
        unset($_ENV['TINA4_TRUSTED_PROXIES']);
    }

    public function testRouteParamNotShadowedByQuery(): void
    {
        // A route `/{id}` hit with `?id=other` -> params["id"] is the ROUTE
        // value; the client value is only ever in query. Also asserts an
        // UNRELATED query key (`extra`) never leaks into params.
        $client = new TestClient();
        $r = $client->get('/__rq29/1?id=other&extra=leak');
        $this->assertSame(200, $r->status);
        $body = $r->json();
        $this->assertSame('1', $body['params']['id']);
        $this->assertSame('other', $body['query']['id']);
        $this->assertArrayNotHasKey('extra', $body['params']);
        $this->assertSame('leak', $body['query']['extra']);
    }

    public function testMalformedJsonBodyAgreedResult(): void
    {
        $malformed = '{not valid json';
        $client = new TestClient();
        $r = $client->post('/__rq29/body', null, $malformed, ['Content-Type' => 'application/json']);
        $this->assertSame(200, $r->status);
        $this->assertSame($malformed, $r->json()['body']);
    }

    public function testAuthMiddlewareSetsRequestUser(): void
    {
        $token = Auth::getToken(['sub' => 'contract-user', 'role' => 'tester']);
        $client = new TestClient();
        $r = $client->post('/__rq29/whoami', null, null, ['Authorization' => "Bearer {$token}"]);
        $this->assertSame(200, $r->status);
        $user = $r->json()['user'];
        $this->assertNotNull($user);
        $this->assertSame('contract-user', $user['sub']);
        $this->assertSame('tester', $user['role']);
    }

    public function testIpHonoursXffOnlyFromTrustedProxy(): void
    {
        // TestClient hardcodes ip to 127.0.0.1 via Request::create()'s
        // testing-convenience default, so a controlled peer needs the raw
        // Request constructor (ip: null lets resolveIp() actually run) —
        // dispatched through the SAME real front controller
        // (Router::dispatch) TestClient itself calls.
        $trustedPeer = '203.0.113.9';
        $untrustedPeer = '198.51.100.7';
        $spoofed = '1.2.3.4';

        $dispatchProbe = function (string $peerIp, string $xff) {
            $request = new Request(
                method: 'GET',
                path: '/__rq29ip/probe',
                query: [],
                body: '',
                headers: ['x-forwarded-for' => $xff],
                ip: null,
                files: [],
                remoteIp: $peerIp,
            );
            return Router::dispatch($request, new Response());
        };

        // Trusted peer: X-Forwarded-For IS honoured.
        putenv("TINA4_TRUSTED_PROXIES={$trustedPeer}");
        $_ENV['TINA4_TRUSTED_PROXIES'] = $trustedPeer;
        $response = $dispatchProbe($trustedPeer, $spoofed);
        $payload = json_decode($response->getBody(), true);
        $this->assertSame($trustedPeer, $payload['remoteIp']);
        $this->assertSame($spoofed, $payload['ip']);

        // Untrusted peer: X-Forwarded-For is ignored — the raw peer wins.
        putenv("TINA4_TRUSTED_PROXIES={$trustedPeer}");
        $_ENV['TINA4_TRUSTED_PROXIES'] = $trustedPeer;
        $response = $dispatchProbe($untrustedPeer, $spoofed);
        $payload = json_decode($response->getBody(), true);
        $this->assertSame($untrustedPeer, $payload['remoteIp']);
        $this->assertSame($untrustedPeer, $payload['ip']);
    }
}
