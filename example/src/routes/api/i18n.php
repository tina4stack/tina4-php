<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


\Tina4\Router::get("/api/locale/{lang}", function ($request, $response) {
    $lang = $request->params['lang'] ?? 'en';
    $request->session->set("locale", $lang);
    $referer = $request->headers['referer'] ?? '/';
    return $response->redirect($referer);
})->noAuth();
