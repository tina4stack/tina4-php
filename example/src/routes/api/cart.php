<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


\Tina4\Router::get("/api/cart", function ($request, $response) {
    $items = getCartItems($request->session);
    $total = getCartTotal($request->session);
    return $response(["items" => $items, "total" => $total]);
})->noAuth();

\Tina4\Router::post("/api/cart", function ($request, $response) {
    $productId = (string) ($request->body['product_id'] ?? '');
    $quantity = (int) ($request->body['quantity'] ?? 1);
    $cart = $request->session->get("cart") ?? [];
    $cart[$productId] = ($cart[$productId] ?? 0) + $quantity;
    $request->session->set("cart", $cart);
    return $response(["message" => "Added to cart", "cart_count" => cartCount($request->session)]);
})->noAuth();

\Tina4\Router::get("/api/cart/count", function ($request, $response) {
    return $response(["count" => cartCount($request->session)]);
})->noAuth();
