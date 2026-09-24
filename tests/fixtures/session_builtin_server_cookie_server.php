<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
 *
 * Real-bug audit (3.13.99), context (a): Tina4\Server, the framework's OWN
 * raw-socket engine (`tina4 serve` boots this). Boots a REAL App + Server on
 * the REAL socket event loop (App::run -> Server::start), same pattern as
 * tests/fixtures/dual_port_server.php, so
 * SessionBuiltinServerCookieTest can make REAL HTTP requests over a REAL
 * socket and prove a first-time session write emits Set-Cookie there.
 *
 * Usage:
 *   php session_builtin_server_cookie_server.php <port>
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$port = (int)($argv[1] ?? 0);
if ($port === 0) {
    fwrite(STDERR, "usage: session_builtin_server_cookie_server.php <port>\n");
    exit(2);
}

\Tina4\Router::post('/login', function (\Tina4\Request $request, \Tina4\Response $response) {
    $request->session->set('token', 'abc');
    return $response(['ok' => true]);
})->noAuth();

\Tina4\Router::get('/whoami', function (\Tina4\Request $request, \Tina4\Response $response) {
    return $response(['token' => $request->session->get('token')]);
});

$app = new \Tina4\App(basePath: sys_get_temp_dir() . '/tina4_bug2_server_' . $port);
$app->run('127.0.0.1', $port);
