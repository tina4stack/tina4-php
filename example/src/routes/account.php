<?php

\Tina4\Router::get("/account", function ($request, $response) {
    $customerId = $request->session->get("customer_id");
    $orders = (new Order())->where("customer_id = ?", [$customerId]);
    return $response(storeRender("storefront/account.twig", [
        "customer_name" => $request->session->get("customer_name"),
        "orders" => array_map(fn($o) => $o->toDict(), $orders),
    ], $request));
})->secure();

\Tina4\Router::get("/account/orders/{id}", function ($request, $response) {
    $id = (int) $request->params['id'];
    $customerId = $request->session->get("customer_id");

    $orders = (new Order())->where("id = ? and customer_id = ?", [$id, $customerId]);
    if (empty($orders)) {
        return $response(storeRender("storefront/account.twig", ["error" => "Order not found"], $request), 404);
    }

    $db = \Tina4\App::getDatabase();
    $items = $db->fetch(
        "SELECT oi.*, p.name as product_name, p.image_url FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?",
        [$id], 10, 0
    );

    return $response(storeRender("storefront/order_detail.twig", [
        "order" => $orders[0]->toDict(),
        "items" => $items ? $items->records : [],
    ], $request));
})->secure();
