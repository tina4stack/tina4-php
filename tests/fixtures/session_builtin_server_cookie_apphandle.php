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
 * Real-bug audit (3.13.99), context (c): App::handle()'s Apache/nginx/FPM-
 * style dispatch tail — reads $response->getHeaders() and calls header()
 * itself (App.php ~line 1645). Response::isRawSocket() is never set on this
 * path (App::__invoke() constructs a bare `new Response()`), so this proves
 * the fix does NOT introduce a duplicate Set-Cookie here: whichever branch
 * emitSessionCookie() took during Router::dispatch() (native setcookie(), or
 * attach-to-Response if headers were already sent), this tail must emit the
 * tina4_session cookie exactly ONCE.
 *
 * Usage: php -S 127.0.0.1:<port> session_builtin_server_cookie_apphandle.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

\Tina4\Router::post('/login', function (\Tina4\Request $request, \Tina4\Response $response) {
    $request->session->set('token', 'abc');
    return $response(['ok' => true]);
})->noAuth();

\Tina4\Router::get('/whoami', function (\Tina4\Request $request, \Tina4\Response $response) {
    return $response(['token' => $request->session->get('token')]);
});

$app = new \Tina4\App(basePath: sys_get_temp_dir() . '/tina4_bug2_apphandle_' . ($_SERVER['SERVER_PORT'] ?? 'x'));
$app->handle();
