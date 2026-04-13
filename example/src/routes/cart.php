<?php

\Tina4\Router::get("/cart", function ($request, $response) {
    $items = getCartItems($request->session);
    $total = getCartTotal($request->session);
    return $response(storeRender("storefront/cart.twig", [
        "cart_items" => $items,
        "cart_total" => $total,
    ], $request));
});

\Tina4\Router::post("/cart/add", function ($request, $response) {
    $productId = (string) ($request->body['product_id'] ?? '');
    $quantity = (int) ($request->body['quantity'] ?? 1);
    $cart = $request->session->get("cart") ?? [];
    $cart[$productId] = ($cart[$productId] ?? 0) + $quantity;
    $request->session->set("cart", $cart);

    $product = (new Product())->findById((int) $productId);
    $productName = $product && $product->id ? $product->name : "Unknown";

    if ($product && $product->id) {
        $customerName = $request->session->get("customer_name") ?? "Guest";
        \Tina4\Events::emit("cart.item_added", [
            "product_name" => $product->name,
            "price" => $product->price,
            "quantity" => $quantity,
            "customer" => $customerName,
        ]);
    }

    $accept = $request->headers['accept'] ?? '';
    $contentType = $request->headers['content-type'] ?? '';
    if (str_contains($accept, 'application/json') || str_contains($contentType, 'application/json')) {
        return $response([
            "ok" => true,
            "count" => cartCount($request->session),
            "total" => getCartTotal($request->session),
            "product_name" => $productName,
        ]);
    }

    $request->session->flash("success", "Item added to cart");
    return $response->redirect("/cart");
})->noAuth();

\Tina4\Router::post("/cart/update", function ($request, $response) {
    $productId = (string) ($request->body['product_id'] ?? '');
    $quantity = (int) ($request->body['quantity'] ?? 1);
    $cart = $request->session->get("cart") ?? [];
    if ($quantity <= 0) {
        unset($cart[$productId]);
    } else {
        $cart[$productId] = $quantity;
    }
    $request->session->set("cart", $cart);
    $contentType = $request->headers['content-type'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        return $response(["ok" => true, "count" => cartCount($request->session)]);
    }
    return $response->redirect("/cart");
})->noAuth();

\Tina4\Router::post("/cart/remove", function ($request, $response) {
    $productId = (string) ($request->body['product_id'] ?? '');
    $cart = $request->session->get("cart") ?? [];
    unset($cart[$productId]);
    $request->session->set("cart", $cart);
    $contentType = $request->headers['content-type'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        return $response(["ok" => true, "count" => cartCount($request->session)]);
    }
    $request->session->flash("success", "Item removed from cart");
    return $response->redirect("/cart");
})->noAuth();
