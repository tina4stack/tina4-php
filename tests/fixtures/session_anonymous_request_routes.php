<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * Routes for SessionAnonymousRequestTest: one that never touches either
 * session, ones that only read, and ones that write. Shared by the php -S and
 * the Tina4\Server fixtures.
 */

\Tina4\Router::get('/plain', fn ($request, $response) => $response('plain'))->noAuth();
\Tina4\Router::get('/read', fn ($request, $response) => $response('user=' . ($request->session->get('user') ?? '-')))->noAuth();
\Tina4\Router::get('/write', function ($request, $response) {
    $request->session->set('user', 'alice');
    return $response('wrote');
})->noAuth();
\Tina4\Router::get('/native-read', fn ($request, $response) => $response('native=' . ($_SESSION['n'] ?? '-')))->noAuth();
\Tina4\Router::get('/native-write', function ($request, $response) {
    $_SESSION['n'] = 'set';
    return $response('native wrote');
})->noAuth();
\Tina4\Router::get('/native-empty', function ($request, $response) {
    $_SESSION = [];
    return $response('native emptied');
})->noAuth();
\Tina4\Router::get('/form', fn ($request, $response) => $response((new \Tina4\Frond())->renderString('{{ form_token_value() }}')))->noAuth();

// The ways a handler can end the request other than returning to the router.
// Under a real SAPI each must still deliver the cookie for a $_SESSION write.
\Tina4\Router::get('/native-write-close', function ($request, $response) {
    $_SESSION['n'] = 'set';
    session_write_close();
    return $response('closed early');
})->noAuth();
\Tina4\Router::get('/native-write-exit', function ($request, $response) {
    $_SESSION['n'] = 'set';
    header('Location: /plain');
    http_response_code(302);
    exit;
})->noAuth();
\Tina4\Router::get('/native-write-early-output', function ($request, $response) {
    $_SESSION['n'] = 'set';
    echo 'early';
    flush();
    return $response('late');
})->noAuth();
\Tina4\Router::get('/exit-without-session-use', function ($request, $response) {
    echo 'bye';
    exit;
})->noAuth();
\Tina4\Router::get('/native-write-after-nested-dispatch', function ($request, $response) {
    (new \Tina4\TestClient())->get('/plain');
    $_SESSION['n'] = 'set';
    return $response('nested ok');
})->noAuth();
\Tina4\Router::get('/native-write-regenerate', function ($request, $response) {
    $_SESSION['n'] = 'set';
    session_regenerate_id(true);
    return $response(session_id());
})->noAuth();

// Calls that mark a session changed without leaving anything in it. A record
// with no data is not a session, so none of these may store one.
\Tina4\Router::get('/delete-missing', function ($request, $response) {
    $request->session->delete('never-set');
    return $response('deleted nothing');
})->noAuth();
\Tina4\Router::get('/clear', function ($request, $response) {
    $request->session->clear();
    return $response('cleared');
})->noAuth();
\Tina4\Router::get('/regenerate-empty', function ($request, $response) {
    $request->session->regenerate();
    return $response('regenerated');
})->noAuth();
\Tina4\Router::get('/read-flash', fn ($request, $response) => $response('flash=' . ($request->session->getFlash('error') ?? '-')))->noAuth();
