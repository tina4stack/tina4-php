# Create a Tina4 Database Migration

Create a SQL migration file for schema changes.

## Instructions

1. Create a numbered SQL file in `migrations/`
2. Use the next available number (check existing migrations)
3. Run `composer tina4 migrate` to apply

## Migration File (`migrations/NNNNNN_description.sql`)

```sql
-- Use IF NOT EXISTS for CREATE TABLE
CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    price REAL DEFAULT 0,
    category TEXT,
    active INTEGER DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
```

## Naming Convention

```
migrations/
  000001_create_users.sql
  000002_create_products.sql
  000003_add_email_to_users.sql
  000004_create_orders.sql
```

## Common Patterns

### Create Table
```sql
CREATE TABLE IF NOT EXISTS orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    total REAL DEFAULT 0,
    status TEXT DEFAULT 'pending',
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
```

### Add Column
```sql
ALTER TABLE users ADD COLUMN phone TEXT;
```

### Create Index
```sql
CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_username ON users(username);
```

### Insert Seed Data
```sql
INSERT OR IGNORE INTO roles (name) VALUES ('admin');
INSERT OR IGNORE INTO roles (name) VALUES ('user');
INSERT OR IGNORE INTO roles (name) VALUES ('guest');
```

## Firebird-Specific

When using Firebird, different rules apply:

```sql
-- Use generators instead of AUTOINCREMENT
CREATE GENERATOR GEN_PRODUCT_ID;

-- No IF NOT EXISTS for ALTER TABLE
-- No TEXT type — use VARCHAR(n) or BLOB SUB_TYPE TEXT
-- No REAL/FLOAT — use DOUBLE PRECISION
-- No foreign keys or triggers
```

## Running Migrations

```bash
composer tina4 migrate              # Apply all pending migrations
```

## Key Rules

- One migration per schema change
- Migrations are applied in numeric order
- Never modify an existing migration — create a new one
- Use `IF NOT EXISTS` for CREATE TABLE
- Use `IF NOT EXISTS` for CREATE INDEX
- Keep migrations small and focused
