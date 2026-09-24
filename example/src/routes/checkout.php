<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


\Tina4\Router::get("/checkout", function ($request, $response) {
    // Redirect to login if not authenticated (session-based, matches Python)
    $customerId = $request->session->get("customer_id");
    if (!$customerId) {
        $request->session->flash("error", "Please login to checkout");
        return $response->redirect("/login");
    }

    $items = getCartItems($request->session);
    $total = getCartTotal($request->session);
    return $response(storeRender("storefront/checkout.twig", [
        "cart_items" => $items,
        "cart_total" => $total,
    ], $request));
})->noAuth();

\Tina4\Router::post("/checkout", function ($request, $response) {
    $cart = $request->session->get("cart") ?? [];
    if (empty($cart)) {
        $request->session->flash("error", "Cart is empty");
        return $response->redirect("/cart");
    }

    $customerId = $request->session->get("customer_id");
    if (!$customerId) {
        $request->session->flash("error", "Please login to checkout");
        return $response->redirect("/login");
    }

    $items = getCartItems($request->session);
    $total = getCartTotal($request->session);

    $order = Order::create([
        "customer_id" => $customerId,
        "total" => $total,
        "status" => "pending",
        "created_at" => date('c'),
    ]);

    foreach ($items as $item) {
        OrderItem::create([
            "order_id" => $order->id,
            "product_id" => $item["productId"],
            "quantity" => $item["quantity"],
            "unit_price" => $item["price"],
        ]);
    }

    // Push to queue (background task processes it)
    $queue = new \Tina4\Queue(topic: "orders");
    $queue->push(["order_id" => $order->id, "customer_id" => $customerId]);

    \Tina4\Events::emit("order.placed", [
        "order_id" => $order->id,
        "total" => $total,
        "customer_id" => $customerId,
    ]);

    $request->session->set("cart", []);
    $request->session->flash("success", "Order placed successfully!");
    return $response->redirect("/account");
})->noAuth();
