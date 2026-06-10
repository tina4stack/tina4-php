<?php

/**
 * Tina4 PHP — v3.13.7 router error handling (DevProx brief PLATFORM-2159).
 *
 * Two contracts:
 *
 *   1. ``tina4.request.error`` event fires before the 500 is rendered, so
 *      observability consumers see route failures even though the
 *      framework caught them. Payload is a single assoc array
 *      ``['exception' => $e, 'request' => $request]``.
 *
 *   2. In non-debug mode the 500 response body never contains a stack
 *      trace (CWE-209 / information disclosure). Debug mode keeps the
 *      ErrorOverlay path unchanged.
 *
 *   3. Listener exceptions MUST NOT break the 500 render. A broken
 *      listener gets logged via ``Log::warning`` and the framework
 *      proceeds.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Events;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

class RouterErrorEventTest extends TestCase
{
    private string|false $savedDebug;

    protected function setUp(): void
    {
        Router::clear();
        Events::clear();
        $this->savedDebug = getenv('TINA4_DEBUG');
        putenv('TINA4_DEBUG=false');
        unset($_ENV['TINA4_DEBUG']);
        \Tina4\DotEnv::resetEnv();
    }

    protected function tearDown(): void
    {
        Router::clear();
        Events::clear();
        if ($this->savedDebug !== false) {
            putenv("TINA4_DEBUG={$this->savedDebug}");
        } else {
            putenv('TINA4_DEBUG');
        }
    }

    private function dispatch500(string $path = '/api/widgets'): Response
    {
        Router::get($path, function () {
            throw new \RuntimeException('simulated explosion inside route');
        });
        $request = Request::create(method: 'GET', path: $path);
        $response = new Response(testing: true);
        return Router::dispatch($request, $response);
    }

    // ── Contract 1: event fires with the right payload ──────────────

    public function testEventFiresWithExceptionAndRequestPayload(): void
    {
        $captured = [];
        Events::on('tina4.request.error', function ($payload) use (&$captured) {
            $captured[] = $payload;
        });

        $this->dispatch500();

        $this->assertCount(1, $captured, 'listener fires exactly once');
        $payload = $captured[0];
        $this->assertInstanceOf(\Throwable::class, $payload['exception']);
        $this->assertInstanceOf(Request::class, $payload['request']);
        $this->assertSame(
            'simulated explosion inside route',
            $payload['exception']->getMessage()
        );
    }

    public function testEventFiresEvenWhenNoListenerCares(): void
    {
        // Sanity: with no listener registered, dispatch still produces
        // a 500 and doesn't crash on the missing event.
        $response = $this->dispatch500();
        $this->assertSame(500, $response->getStatusCode());
    }

    public function testMultipleListenersAllFireInPriorityOrder(): void
    {
        $calls = [];
        Events::on('tina4.request.error', function () use (&$calls) {
            $calls[] = 'first';
        }, priority: 10);
        Events::on('tina4.request.error', function () use (&$calls) {
            $calls[] = 'second';
        }, priority: 0);

        $this->dispatch500();
        $this->assertSame(['first', 'second'], $calls);
    }

    // ── Contract 2: no stack trace in production body (CWE-209) ─────

    public function testNoTracebackMarkersInProductionBody(): void
    {
        $response = $this->dispatch500('/api/leak-test');
        $body = $response->getBody();

        $forbidden = [
            '#0 ',                                            // PHP trace frame
            'getTraceAsString',                                // accidental method name
            'simulated explosion inside route',                // raw exception message
            'RuntimeException',                                // class chain
            'tina4-php/Tina4/Router.php',                       // absolute paths
        ];
        foreach ($forbidden as $marker) {
            $this->assertStringNotContainsString(
                $marker,
                $body,
                "CWE-209 regression: production body leaks '{$marker}'"
            );
        }
    }

    public function testProductionBodyStillShowsRequestId(): void
    {
        $response = $this->dispatch500();
        $body = $response->getBody();

        // The error page should still surface a 12-char request id so
        // support can correlate to logs.
        $this->assertMatchesRegularExpression(
            '/[a-f0-9]{12}/',
            $body,
            'request_id still surfaced in 500 page'
        );
        $this->assertSame(500, $response->getStatusCode());
    }

    // ── Contract 3: listener errors don't break the 500 render ──────

    public function testListenerThatThrowsDoesNotBreak500(): void
    {
        Events::on('tina4.request.error', function () {
            throw new \RuntimeException('listener is broken');
        });

        // If the exception propagated out of the catch, dispatch would
        // either crash or return a different status.
        $response = $this->dispatch500();
        $this->assertSame(500, $response->getStatusCode());
    }
}
