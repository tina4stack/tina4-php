# Create a Tina4 ORM Model

Create an ORM model with its corresponding migration. Always create both together.

## Instructions

1. Ask the user for the model name and fields (or infer from context)
2. Create the migration file in `migrations/`
3. Create the ORM model in `src/orm/`
4. Run the migration with `composer tina4 migrate`

## Step 1: Migration

Create `migrations/NNNNNN_create_<table>.sql`:
```sql
CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    description TEXT,
    price REAL DEFAULT 0,
    active INTEGER DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
```

## Step 2: ORM Model

Create `src/orm/Product.php`:
```php
<?php

class Product extends \Tina4\ORM
{
    public $tableName = "products";
    public $primaryKey = "id";

    public ?int $id = null;
    public string $name = "";
    public ?string $description = null;
    public float $price = 0;
    public int $active = 1;
    public ?string $createdAt = null;
}
```

## Step 3: Run Migration

```bash
composer tina4 migrate
```

## Field Types

| PHP Type | SQLite | PostgreSQL | MySQL |
|----------|--------|-----------|-------|
| `int` | INTEGER | INTEGER | INTEGER |
| `string` | TEXT | VARCHAR(200) | VARCHAR(200) |
| `float` | REAL | DOUBLE PRECISION | DOUBLE |
| `?string` | TEXT | TEXT | TEXT |

## ORM Usage

```php
<?php

// Create
$product = new Product(["name" => "Widget", "price" => 9.99]);
$product->save();

// Create from JSON string
$product = new Product(json_decode('{"name": "Widget", "price": 9.99}', true));
$product->save();

// Load
$product = new Product();
if ($product->load("id = ?", [1])) {
    echo $product->name;
}

// Query
$results = $product->select("*", 20, 0)
    ->filter("price > ?", [5.0])
    ->orderBy("name ASC")
    ->asArray();
foreach ($results as $row) {
    echo $row["name"];
}

// Update
$product->name = "Super Widget";
$product->save();

// Delete
$product->delete();

// To array/JSON
$product->asArray();
$product->asJson();
```

## Key Rules

- One model per file, filename matches class name
- Always create a migration alongside the model
- Never use `createTable()` in production — use migrations
- Table name is set via `$tableName` property
- Primary key is set via `$primaryKey` property
- Use camelCase for PHP properties (maps to snake_case columns automatically)
