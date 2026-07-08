# Data, ORM & Database (PHP)

## Defining Models

Drop a model file in `src/orm/` and it's auto-registered. In v3, **declare public properties
directly** — no field descriptors needed. Extend `\Tina4\ORM`.

```php
class User extends \Tina4\ORM
{
    public string $tableName = "users";
    public string $primaryKey = "id";

    public $id;
    public $name;
    public $email;
    public $bio = "";
    public $isActive = true;
}
```

Column names auto-map between snake_case (database) and camelCase (PHP property). `$tableName` and
`$primaryKey` are typed strings; `$primaryKey` defaults to `"id"`.

## CRUD Operations

**Static methods:** `create`, `find`, `findById`, `findOrFail`, `query`.
**Instance methods:** `save`, `delete`, `all`, `where`, `selectOne`, `toDict`, `toArray`, `toJson`.

Calling an instance method statically (or vice-versa) is a common mistake — keep the split straight.

### Create

```php
// Constructor takes the record data as an associative array (JSON body works directly):
$user  = new User(["name" => "Alice", "email" => "alice@example.com"]);
$saved = $user->save();                  // returns $this on success, false on failure

// Static factory (also static: creates + saves in one call):
$user = User::create(["name" => "Alice", "email" => "alice@example.com"]);
```

### Read — by ID (static)

```php
$user = User::findById(1);      // returns instance or null
$user = User::findOrFail(1);    // returns instance, or throws RuntimeException if not found
```

### Read — filtered list

```php
// find() is STATIC. Pass an associative filter for an equality match; returns array<User>.
$users = User::find(["is_active" => true]);

// where() is an INSTANCE method — a WHERE clause (without the word WHERE) + bound params. Returns array<User>.
$users = (new User())->where("is_active = ?", [1], limit: 20, offset: 0);

// all() is an INSTANCE method — every record (respects soft-delete). Returns array<User>.
$users = (new User())->all(limit: 50);

// A single row: prefer where(...)[0]. selectOne() takes FULL raw SQL, so it's the wrong tool for a clause.
$user = (new User())->where("email = ?", ["alice@example.com"])[0] ?? null;
```

> **`selectOne(string $sql, array $params = [])` takes a COMPLETE `SELECT` statement**, not a bare
> clause — it runs your string verbatim. `(new User())->selectOne("email = ?", [...])` is broken SQL.
> For a filtered single row, use `where("email = ?", [...])[0] ?? null`. Reach for `selectOne` only
> when you genuinely need a hand-written query:
> ```php
> $user = (new User())->selectOne("SELECT * FROM users WHERE lower(email) = ? LIMIT 1", ["alice@example.com"]);
> ```

### Update

```php
$user = User::findById(1);
$user->name = "Alice Smith";
$user->save();
```

### Delete

```php
$user = User::findById(1);
$user->delete();
```

### Serialisation (instance methods)

```php
$user->toDict();   // ["id" => 1, "name" => "Alice", ...]  (snake_case keys by default)
$user->toJson();   // '{"id":1,"name":"Alice",...}'
$user->toArray();  // flat indexed list of values
```

`$response->json($user)` and `return $response($user)` call `toDict()` for you, so you rarely need
to serialise by hand in a route.

## Relationships

Relationships are declared as public arrays keyed by the **accessor name**, with a
`"Model.foreign_key"` spec. Reading the accessor lazy-loads the related record(s).

```php
class Post extends \Tina4\ORM
{
    public string $tableName = "posts";
    public $id;
    public $user_id;
    public $title;

    // belongs-to: accessor => "Model.foreign_key"
    public array $belongsTo = ["user" => "User.user_id"];
}

class User extends \Tina4\ORM
{
    public string $tableName = "users";
    public $id;
    public $name;

    // has-many: accessor => "Model.foreign_key"
    public array $hasMany = ["posts" => "Post.user_id"];
    // has-one is the same shape: public array $hasOne = ["profile" => "Profile.user_id"];
}

// Access:
$user   = User::findById(1);
$posts  = $user->posts;        // array<Post> for this user
$post   = Post::findById(1);
$author = $post->user;         // the owning User
```

### Foreign-key shortcut

Instead of declaring both sides, declare `$foreignKeys` on the child and Tina4 auto-wires the
`belongsTo` on this model **and** a `hasMany` on the referenced model:

```php
class Post extends \Tina4\ORM
{
    public string $tableName = "posts";
    public $id;
    public $user_id;
    public $title;

    // 'fk_column' => 'RelatedModel'  (auto-wires belongsTo here + hasMany on User)
    public array $foreignKeys = ["user_id" => "User"];
    // Extended form for a custom has-many accessor:
    // public array $foreignKeys = ["user_id" => ["model" => "User", "related_name" => "posts"]];
}
```

## Soft Delete

```php
class Article extends \Tina4\ORM
{
    public string $tableName = "articles";
    public bool $softDelete = true;    // uses the is_deleted column (INTEGER, 0/1)
    public $id;
    public $title;
}

$article = Article::findById(1);
$article->delete();          // sets is_deleted = 1 (soft)
$article->restore();         // sets is_deleted = 0
$article->forceDelete();     // actually removes the row

// Default queries exclude deleted records:
$live = (new Article())->all();
// Include deleted:
$all  = (new Article())->withTrashed();
```

## Pagination

`where()` and `all()` accept `limit` and `offset`:
```php
$users = (new User())->all(limit: 20, offset: 40);   // page 3 at 20/page
```

## DatabaseResult Methods

`\Tina4\Database\Database::fetch()` returns a `DatabaseResult` with convenience methods:

| Method | Description |
|--------|-------------|
| `size()` | Record count |
| `toArray()` | Convert to array |
| `toJson()` | Convert to JSON string |
| `toCsv()` | Convert to CSV string |

```php
$results = $db->fetch("SELECT * FROM users");
$results->size();       // 42
$results->toArray();    // [["id" => 1, "name" => "Alice"], ...]
$results->toJson();     // '[{"id":1,"name":"Alice"}, ...]'
```

> `toArray()` / `toJson()` / `toCsv()` are on the `DatabaseResult` from `$db->fetch()`. ORM query
> methods (`find`, `where`, `all`) return a plain **array of model instances** — to serialise them,
> map over the list: `array_map(fn ($m) => $m->toDict(), (new User())->all())` (or just hand the
> array to `$response->json(...)`, which converts each model for you).

## QueryBuilder — Fluent Queries with JOINs

Use `\Tina4\QueryBuilder` for complex queries (JOINs, aggregates, GROUP BY) instead of raw SQL. In
route/ORM files (global namespace) qualify it with the leading backslash.

```php
// Simple query
$users = \Tina4\QueryBuilder::fromTable("users")
    ->select("id", "name", "email")
    ->where("is_active = ?", [1])
    ->orderBy("name ASC")
    ->limit(10)
    ->get();                    // -> DatabaseResult

// JOINs
$orders = \Tina4\QueryBuilder::fromTable("orders o")
    ->select("o.*", "c.name as customer_name")
    ->join("customers c", "o.customer_id = c.id")
    ->where("o.status = ?", ["pending"])
    ->orderBy("o.created_at DESC")
    ->limit(20)
    ->get();

// LEFT JOIN
$products = \Tina4\QueryBuilder::fromTable("products p")
    ->select("p.*", "c.name as category_name")
    ->leftJoin("categories c", "p.category_id = c.id")
    ->where("p.is_active = ?", [1])
    ->get();

// Aggregate — first() returns a single row (assoc array) or null
$total = \Tina4\QueryBuilder::fromTable("orders")
    ->select("coalesce(sum(total), 0) as total")
    ->where("status != ?", ["cancelled"])
    ->first()["total"];

// COUNT / existence
$count  = \Tina4\QueryBuilder::fromTable("users")->where("is_active = ?", [1])->count();  // int
$exists = \Tina4\QueryBuilder::fromTable("users")->where("email = ?", ["a@b.com"])->exists();  // bool

// GROUP BY + HAVING
$stats = \Tina4\QueryBuilder::fromTable("orders o")
    ->select("c.name", "count(*) as order_count")
    ->join("customers c", "o.customer_id = c.id")
    ->groupBy("c.name")
    ->having("count(*) > ?", [5])
    ->get();

// From an ORM model (inherits the model's DB connection) — query() is STATIC
$results = User::query()
    ->where("age > ?", [18])
    ->orderBy("name")
    ->get();
```

### QueryBuilder Methods

| Method | Description |
|--------|-------------|
| `fromTable($table)` | Start a query (static) |
| `select(...$cols)` | Set columns to select |
| `where($cond, $params)` | AND condition |
| `orWhere($cond, $params)` | OR condition |
| `join($table, $on)` | INNER JOIN |
| `leftJoin($table, $on)` | LEFT JOIN |
| `groupBy($col)` | GROUP BY |
| `having($expr, $params)` | HAVING clause |
| `orderBy($expr)` | ORDER BY |
| `limit($n, $offset)` | LIMIT + optional OFFSET |
| `get()` | Execute -> DatabaseResult |
| `first()` | Execute -> single row array or null |
| `count()` | Execute -> int |
| `exists()` | Execute -> bool |
| `toSql()` | Build SQL without executing |

## Raw SQL

For queries that can't be expressed with the ORM or QueryBuilder, use `fetch()` directly:
```php
$db = \Tina4\Database\Database::fromEnv();
$results = $db->fetch("SELECT * FROM users WHERE id = ?", [1]);
```

## Database Connection

Set `TINA4_DATABASE_URL` in `.env` and read it with `fromEnv()`:
```php
$db = \Tina4\Database\Database::fromEnv();   // reads TINA4_DATABASE_URL
```

Connection string formats (same across every Tina4 framework):
```
sqlite:data/app.db
postgresql://user:password@localhost:5432/mydb
mysql://user:password@localhost:3306/mydb
mssql://user:password@localhost:1433/mydb
firebird://user:password@localhost:3050/mydb
```

## Migrations

```bash
tina4php migrate:create "create users table"   # creates a versioned SQL file in src/migrations/
tina4php migrate                                # runs pending migrations
```

Write standard SQL:
```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

## Seeding

Quick seeding with fake data via `\Tina4\FakeData`:
```php
$fake = new \Tina4\FakeData();
$fake->name();      // "Alice Johnson"
$fake->email();     // "alice.johnson@example.com"
$fake->firstName(); // "Alice"

// Bulk seed a model from its field types (static):
\Tina4\FakeData::seedOrm(User::class, count: 50);
```

## Auto-CRUD

Set `public bool $autoCrud = true;` on a model — Tina4 auto-registers a full
list/create/read/update/delete route set for it on startup, no handlers to write:
```php
class User extends \Tina4\ORM
{
    public bool $autoCrud = true;      // registers CRUD routes for this model
    public string $tableName = "users";
    public $id;
    public $name;
    public $email;
}
```
