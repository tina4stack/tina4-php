<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


\Tina4\Router::get("/api/products", function ($request, $response) {
    $page = (int) ($request->query['page'] ?? 1);
    $category = $request->query['category'] ?? null;
    $perPage = 12;
    $offset = ($page - 1) * $perPage;

    if ($category) {
        $products = (new Product())->where(
            "category_id = (select id from categories where slug = ?) and is_active = 1",
            [$category], $perPage, $offset
        );
        $total = (new Product())->count(
            "category_id = (select id from categories where slug = ?) and is_active = 1",
            [$category]
        );
    } else {
        $products = (new Product())->where("is_active = 1", [], $perPage, $offset);
        $total = (new Product())->count("is_active = 1");
    }

    return $response([
        "products" => array_map(fn($p) => $p->toDict(), $products),
        "page" => $page,
        "total_pages" => (int) ceil($total / $perPage),
    ]);
})->noAuth();

\Tina4\Router::get("/api/products/{id}", function ($request, $response) {
    $id = (int) $request->params['id'];
    $product = (new Product())->findById($id);
    if (!$product || !$product->id) {
        return $response(["error" => "Product not found"], 404);
    }
    return $response($product->toDict());
})->noAuth();
