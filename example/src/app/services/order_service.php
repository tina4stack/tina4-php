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

    // pop() returns a Tina4\Job carrying its own lifecycle, so the manual
    // `new \Tina4\Job($job, $queue, $queue->getTopic())` re-wrap this used to
    // need is gone - that workaround existed only because pop() handed back the
    // backend's raw array.
    $db = \Tina4\App::getDatabase();
    $payload = $job->payload;
    $orderId = is_array($payload) ? ($payload["order_id"] ?? null) : null;

    if (!$orderId) {
        $job->complete();
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
        $job->complete();
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
    $job->complete();
}
