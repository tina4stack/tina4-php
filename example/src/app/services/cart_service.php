<?php

/**
 * Cart service — session-based cart with ORM product lookups.
 */

function getCartItems($session): array
{
    $cart = $session->get("cart") ?? [];
    if (empty($cart)) {
        return [];
    }
    $items = [];
    foreach ($cart as $productId => $quantity) {
        $pid = (int) $productId;
        $product = (new Product())->findById($pid);
        if ($product && $product->id) {
            $item = $product->toDict();
            $item["productId"] = $product->id;
            $item["quantity"] = $quantity;
            $item["subtotal"] = $product->price * $quantity;
            $items[] = $item;
        }
    }
    return $items;
}

function getCartTotal($session): float
{
    $items = getCartItems($session);
    $total = 0;
    foreach ($items as $item) {
        $total += $item["subtotal"];
    }
    return $total;
}

function cartCount($session): int
{
    $cart = $session->get("cart") ?? [];
    return array_sum($cart);
}
