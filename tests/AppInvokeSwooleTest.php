<?php declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * App::__invoke() MUST RECOGNISE A SWOOLE REQUEST AS A SWOOLE REQUEST.
 *
 * The docblock on App::__invoke() has documented a Swoole integration since v3,
 * and it could not work. The branch order was:
 *
 *     } elseif (is_object($request) && method_exists($request, 'getMethod')) {
 *         // PSR-7
 *         path: $request->getUri()->getPath(),        <- fatal on Swoole
 *     } elseif (is_object($request) && property_exists($request, 'server')) {
 *         // Swoole                                    <- unreachable
 *
 * Measured against the real extension (openswoole 26.2.0, PHP 8.3):
 * Swoole\Http\Request HAS getMethod() (added in Swoole 4.6) and has NO getUri().
 * So every real Swoole request matched the PSR-7 probe and died with "Call to
 * undefined method Swoole\Http\Request::getUri()", and the Swoole branch below
 * it was dead code.
 *
 * NO MOCKS. These build a genuine Swoole\Http\Request via the extension's own
 * create() + parse(), which is how Swoole itself materialises one from bytes.
 * A hand-rolled stand-in would be worse than useless here: the whole defect was
 * a wrong assumption about the real class's method list, so a double written
 * from the same assumption would have passed against the bug.
 */

use PHPUnit\Framework\TestCase;
use Tina4\App;

class AppInvokeSwooleTest extends TestCase
{
    private string $appDir = '';

    protected function setUp(): void
    {
        if (!extension_loaded('swoole') && !extension_loaded('openswoole')) {
            $this->markTestSkipped(
                'swoole/openswoole is not installed, so a real Swoole\Http\Request cannot be built'
            );
        }
        if (!class_exists('Swoole\Http\Request')) {
            $this->markTestSkipped('the swoole extension is loaded but exposes no Swoole\Http\Request');
        }

        $this->appDir = \TempPath::dir('tina4_swoole_');
        mkdir($this->appDir . '/src/routes', 0755, true);
        file_put_contents($this->appDir . '/src/routes/swoole.php', <<<'PHP'
<?php
\Tina4\Router::get("/hello", function ($request, $response) {
    return $response("hello " . ($request->query["who"] ?? "nobody"), 200);
});
\Tina4\Router::post("/echo", function ($request, $response) {
    return $response($request->body, 200);
});
PHP);
        file_put_contents($this->appDir . '/.env', "TINA4_DEBUG=false\nTINA4_OVERRIDE_CLIENT=true\n");
    }

    /** Build a real Swoole request from real bytes. */
    private function swooleRequest(string $raw): object
    {
        $class = 'Swoole\Http\Request';
        $request = $class::create();
        $request->parse($raw);
        return $request;
    }

    // ── THE INSTRUMENT ──────────────────────────────────────────────────────

    /**
     * If this ever fails, every other test in this file is measuring nothing.
     *
     * It asserts the exact shape that made the bug possible: the real class has
     * the method the old PSR-7 probe keyed on, and lacks the one that probe
     * then called. Should a future Swoole add getUri(), this fails loudly and
     * tells the next maintainer the discriminator needs rethinking, rather than
     * quietly passing for a new reason.
     */
    public function testTheRealSwooleRequestLooksLikePsr7ButIsNot(): void
    {
        $request = $this->swooleRequest("GET /hello HTTP/1.1\r\nHost: x\r\n\r\n");

        $this->assertTrue(
            method_exists($request, 'getMethod'),
            'Swoole\Http\Request no longer has getMethod() - the collision this file guards is gone'
        );
        $this->assertFalse(
            method_exists($request, 'getUri'),
            'Swoole\Http\Request now HAS getUri(); the PSR-7 discriminator in App::__invoke() '
            . 'must be re-derived, because getUri() no longer distinguishes the two shapes'
        );
        $this->assertTrue(property_exists($request, 'server'), 'the Swoole branch keys on $server');
        $this->assertTrue(method_exists($request, 'rawContent'), 'the Swoole branch keys on rawContent()');
    }

    // ── POSITIVE ────────────────────────────────────────────────────────────

    public function testASwooleGetRequestIsRoutedAndAnswered(): void
    {
        $app = new App($this->appDir);
        $request = $this->swooleRequest("GET /hello?who=swoole HTTP/1.1\r\nHost: x\r\n\r\n");

        $response = $app($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            'hello swoole',
            $response->getBody(),
            'the route did not receive the path and query from the Swoole request'
        );
    }

    public function testASwoolePostBodyReachesTheHandler(): void
    {
        $app = new App($this->appDir);
        $body = 'the-posted-body';
        $raw = "POST /echo HTTP/1.1\r\nHost: x\r\nContent-Type: text/plain\r\n"
             . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body;

        $response = $app($this->swooleRequest($raw));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($body, $response->getBody(), 'rawContent() did not reach the handler');
    }

    // ── NEGATIVE ────────────────────────────────────────────────────────────

    /**
     * The regression, stated as the thing that must not happen.
     *
     * With the old branch order this threw
     * Error: Call to undefined method Swoole\Http\Request::getUri().
     * Asserting on the ERROR CLASS AND MESSAGE rather than "no exception" is
     * deliberate: a test that only asserted 200 would also pass if the request
     * fell through to Request::fromGlobals() and matched by accident, which is
     * a different bug wearing the same green.
     */
    public function testASwooleRequestNeverTakesThePsr7Branch(): void
    {
        $app = new App($this->appDir);
        $request = $this->swooleRequest("GET /hello?who=swoole HTTP/1.1\r\nHost: x\r\n\r\n");

        try {
            $response = $app($request);
        } catch (\Throwable $e) {
            $this->fail(
                'App::__invoke() sent a Swoole request down the wrong branch: '
                . get_class($e) . ': ' . $e->getMessage()
            );
        }

        // Proof it was the SWOOLE branch, not a fromGlobals() coincidence: the
        // globals of a PHPUnit process carry no /hello and no ?who=swoole.
        $this->assertSame('hello swoole', $response->getBody());
        $this->assertArrayNotHasKey(
            'REQUEST_URI',
            array_flip(array_keys(array_filter($_SERVER, static fn($v) => $v === '/hello?who=swoole'))),
            'the request was resolved from globals rather than from the Swoole object'
        );
    }
}
