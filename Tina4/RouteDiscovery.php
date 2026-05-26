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
        self::$lastRoutesDir = '';
    }

    /**
     * Scan a directory and register all discovered routes with the Router.
     *
     * Supports two conventions:
     *   1. File-system routing: get.php, post.php, etc. that return a closure
     *   2. Inline routing: any .php file that calls Router::get() / Router::post() etc. directly
     *
     * Idempotent: files seen on a previous call are skipped, so calling
     * scan() repeatedly (e.g. on /__dev/api/reload) only loads NEW files.
     *
     * @param string $routesDir The base directory to scan (e.g., src/routes)
     * @return array<int, array{method: string, path: string, file: string}> List of newly-discovered routes
     */
    public static function scan(string $routesDir): array
    {
        $discovered = [];

        if (!is_dir($routesDir)) {
            return $discovered;
        }

        $routesDir = rtrim(str_replace('\\', '/', $routesDir), '/');
        self::$lastRoutesDir = $routesDir;
        [$conventionFiles, $inlineFiles] = self::findRouteFiles($routesDir);

        $totalFiles = count($conventionFiles) + count($inlineFiles);

        // 1. Convention-based routes (get.php, post.php, etc.)
        foreach ($conventionFiles as $file) {
            if (isset(self::$seenFiles[$file])) {
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
            $discovered[] = [
                'method' => $method,
                'path' => $urlPath,
                'file' => $file,
            ];
        }

        // 2. Inline route files (any other .php file that registers routes directly)
        foreach ($inlineFiles as $file) {
            if (isset(self::$seenFiles[$file])) {
                continue;
            }
            try {
                $result = require_once $file;
                self::$seenFiles[$file] = true;
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
