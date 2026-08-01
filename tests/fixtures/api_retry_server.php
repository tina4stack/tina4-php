<?php

declare(strict_types=1);

/**
 * A REAL HTTP server whose responses follow a per-scenario script, used by
 * ApiTest to drive Tina4\Api's opt-in retry/backoff over real sockets.
 *
 * It owns its own listener (`stream_socket_server`) rather than running under
 * `php -S` for one reason: `php -S` is an HTTP server and nothing else, so it
 * can never ABORT a connection without a response. A retryable TRANSPORT
 * failure — the `http_code => null` branch of Api::withRetry() — needs exactly
 * that: a connection the client really opens, the server really accepts, and
 * then closes with zero bytes. Here the accept loop owns the socket, so the
 * abort is a real one on the wire instead of a canned result array.
 *
 * Everything is deliberately sequential (accept -> serve -> close), so the hit
 * counters below are exact and a test can assert "the client attempted N times"
 * from the SERVER's point of view instead of counting inside a subclass.
 *
 * Usage:  php api_retry_server.php <port>
 *
 * Routes:
 *   GET /scripted?b=<behaviour>&k=<key>  -> the next scripted response for <key>
 *   GET /hits?k=<key>                    -> {"hits": N} for <key> (never counted)
 *
 * Behaviours:
 *   always-200        every attempt -> 200 {"ok":true,"hit":N}
 *   always-404        every attempt -> 404 (a 4xx is NOT retryable)
 *   always-503        every attempt -> 503 (retryable, so retries exhaust)
 *   down-then-ok      attempt 1 -> 503, later attempts -> 200
 *   throttle-then-ok  attempt 1 -> 429, later attempts -> 200
 *   abort-then-ok     attempt 1 -> connection accepted then CLOSED with zero
 *                     bytes (a real transport failure), later attempts -> 200
 */

$port = (int)($argv[1] ?? 0);
if ($port <= 0) {
    fwrite(STDERR, "usage: api_retry_server.php <port>\n");
    exit(1);
}

$listener = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if ($listener === false) {
    fwrite(STDERR, "could not bind 127.0.0.1:{$port}: {$errstr} ({$errno})\n");
    exit(1);
}

/** @var array<string, int> attempts seen per scenario key */
$hits = [];

/** Reason phrases for the statuses this fixture emits. */
$reason = [
    200 => 'OK',
    400 => 'Bad Request',
    404 => 'Not Found',
    429 => 'Too Many Requests',
    503 => 'Service Unavailable',
];

/**
 * Write one complete HTTP/1.1 response and close the connection.
 *
 * @param resource $conn
 */
$respond = static function ($conn, int $status, array $payload) use ($reason): void {
    $body = (string)json_encode($payload);
    $head = "HTTP/1.1 {$status} " . ($reason[$status] ?? 'Status') . "\r\n"
        . "Content-Type: application/json\r\n"
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
    // counted as an attempt, so a request with no request line is dropped.
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
    $key = (string)($query['k'] ?? '');

    if ($path === '/hits') {
        // Reporting endpoint — deliberately excluded from the counters.
        $respond($conn, 200, ['hits' => $hits[$key] ?? 0]);
        continue;
    }

    if ($path !== '/scripted' || $key === '') {
        $respond($conn, 404, ['error' => 'no such scenario', 'path' => $path]);
        continue;
    }

    $behaviour = (string)($query['b'] ?? '');
    $hits[$key] = ($hits[$key] ?? 0) + 1;
    $n = $hits[$key];

    switch ($behaviour) {
        case 'always-200':
            $respond($conn, 200, ['ok' => true, 'hit' => $n]);
            break;

        case 'always-404':
            $respond($conn, 404, ['ok' => false, 'hit' => $n]);
            break;

        case 'always-503':
            $respond($conn, 503, ['ok' => false, 'hit' => $n]);
            break;

        case 'down-then-ok':
            $n === 1
                ? $respond($conn, 503, ['ok' => false, 'hit' => $n])
                : $respond($conn, 200, ['ok' => true, 'hit' => $n]);
            break;

        case 'throttle-then-ok':
            $n === 1
                ? $respond($conn, 429, ['ok' => false, 'hit' => $n])
                : $respond($conn, 200, ['ok' => true, 'hit' => $n]);
            break;

        case 'abort-then-ok':
            if ($n === 1) {
                // The real transport failure: accepted, then closed with no
                // response at all. The client's fopen() fails outright, which
                // is the http_code === null branch of the retry policy.
                @fclose($conn);
                break;
            }
            $respond($conn, 200, ['ok' => true, 'hit' => $n]);
            break;

        default:
            $respond($conn, 400, ['error' => "unknown behaviour '{$behaviour}'"]);
            break;
    }
}
