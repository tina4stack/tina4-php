# Create a Tina4 Route

Create a new route file in `src/routes/`. Follow these rules exactly.

## Instructions

1. Create `src/routes/$ARGUMENTS.php` (or ask the user for the resource name)
2. Use `Tina4\Router` for route registration
3. Follow auth defaults: GET=public, POST/PUT/PATCH/DELETE=secured
4. Use `->noAuth()` to make a write route public, `->secured()` to protect a GET
5. Path parameters: `{id}`, `{slug}`, `{path}`
6. Add Swagger methods if the route is an API endpoint

## Template

```php
<?php

use Tina4\Router;

Router::get("/api/items", function ($request, $response) {
    // Query params: $request->params["page"] ?? "1"
    return $response->json(["items" => []]);
})->description("List all items")->tags(["items"]);

Router::get("/api/items/{id}", function ($id, $request, $response) {
    return $response->json(["id" => $id]);
})->description("Get a single item")->tags(["items"]);

Router::post("/api/items", function ($request, $response) {
    $data = $request->body;
    return $response->json(["created" => true], 201);
})->description("Create an item")
  ->tags(["items"])
  ->example(["name" => "Widget", "price" => 9.99])
  ->exampleResponse(["id" => 1, "name" => "Widget"]);

Router::put("/api/items/{id}", function ($id, $request, $response) {
    $data = $request->body;
    return $response->json(["updated" => true]);
})->description("Update an item")->tags(["items"]);

Router::delete("/api/items/{id}", function ($id, $request, $response) {
    return $response->json(["deleted" => true]);
})->description("Delete an item")->tags(["items"]);
```

## Method Chaining Order

```
Router::get("/path", function (...) { ... })
    ->noAuth() or ->secured()      // auth override
    ->description("...")            // Swagger docs
    ->tags(["..."])
    ->example([...])
```

## Key Rules

- One resource per file (e.g., `users.php`, `products.php`)
- Routes auto-discovered from `src/routes/` — no manual registration
- `$request->body` is auto-parsed (array for JSON, array for form data)
- `$request->params` for query string parameters
- `$request->headers` for HTTP headers (lowercase keys)
- `$request->files` for uploaded files
- Always return `$response->json($data)` or `$response->json($data, $statusCode)`
- Use `$response->render("template.twig", $data)` for HTML pages
