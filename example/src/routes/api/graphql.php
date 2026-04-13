<?php

// ── GraphQL Endpoint ──────────────────────────────────────────
// Schema setup is lazy (inside the handler) because route files
// are autoloaded before the database is initialised in index.php.

$graphqlInstance = null;

function getGraphQL(): \Tina4\GraphQL
{
    global $graphqlInstance;
    if ($graphqlInstance !== null) {
        return $graphqlInstance;
    }

    $graphqlInstance = new \Tina4\GraphQL();

    // Auto-generate types and CRUD queries/mutations from ORM models
    $graphqlInstance->fromOrm(new Product());
    $graphqlInstance->fromOrm(new Category());

    // Custom queries — resolver signature: ($root, $args, $context)
    $graphqlInstance->addQuery(
        "products_by_price",
        ["min_price" => "Float", "max_price" => "Float"],
        "[Product]",
        function ($root, $args, $context) {
            $minPrice = $args['min_price'] ?? 0;
            $maxPrice = $args['max_price'] ?? 99999;
            $products = (new Product())->where(
                "price between ? and ? and is_active = 1",
                [$minPrice, $maxPrice], 50
            );
            return array_map(fn($p) => $p->toDict(), $products);
        }
    );

    $graphqlInstance->addQuery(
        "search_products",
        ["term" => "String", "limit" => "Int"],
        "[Product]",
        function ($root, $args, $context) {
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
        }
    );

    return $graphqlInstance;
}

\Tina4\Router::post("/api/graphql", function ($request, $response) {
    $query = $request->body['query'] ?? '';
    $variables = $request->body['variables'] ?? [];

    $result = getGraphQL()->execute($query, $variables);
    return $response($result);
})->noAuth();
