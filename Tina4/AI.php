<?php

namespace Tina4;

/** Zero-dependency app-facing AI client (ADR-0053, streaming + multimodal per ADR-0060). */
final class AI
{
    private const PROVIDERS = ['local', 'openai', 'anthropic'];

    public static function chat(
        array $messages,
        ?string $model = null,
        ?float $temperature = null,
        ?int $maxTokens = null,
        bool $stream = false,
        ?float $timeout = null,
        ?string $provider = null,
    ): ChatResponse|\Generator {
        self::validateMessages($messages);
        $config = self::config('chat', $model, $timeout, $provider);
        $body = self::chatBody($config, $messages, $temperature, $maxTokens, $stream);
        if ($stream) {
            return self::stream($config, self::headers($config), $body);
        }
        return self::normalizeChat($config['provider'], self::requestJson($config, self::headers($config), $body));
    }

    public static function complete(
        string $prompt,
        ?string $model = null,
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?float $timeout = null,
        ?string $provider = null,
    ): string {
        return self::chat(
            [['role' => 'user', 'content' => $prompt]],
            model: $model,
            temperature: $temperature,
            maxTokens: $maxTokens,
            timeout: $timeout,
            provider: $provider,
        )->text;
    }

    public static function embed(
        string|array $textOrTexts,
        ?string $model = null,
        ?float $timeout = null,
        ?string $provider = null,
    ): array {
        $single = is_string($textOrTexts);
        if (!$single && ($textOrTexts === [] || array_filter($textOrTexts, 'is_string') !== $textOrTexts)) {
            throw new AIConfigError('AI embedding input must be a string or a non-empty list of strings');
        }
        $config = self::config('embed', $model, $timeout, $provider);
        if ($config['provider'] === 'anthropic') {
            throw new AIConfigError('Anthropic does not provide the embedding endpoint in this contract');
        }
        $raw = self::requestJson($config, self::headers($config), [
            'model' => $config['model'],
            'input' => $textOrTexts,
        ]);
        $data = $raw['data'] ?? null;
        if (!is_array($data)) {
            throw new AIParseError('AI provider returned a malformed embedding response');
        }
        usort($data, static fn (array $a, array $b): int => ($a['index'] ?? 0) <=> ($b['index'] ?? 0));
        $vectors = [];
        foreach ($data as $item) {
            $vector = $item['embedding'] ?? null;
            if (!is_array($vector) || $vector === []) {
                throw new AIParseError('AI provider returned a malformed embedding response');
            }
            foreach ($vector as $value) {
                if (!is_int($value) && !is_float($value)) {
                    throw new AIParseError('AI provider returned a malformed embedding response');
                }
            }
            $vectors[] = array_map('floatval', $vector);
        }
        $expected = $single ? 1 : count($textOrTexts);
        if (count($vectors) !== $expected) {
            throw new AIParseError('AI provider returned a malformed embedding response');
        }
        return $single ? $vectors[0] : $vectors;
    }

    private static function validateMessages(array $messages): void
    {
        if ($messages === []) {
            throw new AIConfigError('AI messages must be a non-empty list');
        }
        foreach ($messages as $message) {
            if (!is_array($message)
                || !in_array($message['role'] ?? null, ['system', 'user', 'assistant'], true)
                || !array_key_exists('content', $message)) {
                throw new AIConfigError('Each AI message needs a supported role and content');
            }
            $content = $message['content'];
            if (is_string($content)) {
                continue;
            }
            if (!is_array($content) || $content === []) {
                throw new AIConfigError('AI message content must be a string or a non-empty list of parts');
            }
            foreach ($content as $part) {
                self::validateContentPart($part);
            }
        }
    }

    /**
     * Validate ONE content part per ADR-0060. A part is either:
     *   ['type' => 'text',  'text' => string]
     *   ['type' => 'image', 'source' => 'data:<mime>;base64,<payload>' | 'https://...']
     * Anything else raises AIConfigError BEFORE any request is sent.
     */
    private static function validateContentPart(mixed $part): void
    {
        if (!is_array($part) || !isset($part['type']) || !is_string($part['type'])) {
            throw new AIConfigError('AI content parts must be arrays with a string type');
        }
        $type = $part['type'];
        if ($type === 'text') {
            if (!isset($part['text']) || !is_string($part['text'])) {
                throw new AIConfigError('AI text parts must carry a string text field');
            }
            return;
        }
        if ($type === 'image') {
            if (!isset($part['source']) || !is_string($part['source']) || $part['source'] === '') {
                throw new AIConfigError('AI image parts must carry a non-empty string source field');
            }
            $source = $part['source'];
            if (str_starts_with($source, 'https://') || str_starts_with($source, 'http://')) {
                return;
            }
            if (preg_match('#^data:([^;]+);base64,#', $source) !== 1) {
                throw new AIConfigError('AI image source must be an http/https URL or a data:<mime>;base64,<payload> URI');
            }
            return;
        }
        throw new AIConfigError("Unknown AI content part type: {$type}");
    }

    private static function config(string $capability, ?string $model, ?float $timeout, ?string $provider): array
    {
        $selected = strtolower(trim($provider ?? (getenv('TINA4_AI_PROVIDER') ?: 'local')));
        if (!in_array($selected, self::PROVIDERS, true)) {
            throw new AIConfigError('TINA4_AI_PROVIDER must be local, openai, or anthropic');
        }
        $key = getenv('TINA4_AI_KEY') ?: null;
        if (in_array($selected, ['openai', 'anthropic'], true) && $key === null) {
            throw new AIConfigError("TINA4_AI_KEY is required for the {$selected} provider");
        }
        $defaults = [
            'local' => ['http://localhost:11437', 'llama3.2'],
            'openai' => ['https://api.openai.com/v1', 'gpt-4o-mini'],
            'anthropic' => ['https://api.anthropic.com/v1', 'claude-3-5-haiku-latest'],
        ];
        $url = $capability === 'embed' && getenv('TINA4_EMBED_URL')
            ? getenv('TINA4_EMBED_URL')
            : (getenv('TINA4_AI_URL') ?: $defaults[$selected][0]);
        $url = self::endpoint((string)$url, $capability, $selected);
        $chosenModel = trim($model ?? (getenv('TINA4_AI_MODEL') ?: $defaults[$selected][1]));
        if ($chosenModel === '') {
            throw new AIConfigError('AI model must be a non-empty string');
        }
        $total = $timeout ?? self::number('TINA4_AI_TIMEOUT', 60.0, 0.001);
        if ($total <= 0) {
            throw new AIConfigError('AI timeout must be greater than zero');
        }
        return [
            'provider' => $selected,
            'url' => $url,
            'model' => $chosenModel,
            'key' => $key,
            'total_timeout' => $total,
            'connect_timeout' => self::number('TINA4_AI_CONNECT_TIMEOUT', 10.0, 0.001),
            'max_retries' => (int)self::number('TINA4_AI_MAX_RETRIES', 2, 0),
        ];
    }

    private static function number(string $name, float|int $default, float $minimum): float
    {
        $raw = getenv($name);
        $value = $raw === false || $raw === '' ? (float)$default : filter_var($raw, FILTER_VALIDATE_FLOAT);
        if ($value === false || $value < $minimum) {
            throw new AIConfigError("{$name} must be numeric and at least {$minimum}");
        }
        return (float)$value;
    }

    private static function endpoint(string $value, string $capability, string $provider): string
    {
        $parts = parse_url($value);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['http', 'https'], true) || empty($parts['host'])) {
            throw new AIConfigError('AI URL must be an http or https URL');
        }
        $path = rtrim($parts['path'] ?? '', '/');
        if (in_array($path, ['', '/v1', '/api'], true)) {
            $suffix = $provider === 'anthropic' ? '/messages' : ($capability === 'embed' ? '/embeddings' : '/chat/completions');
            $prefix = $path !== '' ? $path : '/v1';
            $authority = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            return $parts['scheme'] . '://' . $authority . $prefix . $suffix;
        }
        return $value;
    }

    private static function headers(array $config): array
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($config['provider'] === 'openai') {
            $headers['Authorization'] = 'Bearer ' . $config['key'];
        } elseif ($config['provider'] === 'anthropic') {
            $headers['x-api-key'] = $config['key'];
            $headers['anthropic-version'] = '2023-06-01';
        }
        return $headers;
    }

    private static function chatBody(array $config, array $messages, ?float $temperature, ?int $maxTokens, bool $stream): array
    {
        $translated = array_map(
            static fn (array $m): array => self::translateMessage($config['provider'], $m),
            $messages,
        );
        $body = ['model' => $config['model'], 'messages' => $translated, 'stream' => $stream];
        if ($temperature !== null) {
            $body['temperature'] = $temperature;
        }
        if ($maxTokens !== null) {
            $body['max_tokens'] = $maxTokens;
        }
        if ($config['provider'] === 'anthropic') {
            $system = [];
            $body['messages'] = [];
            foreach ($translated as $message) {
                if ($message['role'] === 'system') {
                    // Anthropic system is text-only in this contract; a list of
                    // parts collapses to the concatenated text parts.
                    $system[] = is_string($message['content'])
                        ? $message['content']
                        : self::joinTextParts($message['content']);
                } else {
                    $body['messages'][] = $message;
                }
            }
            $body['max_tokens'] = $maxTokens ?? 1024;
            if ($system !== []) {
                $body['system'] = implode("\n\n", $system);
            }
        }
        return $body;
    }

    /**
     * Translate one message's content into the provider's native shape.
     * Strings pass through unchanged (the pre-ADR-0060 default). A list of
     * multimodal parts becomes:
     *   OpenAI/local: [{type:'text',text}, {type:'image_url', image_url:{url}}]
     *   Anthropic:    [{type:'text',text},
     *                  {type:'image', source: {type:'base64',media_type,data}}]  (data: URIs)
     *                  [{type:'image', source: {type:'url', url}}]              (https:// URLs)
     */
    private static function translateMessage(string $provider, array $message): array
    {
        $content = $message['content'];
        if (is_string($content)) {
            return $message;
        }
        $parts = [];
        foreach ($content as $part) {
            if ($part['type'] === 'text') {
                $parts[] = ['type' => 'text', 'text' => $part['text']];
                continue;
            }
            // image
            $source = $part['source'];
            if ($provider === 'anthropic') {
                if (str_starts_with($source, 'data:')) {
                    if (preg_match('#^data:([^;]+);base64,(.*)$#s', $source, $match) !== 1) {
                        throw new AIConfigError('AI image data URI is malformed');
                    }
                    $parts[] = [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $match[1],
                            'data' => $match[2],
                        ],
                    ];
                } else {
                    $parts[] = [
                        'type' => 'image',
                        'source' => ['type' => 'url', 'url' => $source],
                    ];
                }
            } else {
                // OpenAI-compatible + local
                $parts[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => $source],
                ];
            }
        }
        $message['content'] = $parts;
        return $message;
    }

    /** Flatten a list-of-parts to a text-only concatenation (Anthropic system). */
    private static function joinTextParts(array $parts): string
    {
        $out = [];
        foreach ($parts as $part) {
            if (($part['type'] ?? null) === 'text' && isset($part['text']) && is_string($part['text'])) {
                $out[] = $part['text'];
            }
        }
        return implode("\n\n", $out);
    }

    /** @return array{0:resource,1:int,2:array<string,string>} */
    private static function open(array $config, float $deadline, array $headers, array $body): array
    {
        $parts = parse_url($config['url']);
        $scheme = $parts['scheme'];
        $host = $parts['host'];
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new AITimeoutError('AI total request timeout expired');
        }
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
        ]]);
        $address = ($scheme === 'https' ? 'tls' : 'tcp') . "://{$host}:{$port}";
        $errno = 0;
        $errstr = '';
        $connectStarted = microtime(true);
        $connectLimit = min($config['connect_timeout'], $remaining);
        $stream = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            $connectLimit,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if ($stream === false) {
            $connectElapsed = microtime(true) - $connectStarted;
            if (microtime(true) >= $deadline
                || $connectElapsed >= $connectLimit * 0.8
                || str_contains(strtolower($errstr), 'timed out')) {
                throw new AITimeoutError('AI connection timeout expired');
            }
            throw new AIHTTPError('AI transport failed');
        }
        self::applyTimeout($stream, $deadline);
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $lines = ["POST {$path} HTTP/1.1", "Host: {$host}", 'Connection: close', 'Content-Length: ' . strlen($payload)];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        $request = implode("\r\n", $lines) . "\r\n\r\n" . $payload;
        $offset = 0;
        while ($offset < strlen($request)) {
            self::applyTimeout($stream, $deadline);
            $written = @fwrite($stream, substr($request, $offset));
            if ($written === false || $written === 0) {
                fclose($stream);
                throw new AIHTTPError('AI transport failed');
            }
            $offset += $written;
        }
        $statusLine = self::readLine($stream, $deadline);
        if (!preg_match('/^HTTP\/\S+\s+(\d{3})/', $statusLine, $match)) {
            fclose($stream);
            throw new AIHTTPError('AI provider returned an invalid HTTP response');
        }
        $responseHeaders = [];
        while (($line = self::readLine($stream, $deadline)) !== "\r\n" && $line !== "\n" && $line !== '') {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }
        }
        return [$stream, (int)$match[1], $responseHeaders];
    }

    private static function applyTimeout($stream, float $deadline): void
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new AITimeoutError('AI total request timeout expired');
        }
        $seconds = (int)floor($remaining);
        $micros = (int)(($remaining - $seconds) * 1_000_000);
        stream_set_timeout($stream, $seconds, max(1, $micros));
    }

    private static function readLine($stream, float $deadline): string
    {
        self::applyTimeout($stream, $deadline);
        $line = @fgets($stream);
        if ($line === false) {
            $meta = stream_get_meta_data($stream);
            if ($meta['timed_out'] ?? false || microtime(true) >= $deadline) {
                throw new AITimeoutError('AI total request timeout expired');
            }
            return '';
        }
        return $line;
    }

    /** @return \Generator<string> */
    private static function bodyChunks($stream, array $headers, float $deadline): \Generator
    {
        if (str_contains(strtolower($headers['transfer-encoding'] ?? ''), 'chunked')) {
            while (true) {
                $line = trim(self::readLine($stream, $deadline));
                if ($line === '') {
                    continue;
                }
                $size = hexdec(explode(';', $line, 2)[0]);
                if ($size === 0) {
                    return;
                }
                $chunk = '';
                while (strlen($chunk) < $size) {
                    self::applyTimeout($stream, $deadline);
                    $part = fread($stream, $size - strlen($chunk));
                    if ($part === false || $part === '') {
                        throw new AIHTTPError('AI stream ended unexpectedly');
                    }
                    $chunk .= $part;
                }
                self::readLine($stream, $deadline);
                yield $chunk;
            }
        }
        $remaining = isset($headers['content-length']) ? (int)$headers['content-length'] : null;
        while (!feof($stream) && ($remaining === null || $remaining > 0)) {
            self::applyTimeout($stream, $deadline);
            $length = $remaining === null ? 8192 : min(8192, $remaining);
            $chunk = fread($stream, $length);
            if ($chunk === false) {
                throw new AIHTTPError('AI stream read failed');
            }
            if ($chunk === '') {
                $meta = stream_get_meta_data($stream);
                if ($meta['timed_out'] ?? false) {
                    throw new AITimeoutError('AI total request timeout expired');
                }
                continue;
            }
            if ($remaining !== null) {
                $remaining -= strlen($chunk);
            }
            yield $chunk;
        }
    }

    private static function requestJson(array $config, array $headers, array $body): array
    {
        $deadline = microtime(true) + $config['total_timeout'];
        for ($attempt = 0; $attempt <= $config['max_retries']; $attempt++) {
            $stream = null;
            try {
                [$stream, $status, $responseHeaders] = self::open($config, $deadline, $headers, $body);
                $raw = implode('', iterator_to_array(self::bodyChunks($stream, $responseHeaders, $deadline)));
                fclose($stream);
                $stream = null;
                if ($status < 200 || $status >= 300) {
                    $error = new AIHTTPError("AI provider returned HTTP {$status}", $status);
                    if (($status === 429 || $status >= 500) && $attempt < $config['max_retries']) {
                        self::retryDelay($responseHeaders, $deadline);
                        continue;
                    }
                    throw $error;
                }
                try {
                    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new AIParseError('AI provider returned malformed JSON');
                }
                if (!is_array($decoded)) {
                    throw new AIParseError('AI provider returned a non-object JSON response');
                }
                return $decoded;
            } catch (AITimeoutError|AIHTTPError $error) {
                if ($stream !== null && is_resource($stream)) {
                    fclose($stream);
                }
                if ($error instanceof AIHTTPError && $error->status !== null) {
                    throw $error;
                }
                if ($attempt >= $config['max_retries']) {
                    throw $error;
                }
            }
        }
        throw new AIHTTPError('AI request failed');
    }

    private static function retryDelay(array $headers, float $deadline): void
    {
        $requested = is_numeric($headers['retry-after'] ?? null) ? max(0.0, (float)$headers['retry-after']) : 0.1;
        $delay = min($requested, max(0.0, $deadline - microtime(true)));
        if ($delay > 0) {
            usleep((int)($delay * 1_000_000));
        }
    }

    private static function normalizeChat(string $provider, array $raw): ChatResponse
    {
        try {
            if ($provider === 'anthropic') {
                $parts = array_values(array_map(
                    static fn (array $item): string => $item['text'],
                    array_filter($raw['content'], static fn (array $item): bool => ($item['type'] ?? 'text') === 'text')
                ));
                if ($parts === []) {
                    throw new \UnexpectedValueException();
                }
                $prompt = (int)($raw['usage']['input_tokens'] ?? 0);
                $completion = (int)($raw['usage']['output_tokens'] ?? 0);
                return new ChatResponse(
                    implode('', $parts),
                    (string)($raw['model'] ?? ''),
                    ['prompt_tokens' => $prompt, 'completion_tokens' => $completion, 'total_tokens' => $prompt + $completion],
                    isset($raw['stop_reason']) ? (string)$raw['stop_reason'] : null,
                    $raw,
                );
            }
            $choice = $raw['choices'][0] ?? null;
            $text = is_array($choice) ? ($choice['message']['content'] ?? null) : null;
            if (!is_string($text)) {
                throw new \UnexpectedValueException();
            }
            $usage = $raw['usage'] ?? [];
            return new ChatResponse(
                $text,
                (string)($raw['model'] ?? ''),
                [
                    'prompt_tokens' => (int)($usage['prompt_tokens'] ?? 0),
                    'completion_tokens' => (int)($usage['completion_tokens'] ?? 0),
                    'total_tokens' => (int)($usage['total_tokens'] ?? 0),
                ],
                isset($choice['finish_reason']) ? (string)$choice['finish_reason'] : null,
                $raw,
            );
        } catch (\Throwable $error) {
            if ($error instanceof AIError) {
                throw $error;
            }
            throw new AIParseError('AI provider returned a malformed chat response');
        }
    }

    /**
     * Yield typed AiEvent-shaped associative arrays (ADR-0060). Built on
     * Api::streamSse - there is no duplicate SSE framer inside AI.
     *
     * Pre-stream errors (connection failure, non-2xx status) go through
     * the retry policy and, if unrecovered, raise AITimeoutError /
     * AIHTTPError like the non-streaming path does. Mid-stream failures
     * (after the first event has been yielded) emit an `error` event
     * that replaces the `done` event; the iterator then ends. No retry
     * happens after the first event.
     *
     * @return \Generator<int, array{type:string, ...}>
     */
    private static function stream(array $config, array $headers, array $body): \Generator
    {
        $deadline = microtime(true) + $config['total_timeout'];
        for ($attempt = 0; $attempt <= $config['max_retries']; $attempt++) {
            $started = false;
            $api = new Api(baseUrl: '', timeout: max(1, (int)ceil($config['total_timeout'])));
            try {
                $streamHeaders = $headers;
                $streamHeaders['Accept'] = 'text/event-stream';
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    throw new AITimeoutError('AI total request timeout expired');
                }
                $sse = $api->streamSse($config['url'], [
                    'method' => 'POST',
                    'body' => $body,
                    'headers' => $streamHeaders,
                    'timeout' => $remaining,
                    'connect_timeout' => min($config['connect_timeout'], $remaining),
                ]);
                foreach (self::yieldTypedEvents($config['provider'], $sse) as $event) {
                    $started = true;
                    yield $event;
                    if ($event['type'] === 'done' || $event['type'] === 'error') {
                        return;
                    }
                }
                if (!$started) {
                    // A stream that produced zero events but also no error is a
                    // bug on the wire - treat as parse error, retry only when
                    // the retry policy allows.
                    if ($attempt < $config['max_retries']) {
                        continue;
                    }
                    throw new AIParseError('AI provider stream ended without any events');
                }
                return;
            } catch (ApiStreamHttpError $e) {
                if ($started) {
                    yield ['type' => 'error', 'message' => "AI provider returned HTTP {$e->status}", 'code' => (string)$e->status];
                    return;
                }
                $retryable = $e->status === 429 || $e->status >= 500;
                if ($retryable && $attempt < $config['max_retries']) {
                    usleep(100_000);
                    continue;
                }
                throw new AIHTTPError("AI provider returned HTTP {$e->status}", $e->status);
            } catch (ApiStreamTimeoutError $e) {
                if ($started) {
                    yield ['type' => 'error', 'message' => $e->getMessage(), 'code' => 'timeout'];
                    return;
                }
                if ($attempt < $config['max_retries']) {
                    continue;
                }
                throw new AITimeoutError($e->getMessage());
            } catch (ApiStreamError $e) {
                if ($started) {
                    yield ['type' => 'error', 'message' => $e->getMessage(), 'code' => 'transport_error'];
                    return;
                }
                if ($attempt < $config['max_retries']) {
                    usleep(100_000);
                    continue;
                }
                throw new AIHTTPError($e->getMessage());
            } catch (AIParseError $e) {
                if ($started) {
                    yield ['type' => 'error', 'message' => $e->getMessage(), 'code' => 'parse_error'];
                    return;
                }
                throw $e;
            }
        }
    }

    /**
     * Convert one SSE event stream into typed AiEvent records. Provider-aware
     * so tool_call aggregation works for both OpenAI (buffer tool_calls[i]
     * .function.arguments fragments until they parse) and Anthropic (buffer
     * input_json_delta partial_json between content_block_start/stop for a
     * given content-block index).
     *
     * @param \Generator<int, array> $sseEvents
     * @return \Generator<int, array{type:string, ...}>
     */
    private static function yieldTypedEvents(string $provider, \Generator $sseEvents): \Generator
    {
        if ($provider === 'anthropic') {
            yield from self::yieldAnthropicEvents($sseEvents);
            return;
        }
        yield from self::yieldOpenAiEvents($sseEvents);
    }

    /** @return \Generator<int, array{type:string, ...}> */
    private static function yieldOpenAiEvents(\Generator $sseEvents): \Generator
    {
        $toolCalls = [];
        $finishReason = null;
        $usage = null;
        foreach ($sseEvents as $ev) {
            $data = $ev['data'];
            if ($data === '[DONE]') {
                foreach (self::flushOpenAiToolCalls($toolCalls) as $emitted) {
                    yield $emitted;
                }
                yield ['type' => 'done', 'finish_reason' => $finishReason ?? 'stop', 'usage' => $usage];
                return;
            }
            if ($data === '') {
                continue;
            }
            try {
                $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                throw new AIParseError('AI provider returned malformed stream data');
            }
            if (!is_array($decoded)) {
                continue;
            }
            if (isset($decoded['usage']) && is_array($decoded['usage'])) {
                $usage = $decoded['usage'];
            }
            $choice = $decoded['choices'][0] ?? null;
            if (!is_array($choice)) {
                continue;
            }
            $delta = $choice['delta'] ?? [];
            if (is_array($delta)) {
                if (isset($delta['content']) && is_string($delta['content']) && $delta['content'] !== '') {
                    yield ['type' => 'text_delta', 'text' => $delta['content']];
                }
                if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                    foreach ($delta['tool_calls'] as $tc) {
                        $idx = $tc['index'] ?? 0;
                        if (!isset($toolCalls[$idx])) {
                            $toolCalls[$idx] = ['id' => '', 'name' => '', 'args_buffer' => ''];
                        }
                        if (isset($tc['id']) && is_string($tc['id'])) {
                            $toolCalls[$idx]['id'] = $tc['id'];
                        }
                        if (isset($tc['function']['name']) && is_string($tc['function']['name'])) {
                            $toolCalls[$idx]['name'] = $tc['function']['name'];
                        }
                        if (isset($tc['function']['arguments']) && is_string($tc['function']['arguments'])) {
                            $toolCalls[$idx]['args_buffer'] .= $tc['function']['arguments'];
                        }
                    }
                }
            }
            if (isset($choice['finish_reason']) && $choice['finish_reason'] !== null) {
                $finishReason = (string)$choice['finish_reason'];
                if ($finishReason === 'tool_calls') {
                    foreach (self::flushOpenAiToolCalls($toolCalls) as $emitted) {
                        yield $emitted;
                    }
                    $toolCalls = [];
                }
            }
        }
        // Stream ended without [DONE] - treat as mid-stream failure.
        throw new AIParseError('AI provider stream ended before [DONE]');
    }

    /**
     * @param array<int, array{id:string,name:string,args_buffer:string}> $toolCalls
     * @return \Generator<int, array{type:string, id:string, name:string, args:array}>
     */
    private static function flushOpenAiToolCalls(array $toolCalls): \Generator
    {
        foreach ($toolCalls as $tc) {
            $buffer = $tc['args_buffer'] === '' ? '{}' : $tc['args_buffer'];
            try {
                $args = json_decode($buffer, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                throw new AIParseError('AI provider returned malformed tool_call arguments');
            }
            if (!is_array($args)) {
                $args = [];
            }
            yield ['type' => 'tool_call', 'id' => $tc['id'], 'name' => $tc['name'], 'args' => $args];
        }
    }

    /** @return \Generator<int, array{type:string, ...}> */
    private static function yieldAnthropicEvents(\Generator $sseEvents): \Generator
    {
        $toolUses = [];
        $stopReason = null;
        $usage = null;
        foreach ($sseEvents as $ev) {
            $data = $ev['data'];
            if ($data === '' || $data === '[DONE]') {
                continue;
            }
            try {
                $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                throw new AIParseError('AI provider returned malformed stream data');
            }
            if (!is_array($decoded)) {
                continue;
            }
            $type = $decoded['type'] ?? '';
            switch ($type) {
                case 'content_block_start':
                    $block = $decoded['content_block'] ?? [];
                    $idx = $decoded['index'] ?? 0;
                    if (is_array($block) && ($block['type'] ?? '') === 'tool_use') {
                        $toolUses[$idx] = [
                            'id' => (string)($block['id'] ?? ''),
                            'name' => (string)($block['name'] ?? ''),
                            'input_buffer' => '',
                        ];
                    }
                    break;
                case 'content_block_delta':
                    $idx = $decoded['index'] ?? 0;
                    $delta = $decoded['delta'] ?? [];
                    if (!is_array($delta)) {
                        break;
                    }
                    $deltaType = $delta['type'] ?? '';
                    if ($deltaType === 'text_delta') {
                        $text = $delta['text'] ?? '';
                        if (is_string($text) && $text !== '') {
                            yield ['type' => 'text_delta', 'text' => $text];
                        }
                    } elseif ($deltaType === 'input_json_delta') {
                        if (isset($toolUses[$idx]) && isset($delta['partial_json']) && is_string($delta['partial_json'])) {
                            $toolUses[$idx]['input_buffer'] .= $delta['partial_json'];
                        }
                    }
                    break;
                case 'content_block_stop':
                    $idx = $decoded['index'] ?? 0;
                    if (isset($toolUses[$idx])) {
                        $tu = $toolUses[$idx];
                        $buffer = $tu['input_buffer'] === '' ? '{}' : $tu['input_buffer'];
                        try {
                            $args = json_decode($buffer, true, 512, JSON_THROW_ON_ERROR);
                        } catch (\Throwable) {
                            throw new AIParseError('AI provider returned malformed tool_call arguments');
                        }
                        if (!is_array($args)) {
                            $args = [];
                        }
                        yield ['type' => 'tool_call', 'id' => $tu['id'], 'name' => $tu['name'], 'args' => $args];
                        unset($toolUses[$idx]);
                    }
                    break;
                case 'message_delta':
                    $md = $decoded['delta'] ?? [];
                    if (is_array($md) && isset($md['stop_reason']) && $md['stop_reason'] !== null) {
                        $stopReason = (string)$md['stop_reason'];
                    }
                    if (isset($decoded['usage']) && is_array($decoded['usage'])) {
                        // Anthropic emits partial usage on message_delta; keep the latest.
                        $usage = self::normaliseAnthropicUsage($decoded['usage']);
                    }
                    break;
                case 'message_stop':
                    yield ['type' => 'done', 'finish_reason' => $stopReason ?? 'end_turn', 'usage' => $usage];
                    return;
                case 'error':
                    $err = $decoded['error'] ?? [];
                    yield [
                        'type' => 'error',
                        'message' => (string)($err['message'] ?? 'anthropic stream error'),
                        'code' => isset($err['type']) ? (string)$err['type'] : null,
                    ];
                    return;
                case 'message_start':
                    $msg = $decoded['message'] ?? [];
                    if (is_array($msg) && isset($msg['usage']) && is_array($msg['usage'])) {
                        $usage = self::normaliseAnthropicUsage($msg['usage']);
                    }
                    break;
                case 'ping':
                default:
                    break;
            }
        }
        throw new AIParseError('AI provider stream ended before message_stop');
    }

    /** Fold Anthropic's split usage counts into the shared prompt/completion/total shape. */
    private static function normaliseAnthropicUsage(array $usage): array
    {
        $prompt = (int)($usage['input_tokens'] ?? 0);
        $completion = (int)($usage['output_tokens'] ?? 0);
        return [
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $prompt + $completion,
        ];
    }
}
