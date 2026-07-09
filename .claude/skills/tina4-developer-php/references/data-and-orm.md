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
    public bool $softDelete = true;    // filter out is_deleted = 1 rows by default
    public $id;
    public $title;
    public int $is_deleted = 0;        // REQUIRED — you must declare it yourself
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

> **You MUST declare `is_deleted` yourself.** `createTable()` builds the table from your
> declared public properties only — it does **not** inject an `is_deleted` column when
> `$softDelete = true` (`ORM::getColumnDefinitions()`). Omit the property and the table has no
> such column, so `delete()` (writes `is_deleted = 1`) and `all()` / `find()` / `where()` /
> `findById()` (append `AND is_deleted = 0`) all fail with `no such column: is_deleted`.
> Declare `public int $is_deleted = 0;` (an INTEGER column defaulting to 0). Unlike the write
> path, this footgun surfaces on the first **read** or **delete**, not on `save()` (an INSERT
> simply omits the undeclared column).

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
tina4php migrate:create "create users table"   # creates a versioned SQL file in migrations/
tina4php migrate                                # runs pending migrations
```

> Migrations live in **`migrations/`** at the project root — **not** `src/migrations/`. That is
> the folder the CLI scaffolds (`bin/tina4php` `migrate` / `migrate:create` / `migrate:rollback`
> all use `<cwd>/migrations`) and the folder the server's startup auto-migrate reads
> (`App::autoMigrateOnStartup()` → `<basePath>/migrations`). Startup auto-migration is
> **fail-soft** (a bad migration is logged and the service still boots); the explicit
> `tina4php migrate` CLI stays **fail-fast** (non-zero exit for CI). Disable boot migration with
> `TINA4_AUTO_MIGRATE=false`.

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

## ORM Lifecycle & Footguns

The write path has a deliberate but **inconsistent** failure contract: some calls fail *soft*
(return `false`), some fail *loud* (throw). Getting this wrong is the single biggest source of
wasted debugging on Tina4. Each item is **what bites → the safe idiom → what breaks.** All
verified against the framework source (`Tina4/ORM.php`, `Tina4/Database/Database.php`); the
boot-gate `tests/OrmFootgunsDocTest.php` pins every one against real SQLite.

> **PHP differs from the Python master on two paths** (marked below): the delete family returns
> `false` on a precondition where Python *raises*, and the constructor throws only on a
> *structural* bad input (a list / invalid JSON) — never on a bad field *value*. Don't
> transliterate the Python contract; the PHP contract is what's documented here.

### The write path fails *soft* — `save()` / `create()` return `false`, never throw

`save()` returns `$this` on success and **`false`** on *any* failure (a `validate()` error OR a
driver error). It **never throws** and never returns `null`. The real cause is recorded on
`$model->lastError` and readable via `$model->getError()` / `$model->error()` (mirroring the
adapter's `getError()`), so a swallowed failure is always recoverable — and it is also logged.
`create()` = construct + `save()`; when the save fails it returns `false` (not a half-saved
instance).

```php
// SAFE — check the return, surface the cause
$user = new User(["name" => "Alice", "email" => "a@x.com"]);
if (!$user->save()) {                          // false on failure — NOT an exception
    return $response(["error" => $user->getError()], 422);   // e.g. "name is required"
}
```

* **Breaks:** `try { $user->save(); } catch (...) { ... }` — the `catch` never fires, so a failed
  write looks like success. Testing `$user->save() === null` is also wrong (it's `false`).
  `ORM.php:509` (`save`), `:227` (`create`), `:597` (`getError`).

### No auto table-create — `save()` into a missing table returns `false`

Defining a model does **not** create its table. The first `save()` into a table that doesn't
exist hits `no such table` in the driver, which `save()` catches → returns `false` with the cause
on `lastError`. As of v3.13.60 the cause is augmented with an actionable hint
(`ORM::writeErrorHint()`): *"table 'x' does not exist; call `$model->createTable()` (dev) or run
a migration"*.

```php
// SAFE — create the table (dev) or run a migration (prod) before the first write
(new User())->createTable();    // idempotent: CREATE TABLE IF NOT EXISTS from the declared props
(new User(["name" => "Alice"]))->save();
```

* **Breaks:** relying on `save()` to bootstrap the schema — it returns `false` silently and no row
  lands. `createTable()` builds DDL from **declared public properties only** (it injects nothing —
  see soft-delete's `is_deleted`). `ORM.php:556-566`, `createTable` `ORM.php:1511`.

### The constructor throws only on *structural* bad input — not on bad values (differs from Python)

`new Model($data)` accepts an associative array or a JSON **object** string. It throws
`InvalidArgumentException` in exactly two cases: (1) a **list** array (`new Model([$row1, $row2])`
— "a single model is one record"), and (2) an **invalid / array JSON string**. It does **not**
run field validation, so a valid object with a *bad value* does **not** throw — that surfaces
later as a `save()` returning `false` (via your `validate()` override). This is the opposite of
Python, where the constructor validates on the read path and raises on a bad value.

```php
// SAFE — an object body constructs fine; the value check is your validate() + the save() return
$user = new User($request->body);              // OK for a JSON object body
if (!$user->save()) {                          // validate() failures come back here as false
    return $response(["error" => $user->getError()], 422);
}
```

* **Breaks:** `new User([$rowA, $rowB])` (a *list*) throws `InvalidArgumentException` — map over
  the list to build many records instead. A malformed JSON string
  (`new User('{"bad json')`) also throws. `ORM.php:294` (bad JSON), `:303` (list).

### `delete()` / `restore()` return `false` on a precondition — they do NOT throw (differs from Python)

The delete family returns a **`bool`**: `delete()` / `forceDelete()` return `false` when there's
no primary-key value; `restore()` returns `false` on a model without `$softDelete` (or with no
PK). They only **throw** when the *driver* write itself fails (the exception is re-raised, not
swallowed). This is the inverse of Python, where `delete()`/`force_delete()` raise `ValueError`
on a missing PK and `restore()` raises `RuntimeError` on a non-soft model.

```php
// SAFE — guard the row exists; wrap in try/catch only if the DB write itself may fail
$user = User::findById($uid);
if ($user && $user->delete()) {                // false if $user->id is null; throws on a driver error
    // deleted
}
```

* **Breaks:** expecting `(new User())->delete()` on an unsaved instance to throw — it returns
  `false`. `ORM.php:732` (`delete`), `:1239` (`forceDelete`), `:1275` (`restore`).

### `execute()` throws — it does not return `false`

Raw writes via the `Database` facade's `execute()` / `exec()` fail **loud**: on a driver error
they **throw** `\Tina4\Database\DatabaseException` (and set `getError()`); they do **not** return
`false`. (The ORM's `save()` wraps this and converts it to `false` — but a *direct* `execute()`
propagates.)

```php
// SAFE — wrap raw writes you expect might fail; don't test the return value
try {
    $db->execute("INSERT INTO audit (msg) VALUES (?)", ["ok"]);
} catch (\Tina4\Database\DatabaseException $e) {
    return $response(["error" => $db->getError() ?? $e->getMessage()], 500);
}
```

* **Breaks:** `if (!$db->execute($sql)) { ... }` — a successful write returns `true`, and a
  *failed* one throws rather than returning a falsy value, so the branch never runs.
  `Database.php:459` (`execute`), `:483-488` (raise-on-false).

### Bind a database before any ORM or QueryBuilder call

Every ORM query and `QueryBuilder` needs a resolvable connection. Resolution order
(`ORM::ensureDb()` / `resolveDb()`): the instance's `$_db` → the global set by
`ORM::bindDatabase($db)` → `App::getDatabase()` → `Database::fromEnv()` (`TINA4_DATABASE_URL`). If
none resolves, the call throws `RuntimeException: "No database configured…"`. A `$_db` naming an
unregistered connection throws `"Named database '…' not found"`.

```php
// SAFE — set TINA4_DATABASE_URL in .env (auto-discovered) …
//   sqlite:data/app.db   (scheme-only)   or   sqlite:///data/app.db   (URL form)
// … or bind explicitly at boot:
\Tina4\ORM::bindDatabase(\Tina4\Database\Database::fromEnv());
```

* **Breaks:** running an ORM query in a script/worker that never set `TINA4_DATABASE_URL` or
  called `bindDatabase()` → `RuntimeException`. `ORM.php:167` (`resolveDb`), `:105`
  (`bindDatabase`).

### No default ordering — paginate with a unique tiebreaker

`all()` / `where()` / `find()` apply **no `ORDER BY` unless you pass `orderBy`** (`ORM.php:857`,
`:1191`). Without one, row order is engine-defined (SQLite rowid; unspecified on Postgres), and
`limit`/`offset` pages can repeat or skip rows. Ordering by a non-unique column
(e.g. `created_at`) has the same problem on ties.

```php
// SAFE — always order by a UNIQUE tiebreaker for stable pagination
$page = (new User())->all(limit: 20, offset: 40, orderBy: "created_at DESC, id DESC");
```

* **Breaks:** `(new User())->all(limit: 20, offset: 20)` with no `orderBy`, or
  `orderBy: "created_at DESC"` alone — two rows with the same timestamp can land on two different
  pages (or neither).

### Auto-migrate is fail-soft and server-only

On `tina4php serve`, the app applies pending SQL migrations from `migrations/` (project root) at
boot (`App::autoMigrateOnStartup()`, `App.php:629`). It is **fail-soft**: a bad migration is
logged loud and **the service still starts** (a broken migration must not take the backend down).
The explicit **`tina4php migrate` CLI stays fail-fast** (non-zero exit for CI). Disable boot
migration with `TINA4_AUTO_MIGRATE=false` (recommended for multi-instance prod, where concurrent
first-apply can race).

* **Breaks:** assuming a green server boot means migrations applied — check the logs, or gate
  deploys on `tina4php migrate` (which does exit non-zero).

### Framework gotchas (auth, routing, templates, background work)

These bite outside the ORM but hit the same agent-build loop. Verified against source.

* **N1 — Auth / unexpected 401 (security).** An unexpected 401 means **the caller needs a token**,
  not that the route should be opened. `->noAuth()` is a **last resort** for genuinely public
  endpoints only. See **`auth-and-services.md` → "Auth footguns"** for the full treatment (write
  routes secure by default, `->secure()` to lock a GET, and why you must never blanket
  `->noAuth()` to silence 401s). **Note (differs from Python):** PHP has **no docs-only auth
  annotation** — the `@noauth` / `@secured` docblock tags actually *enforce* (`Router.php:1438`),
  and the OpenAPI security is derived from the real enforcement flag, so there's no
  `@security(...)`-style "documents-but-doesn't-gate" trap to fall into.

* **N2 — Auth is fluent + docblock, not stacked decorators (differs from Python).** PHP has no
  decorator-order footgun: opt a write route out with the chained **`->noAuth()`**, lock a GET
  with **`->secure()`**, or use the equivalent **`@noauth`** / **`@secured`** docblock tag on the
  handler. The two forms are interchangeable (`Router.php:1431-1447`).

* **N3 — Postgres writes inside a transaction need an explicit commit.** A standalone write
  auto-commits (libpq default), but a raw write made **inside `$db->startTransaction()`** (which
  issues `BEGIN`) only becomes durable after `$db->commit()` — otherwise it rolls back and the row
  never lands. The ORM's `save()` already wraps `startTransaction()` + `commit()` for you
  (`ORM.php:538`, `:568`); this only bites hand-rolled multi-statement writes.

* **N4 — Frond templates (`src/templates/`, engine is Frond, not real Twig).** Unescape with
  `{{ x|raw }}` **or** `{{ x|safe }}` (both work). Concatenate with `~`, **not `+`**:
  `{{ "hi " ~ name }}` — Frond's `+` coerces non-numeric operands to `0`, so `{{ "hi " + name }}`
  renders **`0`**, not the joined string (`Frond.php:1717`). Live regions **throw** at render if
  malformed: `{% live "x" poll 5 %}` (poll needs seconds), `{% live "x" ws "/ws/x" %}` (ws needs a
  path), and a `src "..."` must be a **same-origin path** (an absolute `http(s)://` URL throws)
  (`Frond.php:737-770`). Frond accepts **both** `{% elif %}` and `{% elseif %}`, and `{{ x|e }}` /
  `{{ x|escape }}` HTML-escape — so pure-Twig advice ("`elif` is invalid") does **not** apply here.

* **N5 — `DatabaseResult` is not an array.** `$db->fetch()` returns a `DatabaseResult`; the rows
  live on **`->records`** (a `list` of assoc arrays), accessed by key:
  `$result->records[0]["name"]`. It also carries `size()` / `toArray()` / `toJson()` / `toCsv()`.
  A *plain array* (from ORM `all()` / `where()` / `find()`) has **none** of those — serialize it
  with `array_map(fn ($m) => $m->toDict(), ...)` or hand it to `$response->json(...)`.

* **N6 — Periodic work uses `$app->background()`, not a hand-rolled loop or raw threads.**
  Register recurring work with `$app->background($callable, $intervalSeconds)` — it runs
  cooperatively in the server's event loop between HTTP requests, with clean shutdown and error
  handling (`App.php:1135`, the direct parity with Python's `background(fn, interval)`). Never spin
  a bare `while (true) { … sleep() }` inside a request. For a *separate* long-running managed
  worker (its own process/daemon or cron timing), use `\Tina4\ServiceRunner::register(...)` /
  `\Tina4\Service` instead (`ServiceRunner.php:36`).

  ```php
  $app = new \Tina4\App();
  $app->background(fn () => syncInbox(), 60.0);   // every 60s in the event loop
  $app->run();
  ```

* **N7 — Route param types are a fixed set.** A typed path param (`/users/{id:int}`) must use a
  known type, or route registration **throws `InvalidArgumentException`** — never a silent
  match-anything fall-through. Valid types: **`string`, `int`, `integer`, `float`, `number`,
  `alpha`, `alnum`, `slug`, `uuid`, `path`** (`Router.php:1642`). `{id:inetger}` (typo) throws at
  registration (`Router.php:1681`).

## When to reach for `tina4_context`

`tina4_context(instruction, language="php")` (server `tina4-coder`) retrieves the authoritative,
version-current Tina4 API + real examples from the live corpus. It is a **grounding** tool, not a
code generator — write the code yourself from what it returns. Use it as a ladder, not a reflex:

1. **Skill covers it → write from the skill.** These reference files are the source of truth for
   the common surface (models, routes, CRUD, templates, auth, queues). Don't call `tina4_context`
   for something documented here — you'll just spend tokens.
2. **Uncovered / current-tree API / a surprise → then call `tina4_context`.** Reach for it when
   the skill doesn't cover the case, you need an API the installed version added recently, or the
   framework did something the doc didn't predict (a footgun you hit). Pass `language="php"`
   explicitly — auto-detection mis-fires on ambiguous text.
3. **Write it yourself, then verify against the live API.** Confirm any method/field/route shape
   against the running project's MCP index — `api_method("Database", "fetch")`, `api_class("ORM")`,
   `api_search("...")` at `/__dev/mcp` (needs `tina4php serve` + `TINA4_DEBUG=true`). **The
   framework code is the final authority.** Do **not** use `tina4_code` (the self-hosted
   generator) — the value is the retrieval, not a small model.

## Batteries included — zero third-party dependencies

`composer.json` `require` is just **`php >=8.2`, `ext-openssl`, `ext-json`** — Tina4-PHP's core
carries **no third-party Composer packages** (54 built-in features; the only optional package is
`mongodb/mongodb`, a `suggest`/`require-dev` for the Mongo backends). DB drivers ride on PHP
extensions you already have (`ext-sqlite3`, `ext-pdo`/`ext-pgsql`, …), not vendored code. Before
you `composer require` anything, check whether it's already in the box. **Need → Tina4 built-in
(verified entry API) — don't add the dep:**

| Need | Tina4 built-in — don't `composer require …` |
|------|---------------------------------------------|
| Auth / JWT / password hashing | `\Tina4\Auth` — `Auth::getToken/validToken/getPayload/authenticateRequest/hashPassword/checkPassword` *(don't add `firebase/php-jwt`)* |
| ORM / models | `\Tina4\ORM` + `\Tina4\ORM::bindDatabase(...)` *(don't add Eloquent/Doctrine)* |
| Fluent queries / JOINs | `\Tina4\QueryBuilder::fromTable(...)` |
| DB drivers (multi-engine) | `\Tina4\Database\Database::fromEnv()` — SQLite (`ext-sqlite3`) built in; Postgres/MySQL/MSSQL (`ext-pdo`/`ext-pgsql`); Firebird; MongoDB (`ext-mongodb` + `mongodb/mongodb`) |
| Migrations | `tina4php migrate:create` / `tina4php migrate` CLI (or `\Tina4\Migration`) *(don't add Phinx)* |
| Templating | `\Tina4\Frond` + `$response->render("page.twig", [...])`; templates in `src/templates/` *(don't add `twig/twig`)* |
| SCSS → CSS | drop `.scss` in `src/scss/` — auto-compiled on `tina4php serve` (`\Tina4\ScssCompiler`) *(don't add `scssphp/scssphp`)* |
| Input validation | `\Tina4\Validator` |
| Response / JSON serialization | `$response->json(...)` — arrays → JSON; an ORM model/collection serialized via `toDict()`; also `$response(...)`, `$response->render(...)`, `$response->redirect(...)` |
| Background queue | `\Tina4\Queue` — `(new Queue(topic: ...))->produce(...)` / `->consume(...)` *(don't add a broker client for simple jobs)* |
| Background / scheduled workers | `\Tina4\ServiceRunner::register('name', $callable, ['timing' => '…'])` + `\Tina4\Service` |
| Email | `\Tina4\Messenger` — `(new Messenger())->send(to: …, subject: …, body: …, html: true)` *(don't add `phpmailer/phpmailer`)* |
| Sessions | `$request->session->set/get/has/clear` (backends: file/redis/valkey/mongodb/database via `TINA4_SESSION_BACKEND`) |
| Caching | `\Tina4\Cache` — `cacheGet/cacheSet/cacheDelete/cacheClear` + `{% cache "k" 60 %}` template blocks |
| OpenAPI / Swagger docs | auto-generated at `/swagger` (`\Tina4\Docs`) — metadata only, never enforcement |
| WebSockets | `\Tina4\Router::websocket("/ws/…", $handler)`; live regions via Frond `{% live %}` |
| Real-time (WebRTC signalling, presence) | `\Tina4\Realtime\Realtime::mount(...)` (+ `/api/rtc/config`) |
| GraphQL API from models | `(new \Tina4\GraphQL())->fromOrm(new User())->register("/graphql")` *(don't add `webonyx/graphql-php`)* |
| SOAP / WSDL | `\Tina4\WSDL` + `\Tina4\WSDLOperation` |
| i18n / localization | `\Tina4\I18n` — `->translate(key, params)` / `->setLocale(...)`; JSON in `src/locales/` |
| .env loading + typed env | `\Tina4\DotEnv` / `\Tina4\Env` (typed getters) *(don't add `vlucas/phpdotenv`)* |
| Document store (Mongo-style on SQLite) | `\Tina4\SqliteDatabase` / `\Tina4\SqliteCollection` + `\Tina4\ObjectId` |
| Dependency injection | `\Tina4\Container` |
| Fake data / seeding | `\Tina4\FakeData` — `FakeData::seedOrm(User::class, count: 50)` *(don't add `fakerphp/faker`)* |
| Events | `\Tina4\Events::on(...)` / `\Tina4\Events::emit(...)` |
| **Outbound HTTP calls** | `\Tina4\Api` — `(new Api($base))->get(...)/->post(...)/->sendRequest(...)` *(don't add `guzzlehttp/guzzle`)* |
