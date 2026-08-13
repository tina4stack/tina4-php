<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Configurable error pages — Accept-based negotiation, converged 403, request-id, escaping.
 *
 * Feature 42. See ERR-DEC-01/ERR-DEC-02 and
 * tina4-documentation/plan/v3/fixtures/error_pages_contract.json.
 *
 * Driven through the REAL front controller (Router::dispatch, via TestClient) - NO
 * mocks. Real Accept headers, a real GLOBAL class-based middleware denial for the 403
 * case (the gap this feature closes - see Router::runGlobalMiddlewarePass), a real
 * malicious path.
 *
 * Cases (shared names, all four):
 *   1. prod_500_has_no_stack_and_a_request_id  - the LOCKED CWE-209 guarantee (do not
 *      reopen; re-proven here as part of this feature's negotiated 500 path).
 *   2. json_accept_yields_a_json_error_body    - Accept: application/json on 404/403/500
 *      -> {error,code,message,status,request_id}.
 *   3. browser_accept_yields_the_html_error_page - Accept: text/html or *\/* -> the HTML
 *      errors/{code}.twig page.
 *   4. the_403_renders_identically_across_the_four - a middleware denial (both the
 *      per-route/closure path AND the global class-based path) now renders through the
 *      SAME negotiated path as 404/500.
 *   5. a_custom_error_template_overrides_the_builtin - src/templates/errors/{code}.twig
 *      wins over the framework default, for 404 AND 500.
 *   6. a_reflected_path_in_an_error_page_is_escaped - a <script> path on 404/403/500
 *      never appears unescaped in the HTML body.
 *   7. the_404_carries_a_request_id - the negotiated JSON body carries request_id.
 *
 * Same case names in all four:
 *   tina4-python/tests/test_error_pages_contract.py
 *   tina4-ruby/spec/error_pages_contract_spec.rb
 *   tina4-nodejs/test/errorPagesContract.test.ts
 */

use PHPUnit\Framework\TestCase;
use Tina4\Frond;
use Tina4\Middleware;
use Tina4\Response;
use Tina4\Router;
use Tina4\TestClient;
use Tina4\TestResponse;

/** A GLOBAL class-based middleware that denies without setting its own response -
 *  the gap this feature closes (Middleware::runBefore's default fallback). */
class ErrorPagesDenyAllGlobal
{
    public static function beforeDeny($request, $response)
    {
        return false;
    }
}

class ErrorPagesContractTest extends TestCase
{
    private const SECRET_MARKER = 'SECRET-500-TRACE-do-not-leak-e42a-php';
    // No parentheses: Tina4 PHP's router compiles a path pattern into a regex
    // and does not escape literal '(' ')' in a registered path (a separate,
    // pre-existing routing limitation, unrelated to this feature - flagged,
    // not fixed here) - so a route registered with a literal alert(1) never
    // matches its own request. <script> alone still proves the XSS case.
    private const MALICIOUS_PATH = '/<script>tina4XssProbe</script>';

    protected function setUp(): void
    {
        Router::clear();
        Middleware::reset();
        putenv('TINA4_SECRET=error-pages-feature-42-secret');
        $_ENV['TINA4_SECRET'] = 'error-pages-feature-42-secret';
        putenv('TINA4_DEBUG');
        unset($_ENV['TINA4_DEBUG'], $_SERVER['TINA4_DEBUG']);
        // A fresh Frond singleton pointed at the real src/templates - undoes any
        // override a previous test installed via Response::setFrond().
        Response::setFrond(new Frond('src/templates'));
    }

    protected function tearDown(): void
    {
        Router::clear();
        Middleware::reset();
        Response::setFrond(new Frond('src/templates'));
    }

    private function dispatch(\Tina4\Request $request): Response
    {
        return Router::dispatch($request, new Response(testing: true));
    }

    /** Case-insensitive response-header lookup (TestResponse stores Title-Case keys). */
    private function header(TestResponse $res, string $name): ?string
    {
        foreach ($res->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    private function registerBoom(string $path = '/boom'): void
    {
        Router::get($path, function ($request, $response) {
            throw new \RuntimeException(self::SECRET_MARKER);
        });
    }

    /** Registers the path behind a GLOBAL class-based deny-all middleware -
     *  Middleware::use() is process-wide, so this must be the LAST route
     *  registered in a test (nothing after it should hit a live handler). */
    private function registerBlockedGlobal(string $path = '/blocked-global'): void
    {
        Middleware::use(ErrorPagesDenyAllGlobal::class);
        Router::get($path, function ($request, $response) {
            return $response->json(['should' => 'never get here']);
        });
    }

    // ------------------------------------------------------- 1. CWE-209, locked

    public function testProd500HasNoStackAndARequestId(): void
    {
        $this->registerBoom();
        $client = new TestClient();

        $r = $client->get('/boom');
        $this->assertSame(500, $r->status);
        foreach ([self::SECRET_MARKER, '#0 ', '.php:', 'Stack Trace', 'RuntimeException'] as $marker) {
            $this->assertStringNotContainsString($marker, $r->body, "CWE-209 regression: 500 body leaked '$marker'");
        }
        $this->assertNotEmpty($this->header($r, 'X-Request-ID'));

        $rJson = $client->get('/boom', headers: ['Accept' => 'application/json']);
        $this->assertSame(500, $rJson->status);
        $body = $rJson->json();
        $this->assertStringNotContainsString(self::SECRET_MARKER, json_encode($body));
        $this->assertSame('Server Error', $body['message']);
        $this->assertNotEmpty($body['request_id']);
        $this->assertSame($this->header($rJson, 'X-Request-ID'), $body['request_id']);
    }

    // --------------------------------------------- 2 & 3. content negotiation

    public static function jsonNegotiationCases(): array
    {
        return [
            '404' => [404, '/does-not-exist', null],
            '403' => [403, '/blocked-global', 'registerBlockedGlobal'],
            '500' => [500, '/boom', 'registerBoom'],
        ];
    }

    /** @dataProvider jsonNegotiationCases */
    public function testJsonAcceptYieldsAJsonErrorBody(int $code, string $path, ?string $setup): void
    {
        if ($setup) {
            $this->$setup($path);
        }
        $r = (new TestClient())->get($path, headers: ['Accept' => 'application/json']);
        $this->assertSame($code, $r->status);
        $this->assertStringContainsString('application/json', (string) $this->header($r, 'Content-Type'));
        $body = $r->json();
        $this->assertTrue($body['error']);
        $this->assertNotEmpty($body['code']);
        $this->assertNotEmpty($body['message']);
        $this->assertSame($code, $body['status']);
        $this->assertNotEmpty($body['request_id']);
    }

    public static function htmlNegotiationCases(): array
    {
        return [
            '404 text/html' => [404, '/does-not-exist', null, 'text/html'],
            '404 */*' => [404, '/does-not-exist', null, '*/*'],
            '403 text/html' => [403, '/blocked-global', 'registerBlockedGlobal', 'text/html'],
            '500 text/html' => [500, '/boom', 'registerBoom', 'text/html'],
        ];
    }

    /** @dataProvider htmlNegotiationCases */
    public function testBrowserAcceptYieldsTheHtmlErrorPage(int $code, string $path, ?string $setup, string $accept): void
    {
        if ($setup) {
            $this->$setup($path);
        }
        $r = (new TestClient())->get($path, headers: ['Accept' => $accept]);
        $this->assertSame($code, $r->status);
        $this->assertStringContainsString('text/html', (string) $this->header($r, 'Content-Type'));
        $this->assertStringContainsString('"error-code">' . $code . '<', $r->body);
    }

    // ------------------------------------------------------------ 4. 403 split

    public function testThe403RendersIdenticallyAcrossTheFour(): void
    {
        // Case A: per-route closure denial (already went through renderForbidden
        // before this feature).
        Router::get('/blocked-closure', fn($req, $res) => $res->json(['ok' => true]))
            ->middleware([fn() => false]);
        // Case B: GLOBAL class-based denial - the gap this feature closes.
        $this->registerBlockedGlobal('/blocked-global');

        $client = new TestClient();
        foreach (['/blocked-closure', '/blocked-global'] as $path) {
            $rJson = $client->get($path, headers: ['Accept' => 'application/json']);
            $this->assertSame(403, $rJson->status, "$path did not 403");
            $body = $rJson->json();
            $this->assertSame(
                ['error' => true, 'code' => 'FORBIDDEN', 'message' => 'Forbidden', 'status' => 403, 'request_id' => $body['request_id']],
                $body,
                "$path body shape diverged"
            );
            $this->assertNotEmpty($body['request_id']);

            $rHtml = $client->get($path, headers: ['Accept' => 'text/html']);
            $this->assertSame(403, $rHtml->status);
            $this->assertStringContainsString('text/html', (string) $this->header($rHtml, 'Content-Type'));
            $this->assertStringContainsString('"error-code">403<', $rHtml->body);
        }
    }

    // ---------------------------------------------------- 5. override the built-in

    public function testACustomErrorTemplateOverridesTheBuiltin(): void
    {
        // renderError() gates the user-override tier on a HARDCODED
        // project-relative is_file('src/templates/errors/{code}.twig') check
        // (a real app's cwd IS its project root when serving) - so proving the
        // override means actually cd-ing into a project shaped that way, not
        // just repointing the Frond singleton (which the gate never consults).
        $projectDir = sys_get_temp_dir() . '/tina4_errpages_override_' . uniqid();
        mkdir($projectDir . '/src/templates/errors', 0755, true);
        file_put_contents($projectDir . '/src/templates/errors/404.twig', 'CUSTOM-404-PHP path={{ path }}');
        file_put_contents($projectDir . '/src/templates/errors/500.twig', 'CUSTOM-500-PHP rid={{ request_id }}');
        Response::setFrond(new Frond('src/templates'));

        $originalCwd = getcwd();
        chdir($projectDir);
        try {
            $this->registerBoom();
            $client = new TestClient();

            $r404 = $client->get('/nope');
            $this->assertSame(404, $r404->status);
            $this->assertStringContainsString('CUSTOM-404-PHP', $r404->body);

            $r500 = $client->get('/boom');
            $this->assertSame(500, $r500->status);
            $this->assertStringContainsString('CUSTOM-500-PHP', $r500->body);
        } finally {
            chdir($originalCwd);
            @unlink($projectDir . '/src/templates/errors/404.twig');
            @unlink($projectDir . '/src/templates/errors/500.twig');
            @rmdir($projectDir . '/src/templates/errors');
            @rmdir($projectDir . '/src/templates');
            @rmdir($projectDir . '/src');
            @rmdir($projectDir);
        }
    }

    // --------------------------------------------------- 6. reflected-path escaping

    public static function reflectedPathCases(): array
    {
        return [
            '404' => [404, self::MALICIOUS_PATH, null],
            '403' => [403, self::MALICIOUS_PATH, 'registerBlockedGlobal'],
            '500' => [500, self::MALICIOUS_PATH, 'registerBoom'],
        ];
    }

    /** @dataProvider reflectedPathCases */
    public function testAReflectedPathInAnErrorPageIsEscaped(int $code, string $path, ?string $setup): void
    {
        if ($setup) {
            $this->$setup($path);
        }
        $r = (new TestClient())->get($path);
        $this->assertSame($code, $r->status);
        $this->assertStringNotContainsString(
            '<script>tina4XssProbe</script>', $r->body, "XSS: raw <script> reflected in a $code page"
        );
        if ($code !== 500) {
            // 404/403 templates show {{ path }}; the built-in 500.twig does not
            // (only error_message/request_id, and error_message is empty in
            // prod per CWE-209) so there is nothing for it to reflect either way.
            $this->assertStringContainsString(
                '&lt;script&gt;', $r->body, 'expected the escaped form (proves it WAS reflected, safely)'
            );
        }
    }

    // ------------------------------------------------------- 7. 404 request-id

    public function testThe404CarriesARequestId(): void
    {
        $r = (new TestClient())->get('/does-not-exist', headers: ['Accept' => 'application/json']);
        $this->assertSame(404, $r->status);
        $body = $r->json();
        $this->assertNotEmpty($body['request_id'], '404 JSON body has no request_id');
        $this->assertSame($this->header($r, 'X-Request-ID'), $body['request_id']);
    }
}
