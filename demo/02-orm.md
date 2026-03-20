# ORM

The `ORM` class implements the active record pattern for database models. Each model maps to a database table and provides methods for CRUD operations, filtering, pagination, and relationship declarations. Models use magic getters/setters to access column data dynamically.

ORM is abstract — you extend it to create concrete models for each table. The database adapter is injected via constructor or `setDb()`.

## Defining a Model

```php
use Tina4\ORM;
use Tina4\Database\DatabaseAdapter;

class User extends ORM
{
    public string $tableName = 'users';
    public string $primaryKey = 'id';
    public bool $softDelete = false;

    // Map PHP property names to database column names
    public array $fieldMapping = [
        'firstName' => 'first_name',
        'lastName'  => 'last_name',
        'createdAt' => 'created_at',
    ];

    // Relationships
    public array $hasOne = [
        'profile' => 'UserProfile.user_id',
    ];

    public array $hasMany = [
        'posts' => 'Post.author_id',
    ];
}
```

## Creating Records

```php
$db = new \Tina4\Database\SQLite3Adapter('app.db');

// Method 1: Set properties then save
$user = new User($db);
$user->firstName = 'Alice';
$user->lastName = 'Johnson';
$user->email = 'alice@example.com';
$user->save();

echo $user->getPrimaryKeyValue(); // auto-generated ID

// Method 2: Pass data via constructor
$user = new User($db, [
    'first_name' => 'Bob',
    'last_name'  => 'Smith',
    'email'      => 'bob@example.com',
]);
$user->save();
```

## Loading Records

```php
$user = new User($db);
$user->load(42);

if ($user->exists()) {
    echo $user->firstName; // Uses field mapping automatically
    echo $user->email;
}
```

## Updating Records

```php
$user = new User($db);
$user->load(42);
$user->firstName = 'Updated Name';
$user->save(); // Detects existing record, runs UPDATE
```

## Deleting Records

```php
$user = new User($db);
$user->load(42);
$user->delete(); // Hard delete by default
```

## Soft Delete

When `softDelete` is enabled, `delete()` sets `is_deleted = 1` instead of removing the row. All queries automatically filter out soft-deleted records.

```php
class Article extends ORM
{
    public string $tableName = 'articles';
    public string $primaryKey = 'id';
    public bool $softDelete = true;
}

$article = new Article($db);
$article->load(10);
$article->delete(); // Sets is_deleted = 1

// Soft-deleted records are excluded from load() and find() automatically
```

**Important:** Your table must include an `is_deleted` column (INTEGER, default 0) for soft delete to work.

## Finding Records

The `find()` method accepts filter criteria, pagination, and sorting.

```php
$user = new User($db);

// Find by exact match
$activeUsers = $user->find(['status' => 'active']);

// With pagination
$page2 = $user->find(
    filter: ['status' => 'active'],
    limit: 25,
    offset: 25,
);

// With sorting
$sorted = $user->find(
    filter: ['role' => 'admin'],
    limit: 100,
    offset: 0,
    orderBy: 'last_name ASC, first_name ASC',
);
```

## Getting All Records

```php
$user = new User($db);
$result = $user->all(limit: 50, offset: 0);

// $result structure:
// [
//   'data'   => [User, User, ...],   // Array of model instances
//   'total'  => 150,                  // Total count
//   'limit'  => 50,
//   'offset' => 0,
// ]

foreach ($result['data'] as $u) {
    echo $u->firstName . ' ' . $u->lastName . "\n";
}
```

## Serialization

```php
$user = new User($db);
$user->load(1);

// To associative array
$array = $user->toArray();
// ['id' => 1, 'firstName' => 'Alice', 'lastName' => 'Johnson', ...]

// To JSON string
$json = $user->toJson();
// Pretty-printed JSON
```

## Field Mapping

Field mapping translates between PHP property names (camelCase) and database column names (snake_case). This keeps your PHP code idiomatic while preserving database naming conventions.

```php
class Product extends ORM
{
    public string $tableName = 'products';
    public string $primaryKey = 'id';
    public array $fieldMapping = [
        'productName' => 'product_name',
        'unitPrice'   => 'unit_price',
        'stockQty'    => 'stock_qty',
    ];
}

$product = new Product($db);
$product->productName = 'Widget';  // Maps to product_name column
$product->unitPrice = 9.99;        // Maps to unit_price column
$product->save();
```

## Fill from Array

Bulk-assign data from an associative array (uses reverse field mapping).

```php
$user = new User($db);
$user->fill([
    'first_name' => 'Charlie',
    'last_name'  => 'Brown',
    'email'      => 'charlie@example.com',
]);
$user->save();
```

## Setting the Database Adapter

```php
// Via constructor
$user = new User($db);

// Via setter (useful for dependency injection)
$user = new User();
$user->setDb($db);

// Get the adapter back
$adapter = $user->getDb();
```

## Relationships (hasOne / hasMany)

Relationship declarations define connections between models. The format is `'propertyName' => 'ForeignModel.foreign_key'`.

```php
class Order extends ORM
{
    public string $tableName = 'orders';
    public string $primaryKey = 'id';

    public array $hasOne = [
        'customer' => 'Customer.customer_id',
    ];

    public array $hasMany = [
        'items' => 'OrderItem.order_id',
    ];
}
```

## Tips

- Always set `$tableName` — the ORM throws a `RuntimeException` if no database adapter is set.
- Use `$softDelete = true` for audit trails — add an `is_deleted INTEGER DEFAULT 0` column to your table.
- Call `exists()` after `load()` to check if the record was found before accessing properties.
- The `save()` method is an upsert: it checks for an existing primary key and runs INSERT or UPDATE accordingly.
- Combine field mapping with the Seeder class for generating realistic test data.
