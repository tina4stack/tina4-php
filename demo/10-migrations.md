# Migrations

The `Migration` class reads SQL migration files from a directory, applies them in order, and tracks which migrations have been run using a `tina4_migration` table. Migrations support batch tracking for rollbacks and stop on the first error to prevent partial schema changes.

Migration files are plain SQL — no PHP wrappers, no DSL. Each file contains one or more SQL statements separated by a configurable delimiter.

## File Naming Convention

Migration files must follow this naming pattern:

```
YYYYMMDDHHMMSS_description.sql
```

Examples:
```
20260101120000_create_users_table.sql
20260101120100_create_posts_table.sql
20260115090000_add_email_index.sql
20260201143000_add_avatar_column.sql
```

The timestamp prefix ensures migrations are applied in chronological order. Use a descriptive suffix to document what the migration does.

## Migration File Examples

### Create Table

```sql
-- src/migrations/20260101120000_create_users_table.sql
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    role VARCHAR(20) DEFAULT 'user',
    is_deleted INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Multiple Statements

```sql
-- src/migrations/20260101120100_create_posts_and_comments.sql
CREATE TABLE IF NOT EXISTS posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    author_id INTEGER NOT NULL,
    title VARCHAR(255) NOT NULL,
    body TEXT,
    published_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    post_id INTEGER NOT NULL,
    author_name VARCHAR(100),
    body TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Alter Table

```sql
-- src/migrations/20260201143000_add_avatar_to_users.sql
ALTER TABLE users ADD COLUMN avatar_url VARCHAR(500);
```

### Create Index

```sql
-- src/migrations/20260215090000_add_indexes.sql
CREATE INDEX IF NOT EXISTS idx_posts_author ON posts(author_id);
CREATE INDEX IF NOT EXISTS idx_comments_post ON comments(post_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email ON users(email);
```

## Running Migrations

```php
use Tina4\Migration;
use Tina4\Database\SQLite3Adapter;

$db = new SQLite3Adapter('app.db');

// Create migration runner (default directory: src/migrations)
$migration = new Migration($db, 'src/migrations');

// Run all pending migrations
$result = $migration->migrate();

// $result structure:
// [
//   'applied' => ['20260101120000_create_users_table.sql', ...],
//   'skipped' => [],  // Empty files
//   'errors'  => [],  // Filename => error message
// ]

if (!empty($result['applied'])) {
    echo "Applied " . count($result['applied']) . " migrations:\n";
    foreach ($result['applied'] as $name) {
        echo "  - {$name}\n";
    }
}

if (!empty($result['errors'])) {
    echo "Migration errors:\n";
    foreach ($result['errors'] as $name => $error) {
        echo "  - {$name}: {$error}\n";
    }
}
```

## Rollback

Rollback undoes the last batch of migrations by removing their tracking records.

```php
$result = $migration->rollback();

// $result structure:
// [
//   'rolledBack' => ['20260201143000_add_avatar_to_users.sql'],
//   'errors'     => [],
// ]
```

**Note:** Rollback removes the migration from the tracking table. It does not execute reverse SQL (e.g., DROP TABLE). For destructive rollbacks, create a new forward migration.

## Checking Status

```php
// Get all applied migrations
$applied = $migration->getAppliedMigrations();
// [
//   ['migration' => '20260101120000_create_users_table.sql', 'batch' => 1, 'applied_at' => '2026-01-01 12:00:00'],
//   ...
// ]

// Get pending (unapplied) migration files
$pending = $migration->getPendingMigrations();
// ['/path/to/src/migrations/20260301000000_new_feature.sql']

// Get all migration files on disk
$allFiles = $migration->getMigrationFiles();
```

## Batch Tracking

Migrations are grouped into batches. Each call to `migrate()` creates a new batch. Rollback operates on the most recent batch.

```
Batch 1:
  - 20260101120000_create_users_table.sql
  - 20260101120100_create_posts_table.sql

Batch 2:
  - 20260201143000_add_avatar_to_users.sql
  - 20260215090000_add_indexes.sql
```

Rolling back removes all migrations in the latest batch (batch 2 in this example).

## Custom Delimiter

By default, statements are split by `;`. For databases that use different delimiters (e.g., Firebird stored procedures), pass a custom delimiter.

```php
$migration = new Migration($db, 'src/migrations', delimiter: ';;');
```

## Tracking Table

The `tina4_migration` table is created automatically on first use:

```sql
CREATE TABLE tina4_migration (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    migration VARCHAR(255) NOT NULL UNIQUE,
    batch INTEGER NOT NULL,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## Tips

- Always use `CREATE TABLE IF NOT EXISTS` and `CREATE INDEX IF NOT EXISTS` for safety.
- Never modify an existing migration file after it has been applied — create a new migration instead.
- Use descriptive file names: `create_X`, `add_Y_to_Z`, `remove_W_from_Z`, `alter_X`.
- Each migration runs inside a transaction — if any statement fails, the entire migration is rolled back.
- Migration files are sorted alphabetically (by timestamp), so consistent naming is critical.
- Keep migrations small and focused — one logical change per file.
