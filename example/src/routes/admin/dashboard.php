<?php

\Tina4\Router::get("/admin", function ($request, $response) {
    $db = \Tina4\App::getDatabase();

    $totalOrders = (new Order())->count();

    $salesResult = $db->fetch(
        "SELECT coalesce(sum(total), 0) as total FROM orders WHERE status != ?",
        ["cancelled"], 1, 0
    );
    $totalSales = $salesResult && $salesResult->records ? $salesResult->records[0]["total"] : 0;

    $lowStock = (new Product())->where("stock <= 5 and is_active = 1");

    $recentResult = $db->fetch(
        "SELECT o.*, c.name as customer_name FROM orders o JOIN customers c ON o.customer_id = c.id ORDER BY o.created_at DESC",
        [], 10, 0
    );

    return $response(storeRender("admin/dashboard.twig", [
        "stats" => [
            "total_orders" => $totalOrders,
            "total_sales" => $totalSales,
            "low_stock_count" => count($lowStock),
        ],
        "low_stock" => array_map(fn($p) => $p->toDict(), $lowStock),
        "recent_orders" => $recentResult ? $recentResult->records : [],
    ], $request));
})->middleware(["adminAuth"]);
