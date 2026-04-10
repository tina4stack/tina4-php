<?php

/**
 * Tests for Server cross-framework parity: handle(), start(), stop().
 */

use PHPUnit\Framework\TestCase;
use Tina4\Server;
use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

class ServerParityTest extends TestCase
{
    public function testHandleReturnsResponse(): void
    {
        // Register a simple route
        Router::get('/test-handle-parity', function (Request $request, Response $response) {
            return $response->json(['ok' => true]);
        });

        $server = new Server();
        $request = new Request(
            method: 'GET',
            path: '/test-handle-parity',
            query: [],
            body: null,
            headers: [],
        );

        $response = $server->handle($request);
        $this->assertInstanceOf(Response::class, $response);
    }

    public function testStartMethodExists(): void
    {
        $server = new Server();
        $this->assertTrue(method_exists($server, 'start'));
    }

    public function testStopMethodExists(): void
    {
        $server = new Server();
        $this->assertTrue(method_exists($server, 'stop'));
    }

    public function testHandleMethodExists(): void
    {
        $server = new Server();
        $this->assertTrue(method_exists($server, 'handle'));
    }
}
