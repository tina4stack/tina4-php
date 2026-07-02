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

    // pop() returns the job record as an array; the pushed payload lives under
    // the "payload" key (see Tina4\Job / QueueV3Test). Wrap it in a Job so we
    // can acknowledge the reservation via complete()/fail().
    $wrapped = new \Tina4\Job($job, $queue, $queue->getTopic());

    $db = \Tina4\App::getDatabase();
    $payload = $wrapped->payload;
    $orderId = is_array($payload) ? ($payload["order_id"] ?? null) : null;

    if (!$orderId) {
        $wrapped->complete();
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
        $wrapped->complete();
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
    $wrapped->complete();
}
