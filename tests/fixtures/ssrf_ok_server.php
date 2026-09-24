<?php
// Router for `php -S` — a real loopback listener for the SSRF guard suite.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    http_response_code(201);
    echo '';
} else {
    http_response_code(200);
    header('Content-Type: application/json');
    echo '{"ok":true}';
}
