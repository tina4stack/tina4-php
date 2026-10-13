<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Frond;

/**
 * errors/302.twig puts redirect_url into both an <a href> and a
 * <meta http-equiv="refresh"> url. A tainted value must not be able to run
 * script (javascript:/data:/vbscript:) or bounce the user off-site via a
 * scheme-relative //host target. The template collapses anything that is not a
 * same-site relative path or an explicit http/https URL to "/".
 *
 * Real Frond render against the shipped framework template — no mocks.
 */
final class RedirectTemplateSafetyTest extends TestCase
{
    private Frond $frond;

    protected function setUp(): void
    {
        // The shipped framework templates, not the app's src/templates copy.
        $this->frond = new Frond(dirname(__DIR__) . '/Tina4/templates');
    }

    private function renderedUrls(string $redirectUrl): array
    {
        $html = $this->frond->render('errors/302.twig', ['redirect_url' => $redirectUrl, 'title' => 't']);
        self::assertMatchesRegularExpression('/<a href="([^"]*)"/', $html);
        self::assertMatchesRegularExpression('/url=([^"]*)">/', $html);
        preg_match('/<a href="([^"]*)"/', $html, $hrefMatch);
        preg_match('/url=([^"]*)">/', $html, $metaMatch);
        // Both sinks must carry the same sanitized value.
        return ['href' => $hrefMatch[1], 'meta' => $metaMatch[1]];
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function dangerousUrls(): array
    {
        return [
            'javascript scheme'        => ['javascript:alert(1)'],
            'javascript scheme upper'  => ['JAVASCRIPT:alert(1)'],
            'data scheme'              => ['data:text/html,<script>alert(1)</script>'],
            'vbscript scheme'          => ['vbscript:msgbox(1)'],
            'scheme-relative host'     => ['//evil.example.com/pwn'],
            'empty'                    => [''],
            'bare word (no scheme)'    => ['evilsite'],
        ];
    }

    /**
     * @dataProvider dangerousUrls
     */
    public function testDangerousRedirectCollapsesToRoot(string $url): void
    {
        $urls = $this->renderedUrls($url);
        $this->assertSame('/', $urls['href'], "href must be neutralised for: {$url}");
        $this->assertSame('/', $urls['meta'], "meta refresh must be neutralised for: {$url}");
    }

    /**
     * @return array<string, array{0:string,1:string}>
     */
    public static function safeUrls(): array
    {
        return [
            'relative path'      => ['/dashboard?x=1', '/dashboard?x=1'],
            'root'               => ['/', '/'],
            'https absolute'     => ['https://tina4.com/ok', 'https://tina4.com/ok'],
            'http absolute'      => ['http://tina4.com/ok', 'http://tina4.com/ok'],
        ];
    }

    /**
     * @dataProvider safeUrls
     */
    public function testSafeRedirectIsPreserved(string $url, string $expected): void
    {
        $escaped = htmlspecialchars($expected, ENT_QUOTES);
        $urls = $this->renderedUrls($url);
        $this->assertSame($escaped, $urls['href'], "href must preserve a safe target: {$url}");
        $this->assertSame($escaped, $urls['meta'], "meta refresh must preserve a safe target: {$url}");
    }

    /**
     * A reflected relative path that carries HTML/quote metacharacters must stay
     * inside the attribute (Frond auto-escaping), never break out of the href.
     */
    public function testRelativePathWithMetacharactersIsEscapedNotBrokenOut(): void
    {
        $urls = $this->renderedUrls('/search?q="><img src=x onerror=alert(1)>');
        $this->assertStringStartsWith('/search?q=', $urls['href']);
        // The raw attribute value must stay escaped: no live tag, no quote break-out.
        $this->assertStringNotContainsString('<img', $urls['href']);
        $this->assertStringNotContainsString('"', $urls['href']);
        $this->assertStringContainsString('&quot;', $urls['href']);
    }
}
