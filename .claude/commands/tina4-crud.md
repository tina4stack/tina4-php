# Generate Tina4 CRUD

Create a complete CRUD implementation: migration, ORM model, API routes, template page, and tests.

## Instructions

1. Ask the user for the resource name and fields
2. Create ALL of the following:
   - Migration file
   - ORM model
   - REST API routes (list, get, create, update, delete)
   - Template page with listing
   - Tests

## Example: Products CRUD

### 1. Migration (`migrations/NNNNNN_create_products.sql`)

```sql
CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    price REAL DEFAULT 0,
    category TEXT,
    active INTEGER DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
```

### 2. ORM Model (`src/orm/Product.php`)

```php
<?php

class Product extends \Tina4\ORM
{
    public $tableName = "products";
    public $primaryKey = "id";

    public ?int $id = null;
    public string $name = "";
    public float $price = 0;
    public ?string $category = null;
    public int $active = 1;
    public ?string $createdAt = null;
}
```

### 3. API Routes (`src/routes/products.php`)

```php
<?php

use Tina4\Router;

Router::get("/api/products", function ($request, $response) {
    $page = (int)($request->params["page"] ?? 1);
    $limit = (int)($request->params["limit"] ?? 20);
    $skip = ($page - 1) * $limit;
    $search = $request->params["search"] ?? "";

    $product = new Product();
    if ($search) {
        $results = $product->select("*", $limit, $skip)
            ->filter("name LIKE ?", ["%{$search}%"])
            ->asArray();
    } else {
        $results = $product->select("*", $limit, $skip)->asArray();
    }

    return $response->json($results);
})->description("List products with pagination")->tags(["products"]);

Router::get("/api/products/{id}", function ($id, $request, $response) {
    $product = new Product();
    if ($product->load("id = ?", [$id])) {
        return $response->json($product->asArray());
    }
    return $response->json(["error" => "Not found"], 404);
})->description("Get a product")->tags(["products"]);

Router::post("/api/products", function ($request, $response) {
    $product = new Product($request->body);
    $product->save();
    return $response->json($product->asArray(), 201);
})->description("Create a product")->tags(["products"]);

Router::put("/api/products/{id}", function ($id, $request, $response) {
    $product = new Product();
    if (!$product->load("id = ?", [$id])) {
        return $response->json(["error" => "Not found"], 404);
    }
    foreach ($request->body as $key => $value) {
        if (property_exists($product, $key) && $key !== "id") {
            $product->{$key} = $value;
        }
    }
    $product->save();
    return $response->json($product->asArray());
})->description("Update a product")->tags(["products"]);

Router::delete("/api/products/{id}", function ($id, $request, $response) {
    $product = new Product();
    if (!$product->load("id = ?", [$id])) {
        return $response->json(["error" => "Not found"], 404);
    }
    $product->delete();
    return $response->json(["deleted" => true]);
})->description("Delete a product")->tags(["products"]);
```

### 4. Template (`src/templates/pages/products.twig`)

```twig
{% extends "base.twig" %}
{% block title %}Products{% endblock %}
{% block content %}
<div class="container mt-4">
    <h1>{{ title }}</h1>
    <table class="table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Price</th>
                <th>Category</th>
            </tr>
        </thead>
        <tbody>
        {% for product in products %}
            <tr>
                <td>{{ product.name }}</td>
                <td>{{ product.price }}</td>
                <td>{{ product.category }}</td>
            </tr>
        {% endfor %}
        </tbody>
    </table>
</div>
{% endblock %}
```

### 5. Tests (`tests/ProductTest.php`)

```php
<?php

use PHPUnit\Framework\TestCase;

class ProductTest extends TestCase
{
    public function testCreate(): void
    {
        $product = new Product(["name" => "Widget", "price" => 9.99, "category" => "Tools"]);
        $this->assertEquals("Widget", $product->name);
        $this->assertEquals(9.99, $product->price);
    }

    public function testAsArray(): void
    {
        $product = new Product(["name" => "Widget", "price" => 9.99]);
        $data = $product->asArray();
        $this->assertEquals("Widget", $data["name"]);
    }

    public function testDefaults(): void
    {
        $product = new Product();
        $this->assertEquals(1, $product->active);
    }
}
```

### 6. Run

```bash
composer tina4 migrate
composer tina4 test
```

## After Generation

- Run `composer tina4 migrate` to create the table
- Run `composer tina4 test` to verify tests pass
- Visit `/swagger` to see the API documentation
- Use `composer tina4 routes` to list all registered routes
