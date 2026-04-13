<?php

/**
 * Order processing — consume from 'orders' queue topic and process each order.
 * Demonstrates: Queue consume, stock validation, Events.
 */

/**
 * Process a single order from the queue (non-blocking).
 * Designed for use with $app->background() — pops one job per tick.
 */
function processOrders(\Tina4\Queue $queue): void
{
    $job = $queue->pop();
    if ($job === null) {
        return;
    }

    $db = \Tina4\App::getDatabase();
    $data = is_array($job) ? $job : (method_exists($job, 'getData') ? $job->getData() : []);
    $orderId = $data["order_id"] ?? null;

    if (!$orderId) {
        if (is_object($job) && method_exists($job, 'complete')) {
            $job->complete();
        }
        return;
    }

    $items = $db->fetch(
        "SELECT oi.product_id, oi.quantity, p.stock FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?",
        [$orderId], 100, 0
    );

    $outOfStock = false;
    if ($items && $items->records) {
        foreach ($items->records as $item) {
            if ($item["stock"] < $item["quantity"]) {
                $outOfStock = true;
                break;
            }
        }
    }

    if ($outOfStock) {
        $db->execute("UPDATE orders SET status = 'failed' WHERE id = ?", [$orderId]);
        $db->commit();
        \Tina4\Events::emit("order.failed", ["order_id" => $orderId, "reason" => "insufficient stock"]);
        if (is_object($job) && method_exists($job, 'complete')) {
            $job->complete();
        }
        return;
    }

    if ($items && $items->records) {
        foreach ($items->records as $item) {
            $db->execute(
                "UPDATE products SET stock = stock - ? WHERE id = ?",
                [$item["quantity"], $item["product_id"]]
            );
            if ($item["stock"] - $item["quantity"] <= 5) {
                \Tina4\Events::emit("stock.low", [
                    "product_id" => $item["product_id"],
                    "remaining" => $item["stock"] - $item["quantity"],
                ]);
            }
        }
    }

    $db->execute("UPDATE orders SET status = 'processing' WHERE id = ?", [$orderId]);
    $db->commit();
    \Tina4\Events::emit("order.processing", ["order_id" => $orderId]);
    if (is_object($job) && method_exists($job, 'complete')) {
        $job->complete();
    }
}
