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
 * Every response the app emits carries the security headers while
 * SecurityHeadersMiddleware is registered - refusals included
 * (tina4-python#134 / #137 follow-up).
 *
 * The middleware's before hook only decorates the Response it is handed, so
 * any response built OUTSIDE that hook went out bare: a CSRF 403 (CSRF is
 * attached, and so runs, before the security headers), and any middleware or
 * handler that answers with a fresh Response. Each case runs through every PHP
 * entry point in its own real php process. The auth gate's 401 was already
 * covered and is locked in here; a route that sets its OWN X-Frame-Options
 * keeps it (the headers fill gaps, they never overrule the app).
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SecurityEntryProject.php';

class Issue134RefusalSecurityHeadersTest extends TestCase
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
     * Entry point x refusal.
     *
     * @return array<string, array{0: string, 1: bool, 2: string, 3: string, 4: int}>
     */
    public static function refusals(): array
    {
        $cases = [];
        foreach (['socket', 'web-sapi', 'invoke'] as $entry) {
            $cases["{$entry}: CSRF 403"] = [$entry, true, 'POST', '/api/transfer', 403];
            $cases["{$entry}: auth gate 401"] = [$entry, false, 'POST', '/api/transfer', 401];
            $cases["{$entry}: route middleware 403 (fresh Response)"] = [$entry, false, 'GET', '/gated', 403];
        }
        return $cases;
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function send(string $entry, bool $csrf, string $method, string $path): array
    {
        $body = $method === 'POST' ? '{"amount": 100}' : '';
        if ($entry === 'invoke') {
            return $this->project->invoke(
                ['method' => $method, 'path' => $path, 'headers' => ['content-type' => 'application/json'], 'body' => $body],
                $csrf
            );
        }
        return SecurityEntryProject::request($this->project->serve($entry, $csrf) . $path, $method, $body);
    }

    #[DataProvider('refusals')]
    public function testARefusalCarriesTheSecurityHeaders(string $entry, bool $csrf, string $method, string $path, int $status): void
    {
        $response = $this->send($entry, $csrf, $method, $path);

        $this->assertSame($status, $response['status'], "{$entry} {$method} {$path}: " . $response['body']);
        foreach (SecurityEntryProject::HEADERS as $header) {
            $this->assertArrayHasKey($header, $response['headers'], "{$entry}: the {$status} refusal must carry {$header}");
        }
        $this->assertSame('nosniff', $response['headers']['x-content-type-options']);
    }

    /** The headers fill gaps; a value the route set itself is kept. */
    #[DataProvider('entriesOnly')]
    public function testARouteThatSetsItsOwnFrameOptionsKeepsIt(string $entry): void
    {
        $response = $this->send($entry, false, 'GET', '/framed');

        $this->assertSame(200, $response['status']);
        $this->assertSame('DENY', $response['headers']['x-frame-options'] ?? null);
        $this->assertArrayHasKey('content-security-policy', $response['headers']);
    }

    /** @return array<string, array{0: string}> */
    public static function entriesOnly(): array
    {
        return ['socket' => ['socket'], 'web-sapi' => ['web-sapi'], 'invoke' => ['invoke']];
    }
}
