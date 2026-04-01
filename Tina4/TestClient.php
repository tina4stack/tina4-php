<?php

/**
 * Tina4 - This is not a 4ramework.
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

        // Dispatch through the router
        $response = Router::dispatch($request, new Response());

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

    public function __construct(Response $response)
    {
        $this->status = $response->getStatusCode() ?? 200;
        $this->body = $response->getBody() ?? '';
        $this->headers = $response->getHeaders();
        $this->contentType = $this->headers['content-type'] ?? '';
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
