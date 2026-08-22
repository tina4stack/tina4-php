<?php

$stateFile = getenv('AI_CONTRACT_STATE');
$state = is_file($stateFile) ? json_decode((string)file_get_contents($stateFile), true) : [];
$state += ['requests' => [], 'counts' => []];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/state') {
    header('Content-Type: application/json');
    echo json_encode($state);
    return;
}

$body = json_decode((string)file_get_contents('php://input'), true) ?: [];
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$state['requests'][] = [
    'path' => $path,
    'body' => $body,
    'authorization' => $headers['authorization'] ?? null,
    'x_api_key' => $headers['x-api-key'] ?? null,
];
$state['counts'][$path] = ($state['counts'][$path] ?? 0) + 1;
file_put_contents($stateFile, json_encode($state));

$json = static function (int $status, array $payload, array $extra = []): void {
    http_response_code($status);
    header('Content-Type: application/json');
    foreach ($extra as $name => $value) {
        header("{$name}: {$value}");
    }
    echo json_encode($payload);
};

if ($path === '/openai') {
    $json(200, [
        'model' => $body['model'] ?? 'fixture-model',
        'choices' => [['message' => ['content' => 'hello world'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2, 'total_tokens' => 5],
    ]);
} elseif ($path === '/anthropic') {
    $json(200, [
        'model' => $body['model'] ?? 'fixture-model',
        'content' => [['type' => 'text', 'text' => 'hello world']],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 3, 'output_tokens' => 2],
    ]);
} elseif ($path === '/embeddings') {
    $items = is_array($body['input'] ?? null) ? $body['input'] : [$body['input'] ?? null];
    $data = [];
    foreach ($items as $index => $_item) {
        $data[] = ['index' => $index, 'embedding' => [(float)$index, 0.25, 0.5]];
    }
    $json(200, ['data' => $data]);
} elseif ($path === '/stream-openai') {
    header('Content-Type: text/event-stream');
    echo 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'hello ']]]]) . "\n\n";
    echo 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'world'], 'finish_reason' => 'stop']]]) . "\n\n";
    echo "data: [DONE]\n\n";
} elseif ($path === '/stream-anthropic') {
    header('Content-Type: text/event-stream');
    echo 'data: ' . json_encode(['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 3, 'output_tokens' => 0]]]) . "\n\n";
    echo 'data: ' . json_encode(['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]) . "\n\n";
    echo 'data: ' . json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'hello ']]) . "\n\n";
    echo 'data: ' . json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'world']]) . "\n\n";
    echo 'data: ' . json_encode(['type' => 'content_block_stop', 'index' => 0]) . "\n\n";
    echo 'data: ' . json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['input_tokens' => 3, 'output_tokens' => 2]]) . "\n\n";
    echo 'data: ' . json_encode(['type' => 'message_stop']) . "\n\n";
} elseif ($path === '/stream-partial') {
    header('Content-Type: text/event-stream');
    echo 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'first']]]]) . "\n\n";
} elseif ($path === '/stream-openai-tool') {
    header('Content-Type: text/event-stream');
    // Fragmented OpenAI tool_call: name arrives first, then argument JSON in three fragments.
    echo 'data: ' . json_encode(['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_wx', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '']]]]]]]) . "\n\n";
    echo 'data: ' . json_encode(['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{"loc']]]]]]]) . "\n\n";
    echo 'data: ' . json_encode(['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => 'ation":']]]]]]]) . "\n\n";
    echo 'data: ' . json_encode(['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => ' "Cape Town"}']]]]]]]) . "\n\n";
    echo 'data: ' . json_encode(['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]]) . "\n\n";
    echo "data: [DONE]\n\n";
} elseif ($path === '/stream-anthropic-tool') {
    header('Content-Type: text/event-stream');
    echo 'data: ' . json_encode(['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 5, 'output_tokens' => 0]]]) . "\n\n";
    echo 'data: ' . json_encode(['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_weather', 'input' => new stdClass()]]) . "\n\n";
    echo 'data: ' . json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"loc']]) . "\n\n";
    echo 'data: ' . json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => 'ation":']]) . "\n\n";
    echo 'data: ' . json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => ' "Cape Town"}']]) . "\n\n";
    echo 'data: ' . json_encode(['type' => 'content_block_stop', 'index' => 0]) . "\n\n";
    echo 'data: ' . json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['input_tokens' => 5, 'output_tokens' => 6]]) . "\n\n";
    echo 'data: ' . json_encode(['type' => 'message_stop']) . "\n\n";
} elseif ($path === '/multimodal-echo') {
    // Echoes the received body so tests can assert body-shape translation per provider.
    $json(200, [
        'model' => $body['model'] ?? 'echo',
        'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
    ]);
} elseif ($path === '/anthropic-echo') {
    // Echoes the received body but replies with Anthropic-shape success — tests
    // use this to assert tool declaration + tool_choice + tool_result on wire
    // for the Anthropic provider (ADR-0061).
    $json(200, [
        'model' => $body['model'] ?? 'echo',
        'content' => [['type' => 'text', 'text' => 'ok']],
        'stop_reason' => 'end_turn',
        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
    ]);
} elseif ($path === '/agent-openai-turn') {
    // Two-turn agent loop (ADR-0061 round-trip). First POST streams an OpenAI
    // tool_call, second POST streams a text_delta then [DONE]. The state
    // counter distinguishes them so the caller can prove send + receive
    // compose without a raw SSE parser in the caller.
    header('Content-Type: text/event-stream');
    if ($state['counts'][$path] === 1) {
        // Turn 1: emit a full tool_call (name + args in one fragment for brevity)
        echo 'data: ' . json_encode(['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_wx', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"city": "Cape Town"}']]]]]]]) . "\n\n";
        echo 'data: ' . json_encode(['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']]]) . "\n\n";
        echo "data: [DONE]\n\n";
    } else {
        // Turn 2: model has "seen" the tool_result and now answers
        echo 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'It is 22C in Cape Town']]]]) . "\n\n";
        echo 'data: ' . json_encode(['choices' => [['delta' => [], 'finish_reason' => 'stop']]]) . "\n\n";
        echo "data: [DONE]\n\n";
    }
} elseif ($path === '/agent-anthropic-turn') {
    // Two-turn Anthropic agent loop (ADR-0061 round-trip).
    header('Content-Type: text/event-stream');
    if ($state['counts'][$path] === 1) {
        // Turn 1: tool_use block
        echo 'data: ' . json_encode(['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 5, 'output_tokens' => 0]]]) . "\n\n";
        echo 'data: ' . json_encode(['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_weather', 'input' => new stdClass()]]) . "\n\n";
        echo 'data: ' . json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"city": "Cape Town"}']]) . "\n\n";
        echo 'data: ' . json_encode(['type' => 'content_block_stop', 'index' => 0]) . "\n\n";
        echo 'data: ' . json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['input_tokens' => 5, 'output_tokens' => 6]]) . "\n\n";
        echo 'data: ' . json_encode(['type' => 'message_stop']) . "\n\n";
    } else {
        // Turn 2: model answers in text
        echo 'data: ' . json_encode(['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 12, 'output_tokens' => 0]]]) . "\n\n";
        echo 'data: ' . json_encode(['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]) . "\n\n";
        echo 'data: ' . json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'It is 22C in Cape Town']]) . "\n\n";
        echo 'data: ' . json_encode(['type' => 'content_block_stop', 'index' => 0]) . "\n\n";
        echo 'data: ' . json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['input_tokens' => 12, 'output_tokens' => 8]]) . "\n\n";
        echo 'data: ' . json_encode(['type' => 'message_stop']) . "\n\n";
    }
} elseif ($path === '/retry' && $state['counts'][$path] === 1) {
    $json(429, ['error' => 'later'], ['Retry-After' => '0']);
} elseif ($path === '/retry') {
    $json(200, ['model' => 'retry-model', 'choices' => [['message' => ['content' => 'recovered'], 'finish_reason' => 'stop']], 'usage' => []]);
} elseif ($path === '/always500') {
    $json(500, ['error' => 'provider-secret-body']);
} elseif ($path === '/bad400') {
    $json(400, ['error' => 'permanent']);
} elseif ($path === '/slow') {
    usleep(250000);
    $json(200, ['choices' => [['message' => ['content' => 'late']]]]);
} elseif ($path === '/malformed') {
    $json(200, ['choices' => []]);
} else {
    $json(404, ['error' => 'missing']);
}
