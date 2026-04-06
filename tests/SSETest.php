<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Tests for Response::stream() — Server-Sent Events (SSE) support.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Response;

class SSETest extends TestCase
{
    // ── Content-Type ────────────────────────────────────────────

    public function testStreamSetsEventStreamContentType(): void
    {
        $response = new Response(testing: true);
        $response->stream(function () {
            yield "data: hello\n\n";
        });

        $this->assertSame('text/event-stream', $response->getHeader('Content-Type'));
    }

    public function testStreamCustomContentType(): void
    {
        $response = new Response(testing: true);
        $response->stream(function () {
            yield "chunk";
        }, 'application/octet-stream');

        $this->assertSame('application/octet-stream', $response->getHeader('Content-Type'));
    }

    // ── Body collection in testing mode ─────────────────────────

    public function testStreamCollectsChunksIntoBody(): void
    {
        $response = new Response(testing: true);
        $response->stream(function () {
            yield "data: message 0\n\n";
            yield "data: message 1\n\n";
            yield "data: message 2\n\n";
        });

        $expected = "data: message 0\n\ndata: message 1\n\ndata: message 2\n\n";
        $this->assertSame($expected, $response->getBody());
    }

    public function testStreamSingleChunk(): void
    {
        $response = new Response(testing: true);
        $response->stream(function () {
            yield "data: hello\n\n";
        });

        $this->assertSame("data: hello\n\n", $response->getBody());
    }

    public function testStreamEmptyGenerator(): void
    {
        $response = new Response(testing: true);
        $response->stream(function () {
            return;
            yield; // Unreachable but makes it a generator
        });

        $this->assertSame('', $response->getBody());
    }

    // ── isSent flag ─────────────────────────────────────────────

    public function testStreamMarksSent(): void
    {
        $response = new Response(testing: true);
        $response->stream(function () {
            yield "data: hello\n\n";
        });

        $this->assertTrue($response->isSent());
    }

    // ── Fluent interface ────────────────────────────────────────

    public function testStreamReturnsSelf(): void
    {
        $response = new Response(testing: true);
        $result = $response->stream(function () {
            yield "data: hello\n\n";
        });

        $this->assertSame($response, $result);
    }

    // ── Multiple chunks with different content ──────────────────

    public function testStreamSSEFormattedMessages(): void
    {
        $response = new Response(testing: true);
        $response->stream(function () {
            for ($i = 0; $i < 5; $i++) {
                yield "event: update\ndata: {\"count\":{$i}}\n\n";
            }
        });

        $body = $response->getBody();
        $this->assertStringContainsString('event: update', $body);
        $this->assertStringContainsString('"count":0', $body);
        $this->assertStringContainsString('"count":4', $body);
    }

    // ── Default status code ─────────────────────────────────────

    public function testStreamDefaultStatusCode(): void
    {
        $response = new Response(testing: true);
        $response->stream(function () {
            yield "data: hello\n\n";
        });

        $this->assertSame(200, $response->getStatusCode());
    }

    // ── Content type for non-SSE streams ────────────────────────

    public function testStreamWithNdjsonContentType(): void
    {
        $response = new Response(testing: true);
        $response->stream(function () {
            yield "{\"event\":\"start\"}\n";
            yield "{\"event\":\"end\"}\n";
        }, 'application/x-ndjson');

        $this->assertSame('application/x-ndjson', $response->getHeader('Content-Type'));
        $body = $response->getBody();
        $this->assertStringContainsString('"event":"start"', $body);
        $this->assertStringContainsString('"event":"end"', $body);
    }
}
