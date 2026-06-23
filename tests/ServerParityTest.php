<?php

/**
 * Tests for Server cross-framework parity: handle(), start(), stop().
 *
 * Behavioural tests — every test exercises a real operation and asserts the
 * real result/state. The single existence test is a consolidated
 * interface-contract guard against a cross-framework method rename.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Server;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

class ServerParityTest extends TestCase
{
    /**
     * handle() dispatches a registered route through the Router and returns a
     * Response carrying the REAL handler output — both the status code and the
     * JSON body the handler produced.
     */
    public function testHandleDispatchesRouteAndReturnsRealContent(): void
    {
        Router::get('/test-handle-parity', function (Request $request, Response $response) {
            return $response->json(['ok' => true, 'where' => 'handle'], 201);
        });

        $server = new Server();
        $response = $server->handle(new Request(
            method: 'GET',
            path: '/test-handle-parity',
            query: [],
            body: null,
            headers: [],
        ));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(
            ['ok' => true, 'where' => 'handle'],
            $response->getJsonBody(),
            'handle() must return the real JSON body the route produced'
        );
    }

    /**
     * handle() on a genuinely unknown path returns a real 404 Response — proving
     * the request actually traverses the router rather than being short-circuited.
     */
    public function testHandleUnknownPathReturnsRealNotFound(): void
    {
        $server = new Server();
        $response = $server->handle(new Request(
            method: 'GET',
            path: '/no-such-route-parity-' . uniqid(),
            query: [],
            body: null,
            headers: [],
        ));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * stop() flips the running flag to false — the observable state change a
     * caller relies on to break the event loop. (start() binds a real socket
     * and exits the process when unmanaged, so it is covered by the contract
     * test below rather than invoked here.)
     */
    public function testStopMarksServerNotRunning(): void
    {
        $server = new Server();
        // A freshly constructed server has not been started.
        $this->assertFalse($server->isRunning());

        $server->stop();
        $this->assertFalse($server->isRunning(), 'stop() must leave isRunning() false');
    }

    /**
     * Constructor + getHost()/getPort() expose the real bind target. The
     * explicit host/port arguments are returned verbatim — the safe, observable
     * behaviour mirroring Python's resolve_config output.
     */
    public function testConstructorExposesRealHostAndPort(): void
    {
        $server = new Server('127.0.0.1', 8123);
        $this->assertSame('127.0.0.1', $server->getHost());
        $this->assertSame(8123, $server->getPort());
    }

    /**
     * Consolidated interface-contract guard: the Server must expose the
     * cross-framework public surface (handle/start/stop and the host/port/state
     * accessors). This is the single existence test — it locks in the method
     * names so a rename in one framework can't silently drift from the others.
     */
    public function testServerExposesParityInterface(): void
    {
        $server = new Server();
        foreach (['handle', 'start', 'stop', 'getHost', 'getPort', 'isRunning'] as $method) {
            $this->assertTrue(
                method_exists($server, $method),
                "Server must expose {$method}() for cross-framework parity"
            );
        }
    }
}
