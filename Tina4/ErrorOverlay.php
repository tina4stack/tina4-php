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
 * Rich error overlay for development mode.
 *
 * Renders a professional, syntax-highlighted HTML error page when an unhandled
 * exception or error occurs in a route handler.
 *
 * Usage:
 *   try {
 *       $handler($request, $response);
 *   } catch (\Throwable $e) {
 *       echo ErrorOverlay::renderErrorOverlay($e, $_SERVER);
 *   }
 *
 * Only activate when TINA4_DEBUG is true. The production 500 is NOT rendered here —
 * the router renders errors/500.twig with an empty error_message (CWE-209), so the
 * exception detail stays in the server log only, never in the response body.
 *
 * Sensitive request fields (Authorization / Cookie / Set-Cookie headers and
 * password-like body/param keys) are redacted even in the dev overlay, the frame
 * count is capped, and the router wraps this render in a guard, so a broken overlay
 * or a recursive stack still yields a bounded, safe 500.
 */
class ErrorOverlay
{
    // ── Colour palette (Catppuccin Mocha) ────────────────────────────────
    private const BG = '#1e1e2e';
    private const SURFACE = '#313244';
    private const OVERLAY = '#45475a';
    private const TEXT = '#cdd6f4';
    private const SUBTEXT = '#a6adc8';
    private const RED = '#f38ba8';
    private const YELLOW = '#f9e2af';
    private const BLUE = '#89b4fa';
    private const GREEN = '#a6e3a1';
    private const LAVENDER = '#b4befe';
    private const PEACH = '#fab387';
    private const ERROR_LINE_BG = 'rgba(243,139,168,0.15)';

    private const CONTEXT_LINES = 7;

    // OVERLAY-DEC-03: cap the rendered frames so a deep/recursive stack yields a
    // bounded page, not one source-file read per frame.
    private const MAX_FRAMES = 50;

    // OVERLAY-DEC-02: request fields whose KEY matches this are masked in the dev
    // overlay (Authorization/Cookie/Set-Cookie headers via authorization|cookie;
    // password/token/secret/api_key body/param keys via the rest). Over-matching a
    // benign field is the SAFE direction in a dev tool — over-masking leaks nothing.
    private const SENSITIVE_KEY_PATTERN = '/password|passwd|secret|token|authorization|cookie|key/i';
    private const REDACTED = '[redacted]';

    /**
     * Render a rich HTML error overlay.
     *
     * @param \Throwable $e The caught exception or error.
     * @param array|null $request Optional request details ($_SERVER or custom array).
     * @return string Complete HTML page.
     */
    public static function renderErrorOverlay(\Throwable $e, ?array $request = null): string
    {
        // Single timestamp stamped when the overlay starts rendering. Each
        // stack frame compares its source file's mtime against this — if
        // the file changed AFTER the error was captured (which happens
        // constantly when an AI coder rewrites the file between page
        // loads) the frame header gets a peach "FILE MODIFIED @ ..." pill
        // so the user knows the displayed source may no longer match what
        // actually raised the error.
        $capturedAt = microtime(true);

        $excType = get_class($e);
        $excMsg = $e->getMessage();
        $file = $e->getFile();
        $line = $e->getLine();
        $trace = $e->getTrace();
        // Inline dev toolbar — error page is debug-mode-only, so the
        // toolbar always belongs. One click → /__dev (chat, plan,
        // Live Docs, file tree) so the user can fix the failure
        // without leaving the page.
        $devToolbar = self::renderInlineToolbar($request);

        // ── Main error location ──
        $framesHtml = self::formatFrame($file, $line, '{main}', $capturedAt);

        // ── Stack trace frames (OVERLAY-DEC-03: capped) ──
        // A recursive stack of thousands of frames would otherwise do one
        // source-file read per frame and emit an unbounded page; render only the
        // innermost MAX_FRAMES (the main frame counts as the first) and note the rest.
        $shown = 1;
        foreach ($trace as $frame) {
            if ($shown >= self::MAX_FRAMES) {
                break;
            }
            $frameFile = $frame['file'] ?? '[internal]';
            $frameLine = $frame['line'] ?? 0;
            $frameFunc = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
            $framesHtml .= self::formatFrame($frameFile, $frameLine, $frameFunc, $capturedAt);
            $shown++;
        }
        $hidden = (1 + count($trace)) - $shown;
        if ($hidden > 0) {
            $framesHtml .= "<div class=\"eo-hidden-frames\">"
                . "&#8230; {$hidden} more stack frames hidden (truncated at " . self::MAX_FRAMES . ")</div>";
        }

        // ── Request info ──
        $requestPairs = [];
        if ($request !== null) {
            $interesting = [
                'REQUEST_METHOD', 'REQUEST_URI', 'SERVER_PROTOCOL', 'HTTP_HOST',
                'HTTP_USER_AGENT', 'HTTP_ACCEPT', 'CONTENT_TYPE', 'CONTENT_LENGTH',
                'REMOTE_ADDR', 'SERVER_PORT', 'QUERY_STRING',
                'method', 'url', 'path',
            ];
            foreach ($request as $k => $v) {
                if (in_array($k, $interesting, true) || str_starts_with($k, 'HTTP_')) {
                    $val = is_string($v) ? $v : json_encode($v);
                    $requestPairs[] = [$k, self::redact((string)$k, (string)$val)];
                }
            }
            // Also include non-$_SERVER style dicts (headers, params, body). The
            // router hands headers in as a CaseInsensitiveArray (Traversable, not a
            // plain array), so accept any iterable and RENDER + REDACT it deliberately
            // (OVERLAY-DEC-02) — do not rely on the accidental array-only skip that
            // previously hid ALL headers, including a bearer token, on other frameworks.
            foreach (['headers', 'params', 'body'] as $key) {
                $bag = $request[$key] ?? null;
                if (is_array($bag) || $bag instanceof \Traversable) {
                    $entries = is_array($bag) ? $bag : iterator_to_array($bag);
                    if (!empty($entries)) {
                        foreach ($entries as $hk => $hv) {
                            $pairKey = "$key.$hk";
                            $val = is_string($hv) ? $hv : json_encode($hv);
                            $requestPairs[] = [$pairKey, self::redact($pairKey, (string)$val)];
                        }
                    } else {
                        $requestPairs[] = [$key, '(empty)'];
                    }
                }
            }
        }
        $requestSection = !empty($requestPairs)
            ? self::collapsible('Request Details', self::table($requestPairs))
            : '';

        // ── Environment ──
        $envPairs = [
            ['Framework', 'Tina4 PHP'],
            ['Version', defined('TINA4_VERSION') ? TINA4_VERSION : 'unknown'],
            ['PHP', PHP_VERSION],
            ['Platform', PHP_OS],
            ['SAPI', PHP_SAPI],
            ['Debug', getenv('TINA4_DEBUG') ?: ($_ENV['TINA4_DEBUG'] ?? 'false')],
            ['Log Level', getenv('TINA4_LOG_LEVEL') ?: ($_ENV['TINA4_LOG_LEVEL'] ?? 'INFO')],
        ];
        $envSection = self::collapsible('Environment', self::table($envPairs));

        $e_excType = self::esc($excType);
        $e_excMsg = self::esc($excMsg);
        $stackSection = self::collapsible('Stack Trace', $framesHtml, true);
        // Per-response CSP nonce (ADR-0088): the overlay serves its whole
        // stylesheet inside one nonce'd <style> and carries no style= attribute,
        // so it renders under the strict default Content-Security-Policy.
        $nonce = Csp::currentCspNonce();
        $styles = self::overlayStylesheet();

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tina4 Error — {$e_excType}</title>
<style nonce="{$nonce}">
{$styles}
</style>
</head>
<body>
<div class="eo-wrap">
  <div class="eo-header">
    <div class="eo-badge-row">
      <span class="eo-badge">Error</span>
      <span class="eo-sub">Tina4 Debug Overlay</span>
    </div>
    <h1 class="eo-type">{$e_excType}</h1>
    <p class="eo-msg">{$e_excMsg}</p>
  </div>
  {$stackSection}
  {$requestSection}
  {$envSection}
  <div class="eo-footer">
    Tina4 Debug Overlay &mdash; This page is only shown in debug mode. Set TINA4_DEBUG=false in production.
  </div>
</div>
{$devToolbar}
</body>
</html>
HTML;
    }

    /**
     * The overlay's full stylesheet, served inside one nonce'd <style> block.
     *
     * Keeping the rules here (not on the elements) is what makes the overlay
     * CSP-clean: no framework page emits a `style="..."` attribute, because a
     * nonce covers a <style> ELEMENT but never a style attribute (ADR-0088).
     *
     * @return string
     */
    private static function overlayStylesheet(): string
    {
        $bg = self::BG;
        $surface = self::SURFACE;
        $overlay = self::OVERLAY;
        $text = self::TEXT;
        $subtext = self::SUBTEXT;
        $red = self::RED;
        $yellow = self::YELLOW;
        $blue = self::BLUE;
        $green = self::GREEN;
        $lavender = self::LAVENDER;
        $peach = self::PEACH;
        $errorLineBg = self::ERROR_LINE_BG;
        return <<<CSS
*{margin:0;padding:0;box-sizing:border-box;}
body{background:{$bg};color:{$text};font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;padding:24px;line-height:1.5;}
.eo-wrap{max-width:960px;margin:0 auto;}
.eo-header{margin-bottom:24px;}
.eo-badge-row{display:flex;align-items:center;gap:12px;margin-bottom:12px;}
.eo-badge{background:{$red};color:{$bg};padding:4px 12px;border-radius:4px;font-weight:700;font-size:13px;text-transform:uppercase;}
.eo-sub{color:{$subtext};font-size:14px;}
.eo-type{color:{$red};font-size:28px;font-weight:700;margin-bottom:8px;}
.eo-msg{color:{$text};font-size:18px;font-family:'SF Mono','Fira Code','Consolas',monospace;background:{$surface};padding:12px 16px;border-radius:6px;border-left:4px solid {$red};}
.eo-footer{margin-top:32px;padding-top:16px;border-top:1px solid {$overlay};color:{$subtext};font-size:12px;}
.eo-source{background:{$surface};border-radius:6px;padding:12px;overflow-x:auto;font-family:'SF Mono','Fira Code','Consolas',monospace;font-size:13px;line-height:1.6;}
.eo-line{display:flex;padding:1px 0;}
.eo-line-err{background:{$errorLineBg};}
.eo-ln{color:{$yellow};min-width:3.5em;text-align:right;padding-right:1em;user-select:none;}
.eo-marker{color:{$red};width:1.2em;user-select:none;}
.eo-code{color:{$text};white-space:pre-wrap;tab-size:4;}
.eo-frame{margin-bottom:16px;}
.eo-frame-head{margin-bottom:4px;}
.eo-file{color:{$blue};}
.eo-sep{color:{$subtext};}
.eo-lineno{color:{$yellow};}
.eo-fn{color:{$green};}
.eo-stale{background:{$peach};color:{$bg};padding:1px 8px;border-radius:3px;font-size:11px;font-weight:700;margin-left:6px;}
.eo-details{margin-top:16px;}
.eo-summary{cursor:pointer;color:{$lavender};font-weight:600;font-size:15px;padding:8px 0;user-select:none;}
.eo-details-body{padding:8px 0;}
.eo-table{border-collapse:collapse;width:100%;}
.eo-key{color:{$peach};padding:4px 16px 4px 0;vertical-align:top;white-space:nowrap;}
.eo-val{color:{$text};padding:4px 0;word-break:break-all;}
.eo-none{color:{$subtext};}
.eo-hidden-frames{color:{$subtext};padding:8px 0;font-size:13px;}
CSS;
    }

    /**
     * Render the dev toolbar HTML for an error page. The error overlay
     * is, by definition, debug-mode-only — so the toolbar always belongs
     * here. Gives the user a one-click jump to /__dev (chat / plan /
     * file tree / Live Docs) so the error page isn't a dead-end. Falls
     * back to empty string if DevAdmin isn't loaded for any reason, or if
     * the caller could not use the dev surface.
     */
    private static function renderInlineToolbar(?array $request): string
    {
        if (!class_exists('\\Tina4\\DevAdmin')) {
            return '';
        }
        // Only for a caller that could use the dev surface at all
        // (DevAdmin::devSurfaceReachable()): /__dev refuses any other, so its
        // toolbar could only ever fail. The router says so in
        // 'dev_surface_reachable'. Any other caller -- the documented
        // renderErrorOverlay($e, $_SERVER) -- is judged from the same server
        // fields, and one that names no peer is refused.
        $reachable = $request['dev_surface_reachable'] ?? DevAdmin::devSurfaceReachable(Request::create(
            method: (string) ($request['REQUEST_METHOD'] ?? 'GET'),
            path: (string) ($request['REQUEST_URI'] ?? '/'),
            headers: array_filter([
                'host' => $request['HTTP_HOST'] ?? null,
                'authorization' => $request['HTTP_AUTHORIZATION'] ?? null,
                'x-mcp-token' => $request['HTTP_X_MCP_TOKEN'] ?? null,
            ], 'is_string'),
            remoteIp: (string) ($request['REMOTE_ADDR'] ?? ''),
        ));
        if ($reachable !== true) {
            return '';
        }
        $method = $request['REQUEST_METHOD'] ?? 'GET';
        $path   = $request['REQUEST_URI']   ?? '/';
        $rid    = (class_exists('\\Tina4\\Log') && method_exists('\\Tina4\\Log', 'getRequestId'))
            ? (\Tina4\Log::getRequestId() ?? '')
            : '';
        $count  = class_exists('\\Tina4\\Router') ? \Tina4\Router::count() : 0;
        try {
            return \Tina4\DevAdmin::renderToolbar(
                method:         $method,
                path:           $path,
                matchedPattern: 'error',
                requestId:      $rid,
                routeCount:     $count,
            );
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Mask a sensitive request value (OVERLAY-DEC-02).
     *
     * Returns '[redacted]' when $key names a secret field (an
     * Authorization/Cookie/Set-Cookie header or a password/token/secret/key-like
     * body/param key), otherwise the value unchanged.
     */
    private static function redact(string $key, string $value): string
    {
        return preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1 ? self::REDACTED : $value;
    }

    /**
     * Check if TINA4_DEBUG is enabled.
     */
    public static function isDebugMode(): bool
    {
        $debug = getenv('TINA4_DEBUG') ?: ($_ENV['TINA4_DEBUG'] ?? 'false');
        return DotEnv::isTruthy($debug);
    }

    // ── Private helpers ──────────────────────────────────────────────────

    private static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function readSourceLines(string $filename, int $lineno): array
    {
        if (!is_file($filename) || !is_readable($filename)) {
            return [];
        }
        $allLines = @file($filename);
        if ($allLines === false) {
            return [];
        }
        $start = max(0, $lineno - self::CONTEXT_LINES - 1);
        $end = min(count($allLines), $lineno + self::CONTEXT_LINES);
        $result = [];
        for ($i = $start; $i < $end; $i++) {
            $num = $i + 1;
            $result[] = [$num, rtrim($allLines[$i], "\n\r"), $num === $lineno];
        }
        return $result;
    }

    private static function formatSourceBlock(string $filename, int $lineno): string
    {
        $lines = self::readSourceLines($filename, $lineno);
        if (empty($lines)) {
            return '';
        }
        $rows = '';
        foreach ($lines as [$num, $text, $isError]) {
            $rowClass = $isError ? 'eo-line eo-line-err' : 'eo-line';
            $marker = $isError ? '&#x25b6;' : ' ';
            $e_text = self::esc($text);
            $rows .= "<div class=\"{$rowClass}\">"
                . "<span class=\"eo-ln\">{$num}</span>"
                . "<span class=\"eo-marker\">{$marker}</span>"
                . "<span class=\"eo-code\">{$e_text}</span>"
                . "</div>\n";
        }
        return "<div class=\"eo-source\">" . $rows . "</div>";
    }

    /**
     * Render a single stack frame.
     *
     * When the source file's mtime is newer than $capturedAt (with a
     * 0.5 second margin to absorb filesystem-noise false positives) the
     * frame header gets a peach "FILE MODIFIED @ HH:MM:SS UTC" badge —
     * this protects against the "AI coder rewrote the file between
     * generating the overlay and the browser rendering it" confusion
     * where the displayed source no longer matches what actually
     * raised the error.
     */
    private static function formatFrame(string $filename, int $lineno, string $funcName, float $capturedAt = 0.0): string
    {
        $source = ($filename && $lineno > 0) ? self::formatSourceBlock($filename, $lineno) : '';
        $e_file = self::esc($filename);
        $e_func = self::esc($funcName);

        $staleBadge = '';
        if ($capturedAt > 0.0 && $filename !== '' && is_file($filename)) {
            $mtime = @filemtime($filename);
            if ($mtime !== false && $mtime > $capturedAt + 0.5) {
                $mtimeIso = gmdate('H:i:s', $mtime);
                $staleBadge = " <span class=\"eo-stale\">"
                    . "FILE MODIFIED @ {$mtimeIso} UTC — source may not match what failed</span>";
            }
        }

        return "<div class=\"eo-frame\">"
            . "<div class=\"eo-frame-head\">"
            . "<span class=\"eo-file\">{$e_file}</span>"
            . "<span class=\"eo-sep\"> : </span>"
            . "<span class=\"eo-lineno\">{$lineno}</span>"
            . "<span class=\"eo-sep\"> in </span>"
            . "<span class=\"eo-fn\">{$e_func}</span>"
            . $staleBadge
            . "</div>"
            . $source
            . "</div>";
    }

    private static function collapsible(string $title, string $content, bool $openByDefault = false): string
    {
        $open = $openByDefault ? ' open' : '';
        $e_title = self::esc($title);
        return "<details class=\"eo-details\"{$open}>"
            . "<summary class=\"eo-summary\">{$e_title}</summary>"
            . "<div class=\"eo-details-body\">{$content}</div>"
            . "</details>";
    }

    private static function table(array $pairs): string
    {
        if (empty($pairs)) {
            return "<span class=\"eo-none\">None</span>";
        }
        $rows = '';
        foreach ($pairs as [$key, $val]) {
            $e_key = self::esc($key);
            $e_val = self::esc($val);
            $rows .= "<tr>"
                . "<td class=\"eo-key\">{$e_key}</td>"
                . "<td class=\"eo-val\">{$e_val}</td>"
                . "</tr>";
        }
        return "<table class=\"eo-table\">{$rows}</table>";
    }
}
