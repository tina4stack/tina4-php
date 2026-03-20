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
        $html = ErrorOverlay::render($this->makeException());
        $this->assertIsString($html);
        $this->assertStringStartsWith('<!DOCTYPE html>', $html);
    }

    public function testRenderContainsExceptionType(): void
    {
        $html = ErrorOverlay::render($this->makeException());
        $this->assertStringContainsString('RuntimeException', $html);
    }

    public function testRenderContainsExceptionMessage(): void
    {
        $html = ErrorOverlay::render($this->makeException());
        $this->assertStringContainsString('something broke', $html);
    }

    public function testRenderContainsFilePath(): void
    {
        $html = ErrorOverlay::render($this->makeException());
        $this->assertStringContainsString('ErrorOverlayTest.php', $html);
    }

    public function testRenderContainsSourceCode(): void
    {
        $html = ErrorOverlay::render($this->makeException());
        $this->assertStringContainsString('RuntimeException', $html);
    }

    public function testRenderContainsErrorLineMarker(): void
    {
        $html = ErrorOverlay::render($this->makeException());
        $this->assertStringContainsString('&#x25b6;', $html);
    }

    public function testRenderWithRequestArray(): void
    {
        $request = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/users',
            'HTTP_HOST' => 'localhost',
        ];
        $html = ErrorOverlay::render($this->makeException(), $request);
        $this->assertStringContainsString('GET', $html);
        $this->assertStringContainsString('/api/users', $html);
        $this->assertStringContainsString('localhost', $html);
        $this->assertStringContainsString('Request Details', $html);
    }

    public function testRenderWithoutRequest(): void
    {
        $html = ErrorOverlay::render($this->makeException());
        // The collapsible Request Details section should not be rendered
        $this->assertStringNotContainsString('user-select:none;">Request Details</summary>', $html);
    }

    public function testRenderContainsEnvironmentSection(): void
    {
        $html = ErrorOverlay::render($this->makeException());
        $this->assertStringContainsString('Environment', $html);
        $this->assertStringContainsString('Tina4 PHP', $html);
        $this->assertStringContainsString('PHP', $html);
    }

    public function testRenderContainsDebugModeFooter(): void
    {
        $html = ErrorOverlay::render($this->makeException());
        $this->assertStringContainsString('TINA4_DEBUG_LEVEL', $html);
    }

    public function testRenderEscapesHtmlInMessage(): void
    {
        $e = new \RuntimeException('<script>alert("xss")</script>');
        $html = ErrorOverlay::render($e);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testRenderStackTraceOpen(): void
    {
        $html = ErrorOverlay::render($this->makeException());
        $this->assertStringContainsString('Stack Trace', $html);
        $this->assertStringContainsString('<details', $html);
    }

    public function testRenderProductionReturnsHtml(): void
    {
        $html = ErrorOverlay::renderProduction();
        $this->assertIsString($html);
        $this->assertStringStartsWith('<!DOCTYPE html>', $html);
    }

    public function testRenderProductionContainsStatusCode(): void
    {
        $html = ErrorOverlay::renderProduction(404, 'Not Found');
        $this->assertStringContainsString('404', $html);
        $this->assertStringContainsString('Not Found', $html);
    }

    public function testRenderProductionNoStackTrace(): void
    {
        $html = ErrorOverlay::renderProduction();
        $this->assertStringNotContainsString('Stack Trace', $html);
    }

    public function testRenderProductionDefault500(): void
    {
        $html = ErrorOverlay::renderProduction();
        $this->assertStringContainsString('500', $html);
        $this->assertStringContainsString('Internal Server Error', $html);
    }

    public function testIsDebugModeAll(): void
    {
        putenv('TINA4_DEBUG_LEVEL=ALL');
        $this->assertTrue(ErrorOverlay::isDebugMode());
        putenv('TINA4_DEBUG_LEVEL');
    }

    public function testIsDebugModeDebug(): void
    {
        putenv('TINA4_DEBUG_LEVEL=DEBUG');
        $this->assertTrue(ErrorOverlay::isDebugMode());
        putenv('TINA4_DEBUG_LEVEL');
    }

    public function testIsDebugModeWarningIsFalse(): void
    {
        putenv('TINA4_DEBUG_LEVEL=WARNING');
        $this->assertFalse(ErrorOverlay::isDebugMode());
        putenv('TINA4_DEBUG_LEVEL');
    }

    public function testIsDebugModeEmptyIsFalse(): void
    {
        putenv('TINA4_DEBUG_LEVEL');
        $this->assertFalse(ErrorOverlay::isDebugMode());
    }
}
