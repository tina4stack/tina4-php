<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Request;

class RequestV3Test extends TestCase
{
    public function testCreateWithDefaults(): void
    {
        $request = Request::create();
        $this->assertSame('GET', $request->method);
        $this->assertSame('/', $request->path);
        $this->assertSame('127.0.0.1', $request->ip);
        $this->assertSame([], $request->query);
        $this->assertSame([], $request->files);
    }

    public function testCreateWithMethod(): void
    {
        $request = Request::create(method: 'POST');
        $this->assertSame('POST', $request->method);
    }

    public function testMethodIsCaseInsensitive(): void
    {
        $request = Request::create(method: 'post');
        $this->assertSame('POST', $request->method);
    }

    public function testPathParsing(): void
    {
        $request = Request::create(path: '/api/users?page=1&limit=10');
        $this->assertSame('/api/users', $request->path);
        $this->assertSame('/api/users?page=1&limit=10', $request->url);
    }

    public function testQueryParsing(): void
    {
        $request = Request::create(path: '/search?q=hello&page=2');
        $this->assertSame('hello', $request->query['q']);
        $this->assertSame('2', $request->query['page']);
    }

    public function testExplicitQueryParams(): void
    {
        $request = Request::create(query: ['foo' => 'bar', 'baz' => '42']);
        $this->assertSame('bar', $request->query['foo']);
        $this->assertSame('42', $request->query['baz']);
    }

    public function testQueryParamHelper(): void
    {
        $request = Request::create(query: ['name' => 'alice']);
        $this->assertSame('alice', $request->queryParam('name'));
        $this->assertNull($request->queryParam('missing'));
        $this->assertSame('default', $request->queryParam('missing', 'default'));
    }

    public function testJsonBody(): void
    {
        $data = ['name' => 'Alice', 'age' => 30];
        $request = Request::create(
            method: 'POST',
            body: $data,
            headers: ['content-type' => 'application/json'],
        );
        $this->assertSame($data, $request->body);
    }

    public function testInputHelper(): void
    {
        $request = Request::create(body: ['email' => 'a@b.com']);
        $this->assertSame('a@b.com', $request->input('email'));
        $this->assertNull($request->input('missing'));
        $this->assertSame('fallback', $request->input('missing', 'fallback'));
    }

    public function testHeaders(): void
    {
        $request = Request::create(headers: [
            'content-type' => 'application/json',
            'x-custom' => 'test-value',
        ]);
        $this->assertSame('application/json', $request->header('Content-Type'));
        $this->assertSame('test-value', $request->header('x-custom'));
        $this->assertNull($request->header('nonexistent'));
    }

    public function testIpAddress(): void
    {
        $request = Request::create(ip: '192.168.1.100');
        $this->assertSame('192.168.1.100', $request->ip);
    }

    public function testFiles(): void
    {
        $files = ['upload' => ['name' => 'test.txt', 'size' => 1024]];
        $request = Request::create(files: $files);
        $this->assertSame($files, $request->files);
    }

    public function testIsMethod(): void
    {
        $request = Request::create(method: 'PUT');
        $this->assertTrue($request->isMethod('PUT'));
        $this->assertTrue($request->isMethod('put'));
        $this->assertFalse($request->isMethod('GET'));
    }

    public function testWantsJson(): void
    {
        $request = Request::create(headers: ['accept' => 'application/json']);
        $this->assertTrue($request->wantsJson());

        $request2 = Request::create(headers: ['accept' => 'text/html']);
        $this->assertFalse($request2->wantsJson());
    }

    public function testIsJson(): void
    {
        $request = Request::create(headers: ['content-type' => 'application/json; charset=utf-8']);
        $this->assertTrue($request->isJson());

        $request2 = Request::create(headers: ['content-type' => 'text/plain']);
        $this->assertFalse($request2->isJson());
    }

    public function testBearerToken(): void
    {
        $request = Request::create(headers: ['authorization' => 'Bearer abc123xyz']);
        $this->assertSame('abc123xyz', $request->bearerToken());

        $request2 = Request::create(headers: ['authorization' => 'Basic dXNlcjpwYXNz']);
        $this->assertNull($request2->bearerToken());

        $request3 = Request::create();
        $this->assertNull($request3->bearerToken());
    }

    public function testContentType(): void
    {
        $request = Request::create(headers: ['content-type' => 'multipart/form-data']);
        $this->assertSame('multipart/form-data', $request->contentType);
    }

    public function testDynamicParams(): void
    {
        $request = Request::create();
        $request->params = ['id' => '42', 'slug' => 'hello-world'];
        $this->assertSame('42', $request->params['id']);
        $this->assertSame('hello-world', $request->params['slug']);
    }

    public function testNullBody(): void
    {
        $request = Request::create(method: 'GET');
        $this->assertNull($request->body);
    }

    public function testStringBody(): void
    {
        $request = Request::create(body: 'raw text body');
        $this->assertSame('raw text body', $request->rawBody);
    }

    // ── Multipart form data tests ──

    private function buildMultipart(array $fields, array $files = [], string $boundary = 'TestBoundary123'): array
    {
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
            $body .= "{$value}\r\n";
        }
        foreach ($files as $name => $file) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$file['filename']}\"\r\n";
            $body .= "Content-Type: {$file['type']}\r\n\r\n";
            $body .= "{$file['content']}\r\n";
        }
        $body .= "--{$boundary}--\r\n";
        return [
            'body' => $body,
            'content_type' => "multipart/form-data; boundary={$boundary}",
        ];
    }

    public function testMultipartFormFields(): void
    {
        $multi = $this->buildMultipart(['name' => 'Alice', 'email' => 'alice@test.com']);
        $parsed = \Tina4\Server::parseMultipartBody($multi['body'], $multi['content_type']);

        $this->assertSame('Alice', $parsed['fields']['name']);
        $this->assertSame('alice@test.com', $parsed['fields']['email']);
        $this->assertEmpty($parsed['files']);
    }

    public function testMultipartFileUpload(): void
    {
        $multi = $this->buildMultipart(
            ['title' => 'My Photo'],
            ['avatar' => ['filename' => 'photo.png', 'type' => 'image/png', 'content' => 'FAKEPNGDATA']]
        );
        $parsed = \Tina4\Server::parseMultipartBody($multi['body'], $multi['content_type']);

        $this->assertSame('My Photo', $parsed['fields']['title']);
        $this->assertArrayHasKey('avatar', $parsed['files']);
        $this->assertSame('photo.png', $parsed['files']['avatar']['filename']);
        $this->assertSame('image/png', $parsed['files']['avatar']['type']);
        $this->assertSame('FAKEPNGDATA', $parsed['files']['avatar']['content']);
        $this->assertSame(11, $parsed['files']['avatar']['size']);
    }

    public function testMultipartMultipleFiles(): void
    {
        $multi = $this->buildMultipart(
            ['description' => 'Uploads'],
            [
                'photo' => ['filename' => 'a.jpg', 'type' => 'image/jpeg', 'content' => 'JPG1'],
                'doc' => ['filename' => 'b.pdf', 'type' => 'application/pdf', 'content' => 'PDF2'],
            ]
        );
        $parsed = \Tina4\Server::parseMultipartBody($multi['body'], $multi['content_type']);

        $this->assertCount(2, $parsed['files']);
        $this->assertSame('a.jpg', $parsed['files']['photo']['filename']);
        $this->assertSame('b.pdf', $parsed['files']['doc']['filename']);
    }

    public function testMultipartNoBoundary(): void
    {
        $parsed = \Tina4\Server::parseMultipartBody('no data', 'multipart/form-data');
        $this->assertEmpty($parsed['fields']);
        $this->assertEmpty($parsed['files']);
    }

    public function testMultipartEmptyFilename(): void
    {
        // Browser sends empty filename when no file is selected
        $boundary = 'Bound123';
        $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"upload\"; filename=\"\"\r\nContent-Type: application/octet-stream\r\n\r\n\r\n--{$boundary}--\r\n";
        $parsed = \Tina4\Server::parseMultipartBody($body, "multipart/form-data; boundary={$boundary}");

        // Empty filename should still produce a file entry (with empty name)
        $this->assertArrayHasKey('upload', $parsed['files']);
        $this->assertSame('', $parsed['files']['upload']['filename']);
    }

    public function testMultipartBinaryContent(): void
    {
        // File content containing boundary-like strings shouldn't break parsing
        $boundary = 'SimpleBound';
        $binaryContent = "line1\r\nline2\r\n--NotTheBoundary\r\nline3";
        $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"data.bin\"\r\nContent-Type: application/octet-stream\r\n\r\n{$binaryContent}\r\n--{$boundary}--\r\n";
        $parsed = \Tina4\Server::parseMultipartBody($body, "multipart/form-data; boundary={$boundary}");

        $this->assertSame($binaryContent, $parsed['files']['file']['content']);
    }

    public function testMultipartQuotedBoundary(): void
    {
        // Some clients quote the boundary value
        $multi = $this->buildMultipart(['key' => 'value'], [], 'QuotedBound');
        $parsed = \Tina4\Server::parseMultipartBody(
            $multi['body'],
            'multipart/form-data; boundary="QuotedBound"'
        );

        $this->assertSame('value', $parsed['fields']['key']);
    }
}
