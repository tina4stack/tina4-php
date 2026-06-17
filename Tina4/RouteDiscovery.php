<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * File-based route discovery — scans a directory for route files.
 *
 * Convention:
 *   src/routes/api/users/[id]/get.php  → GET /api/users/{id}
 *   src/routes/api/users/post.php      → POST /api/users
 *   src/routes/docs/[...slug]/get.php  → GET /docs/{slug:.*}
 *
 * Each file must return a closure: return function(Request $req, Response $res) { ... };
 *
 * Supported file names: get.php, post.php, put.php, delete.php, patch.php, any.php
 */
class RouteDiscovery
{
    /** @var array<string> Valid route file names */
    private const ROUTE_FILES = ['get.php', 'post.php', 'put.php', 'delete.php', 'patch.php', 'any.php'];

    /** @var array<string, string> Map file name to HTTP method */
    private const METHOD_MAP = [
        'get.php' => 'GET',
        'post.php' => 'POST',
        'put.php' => 'PUT',
        'delete.php' => 'DELETE',
        'patch.php' => 'PATCH',
        'any.php' => 'ANY',
    ];

    /**
     * Files already loaded by a previous scan. Prevents double-registration
     * when scan() is called again on /__dev/api/reload.
     *
     * @var array<string, true>
     */
    private static array $seenFiles = [];

    /**
     * Last-seen modification time per file (filepath => mtime). Lets rescan()
     * re-load a file whose mtime increased (an EXISTING route file was edited)
     * while still skipping unchanged files. Without this, a changed file is
     * never re-run and the server keeps serving the stale handler.
     *
     * @var array<string, int>
     */
    private static array $lastMtimes = [];

    /**
     * Last directory passed to scan(). Lets the reload endpoint re-discover
     * without needing the caller to remember the original path.
     */
    private static string $lastRoutesDir = '';

    /**
     * Re-run the most recent scan — used by POST /__dev/api/reload so new
     * files in src/routes/ register without a server restart. No-op if scan
     * has never been called.
     *
     * @return array<int, array{method: string, path: string, file: string}>
     */
    public static function rescan(): array
    {
        if (self::$lastRoutesDir === '') {
            return [];
        }
        return self::scan(self::$lastRoutesDir);
    }

    /**
     * Reset the seen-files state. Test-only — production code should treat
     * RouteDiscovery as a process-level singleton.
     */
    public static function reset(): void
    {
        self::$seenFiles = [];
        self::$lastMtimes = [];
        self::$lastRoutesDir = '';
    }

    /**
     * Scan a directory and register all discovered routes with the Router.
     *
     * Supports two conventions:
     *   1. File-system routing: get.php, post.php, etc. that return a closure
     *   2. Inline routing: any .php file that calls Router::get() / Router::post() etc. directly
     *
     * mtime-aware: a file is (re)loaded when it is NEW or its mtime increased
     * (an existing route file was edited), and skipped only when it has been
     * seen AND is unchanged. Calling scan() repeatedly (e.g. on
     * /__dev/api/reload) therefore re-imports edited route files — combined
     * with Router's replace-in-place semantics, the edit takes effect without
     * a server restart — while staying cheap and idempotent for unchanged
     * files.
     *
     * @param string $routesDir The base directory to scan (e.g., src/routes)
     * @return array<int, array{method: string, path: string, file: string}> List of newly-discovered or reloaded routes
     */
    public static function scan(string $routesDir): array
    {
        $discovered = [];

        if (!is_dir($routesDir)) {
            return $discovered;
        }

        // Read fresh mtimes — PHP caches stat() results per request, and a
        // same-second rewrite would otherwise look unchanged.
        clearstatcache();

        $routesDir = rtrim(str_replace('\\', '/', $routesDir), '/');
        self::$lastRoutesDir = $routesDir;
        [$conventionFiles, $inlineFiles] = self::findRouteFiles($routesDir);

        $totalFiles = count($conventionFiles) + count($inlineFiles);

        // 1. Convention-based routes (get.php, post.php, etc.)
        foreach ($conventionFiles as $file) {
            // Skip only when seen AND unchanged. Convention files are loaded
            // with `require` below, which re-executes cleanly on every call —
            // so an edited file simply re-runs its `return function (...)`
            // and re-registers, with Router replacing the stale entry.
            if (!self::shouldLoad($file)) {
                continue;
            }
            $relativePath = substr($file, strlen($routesDir));
            $fileName = basename($file);

            if (!isset(self::METHOD_MAP[$fileName])) {
                continue;
            }

            $method = self::METHOD_MAP[$fileName];

            // Build the URL path from the directory structure
            $dirPath = dirname($relativePath);
            $urlPath = self::directoryToUrlPath($dirPath);

            // Include the file to get the closure
            $callback = self::loadRouteFile($file);

            if ($callback === null) {
                continue;
            }

            // Register with the Router
            if ($method === 'ANY') {
                Router::any($urlPath, $callback);
            } else {
                $registerMethod = strtolower($method);
                Router::$registerMethod($urlPath, $callback);
            }

            self::$seenFiles[$file] = true;
            self::$lastMtimes[$file] = self::mtimeOf($file);
            $discovered[] = [
                'method' => $method,
                'path' => $urlPath,
                'file' => $file,
            ];
        }

        // 2. Inline route files (any other .php file that registers routes directly)
        foreach ($inlineFiles as $file) {
            $alreadySeen = isset(self::$seenFiles[$file]);

            if ($alreadySeen && !self::shouldLoad($file)) {
                // Seen and unchanged — nothing to do.
                continue;
            }

            // An EXISTING inline file that changed needs re-execution to pick
            // up edited routes. require_once won't re-run it, and a plain
            // require would fatally redeclare any top-level function/class/etc
            // the file defines. So we only re-execute when the file is safe to
            // re-run (no top-level declarations — just Router::get/post/...
            // calls). Files with top-level declarations are recorded so they
            // stop being re-scanned, but their routes will NOT hot-reload; the
            // user must restart the server. Honest caveat — see CLAUDE.md.
            if ($alreadySeen && !self::isSafeToReExecute($file)) {
                self::$lastMtimes[$file] = self::mtimeOf($file);
                Log::warning(
                    "Inline route file changed but declares top-level functions/classes, " .
                    "so it can't be safely re-included: {$file}. Restart the server to pick up its route changes."
                );
                continue;
            }

            try {
                // First load uses require_once (cannot double-include); a safe
                // changed file is re-run with require so its routes re-register.
                $result = $alreadySeen ? require $file : require_once $file;
                self::$seenFiles[$file] = true;
                self::$lastMtimes[$file] = self::mtimeOf($file);
                // Only count files that return a callable as route files
                if (is_callable($result)) {
                    $discovered[] = [
                        'method' => 'INLINE',
                        'path' => '*',
                        'file' => $file,
                    ];
                }
            } catch (\Throwable $e) {
                Log::error("Failed to load route file {$file}: " . $e->getMessage());
                self::recordBrokenImport($file, $e);
            }
        }

        // Zero-routes warning: src/routes/ has .php files but Router is
        // still empty. Almost certainly the user forgot Router::get(...).
        // Only fire on the first scan; later rescans handle the "no new
        // routes from a single edit" case naturally.
        if ($totalFiles > 0 && count(self::$seenFiles) === $totalFiles) {
            $routes = method_exists(Router::class, 'getRoutes') ? Router::getRoutes() : [];
            if (empty($routes) && empty($discovered)) {
                Log::warning(
                    "Auto-discover found {$totalFiles} .php file(s) in {$routesDir} but no routes registered. " .
                    "Each route file must call Router::get / Router::post / etc., or return a closure for convention files (get.php, post.php, ...)."
                );
            }
        }

        return $discovered;
    }

    /**
     * Write a .broken sentinel so /health and the dev dashboard surface
     * auto-discover failures instead of swallowing them into a log line.
     */
    private static function recordBrokenImport(string $file, \Throwable $error): void
    {
        try {
            $brokenDir = getcwd() . '/data/.broken';
            if (!is_dir($brokenDir)) {
                @mkdir($brokenDir, 0755, true);
            }
            $slug = str_replace(['/', '\\'], '_', $file);
            $payload = json_encode([
                'type' => 'auto_discover_failure',
                'file' => $file,
                'error' => get_class($error) . ': ' . $error->getMessage(),
            ], JSON_PRETTY_PRINT);
            @file_put_contents($brokenDir . "/discover_{$slug}.broken", $payload);
        } catch (\Throwable $e) {
            // If the .broken write itself fails, the original error is
            // already in the log — nothing more to do here.
        }
    }

    /**
     * Convert a directory path to a URL path.
     *
     * [id]      → {id}
     * [...slug] → {slug:.*}
     */
    public static function directoryToUrlPath(string $dirPath): string
    {
        // Normalise directory separators
        $dirPath = str_replace('\\', '/', $dirPath);
        $dirPath = '/' . trim($dirPath, '/');

        if ($dirPath === '/' || $dirPath === '/.') {
            return '/';
        }

        $segments = explode('/', trim($dirPath, '/'));
        $urlSegments = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            // Catch-all: [...slug]
            if (preg_match('/^\[\.\.\.([a-zA-Z_][a-zA-Z0-9_]*)\]$/', $segment, $m)) {
                $urlSegments[] = '{' . $m[1] . ':.*}';
                continue;
            }

            // Dynamic param: [id]
            if (preg_match('/^\[([a-zA-Z_][a-zA-Z0-9_]*)\]$/', $segment, $m)) {
                $urlSegments[] = '{' . $m[1] . '}';
                continue;
            }

            $urlSegments[] = $segment;
        }

        return '/' . implode('/', $urlSegments);
    }

    /**
     * Recursively find all route files in a directory.
     *
     * Returns two lists:
     *   [0] Convention files (get.php, post.php, etc.)
     *   [1] Inline files (any other .php file that registers routes directly)
     *
     * @return array{0: array<string>, 1: array<string>}
     */
    private static function findRouteFiles(string $dir): array
    {
        $conventionFiles = [];
        $inlineFiles = [];

        $items = scandir($dir);
        if ($items === false) {
            return [$conventionFiles, $inlineFiles];
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                [$childConvention, $childInline] = self::findRouteFiles($path);
                $conventionFiles = array_merge($conventionFiles, $childConvention);
                $inlineFiles = array_merge($inlineFiles, $childInline);
            } elseif (in_array($item, self::ROUTE_FILES, true)) {
                $conventionFiles[] = $path;
            } elseif (str_ends_with($item, '.php')) {
                $inlineFiles[] = $path;
            }
        }

        return [$conventionFiles, $inlineFiles];
    }

    /**
     * Decide whether a discovered file should be (re)loaded.
     *
     * Loads when the file is NEW (never seen) or CHANGED (mtime increased).
     * Skips only when it has been seen AND is unchanged. The scope guard means
     * files outside the discovered routes dir are never loaded — though
     * findRouteFiles() already only ever yields files under that dir, this is
     * a defence-in-depth check so we never touch framework/vendor files.
     */
    private static function shouldLoad(string $file): bool
    {
        if (!self::isWithinRoutesDir($file)) {
            return false;
        }
        if (!isset(self::$seenFiles[$file])) {
            return true;
        }
        return self::mtimeOf($file) > (self::$lastMtimes[$file] ?? 0);
    }

    /**
     * Fresh mtime for a file, or 0 if it can't be read.
     */
    private static function mtimeOf(string $file): int
    {
        $mtime = @filemtime($file);
        return $mtime === false ? 0 : $mtime;
    }

    /**
     * Scope guard: a file may only be (re)loaded if it lives under the routes
     * directory passed to the most recent scan(). Framework, vendor, and any
     * other source files are off-limits to the reload path.
     */
    private static function isWithinRoutesDir(string $file): bool
    {
        if (self::$lastRoutesDir === '') {
            return false;
        }
        $normalised = str_replace('\\', '/', $file);
        $prefix = rtrim(self::$lastRoutesDir, '/') . '/';
        return str_starts_with($normalised, $prefix);
    }

    /**
     * Whether an inline route file is safe to re-execute on a hot reload.
     *
     * Re-running a file that declares top-level functions, classes, traits,
     * interfaces, enums, or named constants would fatal with "cannot
     * redeclare". Convention route files don't hit this (they `return` a
     * closure), but arbitrary inline files might. We do a lightweight source
     * scan and treat any top-level declaration as unsafe — such a file is left
     * loaded as-is, and its route edits won't hot-reload until a restart.
     *
     * This is deliberately conservative: a false "unsafe" only costs a manual
     * restart for that one file, whereas a false "safe" would crash the
     * server.
     */
    private static function isSafeToReExecute(string $file): bool
    {
        $source = @file_get_contents($file);
        if ($source === false) {
            return false;
        }

        // Tokenise so keywords inside strings/comments don't trigger a false
        // positive, and so `function ()` (closures/arrow fns — safe) are
        // distinguished from `function name()` (named declarations — unsafe).
        $tokens = @token_get_all($source);
        if (!is_array($tokens)) {
            return false;
        }

        $declaring = [T_CLASS, T_INTERFACE, T_TRAIT];
        if (defined('T_ENUM')) {
            $declaring[] = T_ENUM;
        }

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }
            $id = $token[0];

            // class / interface / trait / enum — but not ::class.
            if (in_array($id, $declaring, true)) {
                $prev = self::previousMeaningfulToken($tokens, $i);
                if ($prev !== null && is_array($prev) && $prev[0] === T_DOUBLE_COLON) {
                    continue; // Foo::class — not a declaration
                }
                return false;
            }

            // A named function declaration: `function foo(`. A closure or
            // arrow fn is `function (` / `fn (` and is safe to re-run.
            if ($id === T_FUNCTION) {
                $next = self::nextMeaningfulToken($tokens, $i);
                if ($next !== null && is_array($next) && $next[0] === T_STRING) {
                    return false;
                }
            }

            // Top-level `const NAME = ...` (T_CONST). Class constants are also
            // T_CONST but live inside a class body — and any file with a class
            // body already returned false above. Function-level `const` isn't
            // legal in PHP, so a surviving T_CONST here is a global constant.
            if ($id === T_CONST) {
                return false;
            }
        }

        return true;
    }

    /**
     * Previous non-whitespace token before index $i, or null.
     *
     * @param array<int, array{0:int,1:string,2:int}|string> $tokens
     * @return array{0:int,1:string,2:int}|string|null
     */
    private static function previousMeaningfulToken(array $tokens, int $i): array|string|null
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                continue;
            }
            return $tokens[$j];
        }
        return null;
    }

    /**
     * Next non-whitespace token after index $i, or null.
     *
     * @param array<int, array{0:int,1:string,2:int}|string> $tokens
     * @return array{0:int,1:string,2:int}|string|null
     */
    private static function nextMeaningfulToken(array $tokens, int $i): array|string|null
    {
        $count = count($tokens);
        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                continue;
            }
            return $tokens[$j];
        }
        return null;
    }

    /**
     * Load a route file and return the closure it defines.
     */
    private static function loadRouteFile(string $file): ?callable
    {
        try {
            $result = require $file;

            if (is_callable($result)) {
                return $result;
            }

            Log::warning("Route file does not return a callable: {$file}");
            return null;
        } catch (\Throwable $e) {
            Log::error("Failed to load route file {$file}: " . $e->getMessage());
            self::recordBrokenImport($file, $e);
            return null;
        }
    }
}
