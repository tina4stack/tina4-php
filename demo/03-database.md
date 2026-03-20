# Database

Tina4 v3 provides a `DatabaseAdapter` interface and a built-in `SQLite3Adapter` implementation. The adapter abstraction allows additional drivers (MySQL, PostgreSQL, MSSQL, Firebird) to be added by implementing the same interface. The `DatabaseUrl` class parses connection strings in standard URL format.

Auto-commit is disabled by default and controlled by the `TINA4_AUTO_COMMIT` environment variable, keeping you safe from accidental writes.

## DatabaseAdapter Interface

Every database driver implements this contract:

```php
namespace Tina4\Database;

interface DatabaseAdapter
{
    public function open(): void;
    public function close(): void;
    public function query(string $sql, array $params = []): array;
    public function fetch(string $sql, int $limit = 10, int $offset = 0, array $params = []): array;
    public function exec(string $sql, array $params = []): bool;
    public function tableExists(string $table): bool;
    public function getColumns(string $table): array;
    public function lastInsertId(): int|string;
    public function begin(): void;
    public function commit(): void;
    public function rollback(): void;
    public function error(): ?string;
}
```

## SQLite3 Adapter

The built-in adapter uses PHP's native `SQLite3` class. WAL mode and foreign keys are enabled by default.

```php
use Tina4\Database\SQLite3Adapter;

// File-based database
$db = new SQLite3Adapter('app.db');

// In-memory database (great for testing)
$db = new SQLite3Adapter(':memory:');

// With explicit auto-commit control
$db = new SQLite3Adapter('app.db', autoCommit: true);
```

## Running Queries

```php
// Simple query — returns array of associative arrays
$rows = $db->query("SELECT * FROM users WHERE status = :status", [':status' => 'active']);

foreach ($rows as $row) {
    echo $row['first_name'] . ' ' . $row['last_name'] . "\n";
}
```

## Fetching with Pagination

The `fetch()` method returns paginated results plus a total count.

```php
$result = $db->fetch(
    sql: "SELECT * FROM products WHERE category = :cat",
    limit: 25,
    offset: 0,
    params: [':cat' => 'electronics'],
);

// $result structure:
// [
//   'data'   => [ ['id' => 1, 'name' => 'Widget', ...], ... ],
//   'total'  => 142,
//   'limit'  => 25,
//   'offset' => 0,
// ]

echo "Showing " . count($result['data']) . " of {$result['total']} products\n";
```

## Executing Statements

`exec()` is for INSERT, UPDATE, DELETE, and DDL statements. Returns `true` on success.

```php
// Create table
$db->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        first_name VARCHAR(100) NOT NULL,
        last_name VARCHAR(100) NOT NULL,
        email VARCHAR(255) UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

// Insert with parameters
$db->exec(
    "INSERT INTO users (first_name, last_name, email) VALUES (:first, :last, :email)",
    [':first' => 'Alice', ':last' => 'Johnson', ':email' => 'alice@example.com']
);

echo $db->lastInsertId(); // Auto-generated ID

// Update
$db->exec(
    "UPDATE users SET email = :email WHERE id = :id",
    [':email' => 'newalice@example.com', ':id' => 1]
);

// Delete
$db->exec("DELETE FROM users WHERE id = :id", [':id' => 1]);
```

## Transactions

```php
$db->begin();

try {
    $db->exec("INSERT INTO accounts (name, balance) VALUES (:name, :bal)", [':name' => 'Checking', ':bal' => 1000]);
    $db->exec("INSERT INTO accounts (name, balance) VALUES (:name, :bal)", [':name' => 'Savings', ':bal' => 5000]);
    $db->commit();
} catch (\Throwable $e) {
    $db->rollback();
    echo "Transaction failed: " . $e->getMessage();
}
```

## Table Introspection

```php
// Check existence
if ($db->tableExists('users')) {
    echo "Users table exists\n";
}

// Get column metadata
$columns = $db->getColumns('users');
// Returns: [
//   ['name' => 'id', 'type' => 'INTEGER', 'nullable' => false, 'default' => null, 'primary' => true],
//   ['name' => 'first_name', 'type' => 'VARCHAR(100)', 'nullable' => false, 'default' => null, 'primary' => false],
//   ...
// ]
```

## Error Handling

```php
$success = $db->exec("INVALID SQL STATEMENT");

if (!$success) {
    echo "Error: " . $db->error();
}
```

## DatabaseUrl — Connection String Parsing

The `DatabaseUrl` class parses standard database connection URLs and maps them to Tina4 driver classes.

```php
use Tina4\DatabaseUrl;

// Parse a connection URL
$url = new DatabaseUrl('pgsql://user:pass@localhost:5432/myapp');
echo $url->driver;   // "DataPostgresql"
echo $url->host;     // "localhost"
echo $url->port;     // 5432
echo $url->database; // "myapp"
echo $url->username; // "user"
echo $url->getDsn(); // "localhost:5432/myapp"

// SQLite variants
$url = new DatabaseUrl('sqlite:///data/app.db');
$url = new DatabaseUrl('sqlite::memory:');

// From environment variable
// .env: DATABASE_URL=mysql://root:secret@db:3306/tina4app
$url = DatabaseUrl::fromEnv('DATABASE_URL');

// Supported schemes:
// sqlite, sqlite3     -> DataSQLite3
// pgsql, postgres     -> DataPostgresql
// mysql, mariadb      -> DataMySQL
// mssql, sqlsrv       -> DataMSSQL
// firebird, fdb       -> DataFirebird

// Safe string (password masked)
echo $url->toSafeString(); // "mysql://root:***@db:3306/tina4app"
```

## Auto-Commit Configuration

By default, auto-commit is **disabled**. You must explicitly commit transactions or enable auto-commit.

```php
// Via environment variable in .env
// TINA4_AUTO_COMMIT=true

// Via constructor parameter
$db = new SQLite3Adapter('app.db', autoCommit: true);
```

**Best practice:** Leave auto-commit disabled in production and use explicit transactions. Set `TINA4_AUTO_COMMIT=true` only in development or testing environments.

## Tips

- Use `:named` placeholders for parameter binding — the adapter handles type detection automatically (INTEGER, FLOAT, TEXT, NULL).
- The SQLite3 adapter enables WAL journal mode for better concurrent read performance.
- Foreign keys are enabled by default (`PRAGMA foreign_keys=ON`).
- Use `DatabaseUrl::fromEnv()` for 12-factor app configuration — keep connection strings in environment variables.
- The `getColumns()` method is useful for building dynamic forms and validation rules.
