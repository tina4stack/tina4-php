<?php

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Frond;
use Tina4\SafeString;

/**
 * Frond auto-escaping hardening -- F1, F2, F3, F5, F7 (ADR-0077). Real renders.
 * Payloads are inert markup used only to prove the escaper transforms the
 * dangerous characters. Mutation-proved (revert the fix, watch each go red).
 */
class FrondEscapingHardeningTest extends TestCase
{
    private function frond(): Frond
    {
        return new Frond(__DIR__ . '/templates');
    }

    // F1: trusted output is a SafeString type, never an in-band marker string.
    public function testMarkerBytesInUserDataAreNotTreatedAsRaw(): void
    {
        $f = $this->frond();
        // A user value that contains the old marker bytes must still be escaped.
        $payload = "\x00FROND_RAW\x00<i>x</i>";
        $out = $f->renderString('{{ q }}', ['q' => $payload]);
        // The marker bytes are now inert data; the markup after them is escaped.
        $this->assertStringNotContainsString('<i>x</i>', $out);
        $this->assertStringContainsString('&lt;i&gt;', $out);
    }

    // F2: a filter after e/escape/raw yields a plain string -> re-escaped.
    public function testFilterAfterEscapeReescapes(): void
    {
        $f = $this->frond();
        $out = $f->renderString("{{ 'Hi @@'|e|replace({'@@': u}) }}", ['u' => '<i>x</i>']);
        $this->assertStringNotContainsString('<i>x</i>', $out);
    }

    public function testSetBlockThenReplaceReescapes(): void
    {
        $f = $this->frond();
        $out = $f->renderString("{% set t %}Hi @@{% endset %}{{ t|replace({'@@': u}) }}", ['u' => '<i>x</i>']);
        $this->assertStringNotContainsString('<i>x</i>', $out);
    }

    // F3: data_uri must not let a client MIME type break out of the attribute.
    public function testDataUriMimeIsSanitised(): void
    {
        $f = $this->frond();
        $out = $f->renderString('<img src="{{ f|data_uri }}">', [
            'f' => ['type' => 'image/png"><i>x</i>', 'content' => 'x'],
        ]);
        // The malformed MIME type is rejected -> no attribute breakout.
        $this->assertStringNotContainsString('<i>x</i>', $out);
        $this->assertStringContainsString('data:application/octet-stream;base64,', $out);
    }

    // F5: js_escape neutralises HTML-significant characters.
    public function testJsEscapeNeutralisesMarkup(): void
    {
        $f = $this->frond();
        $out = $f->renderString('{{ u|js_escape }}', ['u' => "</i>&'\""]);
        foreach (['<', '>', '&', '/', "'", '"'] as $bad) {
            $this->assertStringNotContainsString($bad, $out, "left $bad literal");
        }
    }

    // F7: e(strategy) honours js/url/css/html_attr.
    public function testEUrlStrategyPercentEncodes(): void
    {
        $f = $this->frond();
        $this->assertSame('a%20b%26c%2Fd', $f->renderString("{{ u|e('url') }}", ['u' => 'a b&c/d']));
    }

    public function testEHtmlAttrStrategy(): void
    {
        $f = $this->frond();
        $this->assertSame('a&#x22;b', $f->renderString("{{ u|e('html_attr') }}", ['u' => 'a"b']));
    }

    // positive: normal escaping and safe values unchanged.
    public function testPlainStringStillEscapes(): void
    {
        $f = $this->frond();
        $this->assertSame('&lt;i&gt;x&lt;/i&gt;', $f->renderString('{{ s }}', ['s' => '<i>x</i>']));
    }

    public function testSafeStringStillRendersVerbatim(): void
    {
        $f = $this->frond();
        Frond::addFilter('wrap_i', fn($v) => new SafeString('<i>' . htmlspecialchars((string)$v) . '</i>'));
        $this->assertSame('<i>hi</i>', $this->frond()->renderString('{{ v|wrap_i }}', ['v' => 'hi']));
    }

    // F6: a string in template data must not be invoked as a PHP function.
    public function testDataStringIsNotCalledAsFunction(): void
    {
        $f = $this->frond();
        $out = $f->renderString("{{ u.n('x') }}", ['u' => ['n' => 'strtoupper']]);
        $this->assertSame('', $out);
    }

    public function testSandboxDataStringIsNotCalledAsFunction(): void
    {
        $f = $this->frond();
        $f->sandbox([], ['if', 'for', 'set'], ['user']);
        $out = $f->renderString("{{ user.name('x') }}", ['user' => ['name' => 'strtoupper']]);
        $this->assertSame('', $out);
    }

    public function testClosureInContextStillCallable(): void
    {
        // positive: a real Closure placed by the app still works.
        $f = $this->frond();
        $out = $f->renderString("{{ u.n('hi') }}", ['u' => ['n' => fn($x) => strtoupper($x)]]);
        $this->assertSame('HI', $out);
    }
}
