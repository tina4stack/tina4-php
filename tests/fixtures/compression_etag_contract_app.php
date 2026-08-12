<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Front controller for CompressionEtagContractTest — a REAL Tina4
 * application served by `php -S`, exercising feature 40 (HTTP compression +
 * ETag / conditional-GET) end to end over a real socket: a >1KB compressible
 * JSON route, a tiny JSON route, a >1KB non-compressible-content-type route,
 * and a real static CSS file (path shared via TINA4_TEST_STATIC_DIR) whose
 * mtime is pinned by the parent test so the static ETag is byte-exact
 * assertable.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

Router::get('/big', function (Request $request, Response $response) {
    // ~2010 bytes serialized, all-'x' repeats -> compresses hard, a strong
    // positive gzip signal when the decompressed body is checked byte-exact.
    return $response(['data' => str_repeat('x', 2000)]);
});

Router::get('/small', function (Request $request, Response $response) {
    return $response(['ok' => true]);
});

Router::get('/binary', function (Request $request, Response $response) {
    // >1KB, highly-compressible BYTES, but a non-compressible declared
    // content-type -- proves the content-type gate, not just a size gate.
    return $response(str_repeat('x', 2000), 200, 'application/octet-stream');
});

// Mirrors App::handle()'s non-streaming path: status + headers + body, so the
// socket sees real status codes and headers (200/304, Content-Encoding, ETag).
$response = Router::dispatch(Request::fromGlobals(), new Response());

http_response_code($response->getStatusCode() ?? 200);
foreach ($response->getHeaders() as $headerName => $headerValue) {
    if (!headers_sent()) {
        header("{$headerName}: {$headerValue}");
    }
}
echo $response->getBody();
