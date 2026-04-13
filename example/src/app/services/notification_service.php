<?php

/**
 * Event listeners for SSE dashboard and email notifications.
 * Demonstrates: Events::on(), SSE queue, Messenger (email).
 */

\Tina4\Events::on("order.placed", function ($data) {
    pushSalesEvent([
        "type" => "order_placed",
        "order_id" => $data["order_id"],
        "total" => $data["total"],
    ]);

    // Send order confirmation email via Messenger
    try {
        $html = \Tina4\Response::getFrond()->render("email/order_confirmation.twig", [
            "order_id" => $data["order_id"],
            "total" => $data["total"],
        ]);
        $messenger = new \Tina4\Messenger();
        $messenger->send(
            $data["email"] ?? "",
            "Order #{$data['order_id']} Confirmed",
            $html
        );
    } catch (\Throwable $e) {
        \Tina4\Debug::message("Email skipped (no SMTP configured): " . $e->getMessage());
    }
});

\Tina4\Events::on("order.processing", function ($data) {
    pushSalesEvent([
        "type" => "order_processing",
        "order_id" => $data["order_id"],
    ]);
});

\Tina4\Events::on("order.status_changed", function ($data) {
    pushSalesEvent([
        "type" => "status_changed",
        "order_id" => $data["order_id"],
        "status" => $data["status"],
    ]);

    if (($data["status"] ?? '') === "shipped") {
        try {
            $html = \Tina4\Response::getFrond()->render("email/shipping_notification.twig", [
                "order_id" => $data["order_id"],
            ]);
            $messenger = new \Tina4\Messenger();
            $messenger->send(
                $data["email"] ?? "",
                "Order #{$data['order_id']} Shipped!",
                $html
            );
        } catch (\Throwable $e) {
            \Tina4\Debug::message("Email skipped (no SMTP configured): " . $e->getMessage());
        }
    }
});

\Tina4\Events::on("stock.low", function ($data) {
    pushSalesEvent([
        "type" => "stock_low",
        "product_id" => $data["product_id"],
        "remaining" => $data["remaining"],
    ]);
});

\Tina4\Events::on("cart.item_added", function ($data) {
    pushSalesEvent([
        "type" => "cart_add",
        "product" => $data["product_name"],
        "price" => $data["price"],
        "quantity" => $data["quantity"],
        "customer" => $data["customer"],
    ]);
});

\Tina4\Events::on("customer.registered", function ($data) {
    try {
        $messenger = new \Tina4\Messenger();
        $messenger->send(
            $data["email"] ?? "",
            "Welcome to Tina4 Store!",
            "Hi {$data['name']}, welcome to our store!"
        );
    } catch (\Throwable $e) {
        \Tina4\Debug::message("Welcome email skipped (no SMTP configured): " . $e->getMessage());
    }
});
