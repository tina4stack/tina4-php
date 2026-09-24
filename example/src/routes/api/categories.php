<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


\Tina4\Router::get("/api/categories", function ($request, $response) {
    $categories = (new Category())->all(100, 0);
    return $response(array_map(fn($c) => $c->toDict(), $categories));
})->noAuth();
