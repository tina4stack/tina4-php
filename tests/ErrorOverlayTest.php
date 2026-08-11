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
        // The overlay reads the throwing file and renders a numbered source
        // window around the failing line. makeException() constructs the
        // exception on a line whose literal source contains this exact
        // expression, so the rendered (HTML-escaped) source must include it.
        $html = ErrorOverlay::renderErrorOverlay($this->makeException());
        $this->assertStringContainsString(
            "new \\RuntimeException(&#039;something broke&#039;)",
            $html,
            'overlay must render the actual source line that threw'
        );
        // And the error line itself must carry the arrow marker.
        $this->assertStringContainsString('&#x25b6;', $html);
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

    /**
     * When a frame's source file is modified AFTER the overlay was
     * generated, the rendered HTML must surface a "FILE MODIFIED @ ..."
     * pill so the user knows the displayed source may not match what
     * actually raised the error. We force this by throwing an exception
     * inside a tempfile we control, then touching the file forward.
     *
     * We match the badge's structural HTML rather than the bare
     * "FILE MODIFIED" string — because the overlay also renders a
     * snippet of the test source code, and this test file literally
     * contains the words "FILE MODIFIED" in its assertions, which
     * would produce false positives.
     */
    public function testStaleBadgeAppearsWhenFileModifiedAfterCapture(): void
    {
        $tmp = \TempPath::file('tina4_overlay_', '.php');
        file_put_contents($tmp, "<?php\nfunction tina4_overlay_stale_trigger() {\n    throw new \\RuntimeException('boom from temp');\n}\n");
        try {
            require $tmp;
            try {
                tina4_overlay_stale_trigger();
                $this->fail('expected exception');
            } catch (\RuntimeException $e) {
                // Push the file's mtime well past "now" so it appears
                // newer than the overlay's capturedAt stamp.
                touch($tmp, time() + 5);
                clearstatcache(true, $tmp);
                $html = ErrorOverlay::renderErrorOverlay($e);
                // Match the actual badge HTML — the peach background
                // + font-weight 700 is the signature of the stale pill,
                // never produced by rendered source-code snippets.
                $this->assertMatchesRegularExpression(
                    '/background:#fab387;[^"]*">\s*FILE MODIFIED @ \d{2}:\d{2}:\d{2} UTC/',
                    $html
                );
                $this->assertStringContainsString('source may not match what failed</span>', $html);
            }
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Conversely, when the file is older than the overlay's
     * capturedAt stamp, the stale badge must NOT appear. Touch the
     * file backwards to make it definitively pre-overlay.
     *
     * Match the badge's structural HTML — checking for the bare
     * substring "FILE MODIFIED" would false-positive on the rendered
     * source snippet of this very test file (which contains those
     * words in its assertions).
     */
    public function testStaleBadgeAbsentWhenFileUnchanged(): void
    {
        $tmp = \TempPath::file('tina4_overlay_', '.php');
        file_put_contents($tmp, "<?php\nfunction tina4_overlay_fresh_trigger() {\n    throw new \\RuntimeException('boom from temp fresh');\n}\n");
        try {
            require $tmp;
            try {
                // Backdate the file before throwing — when the overlay
                // stamps capturedAt = now, the file mtime is well in
                // the past, so no badge should appear.
                touch($tmp, time() - 10);
                clearstatcache(true, $tmp);
                tina4_overlay_fresh_trigger();
                $this->fail('expected exception');
            } catch (\RuntimeException $e) {
                $html = ErrorOverlay::renderErrorOverlay($e);
                // Look for the badge HTML signature, not the bare
                // "FILE MODIFIED" substring (which appears in the
                // source-code preview of this very test file).
                $this->assertDoesNotMatchRegularExpression(
                    '/background:#fab387;[^"]*">\s*FILE MODIFIED @/',
                    $html
                );
            }
        } finally {
            @unlink($tmp);
        }
    }
}
