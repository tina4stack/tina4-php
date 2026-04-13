<?php

\Tina4\Router::get("/admin/orders", function ($request, $response) {
    $status = $request->query['status'] ?? null;
    $db = \Tina4\App::getDatabase();

    if ($status) {
        $result = $db->fetch(
            "SELECT o.*, c.name as customer_name FROM orders o JOIN customers c ON o.customer_id = c.id WHERE o.status = ? ORDER BY o.created_at DESC",
            [$status], 100, 0
        );
    } else {
        $result = $db->fetch(
            "SELECT o.*, c.name as customer_name FROM orders o JOIN customers c ON o.customer_id = c.id ORDER BY o.created_at DESC",
            [], 100, 0
        );
    }

    return $response(storeRender("admin/orders.twig", [
        "orders" => $result ? $result->records : [],
        "current_status" => $status,
    ], $request));
})->middleware(["adminAuth"]);

\Tina4\Router::get("/admin/orders/{id}", function ($request, $response) {
    $id = (int) $request->params['id'];
    $db = \Tina4\App::getDatabase();

    $orderResult = $db->fetch(
        "SELECT o.*, c.name as customer_name, c.email as customer_email FROM orders o JOIN customers c ON o.customer_id = c.id WHERE o.id = ?",
        [$id], 1, 0
    );

    if (!$orderResult || empty($orderResult->records)) {
        return $response(storeRender("admin/orders.twig", ["error" => "Order not found"], $request), 404);
    }

    $items = $db->fetch(
        "SELECT oi.*, p.name, p.image_url FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?",
        [$id], 100, 0
    );

    return $response(storeRender("admin/order_detail.twig", [
        "order" => $orderResult->records[0],
        "items" => $items ? $items->records : [],
    ], $request));
})->middleware(["adminAuth"]);

\Tina4\Router::post("/admin/orders/{id}/status", function ($request, $response) {
    $id = (int) $request->params['id'];
    $newStatus = $request->body['status'] ?? '';

    $order = (new Order())->findById($id);
    if ($order && $order->id) {
        $order->status = $newStatus;
        $order->save();
    }

    \Tina4\Events::emit("order.status_changed", ["order_id" => $id, "status" => $newStatus]);

    $request->session->flash("success", "Order status updated to {$newStatus}");
    return $response->redirect("/admin/orders/{$id}");
})->noAuth()->middleware(["adminAuth"]);
