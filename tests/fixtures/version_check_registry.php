<?php

declare(strict_types=1);

/**
 * A REAL Packagist-p2-shaped registry, served by the PHP built-in server
 * (`php -S`) for DevAdminVersionCheckTest. This is a genuine HTTP server
 * process — the dev-admin route does a real socket round-trip against it, so
 * NO stream-wrapper mock stands in for the network. The request PATH selects
 * which canned body is returned, so one server covers both reachable cases.
 *
 * Routes:
 *   GET /no-stable-version -> a p2 document with only dev/rc versions (nothing
 *                             matching the stable filter -> the route must say
 *                             it could not learn a version, not "up to date").
 *   GET /with-version (or anything else) -> a p2 document with real stable
 *                             versions; the highest stable is 3.13.131.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

/**
 * A Packagist p2 body carrying the given version strings.
 *
 * @param list<string> $versions
 */
function packagist_body(array $versions): string
{
    return (string) json_encode([
        'packages' => [
            'tina4stack/tina4php' => array_map(
                static fn(string $v): array => ['version' => $v],
                $versions
            ),
        ],
    ]);
}

header('Content-Type: application/json');

if ($path === '/no-stable-version') {
    // Reaching the registry is not the same as learning the version.
    echo packagist_body(['dev-main', '3.13.131-rc1']);
    exit;
}

// /with-version (and any other path): a real p2 document with stable versions,
// deliberately unsorted so the route's own sort has to pick 3.13.131.
echo packagist_body(['3.13.125', 'dev-main', '3.13.131', '3.13.9']);
