<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Boots a REAL Tina4 App on the REAL server (App::run -> Server::start) with
 * routes that send all 256 byte values (0x00-0xFF) as a binary body, with and
 * without an explicit content type. ResponseBinaryBodyTest spawns this as a
 * genuine subprocess and compares the raw bytes it receives over a socket.
 *
 * Usage: php response_binary_body_server.php <port>
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Tina4\Request;
use Tina4\Response;
use Tina4\Router;

$port = (int)($argv[1] ?? 0);
if ($port === 0) {
    fwrite(STDERR, "usage: response_binary_body_server.php <port>\n");
    exit(2);
}

$allBytes = implode('', array_map('chr', range(0, 255)));

Router::get('/bin/call', fn (Request $request, Response $response) => $response($allBytes, 200, 'image/png'));
Router::get('/bin/send', fn (Request $request, Response $response) => $response->send($allBytes, 200, 'application/octet-stream'));
Router::get('/bin/auto', fn (Request $request, Response $response) => $response($allBytes));
Router::get('/bin/text', fn (Request $request, Response $response) => $response("h\u{e9}llo", 200, 'text/plain; charset=utf-8'));
Router::get('/bin/json', fn (Request $request, Response $response) => $response(['a' => 1], 200, 'application/vnd.api+json'));

$app = new \Tina4\App();
$app->run('127.0.0.1', $port);
