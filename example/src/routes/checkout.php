<?php

\Tina4\Router::get("/checkout", function ($request, $response) {
    $items = getCartItems($request->session);
    $total = getCartTotal($request->session);
    return $response(storeRender("storefront/checkout.twig", [
        "cart_items" => $items,
        "cart_total" => $total,
    ], $request));
})->secure();

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
    $queue = new \Tina4\Queue("orders");
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
