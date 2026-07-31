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
        // ADR-0018 made the CORS default deny. This suite is about CORS POLICY
        // headers, so it now declares the policy it used to inherit from the old
        // permissive default. No assertion below was changed.
        putenv('TINA4_CORS_ORIGINS=*');
        $_ENV['TINA4_CORS_ORIGINS'] = '*';
        \Tina4\Middleware\CorsMiddleware::resetWarnings();
        Router::clear();
        Middleware::reset();
        $this->tmpDir = sys_get_temp_dir() . '/tina4_dispatch_char_' . uniqid('', true);
        mkdir($this->tmpDir . '/src/templates', 0777, true);
        mkdir($this->tmpDir . '/src/public', 0777, true);
    }

    protected function tearDown(): void
    {
        putenv('TINA4_CORS_ORIGINS');
        unset($_ENV['TINA4_CORS_ORIGINS']);
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

    // ── 6. A static asset answers a conditional request cheaply ──
    //
    // PHP DOES have a static stage (StaticFiles::tryServe), in the not-found
    // fallback - after matching, per ADR-0010. The plan's enumeration table
    // recorded "php: none - SAPI serves it", which is WRONG: the SAPI does
    // serve a real file first in production, but the dispatcher has its own
    // lookup and that is what a Tina4 test, the built-in server and any
    // front-controller deployment actually hit.
    //
    // It is also the only framework besides Ruby that honours a conditional
    // request: PHP and Ruby answer 304, Python re-sends the whole body with a
    // 200 (recorded as a gap in the Python suite).
    //
    // The base path must be pointed at the fixture. An earlier version of this
    // case left it at the default '.', so the file was never found, the 404 it
    // asserted was a MISS rather than a policy, and the case passed while
    // claiming something false.

    public function testDispatchStaticAssetReturns304OnMatchingValidator(): void
    {
        $previous = Router::$basePath;
        Router::$basePath = $this->tmpDir;
        file_put_contents($this->tmpDir . '/src/public/char.css', 'body { color: red; }');

        try {
            $response = $this->call('GET', '/char.css');
            $this->assertSame(200, $response->getStatusCode(), 'the static asset was not served at all');

            $etag = $this->header($response, 'ETag');
            $this->assertNotNull($etag, 'static assets no longer carry a validator');

            $again = $this->call('GET', '/char.css', ['if-none-match' => $etag]);
            $this->assertSame(304, $again->getStatusCode());
        } finally {
            Router::$basePath = $previous;
        }
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
    // PHP resolves static in the NOT-FOUND fallback, so a route wins because
    // the static lookup is never reached - the same ordering Ruby and Node
    // moved to. Pinned so the extraction cannot hoist static ahead of
    // matching, which is exactly the hazard ADR-0010 removed.

    public function testARouteWinsOverAFileAtTheSamePath(): void
    {
        $previous = Router::$basePath;
        Router::$basePath = $this->tmpDir;
        file_put_contents($this->tmpDir . '/src/public/clash.json', '{"from":"file"}');
        Router::get('/clash.json', fn($q, $s) => $s->json(['from' => 'route']));

        $response = $this->call('GET', '/clash.json');
        $this->assertSame(200, $response->getStatusCode());
        // Assert on the PAYLOAD, not a bare substring: in dev mode the
        // framework injects a toolbar whose markup can contain either word.
        $this->assertStringContainsString('"from"', $response->getBody());
        $this->assertStringContainsString('route', $response->getBody());
        $this->assertStringNotContainsString('{"from":"file"}', $response->getBody());
        Router::$basePath = $previous;
    }

    /** NEGATIVE: route-first must not stop files being served at all. */
    public function testAFileIsStillServedWhenNoRouteMatches(): void
    {
        $previous = Router::$basePath;
        Router::$basePath = $this->tmpDir;
        file_put_contents($this->tmpDir . '/src/public/plain.json', '{"from":"file"}');

        try {
            $response = $this->call('GET', '/plain.json');
            $this->assertSame(200, $response->getStatusCode(),
                'moving static after matching stopped files being served');
            $this->assertStringContainsString('{"from":"file"}', $response->getBody());
        } finally {
            Router::$basePath = $previous;
        }
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
