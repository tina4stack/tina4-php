<?php

/**
 * Router for the `php -S` server used by AuthRs256OptInTest.
 *
 * Two jobs, both of them real HTTP:
 *
 *   GET /ping               -> 200 {"reply":"pong"}. The control that proves
 *                              plain http:// still works while the `https`
 *                              stream wrapper is unregistered, so the guard is
 *                              SCOPED to https rather than breaking the client.
 *   GET /redirect-to-https  -> 302 to an https:// URL. The hop that needs TLS is
 *                              not the one the caller asked for, which is why
 *                              Api checks the guard per redirect hop.
 *
 * It also serves as the TCP endpoint for the MQTT TLS test: it accepts the
 * connection and then fails the TLS handshake (it speaks plain HTTP), which is
 * what drives Mqtt into its openssl_error_string() branch.
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/redirect-to-https') {
    header('Location: https://127.0.0.1:1/after-the-hop', true, 302);
    header('Content-Type: text/plain');
    echo 'redirecting';
    exit;
}

if ($path === '/ping') {
    header('Content-Type: application/json');
    echo json_encode(['reply' => 'pong']);
    exit;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'not found', 'path' => $path]);
