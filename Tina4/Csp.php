<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
 */

namespace Tina4;

/**
 * Per-response Content-Security-Policy nonce (ADR-0088).
 *
 * The framework serves a strict default CSP (`default-src 'self'`). A browser
 * refuses every inline `<style>` and `<script>` under that policy unless the
 * element carries a nonce that the `Content-Security-Policy` header also names.
 * So the framework mints one cryptographically-random nonce per response, stamps
 * it on every inline `<style>`/`<script>` it emits, and injects the matching
 * `'nonce-<X>'` into the `style-src` and `script-src` directives of the CSP
 * header. The same value reaches user templates through the Frond global
 * `csp_nonce()` and the `$response->cspNonce` property.
 *
 * PHP serves one request per process at a time (the built-in socket server
 * dispatches sequentially; php-fpm/CLI is one request per process), so the
 * nonce lives in a request-scoped static — the equivalent of Python's
 * ContextVar. `Router::dispatch()` sets a fresh nonce at the start of every
 * request and clears it in `finally`, exactly as it does the request id.
 * Whoever touches the nonce first in a request — the body emitter rendering
 * inline content, or the security middleware building the header — gets the
 * same value, because {@see currentCspNonce()} generates one on first access
 * and caches it for the rest of the request.
 */
class Csp
{
    /** @var string|null The current request's nonce; null until set or first-accessed. */
    private static ?string $currentNonce = null;

    /**
     * Return a fresh cryptographically-random nonce (128 bits, base64 — ~24 chars).
     *
     * @return string
     */
    public static function generateNonce(): string
    {
        return base64_encode(random_bytes(16));
    }

    /**
     * Set the nonce for the current request context.
     *
     * @param string $value The per-request nonce.
     * @return void
     */
    public static function setCurrentNonce(string $value): void
    {
        self::$currentNonce = $value;
    }

    /**
     * Clear the nonce at the end of a request (mirrors Log::clearRequestId()).
     *
     * @return void
     */
    public static function clearCurrentNonce(): void
    {
        self::$currentNonce = null;
    }

    /**
     * Return the current request's nonce, minting one on first access.
     *
     * Generate-on-first-access makes the value independent of ordering: whether
     * the inline body or the CSP header is built first, both read the same nonce.
     *
     * @return string
     */
    public static function currentCspNonce(): string
    {
        if (self::$currentNonce === null || self::$currentNonce === '') {
            self::$currentNonce = self::generateNonce();
        }
        return self::$currentNonce;
    }

    /**
     * Frond global: the current response's CSP nonce.
     *
     * Use it on any inline element a template emits:
     *
     *   <style nonce="{{ csp_nonce() }}"> ... </style>
     *   <script nonce="{{ csp_nonce() }}"> ... </script>
     *
     * @return string
     */
    public static function cspNonce(): string
    {
        return self::currentCspNonce();
    }

    /**
     * Return `$csp` with `'nonce-<nonce>'` present in style-src AND script-src.
     *
     * For the default `default-src 'self'` this yields
     * `default-src 'self'; style-src 'self' 'nonce-X'; script-src 'self' 'nonce-X'`.
     * When a directive is absent it is derived from `default-src` (falling back
     * to `'self'`) so the framework's own nonce'd content always works; when
     * present the nonce is appended (idempotently). Every other directive is
     * kept, in order. Never adds `'unsafe-inline'` or `'unsafe-hashes'`.
     *
     * @param string $csp   The source CSP string.
     * @param string $nonce The per-response nonce.
     * @return string
     */
    public static function injectNonceIntoCsp(string $csp, string $nonce): string
    {
        $token = "'nonce-{$nonce}'";

        /** @var array<int, array{0: string, 1: string}> $directives */
        $directives = [];
        foreach (explode(';', $csp) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $bits = preg_split('/\s+/', $part, 2);
            $name = strtolower($bits[0]);
            $value = isset($bits[1]) ? trim($bits[1]) : '';
            $directives[] = [$name, $value];
        }

        $defaultValue = "'self'";
        foreach ($directives as [$name, $value]) {
            if ($name === 'default-src') {
                $defaultValue = $value !== '' ? $value : "'self'";
                break;
            }
        }

        foreach (['style-src', 'script-src'] as $directive) {
            $found = false;
            foreach ($directives as $index => [$name, $value]) {
                if ($name === $directive) {
                    $found = true;
                    if (!in_array($token, preg_split('/\s+/', trim($value)) ?: [], true)) {
                        $directives[$index][1] = trim($value . ' ' . $token);
                    }
                    break;
                }
            }
            if (!$found) {
                $base = $defaultValue !== '' ? $defaultValue : "'self'";
                $directives[] = [$directive, trim($base . ' ' . $token)];
            }
        }

        $parts = [];
        foreach ($directives as [$name, $value]) {
            $parts[] = $value !== '' ? trim("{$name} {$value}") : $name;
        }
        return implode('; ', $parts);
    }

    /**
     * Build the CSP header value for `$nonce` from the environment.
     *
     * Honours `TINA4_CSP` (default `default-src 'self'`) and always injects the
     * nonce into style-src and script-src. A blank/unset `TINA4_CSP` is treated
     * as the default policy (the unset behaviour is unchanged elsewhere).
     *
     * @param string $nonce The per-response nonce.
     * @return string
     */
    public static function resolveCspHeader(string $nonce): string
    {
        $csp = (string)DotEnv::getEnv('TINA4_CSP', "default-src 'self'");
        if ($csp === '') {
            $csp = "default-src 'self'";
        }
        return self::injectNonceIntoCsp($csp, $nonce);
    }
}
