<?php

use PHPUnit\Framework\TestCase;
use Tina4\AI;
use Tina4\AIConfigError;
use Tina4\AIHTTPError;
use Tina4\AIParseError;
use Tina4\AITimeoutError;
use Tina4\ChatResponse;

final class AIClientContractTest extends TestCase
{
    private static ?TestServer $server = null;
    private static ?TestServer $stallServer = null;
    private static string $stateFile;

    public static function setUpBeforeClass(): void
    {
        self::$stateFile = TempPath::file('ai-contract-state-', '.json');
        file_put_contents(self::$stateFile, json_encode(['requests' => [], 'counts' => []]));
        self::$server = TestServer::start(
            __DIR__ . '/fixtures/ai_client_contract_server.php',
            ['AI_CONTRACT_STATE' => self::$stateFile]
        );
        self::$stallServer = TestServer::startScript(__DIR__ . '/fixtures/ai_stall_server.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$stallServer?->stop();
        self::$server = null;
        self::$stallServer = null;
    }

    protected function setUp(): void
    {
        foreach (['TINA4_AI_PROVIDER', 'TINA4_AI_URL', 'TINA4_EMBED_URL', 'TINA4_AI_KEY'] as $key) {
            putenv($key);
        }
        putenv('TINA4_AI_MODEL=env-model');
        putenv('TINA4_AI_TIMEOUT=2');
        putenv('TINA4_AI_CONNECT_TIMEOUT=1');
        putenv('TINA4_AI_MAX_RETRIES=0');
        file_put_contents(self::$stateFile, json_encode(['requests' => [], 'counts' => []]));
    }

    private function base(string $path): string
    {
        return self::$server->base() . $path;
    }

    private function state(): array
    {
        return json_decode((string)file_get_contents(self::$stateFile), true);
    }

    public function testAiPublicSurface(): void
    {
        putenv('TINA4_AI_URL=' . $this->base('/openai'));
        $this->assertInstanceOf(ChatResponse::class, AI::chat([['role' => 'user', 'content' => 'hello']]));
        $this->assertSame('hello world', AI::complete('hello'));
        putenv('TINA4_EMBED_URL=' . $this->base('/embeddings'));
        $this->assertSame([0.0, 0.25, 0.5], AI::embed('hello'));
        $this->assertFalse(method_exists(AI::class, 'ask'));
        $this->assertFalse(method_exists(AI::class, 'askJson'));
        $this->assertFalse(method_exists(AI::class, 'vision'));
        $this->assertFalse(method_exists(AI::class, 'image'));
    }

    public function testAiChatResponseNormalized(): void
    {
        putenv('TINA4_AI_URL=' . $this->base('/openai'));
        $result = AI::chat([['role' => 'user', 'content' => 'hello']], model: 'call-model');
        $this->assertSame('hello world', $result->text);
        $this->assertSame('call-model', $result->model);
        $this->assertSame(['prompt_tokens' => 3, 'completion_tokens' => 2, 'total_tokens' => 5], $result->usage);
        $this->assertSame('stop', $result->finish_reason);

        putenv('TINA4_AI_PROVIDER=anthropic');
        putenv('TINA4_AI_KEY=hosted-key');
        putenv('TINA4_AI_URL=' . $this->base('/anthropic'));
        $result = AI::chat([['role' => 'user', 'content' => 'hello']]);
        $this->assertSame('hello world', $result->text);
        $this->assertSame(5, $result->usage['total_tokens']);
        $this->assertSame('end_turn', $result->finish_reason);
    }

    public function testAiCompleteIsSingleTurnText(): void
    {
        putenv('TINA4_AI_URL=' . $this->base('/openai'));
        $this->assertSame('hello world', AI::complete('only this'));
        $this->assertSame([['role' => 'user', 'content' => 'only this']], $this->state()['requests'][0]['body']['messages']);
    }

    public function testAiEmbeddingCardinality(): void
    {
        putenv('TINA4_EMBED_URL=' . $this->base('/embeddings'));
        $this->assertSame([0.0, 0.25, 0.5], AI::embed('one'));
        $this->assertSame([[0.0, 0.25, 0.5], [1.0, 0.25, 0.5]], AI::embed(['one', 'two']));
    }

    // ── ADR-0060: typed AiEvent streaming ────────────────────────────

    public function testAiStreamTextDeltasOrder(): void
    {
        putenv('TINA4_AI_URL=' . $this->base('/stream-openai'));
        $events = iterator_to_array(AI::chat([['role' => 'user', 'content' => 'hello']], stream: true), false);
        $this->assertSame('text_delta', $events[0]['type']);
        $this->assertSame('hello ', $events[0]['text']);
        $this->assertSame('text_delta', $events[1]['type']);
        $this->assertSame('world', $events[1]['text']);
        $this->assertSame('done', $events[2]['type']);
        $this->assertSame('stop', $events[2]['finish_reason']);
        $this->assertCount(3, $events);

        putenv('TINA4_AI_PROVIDER=anthropic');
        putenv('TINA4_AI_KEY=hosted-key');
        putenv('TINA4_AI_URL=' . $this->base('/stream-anthropic'));
        $events = iterator_to_array(AI::chat([['role' => 'user', 'content' => 'hello']], stream: true), false);
        $this->assertSame('text_delta', $events[0]['type']);
        $this->assertSame('hello ', $events[0]['text']);
        $this->assertSame('text_delta', $events[1]['type']);
        $this->assertSame('world', $events[1]['text']);
        $this->assertSame('done', $events[2]['type']);
        $this->assertSame('end_turn', $events[2]['finish_reason']);
        $this->assertSame(3, $events[2]['usage']['prompt_tokens']);
        $this->assertSame(2, $events[2]['usage']['completion_tokens']);
    }

    public function testAiStreamToolCallAggregatedOpenai(): void
    {
        putenv('TINA4_AI_URL=' . $this->base('/stream-openai-tool'));
        $events = iterator_to_array(AI::chat([['role' => 'user', 'content' => 'weather?']], stream: true), false);
        $toolCalls = array_values(array_filter($events, static fn (array $e): bool => $e['type'] === 'tool_call'));
        $this->assertCount(1, $toolCalls, 'fragments must aggregate into ONE tool_call event');
        $this->assertSame('call_wx', $toolCalls[0]['id']);
        $this->assertSame('get_weather', $toolCalls[0]['name']);
        $this->assertSame(['location' => 'Cape Town'], $toolCalls[0]['args']);
        // done still fires
        $done = end($events);
        $this->assertSame('done', $done['type']);
        $this->assertSame('tool_calls', $done['finish_reason']);
    }

    public function testAiStreamToolCallAggregatedAnthropic(): void
    {
        putenv('TINA4_AI_PROVIDER=anthropic');
        putenv('TINA4_AI_KEY=hosted-key');
        putenv('TINA4_AI_URL=' . $this->base('/stream-anthropic-tool'));
        $events = iterator_to_array(AI::chat([['role' => 'user', 'content' => 'weather?']], stream: true), false);
        $toolCalls = array_values(array_filter($events, static fn (array $e): bool => $e['type'] === 'tool_call'));
        $this->assertCount(1, $toolCalls);
        $this->assertSame('toolu_1', $toolCalls[0]['id']);
        $this->assertSame('get_weather', $toolCalls[0]['name']);
        $this->assertSame(['location' => 'Cape Town'], $toolCalls[0]['args']);
        $done = end($events);
        $this->assertSame('done', $done['type']);
        $this->assertSame('tool_use', $done['finish_reason']);
    }

    public function testAiStreamDoneFiresOnce(): void
    {
        putenv('TINA4_AI_URL=' . $this->base('/stream-openai'));
        $events = iterator_to_array(AI::chat([['role' => 'user', 'content' => 'hello']], stream: true), false);
        $dones = array_values(array_filter($events, static fn (array $e): bool => $e['type'] === 'done'));
        $this->assertCount(1, $dones);
        // done must be the LAST event
        $this->assertSame('done', end($events)['type']);
    }

    public function testAiStreamErrorInsteadOfDoneOnMidstreamFailure(): void
    {
        putenv('TINA4_AI_MAX_RETRIES=1');
        putenv('TINA4_AI_URL=' . $this->base('/stream-partial'));
        $events = iterator_to_array(AI::chat([['role' => 'user', 'content' => 'hello']], stream: true), false);
        $this->assertSame('text_delta', $events[0]['type']);
        $this->assertSame('first', $events[0]['text']);
        $last = end($events);
        $this->assertSame('error', $last['type']);
        // No done fired
        $this->assertSame(0, count(array_filter($events, static fn (array $e): bool => $e['type'] === 'done')));
    }

    public function testAiStreamNoRetryAfterFirstEvent(): void
    {
        putenv('TINA4_AI_MAX_RETRIES=1');
        putenv('TINA4_AI_URL=' . $this->base('/stream-partial'));
        iterator_to_array(AI::chat([['role' => 'user', 'content' => 'hello']], stream: true), false);
        $this->assertSame(1, $this->state()['counts']['/stream-partial'], 'no retry may happen after the first event was yielded');
    }

    // ── ADR-0060: multimodal content parts ────────────────────────────

    public function testAiMultimodalTextPart(): void
    {
        putenv('TINA4_AI_URL=' . $this->base('/multimodal-echo'));
        $result = AI::chat([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'describe this']]]]);
        $this->assertSame('ok', $result->text);
        $body = $this->state()['requests'][0]['body'];
        $this->assertSame([['type' => 'text', 'text' => 'describe this']], $body['messages'][0]['content']);
    }

    public function testAiMultimodalImageDataUri(): void
    {
        putenv('TINA4_AI_URL=' . $this->base('/multimodal-echo'));
        $dataUri = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
        AI::chat([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'look'], ['type' => 'image', 'source' => $dataUri]]]]);
        $body = $this->state()['requests'][0]['body'];
        $parts = $body['messages'][0]['content'];
        $this->assertSame('text', $parts[0]['type']);
        $this->assertSame('image_url', $parts[1]['type']);
        $this->assertSame($dataUri, $parts[1]['image_url']['url']);
    }

    public function testAiMultimodalImageUrl(): void
    {
        putenv('TINA4_AI_URL=' . $this->base('/multimodal-echo'));
        AI::chat([['role' => 'user', 'content' => [['type' => 'image', 'source' => 'https://example.com/pic.png']]]]);
        $body = $this->state()['requests'][0]['body'];
        $this->assertSame('image_url', $body['messages'][0]['content'][0]['type']);
        $this->assertSame('https://example.com/pic.png', $body['messages'][0]['content'][0]['image_url']['url']);
    }

    public function testAiMultimodalMalformedPartFailsConfig(): void
    {
        putenv('TINA4_AI_URL=' . $this->base('/multimodal-echo'));
        $bads = [
            [['role' => 'user', 'content' => [['type' => 'image']]]],            // no source
            [['role' => 'user', 'content' => [['type' => 'text']]]],             // no text
            [['role' => 'user', 'content' => [['type' => 'audio', 'src' => 'x']]]], // unknown type
            [['role' => 'user', 'content' => [['type' => 'image', 'source' => 'file:///etc/passwd']]]], // bad URL scheme
            [['role' => 'user', 'content' => []]],                              // empty parts list
            [['role' => 'user', 'content' => [['type' => 'text', 'text' => 123]]]], // non-string text
        ];
        foreach ($bads as $case) {
            try {
                AI::chat($case);
                $this->fail('malformed part must raise AIConfigError: ' . json_encode($case));
            } catch (AIConfigError) {
                // pass
            }
        }
        // No request may have been sent for any of them
        $this->assertSame([], $this->state()['requests'] ?? []);
    }

    public function testAiMultimodalOpenaiBodyShape(): void
    {
        putenv('TINA4_AI_URL=' . $this->base('/multimodal-echo'));
        $dataUri = 'data:image/jpeg;base64,AAAA';
        AI::chat([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi'], ['type' => 'image', 'source' => $dataUri]]]]);
        $body = $this->state()['requests'][0]['body'];
        $expected = [
            ['type' => 'text', 'text' => 'hi'],
            ['type' => 'image_url', 'image_url' => ['url' => $dataUri]],
        ];
        $this->assertSame($expected, $body['messages'][0]['content']);
    }

    public function testAiMultimodalAnthropicBodyShape(): void
    {
        putenv('TINA4_AI_PROVIDER=anthropic');
        putenv('TINA4_AI_KEY=hosted-key');
        putenv('TINA4_AI_URL=' . $this->base('/anthropic'));
        $dataUri = 'data:image/png;base64,iVBORw0KGgo=';
        AI::chat([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'read'], ['type' => 'image', 'source' => $dataUri]]]]);
        $body = $this->state()['requests'][0]['body'];
        $content = $body['messages'][0]['content'];
        $this->assertSame('text', $content[0]['type']);
        $this->assertSame('read', $content[0]['text']);
        $this->assertSame('image', $content[1]['type']);
        $this->assertSame('base64', $content[1]['source']['type']);
        $this->assertSame('image/png', $content[1]['source']['media_type']);
        $this->assertSame('iVBORw0KGgo=', $content[1]['source']['data']);

        // https URL variant uses source.type = 'url'
        file_put_contents(self::$stateFile, json_encode(['requests' => [], 'counts' => []]));
        AI::chat([['role' => 'user', 'content' => [['type' => 'image', 'source' => 'https://example.com/x.png']]]]);
        $content = $this->state()['requests'][0]['body']['messages'][0]['content'];
        $this->assertSame('image', $content[0]['type']);
        $this->assertSame('url', $content[0]['source']['type']);
        $this->assertSame('https://example.com/x.png', $content[0]['source']['url']);
    }

    public function testAiConfigurationPrecedence(): void
    {
        putenv('TINA4_AI_URL=' . $this->base('/openai'));
        AI::chat([['role' => 'user', 'content' => 'hello']], model: 'call-model', temperature: 0.2, maxTokens: 9);
        $body = $this->state()['requests'][0]['body'];
        $this->assertSame('call-model', $body['model']);
        $this->assertSame(0.2, $body['temperature']);
        $this->assertSame(9, $body['max_tokens']);
    }

    public function testAiHostedKeyFailsClosedAndRedacted(): void
    {
        putenv('TINA4_AI_PROVIDER=openai');
        putenv('TINA4_AI_URL=' . $this->base('/openai'));
        try {
            AI::chat([['role' => 'user', 'content' => 'private prompt']]);
            $this->fail('missing key must fail');
        } catch (AIConfigError) {
            $this->assertSame([], $this->state()['requests']);
        }
        putenv('TINA4_AI_KEY=super-secret-key');
        putenv('TINA4_AI_URL=' . $this->base('/always500'));
        try {
            AI::chat([['role' => 'user', 'content' => 'private prompt']]);
            $this->fail('500 must fail');
        } catch (AIHTTPError $error) {
            $this->assertStringNotContainsString('super-secret-key', $error->getMessage());
            $this->assertStringNotContainsString('private prompt', $error->getMessage());
            $this->assertStringNotContainsString('provider-secret-body', $error->getMessage());
        }
    }

    public function testAiRetriesOnlySafeTransients(): void
    {
        putenv('TINA4_AI_MAX_RETRIES=1');
        putenv('TINA4_AI_URL=' . $this->base('/retry'));
        $this->assertSame('recovered', AI::complete('hello'));
        putenv('TINA4_AI_URL=' . $this->base('/bad400'));
        try {
            AI::complete('hello');
            $this->fail('400 must fail');
        } catch (AIHTTPError) {
            $state = $this->state();
            $this->assertSame(2, $state['counts']['/retry']);
            $this->assertSame(1, $state['counts']['/bad400']);
        }
    }

    public function testAiTimeoutsAreDistinctAndBounded(): void
    {
        putenv('TINA4_AI_URL=' . $this->base('/slow'));
        $start = microtime(true);
        try {
            AI::chat([['role' => 'user', 'content' => 'hello']], timeout: 0.05);
            $this->fail('slow response must timeout');
        } catch (AITimeoutError $error) {
            $this->assertLessThan(0.5, microtime(true) - $start);
            $this->assertStringContainsString('total', $error->getMessage());
        }
        putenv('TINA4_AI_URL=https://127.0.0.1:' . self::$stallServer->port . '/stall');
        putenv('TINA4_AI_CONNECT_TIMEOUT=0.05');
        $start = microtime(true);
        try {
            AI::chat([['role' => 'user', 'content' => 'hello']], timeout: 1);
            $this->fail('stalled TLS connection must timeout');
        } catch (AITimeoutError $error) {
            $this->assertLessThan(0.5, microtime(true) - $start);
            $this->assertStringContainsString('connection', $error->getMessage());
        }
        $this->expectException(AIConfigError::class);
        AI::chat([['role' => 'user', 'content' => 'hello']], timeout: 0);
    }

    public function testAiZeroRuntimeDependenciesRealSocket(): void
    {
        putenv('TINA4_AI_URL=' . $this->base('/malformed'));
        $this->expectException(AIParseError::class);
        AI::chat([['role' => 'user', 'content' => 'hello']]);
    }
}
