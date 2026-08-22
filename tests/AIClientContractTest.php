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

    // -- ADR-0061: outbound tool declarations ----------------------------

    /** @return array{name:string,description:string,parameters:array} */
    private function sampleTool(): array
    {
        return [
            'name' => 'get_weather',
            'description' => 'Look up the current weather in a city',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'city' => ['type' => 'string', 'description' => 'city name'],
                    'units' => ['type' => 'string', 'enum' => ['c', 'f'], 'default' => 'c'],
                ],
                'required' => ['city'],
                'additionalProperties' => false,
            ],
        ];
    }

    public function testAiToolsOpenaiBodyShape(): void
    {
        putenv('TINA4_AI_URL=' . $this->base('/multimodal-echo'));
        AI::chat(
            [['role' => 'user', 'content' => 'weather?']],
            tools: [$this->sampleTool()],
        );
        $body = $this->state()['requests'][0]['body'];
        $this->assertArrayHasKey('tools', $body);
        $this->assertCount(1, $body['tools']);
        $this->assertSame('function', $body['tools'][0]['type']);
        $this->assertSame('get_weather', $body['tools'][0]['function']['name']);
        $this->assertSame('Look up the current weather in a city', $body['tools'][0]['function']['description']);
        $this->assertSame($this->sampleTool()['parameters'], $body['tools'][0]['function']['parameters']);
    }

    public function testAiToolsAnthropicBodyShape(): void
    {
        putenv('TINA4_AI_PROVIDER=anthropic');
        putenv('TINA4_AI_KEY=hosted-key');
        putenv('TINA4_AI_URL=' . $this->base('/anthropic-echo'));
        AI::chat(
            [['role' => 'user', 'content' => 'weather?']],
            tools: [$this->sampleTool()],
        );
        $body = $this->state()['requests'][0]['body'];
        $this->assertArrayHasKey('tools', $body);
        $this->assertCount(1, $body['tools']);
        $tool = $body['tools'][0];
        $this->assertSame('get_weather', $tool['name']);
        $this->assertSame('Look up the current weather in a city', $tool['description']);
        $this->assertArrayHasKey('input_schema', $tool);
        $this->assertArrayNotHasKey('type', $tool);
        $this->assertArrayNotHasKey('parameters', $tool);
        $this->assertSame($this->sampleTool()['parameters'], $tool['input_schema']);
    }

    public function testAiToolsParametersPassthroughJsonschema(): void
    {
        putenv('TINA4_AI_URL=' . $this->base('/multimodal-echo'));
        $rich = [
            'name' => 'search',
            'description' => 'complex JSONSchema',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'q' => ['type' => 'string', 'minLength' => 1],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                    'filters' => [
                        'type' => 'array',
                        'items' => ['type' => 'object', 'properties' => ['k' => ['type' => 'string']]],
                    ],
                ],
                'required' => ['q'],
                '$defs' => ['NonEmpty' => ['type' => 'string', 'minLength' => 1]],
            ],
        ];
        AI::chat([['role' => 'user', 'content' => 'q']], tools: [$rich]);
        $body = $this->state()['requests'][0]['body'];
        $this->assertSame($rich['parameters'], $body['tools'][0]['function']['parameters']);
    }

    // -- ADR-0061: tool_choice mode translation --------------------------

    public function testAiToolChoiceAuto(): void
    {
        putenv('TINA4_AI_URL=' . $this->base('/multimodal-echo'));
        AI::chat([['role' => 'user', 'content' => 'x']], tools: [$this->sampleTool()], toolChoice: 'auto');
        $this->assertSame('auto', $this->state()['requests'][0]['body']['tool_choice']);

        file_put_contents(self::$stateFile, json_encode(['requests' => [], 'counts' => []]));
        putenv('TINA4_AI_PROVIDER=anthropic');
        putenv('TINA4_AI_KEY=hosted-key');
        putenv('TINA4_AI_URL=' . $this->base('/anthropic-echo'));
        AI::chat([['role' => 'user', 'content' => 'x']], tools: [$this->sampleTool()], toolChoice: 'auto');
        $this->assertSame(['type' => 'auto'], $this->state()['requests'][0]['body']['tool_choice']);
    }

    public function testAiToolChoiceNone(): void
    {
        putenv('TINA4_AI_URL=' . $this->base('/multimodal-echo'));
        AI::chat([['role' => 'user', 'content' => 'x']], tools: [$this->sampleTool()], toolChoice: 'none');
        $body = $this->state()['requests'][0]['body'];
        $this->assertSame('none', $body['tool_choice']);
        $this->assertArrayHasKey('tools', $body, 'OpenAI keeps tools even with none');

        file_put_contents(self::$stateFile, json_encode(['requests' => [], 'counts' => []]));
        putenv('TINA4_AI_PROVIDER=anthropic');
        putenv('TINA4_AI_KEY=hosted-key');
        putenv('TINA4_AI_URL=' . $this->base('/anthropic-echo'));
        AI::chat([['role' => 'user', 'content' => 'x']], tools: [$this->sampleTool()], toolChoice: 'none');
        $body = $this->state()['requests'][0]['body'];
        $this->assertArrayNotHasKey('tools', $body, 'Anthropic omits tools entirely for none');
        $this->assertArrayNotHasKey('tool_choice', $body, 'Anthropic has no tool_choice=none');
    }

    public function testAiToolChoiceRequired(): void
    {
        putenv('TINA4_AI_URL=' . $this->base('/multimodal-echo'));
        AI::chat([['role' => 'user', 'content' => 'x']], tools: [$this->sampleTool()], toolChoice: 'required');
        $this->assertSame('required', $this->state()['requests'][0]['body']['tool_choice']);

        file_put_contents(self::$stateFile, json_encode(['requests' => [], 'counts' => []]));
        putenv('TINA4_AI_PROVIDER=anthropic');
        putenv('TINA4_AI_KEY=hosted-key');
        putenv('TINA4_AI_URL=' . $this->base('/anthropic-echo'));
        AI::chat([['role' => 'user', 'content' => 'x']], tools: [$this->sampleTool()], toolChoice: 'required');
        $this->assertSame(['type' => 'any'], $this->state()['requests'][0]['body']['tool_choice']);
    }

    public function testAiToolChoiceNamed(): void
    {
        putenv('TINA4_AI_URL=' . $this->base('/multimodal-echo'));
        AI::chat([['role' => 'user', 'content' => 'x']], tools: [$this->sampleTool()], toolChoice: ['name' => 'get_weather']);
        $this->assertSame(
            ['type' => 'function', 'function' => ['name' => 'get_weather']],
            $this->state()['requests'][0]['body']['tool_choice']
        );

        file_put_contents(self::$stateFile, json_encode(['requests' => [], 'counts' => []]));
        putenv('TINA4_AI_PROVIDER=anthropic');
        putenv('TINA4_AI_KEY=hosted-key');
        putenv('TINA4_AI_URL=' . $this->base('/anthropic-echo'));
        AI::chat([['role' => 'user', 'content' => 'x']], tools: [$this->sampleTool()], toolChoice: ['name' => 'get_weather']);
        $this->assertSame(
            ['type' => 'tool', 'name' => 'get_weather'],
            $this->state()['requests'][0]['body']['tool_choice']
        );
    }

    // -- ADR-0061: tool-result message shape (both forms, both providers)

    public function testAiToolResultOpenaiFormPassthrough(): void
    {
        putenv('TINA4_AI_URL=' . $this->base('/multimodal-echo'));
        AI::chat([
            ['role' => 'user', 'content' => 'weather?'],
            ['role' => 'assistant', 'tool_calls' => [
                ['id' => 'call_wx', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Cape Town"}']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'call_wx', 'content' => '{"temp":22}'],
        ]);
        $messages = $this->state()['requests'][0]['body']['messages'];
        $toolMsg = end($messages);
        $this->assertSame('tool', $toolMsg['role']);
        $this->assertSame('call_wx', $toolMsg['tool_call_id']);
        $this->assertSame('{"temp":22}', $toolMsg['content']);
    }

    public function testAiToolResultAnthropicFormPassthrough(): void
    {
        putenv('TINA4_AI_PROVIDER=anthropic');
        putenv('TINA4_AI_KEY=hosted-key');
        putenv('TINA4_AI_URL=' . $this->base('/anthropic-echo'));
        AI::chat([
            ['role' => 'user', 'content' => 'weather?'],
            ['role' => 'assistant', 'tool_calls' => [
                ['id' => 'toolu_1', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Cape Town"}']],
            ]],
            ['role' => 'user', 'content' => [[
                'type' => 'tool_result',
                'tool_use_id' => 'toolu_1',
                'content' => '{"temp":22}',
            ]]],
        ]);
        $messages = $this->state()['requests'][0]['body']['messages'];
        $last = end($messages);
        $this->assertSame('user', $last['role']);
        $this->assertSame('tool_result', $last['content'][0]['type']);
        $this->assertSame('toolu_1', $last['content'][0]['tool_use_id']);
        $this->assertSame('{"temp":22}', $last['content'][0]['content']);
    }

    public function testAiToolResultOpenaiToAnthropicTranslation(): void
    {
        putenv('TINA4_AI_PROVIDER=anthropic');
        putenv('TINA4_AI_KEY=hosted-key');
        putenv('TINA4_AI_URL=' . $this->base('/anthropic-echo'));
        // Caller sends OpenAI-shape tool result, provider is Anthropic — the
        // client must translate to the Anthropic user-turn form on the wire.
        AI::chat([
            ['role' => 'user', 'content' => 'weather?'],
            ['role' => 'assistant', 'tool_calls' => [
                ['id' => 'call_wx', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Cape Town"}']],
            ]],
            ['role' => 'tool', 'tool_call_id' => 'call_wx', 'content' => '{"temp":22}'],
        ]);
        $messages = $this->state()['requests'][0]['body']['messages'];
        $last = end($messages);
        $this->assertSame('user', $last['role'], 'openai tool -> anthropic user turn');
        $this->assertIsArray($last['content']);
        $this->assertSame('tool_result', $last['content'][0]['type']);
        $this->assertSame('call_wx', $last['content'][0]['tool_use_id']);
        $this->assertSame('{"temp":22}', $last['content'][0]['content']);
    }

    public function testAiToolResultAnthropicToOpenaiTranslation(): void
    {
        putenv('TINA4_AI_URL=' . $this->base('/multimodal-echo'));
        // Caller sends Anthropic-shape tool_result, provider is OpenAI — the
        // client must translate to the OpenAI role:'tool' form on the wire.
        AI::chat([
            ['role' => 'user', 'content' => 'weather?'],
            ['role' => 'assistant', 'tool_calls' => [
                ['id' => 'toolu_1', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Cape Town"}']],
            ]],
            ['role' => 'user', 'content' => [[
                'type' => 'tool_result',
                'tool_use_id' => 'toolu_1',
                'content' => '{"temp":22}',
            ]]],
        ]);
        $messages = $this->state()['requests'][0]['body']['messages'];
        $last = end($messages);
        $this->assertSame('tool', $last['role'], 'anthropic tool_result -> openai role:tool');
        $this->assertSame('toolu_1', $last['tool_call_id']);
        $this->assertSame('{"temp":22}', $last['content']);
    }

    public function testAiToolResultMalformedFailsClosed(): void
    {
        putenv('TINA4_AI_URL=' . $this->base('/multimodal-echo'));
        $bads = [
            // OpenAI form missing tool_call_id
            [['role' => 'user', 'content' => 'x'], ['role' => 'tool', 'content' => 'y']],
            // OpenAI form missing content
            [['role' => 'user', 'content' => 'x'], ['role' => 'tool', 'tool_call_id' => 'a']],
            // Anthropic form missing tool_use_id
            [['role' => 'user', 'content' => [['type' => 'tool_result', 'content' => 'y']]]],
            // Anthropic form missing content
            [['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'a']]]],
            // Bad tool_choice string
            null,
        ];
        foreach ($bads as $index => $case) {
            try {
                if ($case === null) {
                    AI::chat([['role' => 'user', 'content' => 'x']], tools: [$this->sampleTool()], toolChoice: 'sometimes');
                } else {
                    AI::chat($case);
                }
                $this->fail("case {$index} must raise AIConfigError before wire");
            } catch (AIConfigError) {
                // ok
            }
        }
        $this->assertSame([], $this->state()['requests'] ?? []);
    }

    // -- ADR-0061: full agent-loop round-trip ----------------------------

    public function testAiAgentLoopOpenaiRoundTrip(): void
    {
        putenv('TINA4_AI_URL=' . $this->base('/agent-openai-turn'));
        $messages = [['role' => 'user', 'content' => 'weather in Cape Town?']];
        $tools = [$this->sampleTool()];

        // Turn 1: send with tools, receive a tool_call event
        $events = iterator_to_array(AI::chat($messages, stream: true, tools: $tools), false);
        $toolCalls = array_values(array_filter($events, static fn (array $e): bool => $e['type'] === 'tool_call'));
        $this->assertCount(1, $toolCalls);
        $this->assertSame('call_wx', $toolCalls[0]['id']);
        $this->assertSame('get_weather', $toolCalls[0]['name']);
        $this->assertSame(['city' => 'Cape Town'], $toolCalls[0]['args']);

        // Caller executes tool locally, then appends the assistant+tool messages
        $messages[] = ['role' => 'assistant', 'tool_calls' => [
            ['id' => $toolCalls[0]['id'], 'type' => 'function', 'function' => [
                'name' => $toolCalls[0]['name'],
                'arguments' => json_encode($toolCalls[0]['args']),
            ]],
        ]];
        $messages[] = ['role' => 'tool', 'tool_call_id' => $toolCalls[0]['id'], 'content' => '{"temp":22}'];

        // Turn 2: send updated conversation, receive text_delta + done
        $events2 = iterator_to_array(AI::chat($messages, stream: true, tools: $tools), false);
        $text = implode('', array_map(
            static fn (array $e): string => $e['text'] ?? '',
            array_filter($events2, static fn (array $e): bool => $e['type'] === 'text_delta')
        ));
        $this->assertSame('It is 22C in Cape Town', $text);
        $this->assertSame('done', end($events2)['type']);

        $state = $this->state();
        $this->assertSame(2, $state['counts']['/agent-openai-turn'], 'exactly two round-trips');
        // Turn 2 body must include the assistant tool_calls AND role:'tool' the
        // agent appended, in that order.
        $turn2Messages = $state['requests'][1]['body']['messages'];
        $this->assertSame('assistant', $turn2Messages[1]['role']);
        $this->assertSame('call_wx', $turn2Messages[1]['tool_calls'][0]['id']);
        $this->assertSame('tool', $turn2Messages[2]['role']);
        $this->assertSame('call_wx', $turn2Messages[2]['tool_call_id']);
    }

    public function testAiAgentLoopAnthropicRoundTrip(): void
    {
        putenv('TINA4_AI_PROVIDER=anthropic');
        putenv('TINA4_AI_KEY=hosted-key');
        putenv('TINA4_AI_URL=' . $this->base('/agent-anthropic-turn'));
        $messages = [['role' => 'user', 'content' => 'weather in Cape Town?']];
        $tools = [$this->sampleTool()];

        // Turn 1
        $events = iterator_to_array(AI::chat($messages, stream: true, tools: $tools), false);
        $toolCalls = array_values(array_filter($events, static fn (array $e): bool => $e['type'] === 'tool_call'));
        $this->assertCount(1, $toolCalls);
        $this->assertSame('toolu_1', $toolCalls[0]['id']);
        $this->assertSame(['city' => 'Cape Town'], $toolCalls[0]['args']);

        // Caller appends — using Anthropic-shape tool_result on a user turn
        $messages[] = ['role' => 'assistant', 'tool_calls' => [
            ['id' => $toolCalls[0]['id'], 'type' => 'function', 'function' => [
                'name' => $toolCalls[0]['name'],
                'arguments' => json_encode($toolCalls[0]['args']),
            ]],
        ]];
        $messages[] = ['role' => 'user', 'content' => [[
            'type' => 'tool_result',
            'tool_use_id' => $toolCalls[0]['id'],
            'content' => '{"temp":22}',
        ]]];

        // Turn 2
        $events2 = iterator_to_array(AI::chat($messages, stream: true, tools: $tools), false);
        $text = implode('', array_map(
            static fn (array $e): string => $e['text'] ?? '',
            array_filter($events2, static fn (array $e): bool => $e['type'] === 'text_delta')
        ));
        $this->assertSame('It is 22C in Cape Town', $text);
        $this->assertSame('done', end($events2)['type']);

        $state = $this->state();
        $this->assertSame(2, $state['counts']['/agent-anthropic-turn']);
        // Turn 2 wire body: last message is user with a tool_result content block.
        $turn2Messages = $state['requests'][1]['body']['messages'];
        $last = end($turn2Messages);
        $this->assertSame('user', $last['role']);
        $this->assertSame('tool_result', $last['content'][0]['type']);
        $this->assertSame('toolu_1', $last['content'][0]['tool_use_id']);
    }
}
