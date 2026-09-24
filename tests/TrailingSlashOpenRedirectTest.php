<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Medium security finding F3 — the trailing-slash redirect must not be an open
 * redirect.
 *
 * With TINA4_TRAILING_SLASH_REDIRECT on, a request for "//evil.com/" used to
 * normalise to a "Location: //evil.com" header — which a browser treats as the
 * protocol-relative absolute URL of another host (open redirect). The canonical
 * target must always be a same-origin absolute path. Case names match the
 * sibling regression in tina4-python/tests/test_trailing_slash_open_redirect.py.
 * (Node matches registered routes only, and Ruby's predicate emits no Location,
 * so neither is affected.)
 */

use PHPUnit\Framework\TestCase;

class TrailingSlashOpenRedirectTest extends TestCase
{
    private array $savedEnv = [];

    protected function setUp(): void
    {
        $this->savedEnv['ts'] = getenv('TINA4_TRAILING_SLASH_REDIRECT');
        putenv('TINA4_TRAILING_SLASH_REDIRECT=true');
        $_ENV['TINA4_TRAILING_SLASH_REDIRECT'] = 'true';
        \Tina4\Router::clear();
    }

    protected function tearDown(): void
    {
        \Tina4\Router::clear();
        if ($this->savedEnv['ts'] === false) {
            putenv('TINA4_TRAILING_SLASH_REDIRECT');
            unset($_ENV['TINA4_TRAILING_SLASH_REDIRECT']);
        } else {
            putenv('TINA4_TRAILING_SLASH_REDIRECT=' . $this->savedEnv['ts']);
            $_ENV['TINA4_TRAILING_SLASH_REDIRECT'] = $this->savedEnv['ts'];
        }
    }

    private function redirectLocation(string $path): array
    {
        $request = new \Tina4\Request('GET', $path);
        $response = new \Tina4\Response();
        $result = \Tina4\Router::dispatch($request, $response);
        $headers = $result->getHeaders();
        $location = $headers['location'] ?? $headers['Location'] ?? null;
        return [$result->getStatusCode(), $location];
    }

    public function testProtocolRelativePathRedirectsToSameOrigin(): void
    {
        [$status, $location] = $this->redirectLocation('//evil.com/');
        $this->assertSame(301, $status);
        $this->assertNotNull($location);
        $this->assertStringStartsNotWith('//', $location, "open redirect: {$location}");
        $this->assertStringStartsNotWith('/\\', $location, "open redirect: {$location}");
        $this->assertSame('/evil.com', $location);
    }

    public function testBackslashAuthorityRedirectsToSameOrigin(): void
    {
        [$status, $location] = $this->redirectLocation('/\\evil.com/');
        $this->assertSame(301, $status);
        $this->assertNotNull($location);
        $this->assertStringStartsNotWith('//', $location, "open redirect: {$location}");
        $this->assertStringStartsNotWith('/\\', $location, "open redirect: {$location}");
    }

    public function testOrdinaryPathStillRedirectsToCanonical(): void
    {
        \Tina4\Router::get('/foo/bar', fn($req, $res) => $res->json(['ok' => true]));
        [$status, $location] = $this->redirectLocation('/foo/bar/');
        $this->assertSame(301, $status);
        $this->assertSame('/foo/bar', $location);
    }
}
