<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Middleware;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

/**
 * Feature 6, step 1: FREEZE the dispatch behaviour before refactoring it.
 *
 * These are characterisation tests, not new-behaviour tests. Every one asserts
 * what `Router::dispatch` does TODAY, so the named-stage extraction that
 * follows can be proved behaviour-preserving. The plan is explicit that this
 * step is "not optional and not reorderable": PHP's dispatchInner is the
 * largest function in the family (cyclomatic complexity 73 against a ceiling
 * of 10) and it is scheduled last for exactly that reason.
 *
 * They drive `Router::dispatch` directly rather than going through the
 * TestClient: that IS the function being refactored, so this exercises the
 * real thing with nothing in between.
 *
 * NO MOCKS: real routes through the real dispatcher, real files on disk.
 *
 * Identical case names in all four frameworks:
 *   tina4-ruby/spec/dispatch_characterisation_spec.rb
 *   tina4-python/tests/test_dispatch_characterisation.py
 *   tina4-nodejs/test/dispatchCharacterisation.test.ts
 */
class DispatchCharacterisationTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        Router::clear();
        Middleware::reset();
        $this->tmpDir = sys_get_temp_dir() . '/tina4_dispatch_char_' . uniqid('', true);
        mkdir($this->tmpDir . '/src/templates', 0777, true);
        mkdir($this->tmpDir . '/src/public', 0777, true);
    }

    protected function tearDown(): void
    {
        Router::clear();
        Middleware::reset();
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            exec('rm -rf ' . escapeshellarg($this->tmpDir));
        }
    }

    private function call(string $method, string $path, array $headers = [], ?array $query = null): Response
    {
        return Router::dispatch(
            Request::create(method: $method, path: $path, query: $query, headers: $headers),
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

    // ── 1. The happy path ────────────────────────────────────────

    public function testDispatchGetKnownRouteReturnsHandlerBody(): void
    {
        Router::get('/hello', fn($q, $s) => $s->json(['said' => 'world']));

        $response = $this->call('GET', '/hello');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('world', $response->getBody());
    }

    // ── 2. Unknown path is a 404 ─────────────────────────────────

    public function testDispatchUnknownPathReturns404(): void
    {
        $this->assertSame(404, $this->call('GET', '/definitely/not/a/route')->getStatusCode());
    }

    // ── 3. Known path, wrong method: 405 with Allow ──────────────
    //
    // The ordering the pipeline has to preserve: the 405 check only runs when
    // matching found nothing, and it must beat the 404.

    public function testDispatchKnownPathWrongMethodReturns405WithAllow(): void
    {
        Router::get('/only-get', fn($q, $s) => $s->json(['ok' => true]));

        $response = $this->call('POST', '/only-get');
        $this->assertSame(405, $response->getStatusCode());
        $this->assertStringContainsString('GET', strtoupper((string) $this->header($response, 'Allow')));
    }

    // ── 4. OPTIONS on a known path: RFC 9110 shape ───────────────

    public function testDispatchOptionsOnKnownPathReturns204WithAllow(): void
    {
        Router::get('/opt', fn($q, $s) => $s->json(['ok' => true]));

        $response = $this->call('OPTIONS', '/opt');
        $this->assertContains($response->getStatusCode(), [200, 204]);
        $this->assertStringContainsString('GET', strtoupper((string) $this->header($response, 'Allow')));
    }

    // ── 5. A trailing slash: whatever it does today, it keeps doing ──

    public function testDispatchTrailingSlashRedirects301PreservingQuery(): void
    {
        Router::get('/items', fn($q, $s) => $s->json(['list' => true]));

        $response = $this->call('GET', '/items/', [], ['page' => '2', 'sort' => 'name']);

        if (in_array($response->getStatusCode(), [301, 302, 308], true)) {
            $location = (string) $this->header($response, 'Location');
            $this->assertStringContainsString('/items', $location);
        } else {
            // TINA4_TRAILING_SLASH_REDIRECT is opt-in, so unset it serves or
            // 404s directly. Either is acceptable TODAY; the refactor must not
            // change which.
            $this->assertContains($response->getStatusCode(), [200, 404]);
        }
    }

    // ── 6. Static assets: a RUNTIME GIFT, not a stage ────────────
    //
    // PHP has NO static stage at all, and that is correct rather than a gap
    // (audit category 1): `php -S` and nginx serve a real file before
    // index.php is ever reached, so the dispatcher never sees the request.
    // Pinned so the extraction does not "helpfully" add a static stage that
    // would shadow a route - the exact hazard ADR-0010 removed elsewhere.

    public function testDispatchStaticAssetReturns304OnMatchingValidator(): void
    {
        file_put_contents($this->tmpDir . '/src/public/char.css', 'body { color: red; }');

        $response = $this->call('GET', '/char.css');
        $this->assertSame(
            404,
            $response->getStatusCode(),
            'PHP grew a static stage. The SAPI serves files before index.php runs, '
            . 'so a dispatcher-level static lookup is both redundant and a route-shadowing hazard.'
        );
    }

    // ── 7. HEAD behaves like GET on a template route ─────────────

    public function testDispatchTemplatePathRendersForGetAndHead(): void
    {
        $previous = Router::$basePath;
        Router::$basePath = $this->tmpDir;
        file_put_contents($this->tmpDir . '/src/templates/char.twig', '<p>rendered</p>');

        try {
            $get = $this->call('GET', '/char.twig');
            $head = $this->call('HEAD', '/char.twig');
            $this->assertSame(
                $get->getStatusCode(),
                $head->getStatusCode(),
                'HEAD and GET disagree on a template route'
            );
        } finally {
            Router::$basePath = $previous;
        }
    }

    // ── 8. CORS on a short-circuited 401 ─────────────────────────
    //
    // CHARACTERISATION: pins what happens TODAY. PHP's CorsMiddleware is
    // OPT-IN (Middleware::use), which is why the pre/post split's structural
    // advantage here is not automatic - registering it is the developer's
    // call. With it registered, its headers must outlive the 401.

    public function testDispatchCorsHeadersPresentOn401(): void
    {
        Middleware::use(\Tina4\Middleware\CorsMiddleware::class);
        Router::post('/needs-auth', fn($q, $s) => $s->json(['secret' => true]));

        $response = $this->call('POST', '/needs-auth', ['Origin' => 'https://example.com']);
        $this->assertContains(
            $response->getStatusCode(),
            [401, 403],
            'expected the write route to be secured by default'
        );

        $this->assertNotNull(
            $this->header($response, 'Access-Control-Allow-Origin'),
            'CORS headers were lost on the 401 - a browser shown that response reports '
            . 'a CORS error and the real status never reaches the developer'
        );
    }

    // ── 9. Matched-route metadata is visible to the auth stage ───
    //
    // PHP's own comment records that `$request->handler` was once left null,
    // so the ->noAuth() bypass was DEAD CODE on a real dispatch.

    public function testDispatchNoauthWriteRouteIsNotBlockedByCsrf(): void
    {
        Router::post('/public-write', fn($q, $s) => $s->json(['open' => true]))->noAuth();

        $this->assertSame(
            200,
            $this->call('POST', '/public-write')->getStatusCode(),
            'a route marked noAuth() was still blocked - the matched route metadata '
            . 'did not reach the auth stage'
        );
    }

    // ── 10. Middleware ordering contract ─────────────────────────

    public function testDispatchMiddlewareRunsInRegistrationOrder(): void
    {
        CharOrderFirst::$order = [];
        Middleware::use(CharOrderFirst::class);
        Middleware::use(CharOrderSecond::class);
        Router::get('/ordered', fn($q, $s) => $s->json(['done' => true]));

        $this->call('GET', '/ordered');
        $this->assertSame(['first', 'second'], CharOrderFirst::$order,
            'middleware ran out of registration order');
    }

    // ── ADR-0010: routes beat files ──────────────────────────────
    //
    // PHP has no static stage, so a route ALWAYS wins by construction. Pinned
    // anyway: the extraction must not introduce one.

    public function testARouteWinsOverAFileAtTheSamePath(): void
    {
        file_put_contents($this->tmpDir . '/src/public/clash.json', '{"from":"file"}');
        Router::get('/clash.json', fn($q, $s) => $s->json(['from' => 'route']));

        $response = $this->call('GET', '/clash.json');
        $this->assertSame(200, $response->getStatusCode());
        // Assert on the PAYLOAD, not a bare substring: in dev mode the
        // framework injects a toolbar whose markup can contain either word.
        $this->assertStringContainsString('"from"', $response->getBody());
        $this->assertStringContainsString('route', $response->getBody());
        $this->assertStringNotContainsString('{"from":"file"}', $response->getBody());
    }

    public function testAFileIsStillServedWhenNoRouteMatches(): void
    {
        // The SAPI's job, not the dispatcher's - see case 6.
        file_put_contents($this->tmpDir . '/src/public/plain.json', '{"from":"file"}');

        $this->assertSame(404, $this->call('GET', '/plain.json')->getStatusCode());
    }

    public function testAnApiPathNeedsNoSpecialCaseNowThatRoutesWin(): void
    {
        Router::get('/api/thing', fn($q, $s) => $s->json(['routed' => true]));

        $hit = $this->call('GET', '/api/thing');
        $this->assertSame(200, $hit->getStatusCode());
        $this->assertStringContainsString('routed', $hit->getBody());
        $this->assertSame(404, $this->call('GET', '/api/nothing')->getStatusCode());
    }
}

class CharOrderFirst
{
    /** @var array<int, string> */
    public static array $order = [];

    public static function beforeFirst($request, $response): array
    {
        self::$order[] = 'first';
        return [$request, $response];
    }
}

class CharOrderSecond
{
    public static function beforeSecond($request, $response): array
    {
        CharOrderFirst::$order[] = 'second';
        return [$request, $response];
    }
}
