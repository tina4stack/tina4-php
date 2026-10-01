<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The framework's own inline content runs under the strict default CSP (ADR-0088).
 *
 * Tina4 serves `default-src 'self'` by default. A browser refuses every inline
 * `<style>`/`<script>` under that policy unless the element carries a nonce the
 * `Content-Security-Policy` header also names. So the framework mints one nonce
 * per response, injects `'nonce-<X>'` into style-src AND script-src, and stamps
 * the SAME value on every inline `<style>`/`<script>` it emits. It also
 * de-inlines every `style="..."` attribute and `onclick=` handler, because a
 * nonce covers a `<style>`/`<script>` ELEMENT but never an attribute.
 *
 * NO MOCKS: a real throwaway project served by Tina4's own `php -S` entry point
 * over real HTTP. `/` is the welcome page the reported bug rendered unstyled, a
 * 404 is a framework-rendered error page, and `/__dev` is the dev dashboard.
 *
 * Mirrors tina4-python tests/test_csp_nonce_inline.py (PR #190).
 */
final class CspNonceInlineTest extends TestCase
{
    private static string $dir = '';
    private static ?TestServer $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/tina4-csp-nonce-' . bin2hex(random_bytes(6));
        mkdir(self::$dir . '/src/routes', 0777, true);

        // A project with NO "/" route, so GET / falls through to the framework's
        // own welcome page (served at "/" in dev mode).
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        file_put_contents(
            self::$dir . '/index.php',
            "<?php\nrequire {$autoload};\n"
            . "\$app = new \\Tina4\\App(basePath: __DIR__);\n"
            . "\$app->run();\n"
        );
        file_put_contents(self::$dir . '/src/routes/.keep', '');

        self::$server = TestServer::start(self::$dir . '/index.php', [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'TMPDIR' => sys_get_temp_dir(),
            'TINA4_DEBUG' => 'true',
            'TINA4_AUTO_MIGRATE' => 'false',
            'TINA4_NO_BROWSER' => 'true',
            'TINA4_OVERRIDE_CLIENT' => 'true',
            'TINA4_SECRET' => 'csp-nonce-secret-0123456789abcdef',
        ], self::$dir);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    /**
     * GET a path and return [status, lower-cased headers, body].
     *
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function get(string $path): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "Connection: close\r\n",
            'ignore_errors' => true,
            'timeout' => 15,
        ]]);
        $body = @file_get_contents(self::$server->base() . $path, false, $context);
        $this->assertNotFalse($body, "request to {$path} failed: " . self::$server->log());
        $status = 0;
        $headers = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                $status = (int)$m[1];
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }
        }
        return [$status, $headers, (string)$body];
    }

    private function nonceOf(string $csp): ?string
    {
        return preg_match("/'nonce-([^']+)'/", $csp, $m) ? $m[1] : null;
    }

    /** Every inline <style>/<script> (no src) must carry exactly $nonce. */
    private function assertInlineTagsCarryNonce(string $body, string $nonce): void
    {
        preg_match_all('/<style\b([^>]*)>/i', $body, $styles);
        preg_match_all('/<script\b([^>]*)>/i', $body, $scripts);
        $this->assertNotEmpty($styles[1], 'page emitted no <style>');
        foreach ($styles[1] as $attrs) {
            $this->assertStringContainsString("nonce=\"{$nonce}\"", $attrs, "<style{$attrs}> missing the header nonce");
        }
        foreach ($scripts[1] as $attrs) {
            if (stripos($attrs, 'src=') !== false) {
                continue; // external <script src> needs no nonce
            }
            $this->assertStringContainsString("nonce=\"{$nonce}\"", $attrs, "<script{$attrs}> missing the header nonce");
        }
    }

    public function testWelcomeCspHeaderCarriesANonceInStyleAndScriptSrc(): void
    {
        [$status, $headers, $body] = $this->get('/');
        $this->assertSame(200, $status, $body);
        $csp = $headers['content-security-policy'] ?? '';
        $this->assertStringContainsString("default-src 'self'", $csp, $csp);
        $directives = [];
        foreach (explode(';', $csp) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $directives[strtolower(preg_split('/\s+/', $part)[0])] = $part;
        }
        $this->assertStringContainsString("'nonce-", $directives['style-src'] ?? '', $csp);
        $this->assertStringContainsString("'nonce-", $directives['script-src'] ?? '', $csp);
        $this->assertStringNotContainsString("'unsafe-inline'", $csp, $csp);
    }

    public function testWelcomeInlineStyleAndScriptCarryTheHeaderNonce(): void
    {
        [$status, $headers, $body] = $this->get('/');
        $this->assertSame(200, $status);
        $nonce = $this->nonceOf($headers['content-security-policy'] ?? '');
        $this->assertNotNull($nonce, $headers['content-security-policy'] ?? '(none)');
        $this->assertInlineTagsCarryNonce($body, $nonce);
    }

    public function testWelcomePageEmitsNoInlineStyleOrHandlerAttribute(): void
    {
        [$status, , $body] = $this->get('/');
        $this->assertSame(200, $status);
        $this->assertStringNotContainsString('style="', $body, 'framework welcome page still emits a style= attribute');
        $this->assertStringNotContainsString('onclick="', $body, 'framework welcome page still emits an inline onclick handler');
    }

    public function testErrorPageIsCspCleanAndNonced(): void
    {
        [$status, $headers, $body] = $this->get('/no-such-route-' . bin2hex(random_bytes(3)));
        $this->assertSame(404, $status, $body);
        $nonce = $this->nonceOf($headers['content-security-policy'] ?? '');
        $this->assertNotNull($nonce, $headers['content-security-policy'] ?? '(none)');
        $this->assertInlineTagsCarryNonce($body, $nonce);
        $this->assertStringNotContainsString('style="', $body, 'framework 404 page still emits a style= attribute');
        $this->assertStringNotContainsString('onclick="', $body, 'framework 404 page still emits an inline onclick handler');
    }

    public function testDevDashboardIsCspCleanAndNonced(): void
    {
        [$status, $headers, $body] = $this->get('/__dev');
        $this->assertSame(200, $status, $body);
        $csp = $headers['content-security-policy'] ?? '';
        $this->assertStringContainsString("'nonce-", $csp, $csp);
        // The dev dashboard shell loads its bundle via an external <script src>,
        // so it carries no inline style= or onclick= either.
        $this->assertStringNotContainsString('style="', $body, '/__dev still emits a style= attribute');
        $this->assertStringNotContainsString('onclick="', $body, '/__dev still emits an inline onclick handler');
    }

    public function testEachResponseGetsADistinctNonce(): void
    {
        [, $h1] = $this->get('/');
        [, $h2] = $this->get('/');
        $n1 = $this->nonceOf($h1['content-security-policy'] ?? '');
        $n2 = $this->nonceOf($h2['content-security-policy'] ?? '');
        $this->assertNotNull($n1);
        $this->assertNotNull($n2);
        $this->assertNotSame($n1, $n2, "nonce reused across responses: {$n1}");
    }
}
