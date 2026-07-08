# Routing, Middleware, ORM & Database

## Routing

Routes are auto-discovered from `src/routes/`. Each language uses its idiomatic style:

### Python (async decorators)

The route decorators are top-level, but the Swagger/OpenAPI metadata decorators
(`description`, `tags`, `example`) live in `tina4_python.swagger` — import them from there,
not from `tina4_python`.

**Auth default:** GET/HEAD/OPTIONS routes are public; **POST/PUT/PATCH/DELETE are Bearer-token
gated by default** (`auth_required = True`). A write route with no token returns 401 unless you
mark it `@noauth()`. Use `@secured()` to gate a GET route.

```python
from tina4_python import get, post, put, delete, noauth
from tina4_python.swagger import description, tags, example

@get("/hello")
async def hello(request, response):
    return response("Hello World")

@get("/users/{id}")
@description("Get a user by ID")
@tags(["Users"])
async def get_user(request, response):
    user_id = request.params["id"]
    return response.json({"id": user_id})

@post("/users")
@noauth()                      # public write route — without this it is 401 by default
async def create_user(request, response):
    data = request.body
    return response.json(data, 201)
```

### PHP (static method registration)
```php
\Tina4\Get::add("/hello", function(\Tina4\Response $response) {
    return $response("Hello World");
});

\Tina4\Get::add("/users/{id}", function(\Tina4\Response $response, $id) {
    return $response->json(["id" => $id]);
});

// Or map to a class method
\Tina4\Get::add("/test", ["ExampleController", "index"]);
```

### Ruby (DSL-style)
```ruby
get "/hello" do |request, response|
  response.text "Hello World"
end

get "/users/{id}" do |request, response|
  response.json({ id: request.params[:id] })
end
```

### Node.js/TypeScript (decorator or file-based)
```typescript
import { get, post } from 'tina4';

export const hello = get('/hello', async (request, response) => {
  return response.text('Hello World');
});

// File-based: src/routes/users/[id].get.ts auto-maps to GET /users/:id
```

## Middleware

Middleware runs before/after route handlers. Applied per-route or globally.

Class-based middleware is dispatched **by method-name prefix**: every static method whose
name starts with `before_` runs before the handler, every `after_*` runs after. This is a
sharp footgun — **a method named exactly `before` or `after` NEVER runs** (it does not match
the `before_` / `after_` prefix), so an auth check written as `def before(...)` is a **silent
auth bypass**. Always name the method `before_<something>` / `after_<something>`. The methods
are called **synchronously** (regular `def`, not `async def`) — the orchestrator does not
await them.

### Python
```python
from tina4_python import middleware

class AuthCheck:
    @staticmethod
    def before_auth(request, response):       # MUST be `before_*`, not `before`
        token = request.headers.get("Authorization")
        if not token:
            return request, response.json({"error": "Unauthorized"}, 401)
        return request, response

    @staticmethod
    def after_headers(request, response):      # MUST be `after_*`, not `after`
        return request, response

@middleware(AuthCheck)
@get("/protected")
async def protected_route(request, response):
    return response.json({"data": "secret"})
```

## ORM

SQL-first Active Record pattern. Models are auto-discovered from `src/orm/`.

### Python
```python
from tina4_python import (
    ORM, IntegerField, StringField, TextField, BooleanField, ForeignKeyField,
    has_many, has_one, belongs_to,
)

class User(ORM):
    id = IntegerField(primary_key=True, auto_increment=True)
    name = StringField()
    email = StringField()
    bio = TextField(default="")
    is_active = BooleanField(default=True)

# CRUD operations
user = User({"name": "Alice", "email": "alice@example.com"})
user.save()

# select() and where() are CLASSMETHODS that return a plain list[ORM]. There is NO
# chainable .fetch() — the list IS the result. where() takes a filter clause + bound
# params; select() takes FULL SQL (or nothing → SELECT * FROM <table>).
users = User.where("is_active = ?", [True])                     # → list[User]
rows  = User.select("SELECT * FROM user WHERE bio != ?", [""])  # full SQL → list[User]
user  = User.find_by_id(1)                                       # single instance or None
user.name = "Alice Smith"
user.save()
user.delete()

# Serialise for a JSON response — to_dict() per row (ORM objects are not JSON by default):
return response.json([u.to_dict() for u in users])

# Relationships — declare with the v3 descriptors has_many / has_one / belongs_to
# (or a ForeignKeyField). Do NOT assign a dict-list like `has_many = [{"Post": "user_id"}]`:
# that class attribute SHADOWS the descriptor method and silently breaks the relationship.
class Post(ORM):
    id = IntegerField(primary_key=True, auto_increment=True)
    user_id = IntegerField()                       # or: user_id = ForeignKeyField("User")
    title = StringField()
    user = belongs_to("User", foreign_key="user_id")

class User(ORM):
    # ...fields...
    posts = has_many("Post", foreign_key="user_id")

# Soft delete
class Article(ORM):
    soft_delete = True  # Uses the is_deleted column (INTEGER, 0/1)
    # article.delete() sets is_deleted = 1 instead of removing
    # article.restore() sets is_deleted = 0
    # article.force_delete() actually removes
    # Article.with_trashed() is a CLASSMETHOD returning list[Article] incl. deleted
    #   (no .fetch() — e.g. Article.with_trashed("id > ?", [10]))
```

### QueryBuilder — `from()` renamed

The `from()` method has been renamed to `from_table()` (Python/Ruby) and `fromTable()` (PHP/Node.js). The old `from()` method is fully removed with no backward-compatibility alias.

### PHP
```php
class User extends \Tina4\ORM {
    public $id;
    public $name;
    public $email;
    public $primaryKey = "id";
    public $hasMany = [["Post" => "userId"]];
}

$user = new User(["name" => "Alice"]);
$user->save();
$user->load("id = 1");
```

### Paginated Results
All languages return the same JSON structure:
```json
{
    "data": [...],
    "total": 100,
    "page": 1,
    "per_page": 20,
    "total_pages": 5,
    "has_next": true,
    "has_prev": false
}
```

## Database Adapters

All adapters implement the same 16-method interface:

`connect`, `close`, `execute`, `fetch`, `fetch_one`, `insert`, `update`, `delete`,
`start_transaction`, `commit`, `rollback`, `table_exists`, `get_tables`, `get_columns`,
`get_database_type`

### Connection strings

Connection strings must be **identical across all four languages**. A developer switching between
Python, PHP, Ruby, and Node.js should use the exact same connection string format:

```
# These work the same in ALL four frameworks
Database("sqlite:app.db")
Database("postgresql://user:password@localhost:5432/mydb")
Database("mysql://user:password@localhost:3306/mydb")
Database("mssql://user:password@localhost:1433/mydb")
Database("firebird://user:password@localhost:3050/mydb")
Database("mongodb://user:password@localhost:27017/mydb")
```

This is a parity requirement — if you change the connection string format in one language,
it must change in all four.

### Supported databases
SQLite, PostgreSQL, MySQL/MariaDB, MSSQL, Firebird, ODBC, MongoDB (with SQL translation layer)

### Migrations
```bash
tina4 migrate:create "create users table"  # Creates SQL file in migrations/
tina4 migrate                               # Runs pending migrations
```

Migrations are versioned SQL files, executed in order, tracked in a migrations table.

## Response Types

Standardized across all languages:
- `response.json(data, status)` — JSON with Content-Type header
- `response.html(content)` — HTML response
- `response.text(content)` — Plain text
- `response.render("template.twig", data)` — Frond template rendering
- `response.redirect(url)` — HTTP redirect
- `response.file(path)` — File download
- `response.xml(content)` — XML response
- `response.status(code)` — Status-only response

### Response Pipeline
Handler → Frond render → HTML minify (prod) → JSON compact → gzip compress → ETag → Send
