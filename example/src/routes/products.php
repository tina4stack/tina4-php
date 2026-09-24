<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


\Tina4\Router::get("/products", function ($request, $response) {
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

    $categories = (new Category())->all(100);
    $totalPages = (int) ceil($total / $perPage);

    return $response(storeRender("storefront/products.twig", [
        "products" => array_map(fn($p) => $p->toDict(), $products),
        "categories" => array_map(fn($c) => $c->toDict(), $categories),
        "current_category" => $category,
        "page" => $page,
        "total_pages" => $totalPages,
    ], $request));
});

\Tina4\Router::get("/products/{slug}", function ($request, $response) {
    $products = (new Product())->where("slug = ?", [$request->params['slug']], 1);
    if (empty($products)) {
        return $response(storeRender("storefront/home.twig", ["error" => "Product not found"], $request), 404);
    }
    $product = $products[0];
    $productData = $product->toDict(["category"]);
    return $response(storeRender("storefront/product_detail.twig", ["product" => $productData], $request));
});
