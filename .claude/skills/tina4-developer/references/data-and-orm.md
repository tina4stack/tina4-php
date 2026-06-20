# Data, ORM & Database

## Defining Models

Drop a model file in `src/orm/` and it's auto-registered. In v3, **declare public properties directly** — no field descriptors needed.

### Python
```python
from tina4_python import ORM

class User(ORM):
    table_name = "users"
    primary_key = "id"

    id: int = None
    name: str = None
    email: str = None
    bio: str = ""
    is_active: bool = True
```

### PHP
```php
class User extends \Tina4\ORM {
    public $tableName = "users";
    public $primaryKey = "id";

    public $id;
    public $name;
    public $email;
    public $bio = "";
    public $isActive = true;
}
```

### Ruby
```ruby
class User < Tina4::ORM
  self.table_name = "users"
  self.primary_key = "id"

  attr_accessor :id, :name, :email, :bio, :is_active
end
```

### Node.js
```typescript
import { BaseModel } from 'tina4-nodejs';

export class User extends BaseModel {
  static tableName = "users";
  static primaryKey = "id";

  id?: number;
  name?: string;
  email?: string;
  isActive?: boolean;
}
```

## CRUD Operations

> **v3 API** — `find_by_id(id)` for ID lookup, `find(filter)` for filtered lists.
> Do NOT use the v2 query builder chain (`select("*").fetch()`) — it no longer exists.

> QueryBuilder's `from()` method has been renamed to avoid language keyword conflicts:
> - Python / Ruby: `from_table()`
> - PHP / Node.js: `fromTable()`
>
> The old `from()` method is removed.

### Create

```python
# Python
user = User({"name": "Alice", "email": "alice@example.com"})
saved = user.save()   # returns self (fluent) on success, False on failure
```
```php
// PHP
$user = new User(["name" => "Alice", "email" => "alice@example.com"]);
$saved = $user->save();   // returns $this on success, false on failure

// Static factory
$user = User::create(["name" => "Alice", "email" => "alice@example.com"]);
```
```ruby
# Ruby
user = User.new(name: "Alice", email: "alice@example.com")
user.save   # returns self or false
```
```typescript
// Node.js
const user = new User({ name: "Alice", email: "alice@example.com" });
await user.save();
```

### Read — by ID

```python
# Python
user = User().find_by_id(1)         # returns instance or None
```
```php
// PHP
$user = (new User())->findById(1);    // returns instance or null
$user = (new User())->findOrFail(1);  // throws if not found
```
```ruby
# Ruby
user = User.new.find_by_id(1)
```
```typescript
// Node.js
const user = await User.findById(1);
```

### Read — filtered list

```python
# Python — find() takes a filter dict or SQL string
users = User().find({"is_active": True})
users = User().find("is_active = 1", limit=20, offset=0)
users = User().all(limit=50)                         # all records
user  = User().select_one("email = ?", ["alice@example.com"])  # single
```
```php
// PHP
$users = (new User())->find(["is_active" => true]);
$users = (new User())->find("is_active = 1", limit: 20, offset: 0);
$users = (new User())->all(limit: 50);
$users = (new User())->where("email = ?", ["alice@example.com"]);
$user  = (new User())->selectOne("email = ?", ["alice@example.com"]);
```
```ruby
# Ruby
users = User.new.find(is_active: true)
users = User.new.all(limit: 50)
user  = User.new.find_one("email = ?", ["alice@example.com"])
```
```typescript
// Node.js
const users = await User.find({ isActive: true });
const users = await User.all({ limit: 50 });
```

### Update

```python
# Python
user = User().find_by_id(1)
user.name = "Alice Smith"
user.save()
```
```php
// PHP
$user = (new User())->findById(1);
$user->name = "Alice Smith";
$user->save();
```

### Delete

```python
# Python
user = User().find_by_id(1)
user.delete()
```
```php
// PHP
$user = (new User())->findById(1);
$user->delete();
```

### Serialisation

```python
# Python
user.to_dict()    # {"id": 1, "name": "Alice", ...}
user.to_json()    # '{"id": 1, "name": "Alice", ...}'
user.to_array()   # [1, "Alice", ...]
```
```php
// PHP
$user->toDict();   // same as toAssoc()
$user->toJson();
$user->toArray();  // flat indexed list
```

## Relationships

```python
class Post(ORM):
    id = IntegerField(primary_key=True, auto_increment=True)
    user_id = IntegerField()
    title = StringField()
    has_one = [{"User": "user_id"}]

class User(ORM):
    id = IntegerField(primary_key=True, auto_increment=True)
    name = StringField()
    has_many = [{"Post": "user_id"}]

# Access:
user = User().find_by_id(1)
posts = user.posts        # All posts by this user
post  = Post().find_by_id(1)
author = post.user        # The post's author
```

## Soft Delete

```python
class Article(ORM):
    soft_delete = True  # Uses the is_deleted column (INTEGER, 0/1)

article = Article().find_by_id(1)
article.delete()              # Sets is_deleted = 1 (soft)
article.restore()             # Sets is_deleted = 0
article.force_delete()        # Actually removes from DB

# Default queries exclude deleted records
articles = Article().all()
# Include deleted:
articles = Article().with_trashed()
```

## Pagination

`find()`, `all()`, and `where()` accept `limit` and `offset`:
```python
users = User().all(limit=20, offset=40)   # page 3 at 20/page
```

For paginated JSON responses the result includes metadata:
```json
{
    "data": [...],
    "total": 100,
    "page": 3,
    "per_page": 20,
    "total_pages": 5
}
```

## DatabaseResult Methods

`DatabaseResult` provides convenience methods (consistent across all frameworks):

| Method | Description | Frameworks |
|--------|-------------|------------|
| `size()` | Returns record count | All |
| `to_array()` | Convert to list/array | All |
| `to_json()` | Convert to JSON string | All |
| `to_csv()` | Convert to CSV string | All |

```python
# Python
results = db.fetch("SELECT * FROM users")
results.size()       # 42
results.to_array()   # [{"id": 1, "name": "Alice"}, ...]
results.to_json()    # '[{"id": 1, "name": "Alice"}, ...]'
results.to_csv()     # 'id,name\n1,Alice\n...'
```

## QueryBuilder — Fluent Queries with JOINs

Use `QueryBuilder` for complex queries (JOINs, aggregates, GROUP BY) instead of raw SQL.
Always prefer QueryBuilder over `db.fetch()` for maintainability.

```python
# Python
from tina4_python.query_builder import QueryBuilder

# Simple query
users = QueryBuilder.from_table("users") \
    .select("id", "name", "email") \
    .where("is_active = ?", [1]) \
    .order_by("name ASC") \
    .limit(10) \
    .get()                     # -> DatabaseResult

# JOINs
orders = QueryBuilder.from_table("orders o") \
    .select("o.*", "c.name as customer_name") \
    .join("customers c", "o.customer_id = c.id") \
    .where("o.status = ?", ["pending"]) \
    .order_by("o.created_at DESC") \
    .limit(20) \
    .get()

# LEFT JOIN
products = QueryBuilder.from_table("products p") \
    .select("p.*", "c.name as category_name") \
    .left_join("categories c", "p.category_id = c.id") \
    .where("p.is_active = ?", [1]) \
    .get()

# Aggregates
total = QueryBuilder.from_table("orders") \
    .select("coalesce(sum(total), 0) as total") \
    .where("status != ?", ["cancelled"]) \
    .first()["total"]          # -> single row dict

# COUNT
count = QueryBuilder.from_table("users") \
    .where("is_active = ?", [1]) \
    .count()                   # -> int

# GROUP BY + HAVING
stats = QueryBuilder.from_table("orders o") \
    .select("c.name", "count(*) as order_count") \
    .join("customers c", "o.customer_id = c.id") \
    .group_by("c.name") \
    .having("count(*) > ?", [5]) \
    .get()

# From ORM model (inherits the model's DB connection)
results = User.query() \
    .where("age > ?", [18]) \
    .order_by("name") \
    .get()

# Check existence
exists = QueryBuilder.from_table("users") \
    .where("email = ?", ["alice@example.com"]) \
    .exists()                  # -> bool
```

```php
// PHP
$orders = QueryBuilder::fromTable("orders o")
    ->select("o.*", "c.name as customer_name")
    ->join("customers c", "o.customer_id = c.id")
    ->orderBy("o.created_at DESC")
    ->limit(20)
    ->get();
```

```ruby
# Ruby
orders = Tina4::QueryBuilder.from_table("orders o")
    .select("o.*", "c.name as customer_name")
    .join("customers c", "o.customer_id = c.id")
    .order_by("o.created_at DESC")
    .limit(20)
    .get
```

### QueryBuilder Methods

| Method | Description |
|--------|-------------|
| `from_table(table)` | Start a query (Python/Ruby) |
| `fromTable(table)` | Start a query (PHP/Node.js) |
| `select(*cols)` | Set columns to select |
| `where(cond, params)` | AND condition |
| `or_where(cond, params)` | OR condition |
| `join(table, on)` | INNER JOIN |
| `left_join(table, on)` | LEFT JOIN |
| `group_by(col)` | GROUP BY |
| `having(expr, params)` | HAVING clause |
| `order_by(expr)` | ORDER BY |
| `limit(n, offset)` | LIMIT + optional OFFSET |
| `get()` | Execute → DatabaseResult |
| `first()` | Execute → single row dict or None |
| `count()` | Execute → int |
| `exists()` | Execute → bool |
| `to_sql()` | Build SQL string without executing |
| `to_mongo()` | Convert to MongoDB query document |

## Raw SQL

For queries that can't be expressed with ORM or QueryBuilder, use `db.fetch()` directly:
```python
from tina4_python.database import Database

db = Database("sqlite:data/app.db")
results = db.fetch("SELECT * FROM users WHERE id = ?", [1])
```

## Database Connection Strings

Same format in every language (set in `.env` as `TINA4_DATABASE_URL`):
```
sqlite://data/app.db
postgres://user:password@localhost:5432/mydb
mysql://user:password@localhost:3306/mydb
mssql://user:password@localhost:1433/mydb
firebird://user:password@localhost:3050/mydb
```

```python
# Python
from tina4_python import Database
db = Database.from_env()  # reads TINA4_DATABASE_URL
```
```php
// PHP
$db = \Tina4\Database\Database::fromEnv();  // reads TINA4_DATABASE_URL
```

## Migrations

```bash
tina4 migrate:create "create users table"   # Creates SQL file
tina4 migrate                                # Runs pending migrations
```

Migration files are versioned SQL in `src/migrations/`. Write standard SQL:
```sql
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

## Seeding

```bash
tina4 seed:create "initial users"   # Creates seed file
tina4 seed                           # Runs all seeds
```

Quick seeding with fake data:
```python
from tina4_python import FakeData, seed_orm

fake = FakeData()
fake.name()     # "Alice Johnson"
fake.email()    # "alice.johnson@example.com"

seed_orm(User, count=50)  # Bulk seed from field types
```

## Auto-CRUD

Generate a full admin CRUD interface with one call:
```python
@get("/api/users/crud")
async def user_crud(request, response):
    return CRUD.to_crud(request, {
        "sql": "SELECT * FROM users",
        "title": "User Management",
        "primary_key": "id"
    })
```
