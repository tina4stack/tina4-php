# Rich Scaffolding Plan — PHP

## Commands

| Command | Output |
|---------|--------|
| `generate model Product --fields "name:string,price:float"` | `src/orm/Product.php` + `migrations/TS_create_product.sql` |
| `generate route products --model Product` | `src/routes/products.php` with ORM CRUD |
| `generate crud Product --fields "name:string,price:float"` | model + migration + routes + template + test |
| `generate migration add_category` | `migrations/TS_add_category.sql` with UP/DOWN |
| `generate middleware AuthCheck` | `src/middleware/AuthCheck.php` with before/after |
| `generate test Product` | `tests/ProductTest.php` with PHPUnit |

## Field Type Mapping

| CLI | PHP Type | SQL |
|-----|----------|-----|
| string | string | VARCHAR(255) |
| int/integer | ?int | INTEGER |
| float/decimal | ?float | DECIMAL(10,2) |
| bool/boolean | bool | TINYINT(1) |
| text | string | TEXT |
| datetime | ?string | DATETIME |
| blob | ?string | BLOB |

## Table Convention
- Singular: `Product` → `product`
- Override: `$pluralTable = true` → `products`

## DX Fixes
- `--no-browser` flag on serve
- Kill existing process on port
- Migration UP/DOWN parsing in Migration.php

## Files to Modify
- `bin/tina4php` — generators, parseFlags(), serve enhancements
- `Tina4/Migration.php` — extractSection() for UP/DOWN

## Tests
- `tests/ScaffoldingTest.php` — 20 test cases
