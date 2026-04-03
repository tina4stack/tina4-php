<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\HtmlElement;

class HtmlElementTest extends TestCase
{
    // -- Basic rendering ------------------------------------------------------

    public function testSimpleElement(): void
    {
        $el = new HtmlElement('div', ['class' => 'card'], ['Hello']);
        $this->assertSame('<div class="card">Hello</div>', (string) $el);
    }

    public function testEmptyElement(): void
    {
        $el = new HtmlElement('div');
        $this->assertSame('<div></div>', (string) $el);
    }

    public function testMultipleChildren(): void
    {
        $el = new HtmlElement('ul', [], [
            new HtmlElement('li', [], ['One']),
            new HtmlElement('li', [], ['Two']),
        ]);
        $this->assertSame('<ul><li>One</li><li>Two</li></ul>', (string) $el);
    }

    // -- Void tags ------------------------------------------------------------

    public function testVoidTag(): void
    {
        $el = new HtmlElement('br');
        $this->assertSame('<br>', (string) $el);
    }

    public function testVoidTagWithAttributes(): void
    {
        $el = new HtmlElement('img', ['src' => 'photo.jpg', 'alt' => 'A photo']);
        $this->assertSame('<img src="photo.jpg" alt="A photo">', (string) $el);
    }

    public function testInputVoidTag(): void
    {
        $el = new HtmlElement('input', ['type' => 'text', 'name' => 'q']);
        $this->assertSame('<input type="text" name="q">', (string) $el);
    }

    // -- Attribute handling ---------------------------------------------------

    public function testBooleanTrueAttribute(): void
    {
        $el = new HtmlElement('input', ['disabled' => true, 'type' => 'text']);
        $this->assertSame('<input disabled type="text">', (string) $el);
    }

    public function testBooleanFalseAttributeOmitted(): void
    {
        $el = new HtmlElement('input', ['disabled' => false, 'type' => 'text']);
        $this->assertSame('<input type="text">', (string) $el);
    }

    public function testNullAttributeOmitted(): void
    {
        $el = new HtmlElement('div', ['id' => null, 'class' => 'x']);
        $this->assertSame('<div class="x"></div>', (string) $el);
    }

    public function testAttributeEscaping(): void
    {
        $el = new HtmlElement('a', ['href' => '/search?q=a&b=c', 'title' => 'say "hi"']);
        $html = (string) $el;
        $this->assertStringContainsString('href="/search?q=a&amp;b=c"', $html);
        $this->assertStringContainsString('title="say &quot;hi&quot;"', $html);
    }

    // -- Builder pattern (__invoke) -------------------------------------------

    public function testInvokeAddsChildren(): void
    {
        $el = new HtmlElement('div');
        $el2 = $el('Hello', ' ', 'World');
        $this->assertSame('<div>Hello World</div>', (string) $el2);
    }

    public function testInvokeWithNestedElements(): void
    {
        $el = (new HtmlElement('div'))((new HtmlElement('p'))('Text'));
        $this->assertSame('<div><p>Text</p></div>', (string) $el);
    }

    public function testInvokeWithAttributesMerge(): void
    {
        $el = new HtmlElement('div', ['class' => 'a']);
        $el2 = $el(['id' => 'main'], 'Content');
        $this->assertSame('<div class="a" id="main">Content</div>', (string) $el2);
    }

    public function testInvokeDoesNotMutateOriginal(): void
    {
        $el = new HtmlElement('div');
        $el('child');
        $this->assertSame('<div></div>', (string) $el);
    }

    // -- Helper functions -----------------------------------------------------

    public function testHelpersFunctionExists(): void
    {
        $helpers = HtmlElement::helpers();
        $this->assertIsArray($helpers);
        $this->assertArrayHasKey('_div', $helpers);
        $this->assertArrayHasKey('_p', $helpers);
        $this->assertArrayHasKey('_span', $helpers);
        $this->assertArrayHasKey('_a', $helpers);
        $this->assertArrayHasKey('_br', $helpers);
        $this->assertArrayHasKey('_img', $helpers);
    }

    public function testHelperCreatesElement(): void
    {
        $helpers = HtmlElement::helpers();
        $div = $helpers['_div'];
        $el = $div(['class' => 'card'], 'Hello');
        $this->assertSame('<div class="card">Hello</div>', (string) $el);
    }

    public function testHelperNestedElements(): void
    {
        $helpers = HtmlElement::helpers();
        $div = $helpers['_div'];
        $p = $helpers['_p'];
        $el = $div(['class' => 'card'], $p('Hello'));
        $this->assertSame('<div class="card"><p>Hello</p></div>', (string) $el);
    }

    public function testHelperVoidTag(): void
    {
        $helpers = HtmlElement::helpers();
        $br = $helpers['_br'];
        $this->assertSame('<br>', (string) $br());
    }

    public function testExtractedHelpers(): void
    {
        extract(HtmlElement::helpers());
        /** @var callable $_div */
        /** @var callable $_p */
        /** @var callable $_a */
        $html = $_div(['class' => 'nav'], $_a(['href' => '/'], 'Home'));
        $this->assertSame('<div class="nav"><a href="/">Home</a></div>', (string) $html);
    }

    // -- Additional nesting tests --------------------------------------------

    public function testDeeplyNestedElements(): void
    {
        $span = new HtmlElement('span', [], ['deep']);
        $p = new HtmlElement('p', [], [$span]);
        $div = new HtmlElement('div', ['class' => 'wrap'], [$p]);
        $this->assertSame('<div class="wrap"><p><span>deep</span></p></div>', (string) $div);
    }

    // -- Tag lowercased -------------------------------------------------------

    public function testTagLowercased(): void
    {
        $el = new HtmlElement('DIV');
        $this->assertSame('<div></div>', (string) $el);
    }

    // -- Additional void tags ------------------------------------------------

    public function testHrVoidTag(): void
    {
        $el = new HtmlElement('hr');
        $this->assertSame('<hr>', (string) $el);
    }

    public function testMetaVoidTag(): void
    {
        $el = new HtmlElement('meta', ['charset' => 'utf-8']);
        $this->assertSame('<meta charset="utf-8">', (string) $el);
    }

    public function testLinkVoidTag(): void
    {
        $el = new HtmlElement('link', ['rel' => 'stylesheet', 'href' => '/style.css']);
        $html = (string) $el;
        $this->assertStringContainsString('rel="stylesheet"', $html);
        $this->assertStringContainsString('href="/style.css"', $html);
        $this->assertStringNotContainsString('</link>', $html);
    }

    // -- Text child rendering -------------------------------------------------

    public function testTextChildRendered(): void
    {
        $el = new HtmlElement('p', [], ['Hello World']);
        $result = (string) $el;
        $this->assertStringContainsString('Hello World', $result);
    }

    // -- Html element child not double-escaped --------------------------------

    public function testHtmlElementChildNotDoubleEscaped(): void
    {
        $inner = new HtmlElement('em', [], ['bold']);
        $outer = new HtmlElement('p', [], [$inner]);
        $this->assertSame('<p><em>bold</em></p>', (string) $outer);
    }

    // -- Builder with list children ------------------------------------------

    public function testInvokeWithListChildren(): void
    {
        $items = [new HtmlElement('li', [], ['a']), new HtmlElement('li', [], ['b'])];
        $ul = (new HtmlElement('ul'))($items);
        $this->assertSame('<ul><li>a</li><li>b</li></ul>', (string) $ul);
    }

    // -- Paragraph with text --------------------------------------------------

    public function testParagraphWithText(): void
    {
        $el = new HtmlElement('p', [], ['Some text']);
        $this->assertSame('<p>Some text</p>', (string) $el);
    }

    // -- Helpers table tag exists ---------------------------------------------

    public function testHelpersIncludeTableTag(): void
    {
        $helpers = HtmlElement::helpers();
        $this->assertArrayHasKey('_table', $helpers);
    }

    // -- repr same as str -----------------------------------------------------

    public function testReprSameAsStr(): void
    {
        $el = new HtmlElement('div', ['id' => 'r'], ['ok']);
        // In PHP, __toString is the only string conversion
        $this->assertSame((string) $el, (string) $el);
    }
}
