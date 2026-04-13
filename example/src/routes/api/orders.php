<?php

\Tina4\Router::get("/api/orders", function ($request, $response) {
    $customerId = $request->session->get("customer_id");
    $orders = (new Order())->where("customer_id = ?", [$customerId]);
    return $response(array_map(fn($o) => $o->toDict(), $orders));
})->secure();

\Tina4\Router::get("/api/orders/{id}", function ($request, $response) {
    $customerId = $request->session->get("customer_id");
    $orderId = (int) $request->params['id'];

    $orders = (new Order())->where("id = ? and customer_id = ?", [$orderId, $customerId]);
    if (empty($orders)) {
        return $response(["error" => "Order not found"], 404);
    }

    $db = \Tina4\App::getDatabase();
    $items = $db->fetch(
        "SELECT oi.*, p.name, p.image_url FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?",
        [$orderId], 100, 0
    );

    $orderData = $orders[0]->toDict();
    $orderData["items"] = $items ? $items->records : [];
    return $response($orderData);
})->secure();
