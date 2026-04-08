# Routing, Middleware, ORM & Database

## Routing

Routes are auto-discovered from `src/routes/`. Each language uses its idiomatic style:

### Python (async decorators)
```python
from tina4 import get, post, put, delete

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

### Python
```python
from tina4 import middleware

class AuthCheck:
    @staticmethod
    async def before(request, response):
        token = request.headers.get("Authorization")
        if not token:
            return request, response.json({"error": "Unauthorized"}, 401)
        return request, response

    @staticmethod
    async def after(request, response):
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
from tina4 import ORM, IntegerField, StringField, TextField, BooleanField

class User(ORM):
    id = IntegerField(primary_key=True, auto_increment=True)
    name = StringField()
    email = StringField()
    bio = TextField(default="")
    is_active = BooleanField(default=True)

# CRUD operations
user = User({"name": "Alice", "email": "alice@example.com"})
user.save()

users = User().select("*").where("is_active = ?", [True]).fetch()
user = User().find(1)
user.name = "Alice Smith"
user.save()
user.delete()

# Relationships
class Post(ORM):
    id = IntegerField(primary_key=True, auto_increment=True)
    user_id = IntegerField()
    title = StringField()
    has_one = [{"User": "user_id"}]

class User(ORM):
    # ...fields...
    has_many = [{"Post": "user_id"}]

# Soft delete
class Article(ORM):
    soft_delete = True  # Adds deleted_at column
    # article.delete() sets deleted_at instead of removing
    # article.restore() clears deleted_at
    # article.force_delete() actually removes
    # Article().with_trashed().fetch() includes deleted
```

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
Database("sqlite3:app.db")
Database("pgsql://user:password@localhost:5432/mydb")
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
