<?php

use PHPUnit\Framework\TestCase;
use Tina4\Session;
use Tina4\Sso;
use Tina4\SsoError;

final class SsoContractTest extends TestCase
{
    private function options(): array
    {
        return [
            'issuer' => getenv('TINA4_TEST_OIDC_ISSUER') ?: 'http://127.0.0.1:58080/realms/tina4-contract',
            'client_id' => 'tina4-app', 'client_secret' => 'tina4-secret',
            'redirect_uri' => 'http://127.0.0.1:7146/auth/callback',
        ];
    }

    public function testSurfaceConfigurationAndReservedSessionData(): void
    {
        $contract = json_decode((string)file_get_contents(__DIR__ . '/fixtures/sso_contract.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ADR-0056', $contract['adr']);
        $this->assertCount(10, $contract['invariants']);
        $sso = new Sso($this->options());
        foreach (['discover', 'login', 'callback', 'identity', 'refresh', 'logout'] as $method) {
            $this->assertTrue(method_exists($sso, $method));
        }
        $this->assertSame('/dashboard', Sso::safeReturn('/dashboard'));
        $this->assertSame('/', Sso::safeReturn('https://evil.example'));
        try {
            new Sso(array_replace($this->options(), ['issuer' => 'http://identity.example/realm']));
            $this->fail('external plain HTTP issuer should fail');
        } catch (SsoError) {
            $this->addToAssertionCount(1);
        }
        try {
            new Sso(array_replace($this->options(), ['verify' => 'jwks']));
            $this->fail('jwks should fail before an application cryptography capability is installed');
        } catch (SsoError $error) {
            $this->assertStringContainsString('cryptography capability', $error->getMessage());
        }
        $path = sys_get_temp_dir() . '/tina4-sso-' . bin2hex(random_bytes(5));
        $session = new Session('file', ['path' => $path]);
        $session->start();
        $session->set('cart', [42]);
        $session->set(Sso::PENDING_KEY, ['state' => 'secret']);
        $session->set(Sso::SESSION_KEY, ['access_token' => 'secret']);
        $this->assertSame(['cart' => [42]], $session->all());
    }

    private function browserQuery(string $loginUrl, string $callback): array
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0]]);
        $page = file_get_contents($loginUrl, false, $context);
        $headers = $http_response_header ?? [];
        preg_match('/<form[^>]+action="([^"]+)"[^>]*>/', (string)$page, $match);
        $this->assertNotEmpty($match[1] ?? null, 'real provider login form was not found');
        $cookies = [];
        foreach ($headers as $header) {
            if (stripos($header, 'Set-Cookie:') === 0) {
                $cookies[] = trim(explode(';', substr($header, 11), 2)[0]);
            }
        }
        $action = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
        $post = stream_context_create(['http' => [
            'method' => 'POST', 'ignore_errors' => true, 'follow_location' => 0,
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nCookie: " . implode('; ', $cookies),
            'content' => http_build_query(['username' => 'andre', 'password' => 'tina4-pass', 'credentialId' => '']),
        ]]);
        file_get_contents($action, false, $post);
        $responseHeaders = $http_response_header ?? [];
        $location = null;
        foreach ($responseHeaders as $header) {
            if (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, 9));
            }
        }
        $this->assertStringStartsWith($callback, (string)$location);
        parse_str((string)parse_url($location, PHP_URL_QUERY), $query);
        return $query;
    }

    public function testRealOidcPkceSessionRefreshAndLogout(): void
    {
        if (!getenv('TINA4_REQUIRE_OIDC')) {
            $this->markTestSkipped('real OIDC gate runs on the lab');
        }
        $sso = Sso::fromIssuer($this->options());
        $path = sys_get_temp_dir() . '/tina4-sso-' . bin2hex(random_bytes(5));
        $session = new Session('file', ['path' => $path]);
        $oldId = $session->start();
        $session->set('cart', [42]);
        $loginUrl = $sso->login($session, '/dashboard');
        parse_str((string)parse_url($loginUrl, PHP_URL_QUERY), $loginQuery);
        $this->assertSame('code', $loginQuery['response_type']);
        $this->assertSame('S256', $loginQuery['code_challenge_method']);
        $result = $sso->callback($session, $this->browserQuery($loginUrl, $sso->redirectUri));
        $this->assertNotSame($oldId, $session->getSessionId());
        $this->assertSame([42], $session->get('cart'));
        $this->assertSame('/dashboard', $result['return_to']);
        $this->assertSame('andre', $result['identity']['username']);
        $this->assertContains('admin', $result['identity']['roles']);
        $this->assertContains('developer', $result['identity']['roles']);
        $this->assertSame(['/engineering'], $result['identity']['groups']);
        $this->assertArrayNotHasKey(Sso::SESSION_KEY, $session->all());
        $this->assertSame($result['identity']['subject'], $sso->refresh($session)['subject']);
        $this->assertStringContainsString('logout', $sso->logout($session));
        $this->assertSame('', $session->getSessionId());
    }
}
