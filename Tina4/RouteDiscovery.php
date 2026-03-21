<?php

/**
 * Tina4 - This is not a 4ramework.
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
     * @param string $routesDir The base directory to scan (e.g., src/routes)
     * @return array<int, array{method: string, path: string, file: string}> List of discovered routes
     */
    public static function scan(string $routesDir): array
    {
        $discovered = [];

        if (!is_dir($routesDir)) {
            return $discovered;
        }

        $routesDir = rtrim($routesDir, '/');
        $files = self::findRouteFiles($routesDir);

        foreach ($files as $file) {
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
     * @return array<string>
     */
    private static function findRouteFiles(string $dir): array
    {
        $files = [];

        $items = scandir($dir);
        if ($items === false) {
            return $files;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $files = array_merge($files, self::findRouteFiles($path));
            } elseif (in_array($item, self::ROUTE_FILES, true)) {
                $files[] = $path;
            }
        }

        return $files;
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
