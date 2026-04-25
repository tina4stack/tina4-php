<?php

/**
 * Tests for Tina4\ErrorOverlay.
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\ErrorOverlay;

class ErrorOverlayTest extends TestCase
{
    private function makeException(): \RuntimeException
    {
        return new \RuntimeException('something broke');
    }

    public function testRenderReturnsHtmlString(): void
    {
        $html = ErrorOverlay::renderErrorOverlay($this->makeException());
        $this->assertIsString($html);
        $this->assertStringStartsWith('<!DOCTYPE html>', $html);
    }

    public function testRenderContainsExceptionType(): void
    {
        $html = ErrorOverlay::renderErrorOverlay($this->makeException());
        $this->assertStringContainsString('RuntimeException', $html);
    }

    public function testRenderContainsExceptionMessage(): void
    {
        $html = ErrorOverlay::renderErrorOverlay($this->makeException());
        $this->assertStringContainsString('something broke', $html);
    }

    public function testRenderContainsFilePath(): void
    {
        $html = ErrorOverlay::renderErrorOverlay($this->makeException());
        $this->assertStringContainsString('ErrorOverlayTest.php', $html);
    }

    public function testRenderContainsSourceCode(): void
    {
        $html = ErrorOverlay::renderErrorOverlay($this->makeException());
        $this->assertStringContainsString('RuntimeException', $html);
    }

    public function testRenderContainsErrorLineMarker(): void
    {
        $html = ErrorOverlay::renderErrorOverlay($this->makeException());
        $this->assertStringContainsString('&#x25b6;', $html);
    }

    public function testRenderWithRequestArray(): void
    {
        $request = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/users',
            'HTTP_HOST' => 'localhost',
        ];
        $html = ErrorOverlay::renderErrorOverlay($this->makeException(), $request);
        $this->assertStringContainsString('GET', $html);
        $this->assertStringContainsString('/api/users', $html);
        $this->assertStringContainsString('localhost', $html);
        $this->assertStringContainsString('Request Details', $html);
    }

    public function testRenderWithoutRequest(): void
    {
        $html = ErrorOverlay::renderErrorOverlay($this->makeException());
        // The collapsible Request Details section should not be rendered
        $this->assertStringNotContainsString('user-select:none;">Request Details</summary>', $html);
    }

    public function testRenderContainsEnvironmentSection(): void
    {
        $html = ErrorOverlay::renderErrorOverlay($this->makeException());
        $this->assertStringContainsString('Environment', $html);
        $this->assertStringContainsString('Tina4 PHP', $html);
        $this->assertStringContainsString('PHP', $html);
    }

    public function testRenderContainsDebugModeFooter(): void
    {
        $html = ErrorOverlay::renderErrorOverlay($this->makeException());
        $this->assertStringContainsString('TINA4_DEBUG', $html);
    }

    public function testRenderEscapesHtmlInMessage(): void
    {
        $e = new \RuntimeException('<script>alert("xss")</script>');
        $html = ErrorOverlay::renderErrorOverlay($e);
        // The XSS guard is specifically about the exception MESSAGE
        // not being rendered as live HTML. The dev toolbar legitimately
        // injects framework-controlled <script> tags (live-reload JS,
        // toolbar widgets) — those are safe by construction. So we
        // assert the user-controlled payload is escaped, not that
        // every <script> tag in the page is gone.
        $this->assertStringNotContainsString('<script>alert("xss")</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $html);
    }

    public function testRenderStackTraceOpen(): void
    {
        $html = ErrorOverlay::renderErrorOverlay($this->makeException());
        $this->assertStringContainsString('Stack Trace', $html);
        $this->assertStringContainsString('<details', $html);
    }

    public function testRenderProductionReturnsHtml(): void
    {
        $html = ErrorOverlay::renderProductionError();
        $this->assertIsString($html);
        $this->assertStringStartsWith('<!DOCTYPE html>', $html);
    }

    public function testRenderProductionContainsStatusCode(): void
    {
        $html = ErrorOverlay::renderProductionError(404, 'Not Found');
        $this->assertStringContainsString('404', $html);
        $this->assertStringContainsString('Not Found', $html);
    }

    public function testRenderProductionNoStackTrace(): void
    {
        $html = ErrorOverlay::renderProductionError();
        $this->assertStringNotContainsString('Stack Trace', $html);
    }

    public function testRenderProductionDefault500(): void
    {
        $html = ErrorOverlay::renderProductionError();
        $this->assertStringContainsString('500', $html);
        $this->assertStringContainsString('Internal Server Error', $html);
    }

    public function testIsDebugModeTrue(): void
    {
        putenv('TINA4_DEBUG=true');
        $this->assertTrue(ErrorOverlay::isDebugMode());
        putenv('TINA4_DEBUG');
    }

    public function testIsDebugModeOne(): void
    {
        putenv('TINA4_DEBUG=1');
        $this->assertTrue(ErrorOverlay::isDebugMode());
        putenv('TINA4_DEBUG');
    }

    public function testIsDebugModeFalseIsFalse(): void
    {
        putenv('TINA4_DEBUG=false');
        $this->assertFalse(ErrorOverlay::isDebugMode());
        putenv('TINA4_DEBUG');
    }

    public function testIsDebugModeEmptyIsFalse(): void
    {
        putenv('TINA4_DEBUG');
        $this->assertFalse(ErrorOverlay::isDebugMode());
    }
}
