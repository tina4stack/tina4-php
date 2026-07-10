<?php

/**
 * Real HTTP endpoints for ApiTransferTest — served by the PHP built-in server
 * (`php -S`). This is a genuine server process; the tests do real socket
 * round-trips against it (no mocks). Two instances are booted on two ports to
 * give two distinct origins for the cross-origin redirect test.
 *
 * Routes:
 *   GET/POST /echo-headers  -> JSON of the request headers this server received
 *   GET      /redirect      -> 3xx (code=?) to ?to=<urlencoded absolute url>
 *   POST     /upload        -> JSON describing the received multipart file+fields
 *   GET      /set-cookie    -> emits multiple Set-Cookie headers (last-wins case)
 *   GET      /read-cookie   -> JSON of the Cookie header this server received
 *   GET      /download      -> a body of ?size bytes (deterministic pattern)
 *   GET      /json          -> a small JSON document
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

/** Lower-cased map of the request headers this process received. */
function received_headers(): array
{
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = $value;
        }
    }
    return $headers;
}

if ($uri === '/echo-headers') {
    header('Content-Type: application/json');
    echo json_encode(received_headers());
    exit;
}

if ($uri === '/redirect') {
    $to = $_GET['to'] ?? '';
    $code = (int)($_GET['code'] ?? 302);
    header('Location: ' . $to, true, $code);
    exit;
}

if ($uri === '/upload') {
    header('Content-Type: application/json');
    $field = $_GET['field'] ?? 'file';
    $file = $_FILES[$field] ?? null;
    $response = [
        'method' => $_SERVER['REQUEST_METHOD'],
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
        'fields' => $_POST,
        'has_file' => $file !== null,
    ];
    if ($file !== null) {
        $bytes = file_get_contents($file['tmp_name']);
        $response['file_name'] = $file['name'];
        $response['file_type'] = $file['type'];
        $response['file_size'] = $file['size'];
        $response['file_b64'] = base64_encode($bytes);
    }
    echo json_encode($response);
    exit;
}

if ($uri === '/set-cookie') {
    // Two values for the same name (last write wins) plus a second cookie.
    header('Set-Cookie: sessionid=first; Path=/');
    header('Set-Cookie: sessionid=xyz789; Path=/; HttpOnly', false);
    header('Set-Cookie: theme=dark; Path=/', false);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

if ($uri === '/read-cookie') {
    header('Content-Type: application/json');
    $headers = received_headers();
    echo json_encode(['cookie' => $headers['cookie'] ?? null]);
    exit;
}

if ($uri === '/download') {
    $size = (int)($_GET['size'] ?? (3 * 1024 * 1024));
    header('Content-Type: application/octet-stream');
    // Deterministic, verifiable body: repeating 256-byte pattern.
    $pattern = '';
    for ($i = 0; $i < 256; $i++) {
        $pattern .= chr($i);
    }
    $written = 0;
    while ($written < $size) {
        $chunk = substr($pattern, 0, min(strlen($pattern), $size - $written));
        echo $chunk;
        $written += strlen($chunk);
    }
    exit;
}

if ($uri === '/json') {
    header('Content-Type: application/json');
    echo json_encode(['hello' => 'world', 'method' => $_SERVER['REQUEST_METHOD']]);
    exit;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'not found', 'path' => $uri]);
