<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Auth-token rules: the cross-framework contract (ADR-0079).
 *
 * The answer key is tina4-documentation/plan/v3/fixtures/auth_token_contract.json.
 * Each test name is a `case` in that fixture, spelled in PHP's testPascalCase; the
 * SAME cases run in:
 *
 *   tina4-python/tests/test_auth_token_contract.py   (reference)
 *   tina4-ruby/spec/auth_token_contract_spec.rb
 *   tina4-nodejs/test/authTokenContract.test.ts
 *
 * Real Router::dispatch, real Frond form tokens, real HMAC, real file sessions, a
 * real PSR-7 request (nyholm/psr7) and real child PHP processes for the boot
 * checks. No mocks.
 */

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Tina4\App;
use Tina4\Auth;
use Tina4\Frond;
use Tina4\McpServer;
use Tina4\Middleware\CsrfMiddleware;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;
use Tina4\WebSocket;

class AuthTokenContractTest extends TestCase
{
    private const SECRET = 'auth-token-contract-secret-0123456789abcdef';

    private string $sessionPath;

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    private const ENV_KEYS = [
        'TINA4_SECRET', 'TINA4_SESSION_BACKEND', 'TINA4_SESSION_PATH', 'TINA4_API_KEY',
        'TINA4_CSRF', 'TINA4_TOKEN_LIMIT', 'TINA4_DEBUG', 'TINA4_MCP', 'TINA4_MCP_REMOTE',
    ];

    protected function setUp(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $this->savedEnv[$key] = getenv($key);
        }
        Router::clear();
        $this->setEnv('TINA4_SECRET', self::SECRET);
        $this->setEnv('TINA4_SESSION_BACKEND', 'file');
        $this->sessionPath = sys_get_temp_dir() . '/tina4_auth_contract_' . uniqid();
        mkdir($this->sessionPath, 0755, true);
        $this->setEnv('TINA4_SESSION_PATH', $this->sessionPath);
        $this->setEnv('TINA4_API_KEY', null);
        $this->setEnv('TINA4_CSRF', null);
        Frond::$formTokenSessionId = '';

        Router::post('/contract/session', function (Request $request, Response $response) {
            foreach ((array)$request->body as $key => $value) {
                $request->session->set($key, $value);
            }
            return $response(['ok' => true]);
        })->noAuth();

        Router::post('/contract/write', function (Request $request, Response $response) {
            return $response(['user' => $request->user]);
        });

        Router::get('/contract/read', function (Request $request, Response $response) {
            return $response(['user' => $request->user]);
        })->secure();
    }

    protected function tearDown(): void
    {
        Router::clear();
        foreach ($this->savedEnv as $key => $value) {
            $this->setEnv($key, $value === false ? null : $value);
        }
        foreach (glob($this->sessionPath . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->sessionPath);
    }

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
            return;
        }
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
    }

    private function call(string $method, string $path, array $body = [], array $headers = []): Response
    {
        return Router::dispatch(
            Request::create(method: $method, path: $path, body: $body ?: null, headers: $headers, remoteIp: '127.0.0.1'),
            new Response(testing: true)
        );
    }

    private function sessionCookie(array $values): string
    {
        $stored = $this->call('POST', '/contract/session', $values);
        $this->assertSame(200, $stored->getStatusCode(), (string)$stored->getBody());
        $headers = $stored->getHeaders();
        $setCookie = $headers['Set-Cookie'] ?? ($headers['set-cookie'] ?? []);
        $setCookie = is_string($setCookie) ? [$setCookie] : $setCookie;
        $cookie = implode('; ', array_map(fn($c) => explode(';', $c)[0], $setCookie));
        $this->assertNotSame('', $cookie, 'the session route minted no cookie');
        return $cookie;
    }

    private function formToken(): string
    {
        $generate = (new Frond())->getGlobals()['form_token_value'];
        return (string)$generate('');
    }

    private function hs256(array $payload, string $key): string
    {
        $b64 = fn(string $data) => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        $head = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $b64(json_encode($payload));
        return "{$head}.{$body}." . $b64(hash_hmac('sha256', "{$head}.{$body}", $key, true));
    }

    private function user(Response $response): mixed
    {
        return json_decode((string)$response->getBody(), true)['user'] ?? null;
    }

    // ── auth-form-token-is-not-identity ───────────────────────────────────

    public function testAFormTokenInTheBearerHeaderIsRefusedByTheRouteGate(): void
    {
        $res = $this->call('POST', '/contract/write', [], ['Authorization' => 'Bearer ' . $this->formToken()]);
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testAFormTokenInTheBodyIsRefusedByTheRouteGate(): void
    {
        $res = $this->call('POST', '/contract/write', ['formToken' => $this->formToken()]);
        $this->assertSame(401, $res->getStatusCode());
        $this->assertArrayNotHasKey('FreshToken', $res->getHeaders());
    }

    public function testAFormTokenInTheSessionIsRefusedByTheRouteGate(): void
    {
        $cookie = $this->sessionCookie(['token' => $this->formToken()]);
        $this->assertSame(401, $this->call('GET', '/contract/read', [], ['Cookie' => $cookie])->getStatusCode());
    }

    public function testAFormTokenInTheBodyFallsThroughToTheSessionToken(): void
    {
        $cookie = $this->sessionCookie(['token' => Auth::getToken(['user_id' => 7])]);
        $res = $this->call('POST', '/contract/write', ['formToken' => $this->formToken()], ['Cookie' => $cookie]);
        $this->assertSame(200, $res->getStatusCode(), (string)$res->getBody());
        $this->assertSame(7, $this->user($res)['user_id'] ?? null);
    }

    public function testAFormTokenIsRefusedOnASecuredWebsocketUpgrade(): void
    {
        $form = $this->formToken();
        $route = ['auth_required' => true];
        $this->assertSame([null, false], WebSocket::wsAuthorized($route, ['authorization' => "Bearer {$form}"]));
        $this->assertSame([null, false], WebSocket::wsAuthorized($route, [], '', "bearer, {$form}"));
        $this->assertSame([null, false], WebSocket::wsAuthorized($route, [], "token={$form}"));
        [$payload, $ok] = WebSocket::wsAuthorized($route, ['authorization' => 'Bearer ' . Auth::getToken(['user_id' => 3])]);
        $this->assertTrue($ok);
        $this->assertSame(3, $payload['user_id']);
    }

    public function testAFormTokenIsRefusedByAuthenticateRequest(): void
    {
        $this->assertNull(Auth::authenticateRequest(['Authorization' => 'Bearer ' . $this->formToken()]));
        $this->assertSame(4, Auth::authenticateRequest(['Authorization' => 'Bearer ' . Auth::getToken(['user_id' => 4])])['user_id']);
        $middleware = Auth::middleware();
        $this->assertNull($middleware(Request::create(headers: ['Authorization' => 'Bearer ' . $this->formToken()])));
    }

    public function testRefreshNeverIssuesAFreshTokenFromAFormToken(): void
    {
        // The gate earns no FreshToken from a form token...
        $res = $this->call('POST', '/contract/write', ['formToken' => $this->formToken()]);
        $this->assertArrayNotHasKey('FreshToken', $res->getHeaders());
        // ...and refresh preserves purpose: a refreshed form token is still a
        // form token (CSRF rotation), so it is still refused as an identity.
        $rotated = Auth::refreshToken($this->formToken());
        $this->assertSame('form', Auth::validToken($rotated)['type']);
        $this->assertSame(401, $this->call('POST', '/contract/write', [], ['Authorization' => "Bearer {$rotated}"])->getStatusCode());
        $this->assertSame(5, Auth::validToken(Auth::refreshToken(Auth::getToken(['user_id' => 5])))['user_id']);
    }

    public function testAnAuthTokenStillPassesTheRouteGate(): void
    {
        $token = Auth::getToken(['user_id' => 9]);
        $this->assertSame(200, $this->call('POST', '/contract/write', [], ['Authorization' => "Bearer {$token}"])->getStatusCode());
        $body = $this->call('POST', '/contract/write', ['formToken' => $token]);
        $this->assertSame(200, $body->getStatusCode());
        $this->assertArrayHasKey('FreshToken', $body->getHeaders(), 'an auth token in the body still earns a FreshToken');
    }

    public function testAFormTokenStillPassesCsrf(): void
    {
        // CSRF skips noAuth routes, so the realistic case is a logged-in user
        // (auth token in the session) posting a rendered form.
        Router::post('/contract/csrf', function (Request $request, Response $response) {
            return $response(['user' => $request->user]);
        })->middleware([CsrfMiddleware::class]);

        $cookie = $this->sessionCookie(['token' => Auth::getToken(['user_id' => 11])]);
        $passed = $this->call('POST', '/contract/csrf', ['formToken' => $this->formToken()], ['Cookie' => $cookie]);
        $this->assertSame(200, $passed->getStatusCode(), (string)$passed->getBody());
        $this->assertSame(11, $this->user($passed)['user_id'] ?? null);
        $this->assertSame(403, $this->call('POST', '/contract/csrf', ['x' => 1], ['Cookie' => $cookie])->getStatusCode());
    }

    // ── auth-secret-strength ─────────────────────────────────────────────

    public function testSigningWithABlankSecretIsRefused(): void
    {
        $this->setEnv('TINA4_SECRET', null);
        try {
            Auth::getToken(['user_id' => 1]);
            $this->fail('signing with a blank secret must throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('TINA4_SECRET', $e->getMessage());
            $this->assertStringContainsString('openssl rand -hex 32', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        Auth::getToken(['user_id' => 1], '');
    }

    public function testSigningWithASecretShorterThan32BytesIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('32 bytes');
        Auth::getToken(['user_id' => 1], str_repeat('x', 31));
    }

    public function testATokenForgedWithTheEmptyKeyIsRejected(): void
    {
        $this->setEnv('TINA4_SECRET', null);
        $forged = $this->hs256(['user_id' => 1, 'exp' => time() + 600], '');
        $this->assertNull(Auth::validToken($forged));
        $this->assertNull(Auth::validToken($forged, ''));
        $this->assertSame(401, $this->call('POST', '/contract/write', [], ['Authorization' => "Bearer {$forged}"])->getStatusCode());
    }

    public function testA32ByteSecretSignsAndVerifies(): void
    {
        $key = str_repeat('k', 32);
        $this->assertSame(2, Auth::validToken(Auth::getToken(['user_id' => 2], $key), $key)['user_id']);
    }

    /** Boot a real App in a child PHP process; return [exit code, output]. */
    private function boot(string $envFile): array
    {
        $dir = sys_get_temp_dir() . '/tina4_boot_' . uniqid();
        mkdir($dir . '/src/routes', 0755, true);
        file_put_contents($dir . '/.env', $envFile);
        $autoload = realpath(__DIR__ . '/../vendor/autoload.php');
        $code = "require '{$autoload}'; \$app = new \\Tina4\\App('{$dir}'); \$app->start(); echo 'BOOTED';";
        $env = array_filter(getenv(), fn($key) => !str_starts_with($key, 'TINA4_'), ARRAY_FILTER_USE_KEY);
        $env['TINA4_NO_BROWSER'] = 'true';
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, $env);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $exit = proc_close($process);
        return [$exit, $output];
    }

    public function testBootOutsideDevRefusesABlankSecret(): void
    {
        [$exit, $output] = $this->boot("TINA4_DEBUG=false\n");
        $this->assertNotSame(0, $exit, $output);
        $this->assertStringNotContainsString('BOOTED', $output);
        $this->assertStringContainsString('TINA4_SECRET', $output);
        $this->assertStringContainsString('openssl rand -hex 32', $output);
    }

    public function testBootOutsideDevRefusesAShortSecret(): void
    {
        [$exit, $output] = $this->boot("TINA4_DEBUG=false\nTINA4_SECRET=too-short\n");
        $this->assertNotSame(0, $exit, $output);
        $this->assertStringNotContainsString('BOOTED', $output);
        $this->assertStringContainsString('32 bytes', $output);
    }

    // ── empty-peer-is-not-loopback ───────────────────────────────────────

    public function testAnEmptyPeerIsNotLoopback(): void
    {
        $this->assertFalse(McpServer::isLoopback(''));
    }

    public function testALoopbackPeerIsLoopback(): void
    {
        foreach (['127.0.0.1', '127.8.9.10', '::1', '::ffff:127.0.0.1', 'localhost'] as $address) {
            $this->assertTrue(McpServer::isLoopback($address), $address);
        }
        foreach (['10.0.0.1', '0.0.0.0', '192.168.1.5', '::ffff:10.0.0.1'] as $address) {
            $this->assertFalse(McpServer::isLoopback($address), $address);
        }
    }

    public function testAnEmptyPeerIsRefusedByTheMcpGate(): void
    {
        $this->setEnv('TINA4_DEBUG', 'true');
        $this->setEnv('TINA4_MCP', null);
        $this->setEnv('TINA4_MCP_REMOTE', null);
        $this->assertFalse(McpServer::isRequestAllowed('', false));
        $this->assertTrue(McpServer::isRequestAllowed('127.0.0.1', false));
    }

    public function testAPsr7RequestCarriesItsRealPeer(): void
    {
        // PSR-7 runtimes (RoadRunner, FrankenPHP worker mode) hand App::__invoke a
        // ServerRequest; the peer lives in its server params.
        Router::get('/contract/peer', fn(Request $request, Response $response) => $response(['peer' => $request->remoteIp]));
        $app = new App(sys_get_temp_dir());
        $withPeer = $app(new ServerRequest('GET', '/contract/peer', [], null, '1.1', ['REMOTE_ADDR' => '203.0.113.9']));
        $this->assertSame('203.0.113.9', json_decode((string)$withPeer->getBody(), true)['peer']);
        $withoutPeer = $app(new ServerRequest('GET', '/contract/peer'));
        AppTestSupport::releaseHandlers($app);
        $this->assertSame('', json_decode((string)$withoutPeer->getBody(), true)['peer']);
    }

    // ── sso-identity-expires ─────────────────────────────────────────────

    private function sso(int $expiresAt): array
    {
        return ['marker' => 1, '_tina4_sso' => ['version' => 1, 'expires_at' => $expiresAt,
            'identity' => ['issuer' => 'https://idp.example', 'subject' => 'u-1']]];
    }

    public function testAnExpiredSsoIdentityIsRefused(): void
    {
        $cookie = $this->sessionCookie($this->sso(time() - 5));
        $this->assertSame(401, $this->call('GET', '/contract/read', [], ['Cookie' => $cookie])->getStatusCode());
    }

    public function testALiveSsoIdentityPasses(): void
    {
        $live = $this->call('GET', '/contract/read', [], ['Cookie' => $this->sessionCookie($this->sso(time() + 300))]);
        $this->assertSame(200, $live->getStatusCode());
        $this->assertSame('u-1', $this->user($live)['subject'] ?? null);
        $noLifetime = $this->call('GET', '/contract/read', [], ['Cookie' => $this->sessionCookie($this->sso(0))]);
        $this->assertSame(200, $noLifetime->getStatusCode());
    }

    // ── form-token-lifetime ──────────────────────────────────────────────

    public function testAFormTokenLivesTokenLimitMinutes(): void
    {
        $this->setEnv('TINA4_TOKEN_LIMIT', '5');
        $payload = Auth::validToken($this->formToken());
        $this->assertSame(5 * 60, $payload['exp'] - $payload['iat']);
    }
}
