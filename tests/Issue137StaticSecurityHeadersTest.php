<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


declare(strict_types=1);

/**
 * tina4-python#137 parity: responses from the static file handler skipped the
 * security-headers middleware, so an SPA's index.html - served at "/" - had no
 * CSP and could be framed (clickjacking), while the same HTML from a route got
 * every header.
 *
 * PHP had the same gap. SecurityHeadersMiddleware runs in the POST-match global
 * pass, and Router::dispatchInner() hands an unmatched path to
 * dispatchNoMatch() before that pass, so a static file, the template fallback,
 * a 404 and a 405 all left without the headers.
 *
 * Each case goes through every PHP entry point (Tina4's socket server, a web
 * SAPI via php -S, and __invoke()) in its own real php process, and compares
 * against a route response from the same app.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SecurityEntryProject.php';

class Issue137StaticSecurityHeadersTest extends TestCase
{
    private ?SecurityEntryProject $project = null;

    protected function setUp(): void
    {
        $this->project = new SecurityEntryProject();
    }

    protected function tearDown(): void
    {
        $this->project?->destroy();
        $this->project = null;
    }

    /**
     * Entry point x unmatched path.
     *
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function unmatchedPaths(): array
    {
        $cases = [];
        foreach (['socket', 'web-sapi', 'invoke'] as $entry) {
            $cases["{$entry}: SPA front door /"] = [$entry, '/', 200];
            $cases["{$entry}: static /index.html"] = [$entry, '/index.html', 200];
            $cases["{$entry}: static /app.js"] = [$entry, '/app.js', 200];
            $cases["{$entry}: 404 /missing"] = [$entry, '/missing', 404];
        }
        return $cases;
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function get(string $entry, string $path): array
    {
        if ($entry === 'invoke') {
            return $this->project->invoke(['method' => 'GET', 'path' => $path], false);
        }
        return SecurityEntryProject::request($this->project->serve($entry, false) . $path);
    }

    #[DataProvider('unmatchedPaths')]
    public function testUnmatchedPathResponsesCarryTheSameSecurityHeadersAsARoute(string $entry, string $path, int $status): void
    {
        $response = $this->get($entry, $path);
        $this->assertSame($status, $response['status'], "{$entry} GET {$path}");
        if ($path === '/' || $path === '/index.html') {
            $this->assertStringContainsString('<title>spa</title>', $response['body'], 'served from src/public/index.html');
        }

        $route = $this->get($entry, '/page');
        foreach (SecurityEntryProject::HEADERS as $header) {
            $this->assertArrayHasKey($header, $route['headers'], "control: the route response carries {$header}");
            $this->assertSame(
                $route['headers'][$header],
                $response['headers'][$header] ?? null,
                "{$entry} GET {$path} must carry the same {$header} as a route response"
            );
        }
    }

    /** A 405 (known path, wrong method) is built in the same no-match branch. */
    public function testMethodNotAllowedCarriesTheSecurityHeaders(): void
    {
        $response = SecurityEntryProject::request($this->project->serve('web-sapi', false) . '/page', 'DELETE');

        $this->assertSame(405, $response['status']);
        foreach (SecurityEntryProject::HEADERS as $header) {
            $this->assertArrayHasKey($header, $response['headers'], "405 must carry {$header}");
        }
    }

    /** A conditional request answered 304 from the static handler keeps the headers too. */
    public function testStaticNotModifiedCarriesTheSecurityHeaders(): void
    {
        $base = $this->project->serve('socket', false);
        $first = SecurityEntryProject::request($base . '/index.html');
        $this->assertArrayHasKey('etag', $first['headers']);

        $context = stream_context_create(['http' => [
            'header' => "If-None-Match: {$first['headers']['etag']}\r\nConnection: close\r\n",
            'ignore_errors' => true,
        ]]);
        @file_get_contents($base . '/index.html', false, $context);
        $raw = implode("\n", $http_response_header ?? []);

        $this->assertMatchesRegularExpression('#^HTTP/\S+ 304#', $raw);
        $this->assertStringContainsStringIgnoringCase('x-frame-options:', $raw);
        $this->assertStringContainsStringIgnoringCase('content-security-policy:', $raw);
    }
}
