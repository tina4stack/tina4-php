<?php

\Tina4\Router::post("/api/graphql", function ($request, $response) {
    $query = $request->body['query'] ?? '';
    $variables = $request->body['variables'] ?? [];

    $graphql = new \Tina4\GraphQL();

    // Register custom resolvers
    $graphql->addResolver("products_by_price", function ($args) {
        $minPrice = $args['min_price'] ?? 0;
        $maxPrice = $args['max_price'] ?? 99999;
        $products = (new Product())->where(
            "price between ? and ? and is_active = 1",
            [$minPrice, $maxPrice], 50
        );
        return array_map(fn($p) => $p->toDict(), $products);
    });

    $graphql->addResolver("search_products", function ($args) {
        $term = $args['term'] ?? '';
        $limit = $args['limit'] ?? 10;
        if (!$term || strlen($term) < 2) {
            return [];
        }
        $like = "%{$term}%";
        $products = (new Product())->where(
            "(name like ? or description like ?) and is_active = 1",
            [$like, $like], $limit
        );
        return array_map(fn($p) => $p->toDict(), $products);
    });

    $result = $graphql->execute($query, $variables);
    return $response($result);
})->noAuth();
