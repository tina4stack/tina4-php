<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Response;

class ResponseV3Test extends TestCase
{
    public function testJsonResponse(): void
    {
        $response = new Response(testing: true);
        $response->json(['name' => 'Alice', 'age' => 30]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeader('Content-Type'));
        $this->assertSame(['name' => 'Alice', 'age' => 30], $response->getJsonBody());
    }

    public function testJsonWithCustomStatus(): void
    {
        $response = new Response(testing: true);
        $response->json(['error' => 'Not Found'], 404);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not Found', $response->getJsonBody()['error']);
    }

    public function testHtmlResponse(): void
    {
        $response = new Response(testing: true);
        $response->html('<h1>Hello</h1>');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html; charset=UTF-8', $response->getHeader('Content-Type'));
        $this->assertSame('<h1>Hello</h1>', $response->getBody());
    }

    public function testHtmlWithCustomStatus(): void
    {
        $response = new Response(testing: true);
        $response->html('<h1>Server Error</h1>', 500);

        $this->assertSame(500, $response->getStatusCode());
    }

    public function testTextResponse(): void
    {
        $response = new Response(testing: true);
        $response->text('Hello, World!');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/plain; charset=UTF-8', $response->getHeader('Content-Type'));
        $this->assertSame('Hello, World!', $response->getBody());
    }

    public function testRedirectResponse(): void
    {
        $response = new Response(testing: true);
        $response->redirect('https://example.com');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('https://example.com', $response->getHeader('Location'));
    }

    public function testPermanentRedirect(): void
    {
        $response = new Response(testing: true);
        $response->redirect('/new-url', 301);

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/new-url', $response->getHeader('Location'));
    }

    public function testStatusSetter(): void
    {
        $response = new Response(testing: true);
        $result = $response->status(204);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame($response, $result); // Fluent interface
    }

    public function testHeaderSetter(): void
    {
        $response = new Response(testing: true);
        $result = $response->header('X-Custom', 'test-value');

        $this->assertSame('test-value', $response->getHeader('X-Custom'));
        $this->assertSame($response, $result);
    }

    public function testWithHeaders(): void
    {
        $response = new Response(testing: true);
        $response->withHeaders([
            'X-One' => 'alpha',
            'X-Two' => 'bravo',
        ]);

        $this->assertSame('alpha', $response->getHeader('X-One'));
        $this->assertSame('bravo', $response->getHeader('X-Two'));
    }

    public function testCookie(): void
    {
        $response = new Response(testing: true);
        $response->cookie('session', 'abc123', [
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        $cookies = $response->getCookies();
        $this->assertArrayHasKey('session', $cookies);
        $this->assertSame('abc123', $cookies['session']['value']);
        $this->assertTrue($cookies['session']['secure']);
        $this->assertSame('Strict', $cookies['session']['samesite']);
    }

    public function testCookieDefaults(): void
    {
        $response = new Response(testing: true);
        $response->cookie('pref', 'dark');

        $cookies = $response->getCookies();
        $this->assertSame('/', $cookies['pref']['path']);
        $this->assertFalse($cookies['pref']['secure']);
        $this->assertTrue($cookies['pref']['httponly']);
        $this->assertSame('Lax', $cookies['pref']['samesite']);
    }

    public function testSend(): void
    {
        $response = new Response(testing: true);
        $response->json(['ok' => true]);
        $response->send();

        $this->assertTrue($response->isSent());
    }

    public function testSendOnlyOnce(): void
    {
        $response = new Response(testing: true);
        $response->json(['ok' => true]);
        $response->send();
        $response->send(); // Should be a no-op

        $this->assertTrue($response->isSent());
    }

    public function testGetAllHeaders(): void
    {
        $response = new Response(testing: true);
        $response->header('X-One', 'alpha');
        $response->header('X-Two', 'bravo');

        $headers = $response->getHeaders();
        $this->assertCount(2, $headers);
        $this->assertSame('alpha', $headers['X-One']);
    }

    public function testFluentChaining(): void
    {
        $response = new Response(testing: true);
        $result = $response
            ->status(201)
            ->header('X-Custom', 'value')
            ->json(['created' => true], 201);

        $this->assertSame(201, $result->getStatusCode());
        $this->assertSame('value', $result->getHeader('X-Custom'));
    }

    public function testGetBodyEmptyByDefault(): void
    {
        $response = new Response(testing: true);
        $this->assertSame('', $response->getBody());
    }

    public function testGetJsonBodyNull(): void
    {
        $response = new Response(testing: true);
        $this->assertNull($response->getJsonBody());
    }

    public function testDefaultStatusCode(): void
    {
        $response = new Response(testing: true);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testMultipleCookies(): void
    {
        $response = new Response(testing: true);
        $response->cookie('one', 'a');
        $response->cookie('two', 'b');

        $cookies = $response->getCookies();
        $this->assertCount(2, $cookies);
        $this->assertArrayHasKey('one', $cookies);
        $this->assertArrayHasKey('two', $cookies);
    }
}
