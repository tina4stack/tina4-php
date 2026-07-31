<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Middleware;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

/**
 * RFC 9110 s9.3.2: a HEAD response MUST NOT carry content. On EVERY path.
 *
 * PHP already behaves correctly; this LOCKS IT IN, because Ruby did not.
 *
 * Ruby stripped the body for a routed response, a 404 and a 405, but NOT for a
 * static asset - its static and swagger branches returned early and skipped the
 * strip. Measured 2026-07-31: Ruby returned 15 bytes where PHP, Python and Node
 * all returned 0.
 *
 * Why it matters beyond conformance: HEAD is what link checkers, monitoring
 * probes and cache validators use precisely to AVOID transferring the body. A
 * HEAD that returns the body makes every one of those checks cost a full
 * download, silently.
 *
 * NO MOCKS: real routes and real files through the real dispatcher.
 *
 * Same case names in all four frameworks.
 */
class HeadNoBodyConformanceTest extends TestCase
{
    private string $tmpDir = '';
    private string $previousBasePath = '';

    protected function setUp(): void
    {
        Router::clear();
        Middleware::reset();
        $this->tmpDir = sys_get_temp_dir() . '/tina4_head_conformance_' . uniqid('', true);
        mkdir($this->tmpDir . '/src/public', 0777, true);
        file_put_contents($this->tmpDir . '/src/public/asset.css', 'body { color: red; }');
        $this->previousBasePath = Router::$basePath;
        Router::$basePath = $this->tmpDir;
        Router::get('/routed', fn($q, $s) => $s->json(['said' => 'hello from the route']));
    }

    protected function tearDown(): void
    {
        Router::$basePath = $this->previousBasePath;
        Router::clear();
        Middleware::reset();
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            exec('rm -rf ' . escapeshellarg($this->tmpDir));
        }
    }

    private function call(string $method, string $path): Response
    {
        return Router::dispatch(
            Request::create(method: $method, path: $path),
            new Response(testing: true)
        );
    }

    private function header(Response $response, string $name): ?string
    {
        foreach ($response->getHeaders() as $key => $value) {
            if (strtolower($key) === strtolower($name)) {
                return $value;
            }
        }
        return null;
    }

    public function testAHeadOnAStaticAssetCarriesNoBody(): void
    {
        $response = $this->call('HEAD', '/asset.css');
        $this->assertSame(200, $response->getStatusCode(), 'the static asset was not served at all');
        $this->assertSame(
            0,
            strlen($response->getBody()),
            'HEAD returned ' . strlen($response->getBody()) . ' bytes of the file - RFC 9110 '
            . 's9.3.2 forbids content in a HEAD response'
        );
    }

    public function testAHeadOnARoutedResponseCarriesNoBody(): void
    {
        $response = $this->call('HEAD', '/routed');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, strlen($response->getBody()));
    }

    public function testAHeadOnA404CarriesNoBody(): void
    {
        $response = $this->call('HEAD', '/definitely/not/a/route');
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(0, strlen($response->getBody()));
    }

    /**
     * s9.3.2 SHOULD: the same headers as the equivalent GET. That is the whole
     * point of a HEAD probe - a size estimate without the transfer.
     */
    public function testAHeadStillReportsTheContentLengthTheGetWouldHaveSent(): void
    {
        $response = $this->call('HEAD', '/asset.css');
        $length = $this->header($response, 'Content-Length');

        $this->assertNotNull($length, 'HEAD dropped Content-Length, so the probe learns nothing');
        $this->assertSame(filesize($this->tmpDir . '/src/public/asset.css'), (int) $length);
    }

    /** NEGATIVE: stripping HEAD must not have broken GET. */
    public function testAGetOnAStaticAssetStillReturnsTheBody(): void
    {
        $response = $this->call('GET', '/asset.css');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('color: red', $response->getBody());
    }
}
