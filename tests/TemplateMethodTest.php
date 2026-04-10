<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Response;

class TemplateMethodTest extends TestCase
{
    private string $templateDir;

    protected function setUp(): void
    {
        $this->templateDir = __DIR__ . '/fixtures/templates';
    }

    public function testTemplateRendersHtml(): void
    {
        $response = new Response(testing: true);
        $response->render('greeting.twig', ['name' => 'Alice', 'message' => 'Welcome'], templateDir: $this->templateDir);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html; charset=UTF-8', $response->getHeader('Content-Type'));
        $this->assertStringContainsString('Hello, Alice!', $response->getBody());
        $this->assertStringContainsString('Welcome', $response->getBody());
    }

    public function testTemplateWithCustomStatus(): void
    {
        $response = new Response(testing: true);
        $response->render('greeting.twig', ['name' => 'Bob', 'message' => 'Error page'], status: 500, templateDir: $this->templateDir);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('Hello, Bob!', $response->getBody());
    }

    public function testTemplateReturnsSelf(): void
    {
        $response = new Response(testing: true);
        $result = $response->render('greeting.twig', ['name' => 'Test', 'message' => ''], templateDir: $this->templateDir);

        $this->assertSame($response, $result);
    }

    public function testTemplateNotFoundThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Template not found');

        $response = new Response(testing: true);
        $response->render('nonexistent.twig', [], templateDir: $this->templateDir);
    }

    public function testTemplateDefaultStatusIs200(): void
    {
        $response = new Response(testing: true);
        $response->render('greeting.twig', ['name' => 'Default', 'message' => 'ok'], templateDir: $this->templateDir);

        $this->assertSame(200, $response->getStatusCode());
    }
}
