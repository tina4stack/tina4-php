<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

/**
 * CSP nonce respects a user's 'unsafe-inline' (ADR-0100, refines ADR-0088).
 *
 * A browser ignores 'unsafe-inline' in any directive that also lists a nonce
 * (CSP Level 2+). Since 3.13.143 the framework appended its per-response nonce
 * to script-src/style-src unconditionally - including an app's own TINA4_CSP -
 * so an app that deliberately allowed inline through 'unsafe-inline' had it
 * silently blocked (#330). The rule now: a directive (present, or derived from
 * default-src) that already carries 'unsafe-inline' gets NO nonce; the app's
 * explicit choice wins. The strict default policy is unchanged and still nonced.
 *
 * Answer key: tina4-documentation/plan/v3/fixtures/securityheaders_contract.json,
 * invariant csp-nonce-respects-unsafe-inline. NO MOCKS - the real injector, a
 * pure string transform.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Csp;

final class CspNonceUnsafeInlineContractTest extends TestCase
{
    private const NONCE = 'abc123';
    private const TOKEN = "'nonce-abc123'";

    private function directive(string $csp, string $name): string
    {
        foreach (explode(';', $csp) as $part) {
            $part = trim($part);
            $bits = preg_split('/\s+/', $part, 2);
            if (strtolower($bits[0]) === $name) {
                return $part;
            }
        }
        return '';
    }

    public function testExplicitUnsafeInlineScriptSrcKeepsUnsafeInlineAndGetsNoNonce(): void
    {
        $out = Csp::injectNonceIntoCsp("default-src 'self'; script-src 'self' 'unsafe-inline'", self::NONCE);
        $this->assertStringContainsString("'unsafe-inline'", $this->directive($out, 'script-src'));
        $this->assertStringNotContainsString(self::TOKEN, $this->directive($out, 'script-src'));
    }

    public function testExplicitUnsafeInlineStyleSrcKeepsUnsafeInlineAndGetsNoNonce(): void
    {
        $out = Csp::injectNonceIntoCsp("default-src 'self'; style-src 'self' 'unsafe-inline'", self::NONCE);
        $this->assertStringContainsString("'unsafe-inline'", $this->directive($out, 'style-src'));
        $this->assertStringNotContainsString(self::TOKEN, $this->directive($out, 'style-src'));
    }

    public function testUnsafeInlineDefaultSrcDoesNotSpawnANoncedDirective(): void
    {
        $out = Csp::injectNonceIntoCsp("default-src 'self' 'unsafe-inline'", self::NONCE);
        $this->assertStringNotContainsString(self::TOKEN, $out);
        $this->assertSame('', $this->directive($out, 'script-src'));
        $this->assertSame('', $this->directive($out, 'style-src'));
    }

    public function testMixedUnsafeInlineScriptButStrictStyleNoncesOnlyStyle(): void
    {
        $out = Csp::injectNonceIntoCsp("default-src 'self'; script-src 'self' 'unsafe-inline'", self::NONCE);
        $this->assertStringNotContainsString(self::TOKEN, $this->directive($out, 'script-src'));
        $this->assertStringContainsString(self::TOKEN, $this->directive($out, 'style-src'));
    }

    public function testStrictDefaultPolicyStillGetsTheNonce(): void
    {
        $out = Csp::injectNonceIntoCsp("default-src 'self'", self::NONCE);
        $this->assertStringContainsString(self::TOKEN, $this->directive($out, 'script-src'));
        $this->assertStringContainsString(self::TOKEN, $this->directive($out, 'style-src'));
    }

    public function testExplicitNonUnsafeInlineDirectiveStillGetsTheNonce(): void
    {
        $out = Csp::injectNonceIntoCsp("default-src 'self'; script-src 'self' https://cdn.example", self::NONCE);
        $this->assertStringContainsString(self::TOKEN, $this->directive($out, 'script-src'));
    }
}
