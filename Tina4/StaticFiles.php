<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * Static file serving — serves files from public directories with MIME type detection.
 * Zero dependencies — uses only PHP built-in functions.
 */
class StaticFiles
{
    /** @var array<string, string> Extension-to-MIME-type mapping */
    private const MIME_TYPES = [
        // Text
        'html' => 'text/html; charset=utf-8',
        'css'  => 'text/css; charset=utf-8',
        'js'   => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'xml'  => 'application/xml; charset=utf-8',
        'txt'  => 'text/plain; charset=utf-8',
        'csv'  => 'text/csv; charset=utf-8',

        // Images
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        'webp' => 'image/webp',

        // Documents
        'pdf'  => 'application/pdf',
        'zip'  => 'application/zip',

        // Fonts
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'eot'   => 'application/vnd.ms-fontobject',

        // Media
        'mp3'  => 'audio/mpeg',
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
        'ogg'  => 'audio/ogg',
    ];

    /**
     * Try to serve a static file from the public directory.
     * Returns a Response if a file was found, or null if not.
     *
     * @param string $path The request path (e.g. "/css/style.css")
     * @param string $basePath The application base path
     */
    public static function tryServe(string $path, string $basePath = '.'): ?Response
    {
        // Security: reject directory traversal
        if (str_contains($path, '..')) {
            return null;
        }

        // Security: don't serve PHP files
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'php') {
            return null;
        }

        // Search directories in order: app public, then framework public
        $searchDirs = [
            $basePath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'public',
            $basePath . DIRECTORY_SEPARATOR . 'public',
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'public',
        ];

        // Normalise the request path to a relative file path
        $relativePath = ltrim($path, '/');

        foreach ($searchDirs as $dir) {
            // Try exact file match, then index.html for directory-style requests
            $candidates = [
                $dir . DIRECTORY_SEPARATOR . $relativePath,
                $dir . DIRECTORY_SEPARATOR . $relativePath . DIRECTORY_SEPARATOR . 'index.html',
            ];

            foreach ($candidates as $filePath) {
                $realPath = realpath($filePath);

                // File must exist and be a regular file
                if ($realPath === false || !is_file($realPath)) {
                    continue;
                }

                // Security: ensure resolved path is inside the search directory
                $realDir = realpath($dir);
                if ($realDir === false || !str_starts_with($realPath, $realDir . DIRECTORY_SEPARATOR)) {
                    continue;
                }

                // Determine MIME type
                $fileExtension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
                $contentType = self::getMimeType($fileExtension);
                $fileSize = filesize($realPath);
                $content = file_get_contents($realPath);

                $response = new Response();
                $response->status(200);
                $response->header('Content-Type', $contentType);
                $response->header('Content-Length', (string) $fileSize);
                $response->setBody($content);

                return $response;
            }
        }

        return null;
    }

    /**
     * Get MIME type for a file extension.
     */
    private static function getMimeType(string $extension): string
    {
        return self::MIME_TYPES[$extension] ?? 'application/octet-stream';
    }
}
