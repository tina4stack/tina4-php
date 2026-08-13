<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Test Client — Test routes without starting a server.
 *
 * Usage:
 *
 *     $client = new TestClient();
 *
 *     $response = $client->get("/api/users");
 *     assert($response->status === 200);
 *     assert($response->json()["users"] !== null);
 *
 *     $response = $client->post("/api/users", json: ["name" => "Alice"]);
 *     assert($response->status === 201);
 *
 *     $response = $client->get("/api/users/1", headers: ["Authorization" => "Bearer token123"]);
 */
class TestClient
{
    /**
     * Send a GET request.
     */
    public function get(string $path, ?array $headers = null): TestResponse
    {
        return $this->request('GET', $path, headers: $headers);
    }

    /**
     * Send a POST request.
     */
    public function post(string $path, ?array $json = null, ?string $body = null, ?array $headers = null): TestResponse
    {
        return $this->request('POST', $path, json: $json, body: $body, headers: $headers);
    }

    /**
     * Send a PUT request.
     */
    public function put(string $path, ?array $json = null, ?string $body = null, ?array $headers = null): TestResponse
    {
        return $this->request('PUT', $path, json: $json, body: $body, headers: $headers);
    }

    /**
     * Send a PATCH request.
     */
    public function patch(string $path, ?array $json = null, ?string $body = null, ?array $headers = null): TestResponse
    {
        return $this->request('PATCH', $path, json: $json, body: $body, headers: $headers);
    }

    /**
     * Send a DELETE request.
     */
    public function delete(string $path, ?array $headers = null): TestResponse
    {
        return $this->request('DELETE', $path, headers: $headers);
    }

    /**
     * Build a mock Request, match the route, execute the handler, return a TestResponse.
     */
    private function request(
        string $method,
        string $path,
        ?array $json = null,
        ?string $body = null,
        ?array $headers = null,
    ): TestResponse {
        // Build raw body and content type
        $rawBody = '';
        $contentType = '';

        if ($json !== null) {
            $rawBody = json_encode($json);
            $contentType = 'application/json';
        } elseif ($body !== null) {
            $rawBody = $body;
        }

        // Build headers
        $reqHeaders = [];
        if ($headers !== null) {
            foreach ($headers as $k => $v) {
                $reqHeaders[strtolower($k)] = $v;
            }
        }
        if ($contentType && !isset($reqHeaders['content-type'])) {
            $reqHeaders['content-type'] = $contentType;
        }
        if ($rawBody !== '' && !isset($reqHeaders['content-length'])) {
            $reqHeaders['content-length'] = (string) strlen($rawBody);
        }

        // Split path and query string
        $query = null;
        $cleanPath = $path;
        if (str_contains($path, '?')) {
            [$cleanPath, $queryString] = explode('?', $path, 2);
            parse_str($queryString, $query);
        }

        // Create the Request
        $request = Request::create(
            method: $method,
            path: $cleanPath,
            query: $query,
            body: $rawBody ?: null,
            headers: $reqHeaders,
            ip: '127.0.0.1',
        );

        // Dispatch through the router. testing: true marks the Response as
        // one nobody will ever ->send() over a real transport — Router's
        // session-cookie emission reads it (Response::isTesting()) to skip
        // straight to attaching Set-Cookie onto the Response instead of
        // calling PHP's native setcookie(), which a CLI process like this one
        // has no real SAPI to send it through (feature 131, TC-DEC-02: without
        // this a TestClient-driven login route could set request->session but
        // the session cookie never reached TestResponse).
        $response = Router::dispatch($request, new Response(testing: true));

        return new TestResponse($response);
    }
}

/**
 * Wraps a Response object with a clean test-friendly API.
 */
class TestResponse
{
    public readonly int $status;
    public readonly string $body;
    public readonly array $headers;
    public readonly string $contentType;

    /** @var array<string, string[]> Every value sent per header name (lowercased), in emission order. */
    private readonly array $headerList;

    public function __construct(Response $response)
    {
        $body = $response->getBody() ?? '';

        // TestClient is a transport like any other, so a streamed body (SSE)
        // has to be driven here — otherwise a streaming route would report an
        // empty body and every assertion against it would prove nothing.
        if ($body === '' && $response->isStreaming()) {
            foreach ($response->streamChunks() as $chunk) {
                $body .= $chunk;
            }
        }

        $this->status = $response->getStatusCode() ?? 200;
        $this->body = $body;

        // Build the multi-map. getHeaders() is a plain name=>value array (a
        // header set twice already overwrote itself at the source, Response::
        // header()) — one value each. Cookies live SEPARATELY precisely so
        // more than one survives (Response::cookie() keys its own store by
        // cookie NAME, not header name), and getHeaders() never included
        // them at all — a real gap, not a collapse: TestResponse could not
        // see a Set-Cookie before this fix. Response::cookieHeaderLines() is
        // the SAME builder Server.php's raw-socket writer renders from, so a
        // cookie set twice comes out identical whether the request went over
        // a real socket or through TestClient (feature 131, TC-DEC-02).
        $list = [];
        foreach ($response->getHeaders() as $name => $value) {
            $list[strtolower($name)][] = $value;
        }
        foreach ($response->cookieHeaderLines() as $cookie) {
            $list['set-cookie'][] = $cookie;
        }
        $this->headerList = $list;

        // `headers` stays the back-compat single-value view — the LAST value
        // per name, same shape every existing reader already expects.
        $flat = [];
        foreach ($list as $name => $values) {
            $flat[$name] = $values[array_key_last($values)];
        }
        $this->headers = $flat;
        $this->contentType = $this->headers['content-type'] ?? '';
    }

    /**
     * Every value sent for $name (case-insensitive), in emission order.
     *
     * A header sent once returns a one-item list; a header never sent
     * returns an empty array. This is the one place a duplicate response
     * header (two Set-Cookie) is visible — headers[name] always collapses to
     * the LAST value, same as before (TC-HEADER-COLLAPSE, TC-DEC-02).
     *
     * @return string[]
     */
    public function getList(string $name): array
    {
        return $this->headerList[strtolower($name)] ?? [];
    }

    /**
     * Parse body as JSON.
     */
    public function json(): array|null
    {
        if ($this->body === '') {
            return null;
        }
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Return body as a string.
     */
    public function text(): string
    {
        return $this->body;
    }

    public function __toString(): string
    {
        return "<TestResponse status={$this->status} content_type=\"{$this->contentType}\">";
    }
}
