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
     * Scan a directory and register all discovered routes with the Router.
     *
     * Supports two conventions:
     *   1. File-system routing: get.php, post.php, etc. that return a closure
     *   2. Inline routing: any .php file that calls Router::get() / Router::post() etc. directly
     *
     * @param string $routesDir The base directory to scan (e.g., src/routes)
     * @return array<int, array{method: string, path: string, file: string}> List of discovered routes
     */
    public static function scan(string $routesDir): array
    {
        $discovered = [];

        if (!is_dir($routesDir)) {
            return $discovered;
        }

        $routesDir = rtrim(str_replace('\\', '/', $routesDir), '/');
        [$conventionFiles, $inlineFiles] = self::findRouteFiles($routesDir);

        // 1. Convention-based routes (get.php, post.php, etc.)
        foreach ($conventionFiles as $file) {
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

            $discovered[] = [
                'method' => $method,
                'path' => $urlPath,
                'file' => $file,
            ];
        }

        // 2. Inline route files (any other .php file that registers routes directly)
        foreach ($inlineFiles as $file) {
            $result = require_once $file;
            // Only count files that return a callable as route files
            if (is_callable($result)) {
                $discovered[] = [
                    'method' => 'INLINE',
                    'path' => '*',
                    'file' => $file,
                ];
            }
        }

        return $discovered;
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
        $result = require $file;

        if (is_callable($result)) {
            return $result;
        }

        Log::warning("Route file does not return a callable: {$file}");
        return null;
    }
}
