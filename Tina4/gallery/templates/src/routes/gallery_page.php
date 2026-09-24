<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

/**
 * Gallery: Templates — render an HTML page with dynamic data via Twig.
 */

\Tina4\Router::get('/gallery/page', function (\Tina4\Request $request, \Tina4\Response $response) {
    return $response->render('gallery_page.twig', [
        'title' => 'Gallery Demo Page',
        'items' => [
            ['name' => 'Tina4 PHP', 'description' => 'Zero-dep web framework', 'badge' => 'v3.0.0'],
            ['name' => 'Twig Engine', 'description' => 'Built-in template rendering', 'badge' => 'included'],
            ['name' => 'Auto-Reload', 'description' => 'Templates refresh on save', 'badge' => 'dev mode'],
        ],
    ]);
});
