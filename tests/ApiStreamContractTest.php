<?php

use PHPUnit\Framework\TestCase;
use Tina4\Api;
use Tina4\ApiStreamError;
use Tina4\ApiStreamTimeoutError;

/**
 * Executable proof of api_stream_contract.json (ADR-0060). Every case reaches
 * a real local HTTP server over a real socket. No mocks. The fixture server
 * (tests/fixtures/api_stream_contract_server.php) owns its own listener via
 * stream_socket_server so it can flush per byte, chunk on demand, or drop a
 * connection mid-body - php -S buffers responses whole and cannot.
 */
final class ApiStreamContractTest extends TestCase
{
    private static ?TestServer $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = TestServer::startScript(__DIR__ . '/fixtures/api_stream_contract_server.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    private function api(): Api
    {
        return new Api(self::$server->base(), timeout: 10);
    }

    // ── stream-bytes-primitive ─────────────────────────────────────────

    public function testStreamBytesYieldsChunksInOrder(): void
    {
        $chunks = iterator_to_array($this->api()->streamBytes('/bytes/chunks'), false);
        self::assertNotEmpty($chunks, 'streamBytes must yield at least one chunk');
        self::assertSame('hello world!', implode('', $chunks));
    }

    public function testStreamBytesEndsOnEof(): void
    {
        $bytes = implode('', iterator_to_array($this->api()->streamBytes('/bytes/eof'), false));
        self::assertSame('hello', $bytes);
    }

    public function testStreamBytesRaisesOnTransportDrop(): void
    {
        $this->expectException(ApiStreamError::class);
        $received = '';
        foreach ($this->api()->streamBytes('/bytes/drop') as $chunk) {
            $received .= $chunk;
        }
        self::fail("drop must raise: received {$received}");
    }

    // ── stream-lines-newline-buffered ─────────────────────────────────

    public function testStreamLinesSplitsOnLf(): void
    {
        $lines = iterator_to_array($this->api()->streamLines('/lines/lf'), false);
        self::assertSame(['alpha', 'beta', 'gamma'], $lines);
    }

    public function testStreamLinesSplitsOnCrlf(): void
    {
        $lines = iterator_to_array($this->api()->streamLines('/lines/crlf'), false);
        self::assertSame(['alpha', 'beta', 'gamma'], $lines);
    }

    public function testStreamLinesYieldsTrailingLineWithoutNewline(): void
    {
        $lines = iterator_to_array($this->api()->streamLines('/lines/trailing'), false);
        self::assertSame(['alpha', 'beta', 'gamma'], $lines);
    }

    public function testStreamLinesMultibyteAcrossChunkBoundary(): void
    {
        $lines = iterator_to_array($this->api()->streamLines('/lines/multibyte'), false);
        self::assertSame(["hi \xE2\x82\xACllo", 'second'], $lines);
        // Prove the multibyte character survived intact.
        self::assertSame(1, preg_match('/\x{20AC}/u', $lines[0]));
    }

    // ── stream-sse-framing ────────────────────────────────────────────

    public function testStreamSseSingleEvent(): void
    {
        $events = iterator_to_array($this->api()->streamSse('/sse/single'), false);
        self::assertCount(1, $events);
        self::assertSame('hello', $events[0]['data']);
        self::assertNull($events[0]['event']);
        self::assertNull($events[0]['id']);
        self::assertNull($events[0]['retry']);
    }

    public function testStreamSseMultiLineDataConcatenated(): void
    {
        $events = iterator_to_array($this->api()->streamSse('/sse/multiline'), false);
        self::assertCount(1, $events);
        self::assertSame("line1\nline2", $events[0]['data']);
    }

    public function testStreamSseNamedEvent(): void
    {
        $events = iterator_to_array($this->api()->streamSse('/sse/named'), false);
        self::assertCount(1, $events);
        self::assertSame('greeting', $events[0]['event']);
        self::assertSame('hi', $events[0]['data']);
    }

    public function testStreamSseCommentIgnored(): void
    {
        $events = iterator_to_array($this->api()->streamSse('/sse/comment'), false);
        self::assertCount(1, $events);
        self::assertSame('after', $events[0]['data']);
    }

    public function testStreamSseBlankLineBoundary(): void
    {
        $events = iterator_to_array($this->api()->streamSse('/sse/boundary'), false);
        $datas = array_map(static fn (array $e): string => $e['data'], $events);
        self::assertSame(['a', 'b', 'c'], $datas);
    }

    public function testStreamSseDoneSentinelDelivered(): void
    {
        // [DONE] is delivered as an ORDINARY event; the primitive itself does
        // NOT stop the iterator - only transport EOF does. The AI client
        // (yieldOpenAiEvents) is what terminates ITS typed event stream on
        // seeing [DONE]. This matches the Python master's stream_sse contract.
        $events = iterator_to_array($this->api()->streamSse('/sse/done'), false);
        $datas = array_map(static fn (array $e): string => $e['data'], $events);
        self::assertContains('[DONE]', $datas, '[DONE] must be delivered as one of the events');
        self::assertSame('hello', $events[0]['data']);
        // The event AFTER [DONE] must be reachable - iterator does not stop
        // on the sentinel.
        self::assertContains('unreachable', $datas);
    }

    public function testStreamSseRetryFieldCaptured(): void
    {
        $events = iterator_to_array($this->api()->streamSse('/sse/retry'), false);
        self::assertCount(1, $events);
        self::assertSame(5000, $events[0]['retry']);
        self::assertSame('with-retry', $events[0]['data']);
    }

    // ── stream-timeouts-and-close ─────────────────────────────────────

    public function testStreamConnectTimeoutHonoured(): void
    {
        // Address the OS will drop rather than answer - RFC 5737 test-net-1
        // (192.0.2.0/24) is documented as never routed. A short connect-timeout
        // must expire well below the total timeout.
        $api = new Api('http://192.0.2.1', timeout: 30);
        $start = microtime(true);
        try {
            iterator_to_array($api->streamBytes('/nope', ['connect_timeout' => 0.25, 'timeout' => 5]), false);
            self::fail('unreachable host must trigger a connect timeout');
        } catch (ApiStreamTimeoutError $e) {
            self::assertStringContainsString('connection', $e->getMessage());
        } catch (ApiStreamError $e) {
            // Some OS/kernel combos return "network unreachable" instantly rather
            // than blocking; that is still a transport failure and satisfies the
            // "no unbounded hang" invariant.
            self::assertTrue(true);
        }
        self::assertLessThan(2.0, microtime(true) - $start);
    }

    public function testStreamTotalTimeoutHonoured(): void
    {
        $api = new Api(self::$server->base(), timeout: 10);
        $start = microtime(true);
        try {
            iterator_to_array($api->streamBytes('/slow-body', ['timeout' => 0.2, 'connect_timeout' => 1.0]), false);
            self::fail('slow body must trigger the total timeout');
        } catch (ApiStreamTimeoutError $e) {
            self::assertStringContainsString('total', $e->getMessage());
        }
        self::assertLessThan(1.5, microtime(true) - $start);
    }

    public function testStreamEarlyCloseReleasesSocket(): void
    {
        // Take the first two events from a long stream, then break out of the
        // loop. The generator's finally block MUST close the socket - proven
        // here by asserting the process moves past the break within milliseconds
        // instead of hanging until the server is done sending 1000 events (~40s).
        $api = new Api(self::$server->base(), timeout: 60);
        $count = 0;
        $start = microtime(true);
        foreach ($api->streamSse('/never-ending', ['timeout' => 60]) as $event) {
            $count++;
            if ($count >= 2) {
                break;
            }
        }
        $elapsed = microtime(true) - $start;
        self::assertGreaterThanOrEqual(2, $count);
        self::assertLessThan(2.0, $elapsed, 'early break must release the socket promptly');
    }

    // ── stream-shared-with-ai-chat ────────────────────────────────────

    public function testAiChatUsesApiStreamSseUnderTheHood(): void
    {
        // Prove the AI stream implementation reads back the same primitive by
        // scanning the source - there is one framer, not two.
        $aiSource = (string)file_get_contents(__DIR__ . '/../Tina4/AI.php');
        self::assertStringContainsString('streamSse', $aiSource, 'Ai must call Api::streamSse');
        // And prove the old duplicated framer is gone.
        self::assertStringNotContainsString('function streamData(', $aiSource, 'AI must not carry its own SSE framer');
    }
}
