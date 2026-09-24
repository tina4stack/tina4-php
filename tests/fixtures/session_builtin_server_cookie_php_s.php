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
 * Real-bug audit (3.13.99), context (b): `php -S`, the built-in PHP
 * development server — a REAL SAPI, unaffected by the Response::isRawSocket()
 * fix (App::__invoke()'s Response never sets it). Router::dispatch() is
 * called directly, exactly like tests/SessionCookieNameTest.php's fixture, so
 * this is the regression check: this path must be BYTE-IDENTICAL before and
 * after the fix.
 *
 * Usage: php -S 127.0.0.1:<port> session_builtin_server_cookie_php_s.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

\Tina4\Router::post('/login', function (\Tina4\Request $request, \Tina4\Response $response) {
    $request->session->set('token', 'abc');
    return $response(['ok' => true]);
})->noAuth();

\Tina4\Router::get('/whoami', function (\Tina4\Request $request, \Tina4\Response $response) {
    return $response(['token' => $request->session->get('token')]);
});

$result = \Tina4\Router::dispatch(new \Tina4\Request(), new \Tina4\Response());
echo $result->getBody();
