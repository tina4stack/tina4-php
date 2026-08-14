<?php

declare(strict_types=1);

/**
 * A REAL HTTP server whose responses follow a scripted sequence per path,
 * used by AIFetchRetryTest to drive Tina4\AITools::fetchBytes()'s retry loop over
 * real sockets — no mocks, no monkeypatched curl/file_get_contents/sleep.
 *
 * Owns its own listener (`stream_socket_server`) rather than running under
 * `php -S`, for the same reason as tests/fixtures/api_retry_server.php: it is
 * one long-running process, so the hit counters below are exact and a test
 * can assert "the client attempted N times" from the SERVER's point of view
 * instead of counting inside the class under test.
 *
 * Usage:  php ai_fetch_retry_server.php <port>
 *
 * Routes:
 *   GET /skill        -> hit 1 & 2: 503 "down"; hit 3+: 200 "skill body"
 *   GET /missing      -> always 404 "not found"
 *   GET /hits?path=P  -> {"hits": N} for path P (never counted itself)
 */

$port = (int)($argv[1] ?? 0);
if ($port <= 0) {
    fwrite(STDERR, "usage: ai_fetch_retry_server.php <port>\n");
    exit(1);
}

$listener = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if ($listener === false) {
    fwrite(STDERR, "could not bind 127.0.0.1:{$port}: {$errstr} ({$errno})\n");
    exit(1);
}

/** @var array<string, int> hits seen per path (the /hits reporting route is excluded) */
$hits = [];

/** Reason phrases for the statuses this fixture emits. */
$reason = [200 => 'OK', 404 => 'Not Found', 503 => 'Service Unavailable'];

/**
 * Write one complete HTTP/1.1 response and close the connection.
 *
 * @param resource $conn
 */
$respond = static function ($conn, int $status, string $body, string $contentType = 'application/octet-stream') use ($reason): void {
    $head = "HTTP/1.1 {$status} " . ($reason[$status] ?? 'Status') . "\r\n"
        . "Content-Type: {$contentType}\r\n"
        . 'Content-Length: ' . strlen($body) . "\r\n"
        . "Connection: close\r\n\r\n";
    @fwrite($conn, $head . $body);
    @fclose($conn);
};

while (true) {
    $conn = @stream_socket_accept($listener, 60);
    if ($conn === false) {
        // Idle accept timeout — keep listening. The parent TestServer signals
        // this process when the test class is done.
        continue;
    }

    // Read the request line + headers. TestServer's readiness probe opens a
    // connection and closes it without sending anything; that must NOT be
    // counted as a hit, so a request with no request line is dropped.
    stream_set_timeout($conn, 5);
    $requestLine = fgets($conn);
    if ($requestLine === false || trim($requestLine) === '') {
        @fclose($conn);
        continue;
    }
    while (($line = fgets($conn)) !== false) {
        if ($line === "\r\n" || $line === "\n") {
            break;
        }
    }

    $target = explode(' ', trim($requestLine))[1] ?? '/';
    $path = (string)parse_url($target, PHP_URL_PATH);
    parse_str((string)parse_url($target, PHP_URL_QUERY), $query);

    if ($path === '/hits') {
        $key = (string)($query['path'] ?? '');
        $respond($conn, 200, (string)json_encode(['hits' => $hits[$key] ?? 0]), 'application/json');
        continue;
    }

    if ($path === '/skill') {
        $hits[$path] = ($hits[$path] ?? 0) + 1;
        $n = $hits[$path];
        $n < 3
            ? $respond($conn, 503, 'down')
            : $respond($conn, 200, 'skill body');
        continue;
    }

    if ($path === '/missing') {
        $hits[$path] = ($hits[$path] ?? 0) + 1;
        $respond($conn, 404, 'not found');
        continue;
    }

    $respond($conn, 404, 'no such route: ' . $path);
}
