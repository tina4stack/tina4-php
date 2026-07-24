<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
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
     * A served asset always carries `Cache-Control: no-cache, must-revalidate`
     * plus two validators — a weak `ETag` (from mtime + size) and a
     * `Last-Modified` — so a browser may cache it but must revalidate before
     * use: a redeployed asset reaches the browser on the next load without a
     * manual hard refresh. When the incoming request revalidates with a
     * matching `If-None-Match` (weak comparison, or `*`) — or an
     * `If-Modified-Since` at least as new as the file — a bare `304 Not
     * Modified` (no body) is returned instead of re-streaming the bytes.
     *
     * @param string $path The request path (e.g. "/css/style.css")
     * @param string $basePath The application base path
     * @param string|null $ifNoneMatch Incoming `If-None-Match` request header (null when absent)
     * @param string|null $ifModifiedSince Incoming `If-Modified-Since` request header (null when absent)
     */
    public static function tryServe(string $path, string $basePath = '.', ?string $ifNoneMatch = null, ?string $ifModifiedSince = null): ?Response
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

        // The framework ships the Swagger UI as STATIC assets
        // (src/public/swagger/index.html + oauth2-redirect.html). Static files are
        // resolved BEFORE routes, and the index resolution below turns a bare
        // '/swagger' into 'swagger/index.html' -- so without this gate the Swagger
        // UI was served even when Swagger::isEnabled() is false (explicit
        // TINA4_SWAGGER_ENABLED=false, or debug off, or --production), silently
        // bypassing the documented production on/off switch. The tell was
        // /swagger returning 200 (static file) while /swagger/openapi.json (the
        // gated route) correctly returned 404.
        $normalisedPath = '/' . ltrim($path, '/');
        if (($normalisedPath === '/swagger' || str_starts_with($normalisedPath, '/swagger/'))
            && !Swagger::isEnabled()
        ) {
            return null;
        }

        // Search directories in order: TINA4_PUBLIC_DIR override, app public,
        // then framework built-in public (tina4.min.js etc.)
        $searchDirs = [];
        $customPublic = getenv('TINA4_PUBLIC_DIR');
        if ($customPublic !== false && $customPublic !== '') {
            $searchDirs[] = rtrim($customPublic, DIRECTORY_SEPARATOR);
        }
        $searchDirs[] = $basePath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'public';
        $searchDirs[] = $basePath . DIRECTORY_SEPARATOR . 'public';
        $searchDirs[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'public';

        // Normalise the request path to a relative file path
        $relativePath = ltrim($path, '/');

        // Index resolution: '/' or '/foo/' -> append 'index.html' so a Vite/SPA
        // build with src/public/index.html serves at the matching URL — no
        // custom Router::get('/', ...) route needed.
        if ($relativePath === '' || str_ends_with($relativePath, '/')) {
            $relativePath .= 'index.html';
        }

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
                $modifiedTime = filemtime($realPath);

                // Validators: a weak ETag from mtime + size, and an HTTP-date
                // Last-Modified. The asset may be cached but must be revalidated
                // before use, so a redeployed file reaches the browser on the
                // next load without a manual hard refresh.
                $eTag = sprintf('W/"%d-%d"', $modifiedTime, $fileSize);
                $lastModified = gmdate('D, d M Y H:i:s', $modifiedTime) . ' GMT';

                // Conditional request — a matching validator means the browser
                // already has this exact asset, so revalidation is a cheap 304
                // round-trip rather than a re-download.
                if (self::isNotModified($ifNoneMatch, $ifModifiedSince, $eTag, $modifiedTime)) {
                    $notModified = new Response();
                    $notModified->status(304);
                    $notModified->header('Cache-Control', 'no-cache, must-revalidate');
                    $notModified->header('ETag', $eTag);
                    $notModified->header('Last-Modified', $lastModified);
                    $notModified->setBody('');

                    return $notModified;
                }

                $content = file_get_contents($realPath);

                $response = new Response();
                $response->status(200);
                $response->header('Content-Type', $contentType);
                $response->header('Content-Length', (string) $fileSize);
                $response->header('Cache-Control', 'no-cache, must-revalidate');
                $response->header('ETag', $eTag);
                $response->header('Last-Modified', $lastModified);
                $response->setBody($content);

                return $response;
            }
        }

        return null;
    }

    /**
     * Decide whether a conditional request may be answered with 304 Not Modified.
     *
     * `If-None-Match` takes precedence over `If-Modified-Since` (RFC 7232 §6):
     * when it is present its result is authoritative and the date is ignored.
     *
     * @param string|null $ifNoneMatch Incoming `If-None-Match` header (null when absent)
     * @param string|null $ifModifiedSince Incoming `If-Modified-Since` header (null when absent)
     * @param string $eTag The validator this response would carry
     * @param int $modifiedTime The file's modification time (Unix timestamp)
     * @return bool True when the client's cached copy is still current
     */
    private static function isNotModified(?string $ifNoneMatch, ?string $ifModifiedSince, string $eTag, int $modifiedTime): bool
    {
        if ($ifNoneMatch !== null && $ifNoneMatch !== '') {
            return self::eTagMatches($ifNoneMatch, $eTag);
        }

        if ($ifModifiedSince !== null && $ifModifiedSince !== '') {
            $since = strtotime($ifModifiedSince);
            // 304 when the client's copy is at least as new as the file
            // (If-Modified-Since >= Last-Modified).
            return $since !== false && $modifiedTime <= $since;
        }

        return false;
    }

    /**
     * Match an `If-None-Match` header value against our ETag.
     *
     * Uses weak comparison (RFC 7232 §3.2): an optional `W/` prefix is ignored
     * on both sides. Accepts a comma-separated list of candidate tags and the
     * wildcard `*`.
     *
     * @param string $ifNoneMatch The raw `If-None-Match` header value
     * @param string $eTag The validator this response would carry
     * @return bool True when any candidate tag matches (or is `*`)
     */
    private static function eTagMatches(string $ifNoneMatch, string $eTag): bool
    {
        $ourTag = self::stripWeakPrefix($eTag);

        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*') {
                return true;
            }
            if (self::stripWeakPrefix($candidate) === $ourTag) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip an optional leading `W/` weak-validator prefix from an ETag.
     *
     * @param string $eTag The ETag value (possibly weak, e.g. `W/"abc"`)
     * @return string The ETag with any `W/` prefix removed
     */
    private static function stripWeakPrefix(string $eTag): string
    {
        return str_starts_with($eTag, 'W/') ? substr($eTag, 2) : $eTag;
    }

    /**
     * Get MIME type for a file extension.
     */
    private static function getMimeType(string $extension): string
    {
        return self::MIME_TYPES[$extension] ?? 'application/octet-stream';
    }
}
