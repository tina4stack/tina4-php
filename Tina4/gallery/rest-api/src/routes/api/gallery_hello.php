<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

/**
 * Gallery: REST API — simple JSON endpoints.
 */

\Tina4\Router::get('/api/gallery/hello', function (\Tina4\Request $request, \Tina4\Response $response) {
    return $response->json(['message' => 'Hello from Tina4!', 'method' => 'GET']);
});

\Tina4\Router::get('/api/gallery/hello/{name}', function (\Tina4\Request $request, \Tina4\Response $response) {
    $name = $request->params['name'] ?? 'World';
    return $response->json(['message' => "Hello {$name}!", 'method' => 'GET']);
});

\Tina4\Router::post('/api/gallery/hello', function (\Tina4\Request $request, \Tina4\Response $response) {
    $data = $request->body ?? [];
    return $response->json(['echo' => $data, 'method' => 'POST'], 201);
});
