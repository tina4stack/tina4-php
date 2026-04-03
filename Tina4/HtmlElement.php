<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Programmatic HTML builder — avoids string concatenation.
 *
 * Usage:
 *   $el = new HtmlElement("div", ["class" => "card"], ["Hello"]);
 *   echo $el;  // <div class="card">Hello</div>
 *
 *   // Builder pattern (via __invoke)
 *   $el = (new HtmlElement("div"))((new HtmlElement("p"))("Text"));
 *
 *   // Helper functions
 *   $helpers = HtmlElement::helpers();
 *   extract($helpers);
 *   $html = $_div(["class" => "card"], $_p("Hello"));
 */
class HtmlElement
{
    /** Tags that must not have a closing tag. */
    private const VOID_TAGS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /** Common HTML tags for helper generation. */
    private const HTML_TAGS = [
        'a', 'abbr', 'address', 'area', 'article', 'aside', 'audio',
        'b', 'base', 'bdi', 'bdo', 'blockquote', 'body', 'br', 'button',
        'canvas', 'caption', 'cite', 'code', 'col', 'colgroup',
        'data', 'datalist', 'dd', 'del', 'details', 'dfn', 'dialog', 'div', 'dl', 'dt',
        'em', 'embed',
        'fieldset', 'figcaption', 'figure', 'footer', 'form',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'head', 'header', 'hgroup', 'hr', 'html',
        'i', 'iframe', 'img', 'input', 'ins',
        'kbd',
        'label', 'legend', 'li', 'link',
        'main', 'map', 'mark', 'menu', 'meta', 'meter',
        'nav', 'noscript',
        'object', 'ol', 'optgroup', 'option', 'output',
        'p', 'param', 'picture', 'pre', 'progress',
        'q',
        'rp', 'rt', 'ruby',
        's', 'samp', 'script', 'section', 'select', 'slot', 'small', 'source', 'span',
        'strong', 'style', 'sub', 'summary', 'sup',
        'table', 'tbody', 'td', 'template', 'textarea', 'tfoot', 'th', 'thead', 'time',
        'title', 'tr', 'track',
        'u', 'ul',
        'var', 'video',
        'wbr',
    ];

    private string $tag;
    private array $attrs;
    private array $children;

    /**
     * @param string $tag       HTML tag name
     * @param array  $attrs     Associative array of attribute => value
     * @param array  $children  Child elements (strings or HtmlElement instances)
     */
    public function __construct(string $tag, array $attrs = [], array $children = [])
    {
        $this->tag = strtolower($tag);
        $this->attrs = $attrs;
        $this->children = $children;
    }

    /**
     * Builder pattern — calling an HtmlElement as a function appends children.
     *
     * $el = (new HtmlElement("div"))(new HtmlElement("p")("Hello"), "world");
     *
     * @param  mixed ...$children  Strings, HtmlElements, arrays, or associative arrays (treated as attrs)
     * @return self                A new HtmlElement with the appended children
     */
    public function __invoke(mixed ...$children): self
    {
        $attrs = $this->attrs;
        $kids = $this->children;

        foreach ($children as $child) {
            if (is_array($child) && !array_is_list($child)) {
                // Associative array → merge as attributes
                $attrs = array_merge($attrs, $child);
            } elseif (is_array($child)) {
                // Sequential array → spread as children
                foreach ($child as $item) {
                    $kids[] = $item;
                }
            } else {
                $kids[] = $child;
            }
        }

        return new self($this->tag, $attrs, $kids);
    }

    /**
     * Render to HTML string.
     */
    public function __toString(): string
    {
        $html = '<' . $this->tag;

        foreach ($this->attrs as $key => $value) {
            if ($value === true) {
                $html .= ' ' . $key;
            } elseif ($value !== false && $value !== null) {
                $html .= ' ' . $key . '="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '"';
            }
        }

        if (in_array($this->tag, self::VOID_TAGS, true)) {
            return $html . '>';
        }

        $html .= '>';

        foreach ($this->children as $child) {
            $html .= (string) $child;
        }

        $html .= '</' . $this->tag . '>';

        return $html;
    }

    /**
     * Returns an associative array of helper closures: "_div", "_p", "_span", etc.
     *
     * Usage:
     *   extract(HtmlElement::helpers());
     *   echo $_div(["class" => "card"], $_p("Hello"));
     *
     * @return array<string, \Closure>
     */
    public static function helpers(): array
    {
        $helpers = [];

        foreach (self::HTML_TAGS as $tag) {
            $helpers['_' . $tag] = static function (mixed ...$args) use ($tag): HtmlElement {
                $attrs = [];
                $children = [];

                foreach ($args as $arg) {
                    if (is_array($arg) && !array_is_list($arg)) {
                        $attrs = array_merge($attrs, $arg);
                    } elseif ($arg instanceof self) {
                        $children[] = $arg;
                    } elseif (is_array($arg)) {
                        foreach ($arg as $item) {
                            $children[] = $item;
                        }
                    } else {
                        $children[] = $arg;
                    }
                }

                return new HtmlElement($tag, $attrs, $children);
            };
        }

        return $helpers;
    }
}
