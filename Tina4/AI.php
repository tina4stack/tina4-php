<?php

namespace Tina4;

/** Zero-dependency app-facing AI client (ADR-0053). */
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
                || !is_string($message['content'] ?? null)) {
                throw new AIConfigError('Each AI message needs a supported role and string content');
            }
        }
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
        $body = ['model' => $config['model'], 'messages' => $messages, 'stream' => $stream];
        if ($temperature !== null) {
            $body['temperature'] = $temperature;
        }
        if ($maxTokens !== null) {
            $body['max_tokens'] = $maxTokens;
        }
        if ($config['provider'] === 'anthropic') {
            $system = [];
            $body['messages'] = [];
            foreach ($messages as $message) {
                if ($message['role'] === 'system') {
                    $system[] = $message['content'];
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

    /** @return array{0:bool,1:?string} */
    private static function streamDelta(string $provider, string $data): array
    {
        if ($data === '[DONE]') {
            return [true, null];
        }
        try {
            $event = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            $text = $provider === 'anthropic'
                ? (($event['type'] ?? null) === 'content_block_delta' ? ($event['delta']['text'] ?? null) : null)
                : ($event['choices'][0]['delta']['content'] ?? null);
        } catch (\Throwable) {
            throw new AIParseError('AI provider returned malformed stream data');
        }
        if ($text !== null && !is_string($text)) {
            throw new AIParseError('AI provider returned malformed stream data');
        }
        return [false, $text];
    }

    /** @return \Generator<string> */
    private static function streamData($stream, array $headers, float $deadline): \Generator
    {
        $buffer = '';
        foreach (self::bodyChunks($stream, $headers, $deadline) as $chunk) {
            $buffer .= $chunk;
            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $newline));
                $buffer = substr($buffer, $newline + 1);
                if (str_starts_with($line, 'data:')) {
                    yield trim(substr($line, 5));
                }
            }
        }
    }

    private static function stream(array $config, array $headers, array $body): \Generator
    {
        $deadline = microtime(true) + $config['total_timeout'];
        for ($attempt = 0; $attempt <= $config['max_retries']; $attempt++) {
            $stream = null;
            $yielded = false;
            try {
                $headers['Accept'] = 'text/event-stream';
                [$stream, $status, $responseHeaders] = self::open($config, $deadline, $headers, $body);
                if ($status < 200 || $status >= 300) {
                    iterator_to_array(self::bodyChunks($stream, $responseHeaders, $deadline));
                    fclose($stream);
                    $stream = null;
                    if (($status === 429 || $status >= 500) && $attempt < $config['max_retries']) {
                        self::retryDelay($responseHeaders, $deadline);
                        continue;
                    }
                    throw new AIHTTPError("AI provider returned HTTP {$status}", $status);
                }
                foreach (self::streamData($stream, $responseHeaders, $deadline) as $data) {
                    [$completed, $text] = self::streamDelta($config['provider'], $data);
                    if ($completed) {
                        fclose($stream);
                        return;
                    }
                    if ($text !== null) {
                        $yielded = true;
                        yield $text;
                    }
                }
                fclose($stream);
                $stream = null;
                throw new AIParseError('AI provider stream ended before [DONE]');
            } catch (AITimeoutError|AIHTTPError|AIParseError $error) {
                if ($stream !== null && is_resource($stream)) {
                    fclose($stream);
                }
                if ($error instanceof AIParseError) {
                    throw $error;
                }
                if ($yielded || $attempt >= $config['max_retries']) {
                    throw $error;
                }
            }
        }
    }
}
