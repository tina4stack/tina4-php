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

    public function testAiStreamIsOrderedDeltas(): void
    {
        putenv('TINA4_AI_URL=' . $this->base('/stream-openai'));
        $this->assertSame(['hello ', 'world'], iterator_to_array(AI::chat([['role' => 'user', 'content' => 'hello']], stream: true)));
        putenv('TINA4_AI_PROVIDER=local');
        putenv('TINA4_AI_KEY');
        putenv('TINA4_AI_MAX_RETRIES=1');
        putenv('TINA4_AI_URL=' . $this->base('/stream-partial'));
        try {
            iterator_to_array(AI::chat([['role' => 'user', 'content' => 'hello']], stream: true));
            $this->fail('partial stream must fail');
        } catch (AIParseError) {
            $this->assertSame(1, $this->state()['counts']['/stream-partial']);
        }
        putenv('TINA4_AI_PROVIDER=anthropic');
        putenv('TINA4_AI_KEY=hosted-key');
        putenv('TINA4_AI_URL=' . $this->base('/stream-anthropic'));
        $this->assertSame(['hello ', 'world'], iterator_to_array(AI::chat([['role' => 'user', 'content' => 'hello']], stream: true)));
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
