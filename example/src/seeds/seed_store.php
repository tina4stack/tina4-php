<?php

/**
 * Seed demo data — deterministic for reproducible demos.
 */
function seedStore($db): void
{
    // Skip if already seeded
    if ((new Category())->count() > 0) {
        return;
    }

    $fake = new \Tina4\FakeData(42);

    // Categories
    $categoriesData = [
        ["Electronics", "electronics", "Gadgets, devices, and tech accessories"],
        ["Clothing", "clothing", "Apparel for all seasons"],
        ["Books", "books", "Fiction, non-fiction, and technical reads"],
        ["Home & Garden", "home-garden", "Everything for your living space"],
        ["Sports", "sports", "Gear and equipment for active living"],
    ];
    foreach ($categoriesData as [$name, $slug, $desc]) {
        Category::create([
            "name" => $name,
            "slug" => $slug,
            "description" => $desc,
        ]);
    }

    // Products (50)
    $adjectives = ["Premium", "Classic", "Ultra", "Pro", "Essential", "Deluxe", "Smart", "Eco", "Compact", "Vintage"];
    $nouns = ["Widget", "Gadget", "Tool", "Kit", "Set", "Pack", "Bundle", "Device", "Gear", "Item"];

    for ($i = 0; $i < 50; $i++) {
        $catId = ($i % 5) + 1;
        $adj = $adjectives[$i % count($adjectives)];
        $noun = $nouns[(int) floor($i / count($adjectives)) % count($nouns)];
        Product::create([
            "category_id" => $catId,
            "name" => "{$adj} {$noun} " . ($i + 1),
            "slug" => "product-" . ($i + 1),
            "description" => $fake->paragraph(),
            "price" => round($fake->numeric(5, 200), 2),
            "stock" => $fake->integer(0, 100),
            "image_url" => "/uploads/placeholder-" . (($i % 5) + 1) . ".svg",
            "is_active" => 1,
        ]);
    }

    // Admin user
    Customer::create([
        "name" => "Admin",
        "email" => "admin@tina4store.com",
        "password_hash" => \Tina4\Auth::hashPassword("admin123"),
        "role" => "admin",
    ]);

    // Named demo customer
    Customer::create([
        "name" => "Alice Smith",
        "email" => "alice@example.com",
        "password_hash" => \Tina4\Auth::hashPassword("customer123"),
        "role" => "customer",
    ]);

    // Additional customers
    for ($i = 0; $i < 9; $i++) {
        Customer::create([
            "name" => $fake->name(),
            "email" => $fake->email(),
            "password_hash" => \Tina4\Auth::hashPassword("customer123"),
            "role" => "customer",
        ]);
    }

    // Sample orders for Alice (customer_id=2)
    $statuses = ["delivered", "shipped", "pending"];
    for ($i = 0; $i < 3; $i++) {
        $order = Order::create([
            "customer_id" => 2,
            "status" => $statuses[$i],
            "total" => 0,
            "created_at" => date('c'),
        ]);
        $total = 0;
        $itemCount = $fake->integer(1, 4);
        for ($j = 0; $j < $itemCount; $j++) {
            $product = (new Product())->findById($fake->integer(1, 50));
            if (!$product || !$product->id) continue;
            $qty = $fake->integer(1, 3);
            OrderItem::create([
                "order_id" => $order->id,
                "product_id" => $product->id,
                "quantity" => $qty,
                "unit_price" => $product->price,
            ]);
            $total += $product->price * $qty;
        }
        $order->total = round($total, 2);
        $order->save();
    }
}
