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

    // ── Status codes ────────────────────────────────────────────

    public function testStatus201Created(): void
    {
        $response = new Response(testing: true);
        $response->json(['id' => 1], 201);
        $this->assertSame(201, $response->getStatusCode());
    }

    public function testStatus204NoContent(): void
    {
        $response = new Response(testing: true);
        $response->status(204);
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', $response->getBody());
    }

    public function testStatus400BadRequest(): void
    {
        $response = new Response(testing: true);
        $response->json(['error' => 'Bad Request'], 400);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testStatus403Forbidden(): void
    {
        $response = new Response(testing: true);
        $response->json(['error' => 'Forbidden'], 403);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testStatus500ServerError(): void
    {
        $response = new Response(testing: true);
        $response->html('<h1>Internal Server Error</h1>', 500);
        $this->assertSame(500, $response->getStatusCode());
    }

    public function testStatus503ServiceUnavailable(): void
    {
        $response = new Response(testing: true);
        $response->status(503);
        $this->assertSame(503, $response->getStatusCode());
    }

    // ── JSON responses ──────────────────────────────────────────

    public function testJsonNestedData(): void
    {
        $response = new Response(testing: true);
        $data = ['users' => [['id' => 1, 'name' => 'Alice'], ['id' => 2, 'name' => 'Bob']]];
        $response->json($data);

        $decoded = $response->getJsonBody();
        $this->assertCount(2, $decoded['users']);
        $this->assertSame('Alice', $decoded['users'][0]['name']);
    }

    public function testJsonEmptyArray(): void
    {
        $response = new Response(testing: true);
        $response->json([]);
        $this->assertSame([], $response->getJsonBody());
    }

    public function testJsonNullValue(): void
    {
        $response = new Response(testing: true);
        $response->json(null);
        $this->assertNull($response->getJsonBody());
    }

    public function testJsonWithUnicode(): void
    {
        $response = new Response(testing: true);
        $response->json(['greeting' => 'Bonjour le monde']);
        $body = $response->getBody();
        $this->assertStringContainsString('Bonjour', $body);
    }

    public function testJsonWithSlashes(): void
    {
        $response = new Response(testing: true);
        $response->json(['url' => 'https://example.com/path']);
        $body = $response->getBody();
        // JSON_UNESCAPED_SLASHES should preserve slashes
        $this->assertStringContainsString('https://example.com/path', $body);
    }

    // ── HTML responses ──────────────────────────────────────────

    public function testHtmlEmptyBody(): void
    {
        $response = new Response(testing: true);
        $response->html('');
        $this->assertSame('', $response->getBody());
        $this->assertSame('text/html; charset=UTF-8', $response->getHeader('Content-Type'));
    }

    public function testHtmlLargeContent(): void
    {
        $response = new Response(testing: true);
        $content = '<html><body>' . str_repeat('<p>Paragraph</p>', 1000) . '</body></html>';
        $response->html($content);
        $this->assertSame($content, $response->getBody());
    }

    // ── Text responses ──────────────────────────────────────────

    public function testTextEmpty(): void
    {
        $response = new Response(testing: true);
        $response->text('');
        $this->assertSame('', $response->getBody());
        $this->assertSame('text/plain; charset=UTF-8', $response->getHeader('Content-Type'));
    }

    public function testTextWithStatus(): void
    {
        $response = new Response(testing: true);
        $response->text('Accepted', 202);
        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('Accepted', $response->getBody());
    }

    // ── Redirect responses ──────────────────────────────────────

    public function testRedirect303SeeOther(): void
    {
        $response = new Response(testing: true);
        $response->redirect('/dashboard', 303);
        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/dashboard', $response->getHeader('Location'));
    }

    public function testRedirect307TemporaryRedirect(): void
    {
        $response = new Response(testing: true);
        $response->redirect('/temp', 307);
        $this->assertSame(307, $response->getStatusCode());
    }

    public function testRedirectWithQueryString(): void
    {
        $response = new Response(testing: true);
        $response->redirect('/search?q=hello&page=1');
        $this->assertSame('/search?q=hello&page=1', $response->getHeader('Location'));
    }

    // ── Custom headers ──────────────────────────────────────────

    public function testGetHeaderReturnsNullForMissing(): void
    {
        $response = new Response(testing: true);
        $this->assertNull($response->getHeader('X-Nonexistent'));
    }

    public function testHeaderOverwrite(): void
    {
        $response = new Response(testing: true);
        $response->header('X-Custom', 'first');
        $response->header('X-Custom', 'second');
        $this->assertSame('second', $response->getHeader('X-Custom'));
    }

    public function testWithHeadersMerges(): void
    {
        $response = new Response(testing: true);
        $response->header('X-Existing', 'keep');
        $response->withHeaders(['X-New' => 'added']);
        $this->assertSame('keep', $response->getHeader('X-Existing'));
        $this->assertSame('added', $response->getHeader('X-New'));
    }

    // ── Content type detection via __invoke ──────────────────────

    public function testInvokeWithArray(): void
    {
        $response = new Response(testing: true);
        $response(['key' => 'value']);
        $this->assertSame('application/json', $response->getHeader('Content-Type'));
        $this->assertSame('value', $response->getJsonBody()['key']);
    }

    public function testInvokeWithHtmlString(): void
    {
        $response = new Response(testing: true);
        $response('<div>hello</div>');
        $this->assertSame('text/html; charset=UTF-8', $response->getHeader('Content-Type'));
    }

    public function testInvokeWithPlainString(): void
    {
        $response = new Response(testing: true);
        $response('just text');
        $this->assertSame('text/plain; charset=UTF-8', $response->getHeader('Content-Type'));
    }

    public function testInvokeWithNull(): void
    {
        $response = new Response(testing: true);
        $response(null);
        $this->assertSame('', $response->getBody());
    }

    public function testInvokeWithExplicitContentType(): void
    {
        $response = new Response(testing: true);
        $response('csv,data', 200, 'text/csv');
        $this->assertSame('text/csv', $response->getHeader('Content-Type'));
        $this->assertSame('csv,data', $response->getBody());
    }

    public function testInvokeWithArrayAndExplicitContentType(): void
    {
        $response = new Response(testing: true);
        $response(['ok' => true], 200, 'application/json');
        $this->assertSame('application/json', $response->getHeader('Content-Type'));
        $this->assertTrue($response->getJsonBody()['ok']);
    }

    public function testInvokeWithStatusCode(): void
    {
        $response = new Response(testing: true);
        $response(['error' => 'bad'], 422);
        $this->assertSame(422, $response->getStatusCode());
    }

    // ── setBody / getContentType ────────────────────────────────

    public function testSetBody(): void
    {
        $response = new Response(testing: true);
        $result = $response->setBody('custom body');
        $this->assertSame('custom body', $response->getBody());
        $this->assertSame($response, $result); // fluent
    }

    public function testGetContentType(): void
    {
        $response = new Response(testing: true);
        $this->assertNull($response->getContentType());

        $response->json(['ok' => true]);
        $this->assertSame('application/json', $response->getContentType());
    }

    // ── Cookie with expiry and domain ───────────────────────────

    public function testCookieWithExpiry(): void
    {
        $response = new Response(testing: true);
        $expires = time() + 86400;
        $response->cookie('token', 'xyz', [
            'expires' => $expires,
            'domain' => '.example.com',
        ]);

        $cookies = $response->getCookies();
        $this->assertSame($expires, $cookies['token']['expires']);
        $this->assertSame('.example.com', $cookies['token']['domain']);
    }

    public function testCookieOverwrite(): void
    {
        $response = new Response(testing: true);
        $response->cookie('pref', 'light');
        $response->cookie('pref', 'dark');

        $cookies = $response->getCookies();
        $this->assertCount(1, $cookies);
        $this->assertSame('dark', $cookies['pref']['value']);
    }

    // ── isSent behavior ─────────────────────────────────────────

    public function testIsNotSentByDefault(): void
    {
        $response = new Response(testing: true);
        $this->assertFalse($response->isSent());
    }
}
