<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


\Tina4\Router::get("/admin/categories", function ($request, $response) {
    $categories = (new Category())->all(100, 0);
    return $response(storeRender("admin/categories.twig", [
        "categories" => array_map(fn($c) => $c->toDict(), $categories),
    ], $request));
})->middleware(["adminAuth"]);

\Tina4\Router::post("/admin/categories", function ($request, $response) {
    $name = $request->body['name'] ?? '';
    $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
    $slug = trim($slug, '-');

    Category::create([
        "name" => $name,
        "slug" => $slug,
    ]);
    $request->session->flash("success", "Category created");
    return $response->redirect("/admin/categories");
})->noAuth()->middleware(["adminAuth"]);
