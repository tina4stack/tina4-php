# Auto-CRUD

The `AutoCrud` class automatically generates REST API endpoints from ORM model definitions. Register your models, call `generateRoutes()`, and you get full CRUD with pagination, filtering, and sorting — zero boilerplate. Generated endpoints follow REST conventions and are registered on the `Router`.

## How It Works

For each registered model, AutoCrud creates five endpoints:

| Method | Path | Action |
|--------|------|--------|
| GET | `/api/{table}` | List with pagination, filtering, sorting |
| GET | `/api/{table}/{id}` | Get single record |
| POST | `/api/{table}` | Create record |
| PUT | `/api/{table}/{id}` | Update record |
| DELETE | `/api/{table}/{id}` | Delete record (or soft delete) |

## Setup

```php
use Tina4\AutoCrud;
use Tina4\Database\SQLite3Adapter;

$db = new SQLite3Adapter('app.db');

$crud = new AutoCrud($db, prefix: '/api');
```

## Registering Models

### Manual Registration

```php
class Product extends \Tina4\ORM
{
    public string $tableName = 'products';
    public string $primaryKey = 'id';
    public bool $softDelete = false;
}

class Order extends \Tina4\ORM
{
    public string $tableName = 'orders';
    public string $primaryKey = 'id';
    public bool $softDelete = true;
}

$crud->register(Product::class);
$crud->register(Order::class);
```

### Auto-Discovery

Scan a directory to discover all ORM subclasses automatically.

```php
$discovered = $crud->discover('src/orm');
// Returns: ['Product', 'Order', 'Customer', ...]

echo count($discovered) . " models discovered\n";
```

## Generating Routes

```php
$routes = $crud->generateRoutes();

// Returns the list of generated routes:
// [
//   ['method' => 'GET',    'path' => '/api/products',      'table' => 'products'],
//   ['method' => 'GET',    'path' => '/api/products/{id}',  'table' => 'products'],
//   ['method' => 'POST',   'path' => '/api/products',      'table' => 'products'],
//   ['method' => 'PUT',    'path' => '/api/products/{id}',  'table' => 'products'],
//   ['method' => 'DELETE', 'path' => '/api/products/{id}',  'table' => 'products'],
//   ['method' => 'GET',    'path' => '/api/orders',         'table' => 'orders'],
//   ...
// ]
```

## Using the Generated API

### List Records

```
GET /api/products?limit=25&offset=0
```

Response:
```json
{
    "data": [
        {"id": 1, "name": "Widget", "price": 9.99},
        {"id": 2, "name": "Gadget", "price": 19.99}
    ],
    "total": 42,
    "limit": 25,
    "offset": 0
}
```

### Filter Records

```
GET /api/products?filter[category]=electronics&filter[status]=active
```

### Sort Records

Use `-` prefix for descending order:

```
GET /api/products?sort=-price,name
```

This produces `ORDER BY price DESC, name ASC`.

### Get Single Record

```
GET /api/products/42
```

Response:
```json
{"id": 42, "name": "Widget", "price": 9.99, "category": "electronics"}
```

Returns 404 if not found.

### Create Record

```
POST /api/products
Content-Type: application/json

{"name": "New Widget", "price": 14.99, "category": "electronics"}
```

Response (201 Created):
```json
{"id": 43, "name": "New Widget", "price": 14.99, "category": "electronics"}
```

### Update Record

```
PUT /api/products/43
Content-Type: application/json

{"name": "Updated Widget", "price": 12.99}
```

Response:
```json
{"id": 43, "name": "Updated Widget", "price": 12.99, "category": "electronics"}
```

Returns 404 if not found.

### Delete Record

```
DELETE /api/products/43
```

Response:
```json
{"message": "Deleted"}
```

If the model has `softDelete = true`, the record is marked as deleted rather than removed.

## Custom API Prefix

```php
// Default prefix: /api
$crud = new AutoCrud($db, prefix: '/api');

// Custom prefix
$crud = new AutoCrud($db, prefix: '/api/v2');
// Generates: /api/v2/products, /api/v2/orders, etc.

// No prefix
$crud = new AutoCrud($db, prefix: '');
// Generates: /products, /orders, etc.
```

## Complete Example

```php
<?php
require_once 'vendor/autoload.php';

use Tina4\Database\SQLite3Adapter;
use Tina4\AutoCrud;
use Tina4\Migration;

// Setup database
$db = new SQLite3Adapter('app.db');

// Run migrations
$migration = new Migration($db, 'src/migrations');
$migration->migrate();

// Auto-CRUD
$crud = new AutoCrud($db, prefix: '/api/v1');
$crud->discover('src/orm');
$routes = $crud->generateRoutes();

echo "Generated " . count($routes) . " CRUD routes\n";
foreach ($routes as $route) {
    echo "  {$route['method']} {$route['path']}\n";
}
```

## Inspecting Registered Models

```php
$models = $crud->getModels();
// ['products' => 'Product', 'orders' => 'Order']
```

## Tips

- AutoCrud creates standard REST endpoints — add custom routes for business-specific logic.
- Models with `softDelete = true` use soft delete on the DELETE endpoint.
- The discover method scans for classes extending `ORM` in the given directory, checking common namespaces (`ClassName`, `\ClassName`, `App\ClassName`).
- Combine AutoCrud with middleware for authentication on generated routes — register the routes first, then apply group middleware.
- Use the `filter` query parameter as an associative array: `?filter[key]=value`.
- Sorting with `-` prefix maps to DESC, no prefix maps to ASC.
