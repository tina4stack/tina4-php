<?php

/**
 * Tina4 - This is not a 4ramework.
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
 *       echo ErrorOverlay::render($e, $_SERVER);
 *   }
 *
 * Only activate when TINA4_DEBUG is true.
 * In production, call ErrorOverlay::renderProduction() instead.
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

    /**
     * Render a rich HTML error overlay.
     *
     * @param \Throwable $e The caught exception or error.
     * @param array|null $request Optional request details ($_SERVER or custom array).
     * @return string Complete HTML page.
     */
    public static function render(\Throwable $e, ?array $request = null): string
    {
        $excType = get_class($e);
        $excMsg = $e->getMessage();
        $file = $e->getFile();
        $line = $e->getLine();
        $trace = $e->getTrace();

        // ── Main error location ──
        $framesHtml = self::formatFrame($file, $line, '{main}');

        // ── Stack trace frames ──
        foreach ($trace as $frame) {
            $frameFile = $frame['file'] ?? '[internal]';
            $frameLine = $frame['line'] ?? 0;
            $frameFunc = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
            $framesHtml .= self::formatFrame($frameFile, $frameLine, $frameFunc);
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
                    $requestPairs[] = [$k, is_string($v) ? $v : json_encode($v)];
                }
            }
            // Also include non-$_SERVER style dicts (headers, params)
            foreach (['headers', 'params', 'body'] as $key) {
                if (isset($request[$key]) && is_array($request[$key])) {
                    foreach ($request[$key] as $hk => $hv) {
                        $requestPairs[] = ["$key.$hk", is_string($hv) ? $hv : json_encode($hv)];
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
            ['Log Level', getenv('TINA4_LOG_LEVEL') ?: ($_ENV['TINA4_LOG_LEVEL'] ?? 'ERROR')],
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
</body>
</html>
HTML;
    }

    /**
     * Render a safe, generic error page for production.
     */
    public static function renderProduction(int $statusCode = 500, string $message = 'Internal Server Error'): string
    {
        $e_msg = self::esc($message);
        $bg = self::BG;
        $text = self::TEXT;
        $red = self::RED;
        $subtext = self::SUBTEXT;
        $overlay = self::OVERLAY;

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$statusCode} — {$e_msg}</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{background:{$bg};color:{$text};font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
display:flex;justify-content:center;align-items:center;min-height:100vh;text-align:center;}
</style>
</head>
<body>
<div>
  <h1 style="font-size:72px;color:{$red};margin-bottom:16px;">{$statusCode}</h1>
  <p style="font-size:20px;color:{$subtext};">{$e_msg}</p>
  <p style="margin-top:24px;font-size:14px;color:{$overlay};">Tina4 PHP</p>
</div>
</body>
</html>
HTML;
    }

    /**
     * Check if TINA4_DEBUG is enabled.
     */
    public static function isDebugMode(): bool
    {
        $debug = getenv('TINA4_DEBUG') ?: ($_ENV['TINA4_DEBUG'] ?? 'false');
        return strtolower($debug) === 'true';
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

    private static function formatFrame(string $filename, int $lineno, string $funcName): string
    {
        $source = ($filename && $lineno > 0) ? self::formatSourceBlock($filename, $lineno) : '';
        $e_file = self::esc($filename);
        $e_func = self::esc($funcName);
        $blue = self::BLUE;
        $yellow = self::YELLOW;
        $green = self::GREEN;
        $subtext = self::SUBTEXT;

        return "<div style=\"margin-bottom:16px;\">"
            . "<div style=\"margin-bottom:4px;\">"
            . "<span style=\"color:{$blue};\">{$e_file}</span>"
            . "<span style=\"color:{$subtext};\"> : </span>"
            . "<span style=\"color:{$yellow};\">{$lineno}</span>"
            . "<span style=\"color:{$subtext};\"> in </span>"
            . "<span style=\"color:{$green};\">{$e_func}</span>"
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
