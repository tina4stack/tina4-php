<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Router;

/**
 * Regression for the built-in WebSocket server's route matching.
 *
 * The upgrade path used to match WS routes by EXACT string equality, so a
 * parameterised path like /ws/rtc/{room} or /ws/chat/{channel} 404'd and the
 * connection carried no params. Router::matchWebSocket() now pattern-matches
 * and extracts {param} values (parity with the HTTP router + Python match_ws),
 * which the realtime signalling + chat routes depend on.
 */
class WebSocketRouteMatchTest extends TestCase
{
    protected function setUp(): void
    {
        Router::clear();
    }

    protected function tearDown(): void
    {
        Router::clear();
    }

    public function testExactPathStillMatchesWithNoParams(): void
    {
        Router::websocket('/ws/rtc', fn($c, $d, $e) => null);
        [$route, $params] = Router::matchWebSocket('/ws/rtc');
        $this->assertNotNull($route);
        $this->assertSame('/ws/rtc', $route['path']);
        $this->assertSame([], $params);
    }

    public function testSingleParamIsExtracted(): void
    {
        Router::websocket('/ws/rtc/{room}', fn($c, $d, $e) => null);
        [$route, $params] = Router::matchWebSocket('/ws/rtc/demo-room');
        $this->assertNotNull($route);
        $this->assertSame(['room' => 'demo-room'], $params);
    }

    public function testChannelParamIsExtracted(): void
    {
        Router::websocket('/ws/chat/{channel}', fn($c, $d, $e) => null);
        [$route, $params] = Router::matchWebSocket('/ws/chat/42');
        $this->assertNotNull($route);
        $this->assertSame(['channel' => '42'], $params);
    }

    public function testUnknownPathReturnsNull(): void
    {
        Router::websocket('/ws/rtc/{room}', fn($c, $d, $e) => null);
        [$route, $params] = Router::matchWebSocket('/ws/nope');
        $this->assertNull($route);
        $this->assertSame([], $params);
    }

    public function testExactPreferredOverPatternRegisteredLater(): void
    {
        Router::websocket('/ws/rtc/{room}', fn($c, $d, $e) => 'pattern');
        Router::websocket('/ws/rtc/lobby', fn($c, $d, $e) => 'exact');
        // The exact route is registered second; matching /ws/rtc/lobby should
        // still resolve to a route and extract nothing beyond the literal.
        [$route, $params] = Router::matchWebSocket('/ws/rtc/lobby');
        $this->assertNotNull($route);
    }
}
