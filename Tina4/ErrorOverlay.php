<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
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
            $subtext = self::SUBTEXT;
            $framesHtml .= "<div style=\"color:{$subtext};padding:8px 0;font-size:13px;\">"
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
        $bg = self::BG;
        $text = self::TEXT;
        $red = self::RED;
        $subtext = self::SUBTEXT;
        $surface = self::SURFACE;
        $overlay = self::OVERLAY;
        $stackSection = self::collapsible('Stack Trace', $framesHtml, true);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tina4 Error — {$e_excType}</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{background:{$bg};color:{$text};font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;padding:24px;line-height:1.5;}
</style>
</head>
<body>
<div style="max-width:960px;margin:0 auto;">
  <div style="margin-bottom:24px;">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
      <span style="background:{$red};color:{$bg};padding:4px 12px;border-radius:4px;font-weight:700;font-size:13px;text-transform:uppercase;">Error</span>
      <span style="color:{$subtext};font-size:14px;">Tina4 Debug Overlay</span>
    </div>
    <h1 style="color:{$red};font-size:28px;font-weight:700;margin-bottom:8px;">{$e_excType}</h1>
    <p style="color:{$text};font-size:18px;font-family:'SF Mono','Fira Code','Consolas',monospace;background:{$surface};padding:12px 16px;border-radius:6px;border-left:4px solid {$red};">{$e_excMsg}</p>
  </div>
  {$stackSection}
  {$requestSection}
  {$envSection}
  <div style="margin-top:32px;padding-top:16px;border-top:1px solid {$overlay};color:{$subtext};font-size:12px;">
    Tina4 Debug Overlay &mdash; This page is only shown in debug mode. Set TINA4_DEBUG=false in production.
  </div>
</div>
{$devToolbar}
</body>
</html>
HTML;
    }

    /**
     * Render the dev toolbar HTML for an error page. The error overlay
     * is, by definition, debug-mode-only — so the toolbar always belongs
     * here. Gives the user a one-click jump to /__dev (chat / plan /
     * file tree / Live Docs) so the error page isn't a dead-end. Falls
     * back to empty string if DevAdmin isn't loaded for any reason.
     */
    private static function renderInlineToolbar(?array $request): string
    {
        if (!class_exists('\\Tina4\\DevAdmin')) {
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
            $bg = $isError ? 'background:' . self::ERROR_LINE_BG . ';' : '';
            $marker = $isError ? '&#x25b6;' : ' ';
            $e_text = self::esc($text);
            $yellow = self::YELLOW;
            $red = self::RED;
            $textColor = self::TEXT;
            $rows .= "<div style=\"{$bg}display:flex;padding:1px 0;\">"
                . "<span style=\"color:{$yellow};min-width:3.5em;text-align:right;padding-right:1em;user-select:none;\">{$num}</span>"
                . "<span style=\"color:{$red};width:1.2em;user-select:none;\">{$marker}</span>"
                . "<span style=\"color:{$textColor};white-space:pre-wrap;tab-size:4;\">{$e_text}</span>"
                . "</div>\n";
        }
        $surface = self::SURFACE;
        return "<div style=\"background:{$surface};border-radius:6px;padding:12px;overflow-x:auto;"
            . "font-family:'SF Mono','Fira Code','Consolas',monospace;font-size:13px;line-height:1.6;\">"
            . $rows . "</div>";
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
        $blue = self::BLUE;
        $yellow = self::YELLOW;
        $green = self::GREEN;
        $subtext = self::SUBTEXT;

        $staleBadge = '';
        if ($capturedAt > 0.0 && $filename !== '' && is_file($filename)) {
            $mtime = @filemtime($filename);
            if ($mtime !== false && $mtime > $capturedAt + 0.5) {
                $mtimeIso = gmdate('H:i:s', $mtime);
                $peach = self::PEACH;
                $bg = self::BG;
                $staleBadge = " <span style=\"background:{$peach};color:{$bg};padding:1px 8px;"
                    . "border-radius:3px;font-size:11px;font-weight:700;margin-left:6px;\">"
                    . "FILE MODIFIED @ {$mtimeIso} UTC — source may not match what failed</span>";
            }
        }

        return "<div style=\"margin-bottom:16px;\">"
            . "<div style=\"margin-bottom:4px;\">"
            . "<span style=\"color:{$blue};\">{$e_file}</span>"
            . "<span style=\"color:{$subtext};\"> : </span>"
            . "<span style=\"color:{$yellow};\">{$lineno}</span>"
            . "<span style=\"color:{$subtext};\"> in </span>"
            . "<span style=\"color:{$green};\">{$e_func}</span>"
            . $staleBadge
            . "</div>"
            . $source
            . "</div>";
    }

    private static function collapsible(string $title, string $content, bool $openByDefault = false): string
    {
        $open = $openByDefault ? ' open' : '';
        $e_title = self::esc($title);
        $lavender = self::LAVENDER;
        return "<details style=\"margin-top:16px;\"{$open}>"
            . "<summary style=\"cursor:pointer;color:{$lavender};font-weight:600;font-size:15px;"
            . "padding:8px 0;user-select:none;\">{$e_title}</summary>"
            . "<div style=\"padding:8px 0;\">{$content}</div>"
            . "</details>";
    }

    private static function table(array $pairs): string
    {
        if (empty($pairs)) {
            $subtext = self::SUBTEXT;
            return "<span style=\"color:{$subtext};\">None</span>";
        }
        $rows = '';
        foreach ($pairs as [$key, $val]) {
            $e_key = self::esc($key);
            $e_val = self::esc($val);
            $peach = self::PEACH;
            $text = self::TEXT;
            $rows .= "<tr>"
                . "<td style=\"color:{$peach};padding:4px 16px 4px 0;vertical-align:top;white-space:nowrap;\">{$e_key}</td>"
                . "<td style=\"color:{$text};padding:4px 0;word-break:break-all;\">{$e_val}</td>"
                . "</tr>";
        }
        return "<table style=\"border-collapse:collapse;width:100%;\">{$rows}</table>";
    }
}
