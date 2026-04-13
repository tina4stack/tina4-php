<?php

$CATEGORY_STYLES = [
    ["icon" => "📚", "gradient" => "linear-gradient(135deg, #667eea, #764ba2)"],
    ["icon" => "👕", "gradient" => "linear-gradient(135deg, #f093fb, #f5576c)"],
    ["icon" => "📱", "gradient" => "linear-gradient(135deg, #4facfe, #00f2fe)"],
    ["icon" => "🌿", "gradient" => "linear-gradient(135deg, #43e97b, #38f9d7)"],
    ["icon" => "🏋️", "gradient" => "linear-gradient(135deg, #fa709a, #fee140)"],
    ["icon" => "🎮", "gradient" => "linear-gradient(135deg, #a18cd1, #fbc2eb)"],
    ["icon" => "🍳", "gradient" => "linear-gradient(135deg, #fccb90, #d57eeb)"],
    ["icon" => "🎨", "gradient" => "linear-gradient(135deg, #e0c3fc, #8ec5fc)"],
    ["icon" => "🎵", "gradient" => "linear-gradient(135deg, #f5576c, #ff6a88)"],
    ["icon" => "🛋️", "gradient" => "linear-gradient(135deg, #667eea, #00f2fe)"],
];

\Tina4\Router::get("/", function ($request, $response) use ($CATEGORY_STYLES) {
    $products = (new Product())->where("is_active = 1", [], 8);
    $categories = (new Category())->all(100);
    $catList = [];
    foreach ($categories as $i => $c) {
        $d = $c->toDict();
        $style = $CATEGORY_STYLES[$i % count($CATEGORY_STYLES)];
        $d["icon"] = $style["icon"];
        $d["gradient"] = $style["gradient"];
        $catList[] = $d;
    }
    return $response(storeRender("storefront/home.twig", [
        "featured_products" => array_map(fn($p) => $p->toDict(), $products),
        "categories" => $catList,
    ], $request));
});
