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
 * Regression fixture for tina4stack/tina4-php#253, context: Tina4\Server, the
 * framework's OWN raw-socket engine (`tina4 serve` boots this). A raw socket
 * engages no PHP SAPI header mechanism, so headers_sent() stays false and
 * session_regenerate_id() actually rotates the id mid-request - exactly the
 * scenario that broke. Boots a REAL App + Server on the REAL socket event
 * loop, same pattern as session_builtin_server_cookie_server.php.
 *
 * Uses PHP's NATIVE session ($_SESSION + session_regenerate_id), the bridge the
 * router wires under the CLI worker - NOT Tina4's own $request->session.
 *
 * Usage:
 *   php session_regenerate_native_server.php <port>
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$port = (int)($argv[1] ?? 0);
if ($port === 0) {
    fwrite(STDERR, "usage: session_regenerate_native_server.php <port>\n");
    exit(2);
}

// Mid-request id rotation - the standard session-fixation defence on login.
\Tina4\Router::post('/regen', function (\Tina4\Request $request, \Tina4\Response $response) {
    session_regenerate_id(true);
    $_SESSION['hit'] = ($_SESSION['hit'] ?? 0) + 1;
    return $response(['id' => session_id(), 'hit' => $_SESSION['hit']]);
})->noAuth();

// Reads the native session back on the FOLLOWING request.
\Tina4\Router::get('/whoami', function (\Tina4\Request $request, \Tina4\Response $response) {
    return $response(['id' => session_id(), 'hit' => $_SESSION['hit'] ?? null]);
})->noAuth();

$app = new \Tina4\App(basePath: sys_get_temp_dir() . '/tina4_regen253_' . $port);
$app->run('127.0.0.1', $port);
