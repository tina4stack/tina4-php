# Tina4 v3.0 — AI Reference Specification

## Purpose
This document defines the complete paradigm reference for Tina4 v3.0 across all four languages (PHP, Python, Ruby, Node.js/TypeScript). It should be placed as `CLAUDE.md`, `AI.md`, or `llm.txt` in each framework's repository root so that any AI assistant can work confidently with the framework.

---

## What is Tina4?
Tina4 is a lightweight, zero-dependency web framework available in four languages: PHP, Python, Ruby, and Node.js/TypeScript. All four implementations share identical conventions, features, and API concepts. If you know one, you know them all.

## Project Structure (All Frameworks)
```
project/
├── .env                    # Environment variables
├── src/
│   ├── routes/             # Route handlers (auto-discovered)
│   ├── orm/                # ORM model definitions (auto-discovered)
│   ├── migrations/         # SQL migration files
│   ├── seeds/              # Database seed files
│   ├── templates/          # Frond template files
│   ├── public/             # Static files (served directly)
│   │   ├── js/
│   │   │   └── frond.js
│   │   ├── css/
│   │   └── images/
│   └── locales/            # Translation files (JSON)
├── data/                   # SQLite databases (gitignored)
└── secrets/                # JWT keys (gitignored)
```

## Convention Over Configuration
- Files in `src/routes/` are auto-discovered as route handlers
- Files in `src/orm/` are auto-discovered as ORM models
- Files in `src/migrations/` are run in alphabetical order
- Files in `src/seeds/` are run by the seeder
- Files in `src/templates/` are available to Frond
- Files in `src/public/` are served as static files

## Environment Variables
```env
DATABASE_URL=sqlite:///data/app.db         # Required
TINA4_DEBUG=true                           # Enable debug mode + overlay
TINA4_PORT=7145                            # Server port (default: 7145)
JWT_SECRET=your-secret-key                 # For JWT token signing
TINA4_LANGUAGE=en                          # Default locale
CORS_ORIGINS=*                             # Allowed CORS origins
RATE_LIMIT=60                              # Requests per minute per IP
SESSION_DRIVER=file                        # file|redis|memcache|mongodb|database
SESSION_SECRET=your-session-secret
REDIS_URL=redis://localhost:6379           # If using Redis sessions
```

---

## Routing

### File-Based (Node.js pattern, all frameworks support)
```
src/routes/api/users/get.{ext}           → GET /api/users
src/routes/api/users/post.{ext}          → POST /api/users
src/routes/api/users/[id]/get.{ext}      → GET /api/users/:id
src/routes/api/users/[id]/put.{ext}      → PUT /api/users/:id
src/routes/api/users/[id]/delete.{ext}   → DELETE /api/users/:id
```

### Decorator/Function-Based

**Python:**
```python
from tina4_python import get, post, put, delete

@get("/api/users")
async def get_users(request, response):
    users = User.select()
    return response.json(users)

@get("/api/users/{id}")
async def get_user(request, response):
    user = User.find(request.params["id"])
    return response.json(user)

@post("/api/users")
async def create_user(request, response):
    user = User(request.body)
    user.save()
    return response.json(user, 201)
```

**PHP:**
```php
use Tina4\Route;

Route::get("/api/users", function($request, $response) {
    $users = User::select();
    return $response->json($users);
});

Route::get("/api/users/{id}", function($request, $response) {
    $user = User::find($request->params["id"]);
    return $response->json($user);
});

Route::post("/api/users", function($request, $response) {
    $user = new User($request->body);
    $user->save();
    return $response->json($user, 201);
});
```

**Ruby:**
```ruby
require 'tina4'

get "/api/users" do |request, response|
  users = User.select
  response.json(users)
end

get "/api/users/:id" do |request, response|
  user = User.find(request.params[:id])
  response.json(user)
end

post "/api/users" do |request, response|
  user = User.new(request.body)
  user.save
  response.json(user, 201)
end
```

**TypeScript (Node.js):**
```typescript
// src/routes/api/users/get.ts
import { Tina4Request, Tina4Response } from '@tina4/core';
import User from '../../../models/User';

export default async (req: Tina4Request, res: Tina4Response) => {
  const users = User.select();
  res.json(users);
};
```

### Route Modifiers
```
.middleware([authMiddleware])    # Apply middleware
.cache(true)                    # Enable response caching
.secure(true)                   # Require JWT auth
```

### Response Types (All Frameworks)
```
response.json(data, statusCode)       # JSON response
response.html(content, statusCode)    # HTML response
response.xml(content, statusCode)     # XML response
response.text(content, statusCode)    # Plain text
response.file(filePath)               # File download
response.redirect(url, statusCode)    # Redirect
response.render(template, data)       # Frond template
response.status(code)                 # Set status (chainable)
response.header(name, value)          # Set header
```

---

## ORM (SQL-First)

### Model Definition

**Python:**
```python
from tina4_python import ORM, StringField, IntegerField, DateTimeField

class User(ORM):
    table_name = "users"
    primary_key = "id"
    soft_delete = True

    id = IntegerField(primary_key=True, auto_increment=True)
    name = StringField(max_length=100, required=True)
    email = StringField(max_length=255, required=True)
    created_at = DateTimeField(default="now")
```

**PHP:**
```php
namespace App\Models;

use Tina4\ORM;

class User extends ORM {
    public string $tableName = "users";
    public string $primaryKey = "id";
    public bool $softDelete = true;

    public ?int $id;
    public string $name;
    public string $email;
    public ?string $createdAt;
}
```

**Ruby:**
```ruby
class User < Tina4::ORM
  table_name "users"
  primary_key "id"
  soft_delete true

  integer_field :id, primary_key: true, auto_increment: true
  string_field :name, max_length: 100, required: true
  string_field :email, max_length: 255, required: true
  datetime_field :created_at, default: "now"
end
```

**TypeScript:**
```typescript
export default class User {
  static tableName = "users";
  static primaryKey = "id";
  static softDelete = true;

  static fields = {
    id: { type: "integer" as const, primaryKey: true, autoIncrement: true },
    name: { type: "string" as const, required: true, maxLength: 100 },
    email: { type: "string" as const, required: true, maxLength: 255 },
    createdAt: { type: "datetime" as const, default: "now" },
  };
}
```

### ORM Operations (Same Concepts, All Languages)

| Operation | Python | PHP | Ruby | TypeScript |
|-----------|--------|-----|------|------------|
| Find by ID | `User.find(1)` | `User::find(1)` | `User.find(1)` | `User.find(1)` |
| Find with filter | `User.where("age > ?", [25])` | `User::where("age > ?", [25])` | `User.where("age > ?", [25])` | `User.where("age > ?", [25])` |
| Select all | `User.select()` | `User::select()` | `User.select` | `User.select()` |
| Create | `User(data).save()` | `(new User($data))->save()` | `User.create(data)` | `User.create(data)` |
| Update | `user.name = "Jane"; user.save()` | `$user->name = "Jane"; $user->save()` | `user.name = "Jane"; user.save` | `user.name = "Jane"; user.save()` |
| Delete | `user.delete()` | `$user->delete()` | `user.delete` | `user.delete()` |
| Soft delete | `user.soft_delete()` | `$user->softDelete()` | `user.soft_delete` | `user.softDelete()` |
| Restore | `user.restore()` | `$user->restore()` | `user.restore` | `user.restore()` |
| Force delete | `user.force_delete()` | `$user->forceDelete()` | `user.force_delete` | `user.forceDelete()` |
| With trashed | `User.with_trashed()` | `User::withTrashed()` | `User.with_trashed` | `User.withTrashed()` |
| Count | `User.count("active = 1")` | `User::count("active = 1")` | `User.count("active = 1")` | `User.count("active = 1")` |
| Raw SQL | `User.fetch("SELECT ...")` | `User::fetch("SELECT ...")` | `User.fetch("SELECT ...")` | `User.fetch("SELECT ...")` |
| Raw one | `User.fetch_one("SELECT ...")` | `User::fetchOne("SELECT ...")` | `User.fetch_one("SELECT ...")` | `User.fetchOne("SELECT ...")` |
| Paginate | `User.select(page=2, per_page=20)` | `User::select(page: 2, perPage: 20)` | `User.select(page: 2, per_page: 20)` | `User.select({ page: 2, perPage: 20 })` |

### Paginated Result Format
```json
{
  "data": [{ "id": 1, "name": "John" }, ...],
  "total": 150,
  "page": 2,
  "per_page": 20,
  "total_pages": 8,
  "has_next": true,
  "has_prev": true
}
```

### Relationships
```
# Python
class Post(ORM):
    user = has_one(User, "user_id")
    comments = has_many(Comment, "post_id")

# PHP
class Post extends ORM {
    public array $hasOne = [["User", "user_id"]];
    public array $hasMany = [["Comment", "post_id"]];
}

# Ruby
class Post < Tina4::ORM
  has_one :user, "user_id"
  has_many :comments, "post_id"
end

# TypeScript
export default class Post {
  static relationships = {
    user: { type: "hasOne", model: "User", foreignKey: "userId" },
    comments: { type: "hasMany", model: "Comment", foreignKey: "postId" },
  };
}
```

---

## Database

### Supported Drivers
| Driver | Connection String |
|--------|------------------|
| SQLite | `sqlite:///path/to/db.sqlite` |
| PostgreSQL | `postgresql://user:pass@host:5432/dbname` |
| MySQL | `mysql://user:pass@host:3306/dbname` |
| MSSQL | `mssql://user:pass@host:1433/dbname` |
| Firebird | `firebird://user:pass@host:3050/path/to/db.fdb` |
| ODBC | `odbc://DSN_NAME` |

### Direct Database Usage
```
# All languages support direct SQL
dba = Database(DATABASE_URL)
rows = dba.fetch("SELECT * FROM users WHERE active = ?", [1])
row = dba.fetch_one("SELECT * FROM users WHERE id = ?", [1])
dba.execute("UPDATE users SET name = ? WHERE id = ?", ["John", 1])
```

---

## Frond (Template Engine)

### Syntax Quick Reference
```html
{# Comment — not rendered #}
{{ variable }}                          {# Output (auto-escaped) #}
{{ variable | upper }}                  {# Filter #}
{{ variable | default("N/A") }}        {# Default value #}
{{ obj.property }}                      {# Dot notation #}
{{ obj?.nested?.value }}                {# Null-safe access #}
{{ value ?? "fallback" }}               {# Null coalescing #}
{{ condition ? "yes" : "no" }}          {# Ternary #}
{{ "Hello " ~ name }}                   {# String concatenation #}

{% if condition %}...{% endif %}        {# Conditional #}
{% for item in items %}...{% endfor %}  {# Loop #}
{% set x = value %}                     {# Variable assignment #}
{% extends "base.html" %}              {# Template inheritance #}
{% block name %}...{% endblock %}      {# Block definition #}
{% include "partial.html" %}           {# Include #}
{% macro name(args) %}...{% endmacro %} {# Reusable function #}

{{ variable | raw }}                    {# Disable escaping #}
{% verbatim %}{{ not parsed }}{% endverbatim %} {# Raw output #}
```

### Available Filters
**String:** `upper`, `lower`, `capitalize`, `title`, `trim`, `truncate(n)`, `truncatewords(n)`, `replace(a,b)`, `split(sep)`, `slug`, `nl2br`, `striptags`, `wordwrap(n)`, `urlize`
**Array:** `join(sep)`, `first`, `last`, `length`, `reverse`, `sort`, `sort_natural`, `unique`, `keys`, `values`, `merge(other)`, `slice(start,len)`, `where(key,val)`, `find(key,val)`, `groupby(key)`, `map(key)`, `compact`, `flatten`, `batch(n)`, `sum`
**Number:** `number_format(dec)`, `abs`, `round(prec)`, `ceil`, `floor`, `at_least(n)`, `at_most(n)`, `filesizeformat`
**Date:** `date(format)`, `date_modify(mod)`, `timeago`
**Encoding:** `escape`/`e`, `escape("js")`, `escape("url")`, `escape("css")`, `raw`, `url_encode`, `url_decode`, `json_encode`, `base64_encode`, `base64_decode`, `escape_once`
**Utility:** `default(val)`, `type`

### Template Inheritance
```html
{# base.html #}
<!DOCTYPE html>
<html>
<head><title>{% block title %}Default{% endblock %}</title></head>
<body>{% block content %}{% endblock %}</body>
</html>

{# page.html #}
{% extends "base.html" %}
{% block title %}My Page{% endblock %}
{% block content %}<h1>Hello {{ name }}</h1>{% endblock %}
```

---

## Migrations

### File Naming
```
src/migrations/
  20260319100000_create_users_table.sql        # Up migration
  20260319100000_create_users_table.down.sql   # Rollback migration
```

### CLI Commands
```bash
tina4 migrate                    # Run pending migrations
tina4 migrate:create "description"  # Create migration pair
tina4 migrate:rollback 1         # Rollback last N migrations
```

---

## Sessions
```
# Set session driver via environment
SESSION_DRIVER=file          # File-based (dev)
SESSION_DRIVER=redis         # Redis (production)
SESSION_DRIVER=memcache      # Memcache (production)
SESSION_DRIVER=mongodb       # MongoDB (production)
SESSION_DRIVER=database      # Use connected database
```

---

## JWT Authentication
```
# Python
token = Auth.get_token({"user_id": 1}, expires_in_days=7)
valid = Auth.valid_token(token)
payload = Auth.get_payload(token)

# PHP
$token = Auth::getToken(["user_id" => 1], 7);
$valid = Auth::validToken($token);
$payload = Auth::getPayload($token);

# Ruby
token = Tina4::Auth.get_token({ user_id: 1 }, expires_in_days: 7)
valid = Tina4::Auth.valid_token?(token)
payload = Tina4::Auth.get_payload(token)

# TypeScript
const token = Auth.getToken({ userId: 1 }, 7);
const valid = Auth.validToken(token);
const payload = Auth.getPayload(token);
```

---

## Queue (Database-Backed)
```
# Python
queue = Queue("emails")
queue.push({"to": "user@example.com", "subject": "Welcome"})
message = queue.pop()
queue.complete(message)
queue.fail(message, "SMTP error")

# PHP
$queue = new Queue("emails");
$queue->push(["to" => "user@example.com", "subject" => "Welcome"]);
$message = $queue->pop();
$queue->complete($message);
$queue->fail($message, "SMTP error");
```

---

## CLI Commands

Each framework has its own CLI command: `tina4py`, `tina4php`, `tina4rb`, `tina4js`.

```bash
# Python
tina4py init [name]             # Scaffold new project
tina4py serve [--port 7145]     # Start dev server with hot reload
tina4py migrate                 # Run pending migrations
tina4py migrate:create "desc"   # Create migration files
tina4py migrate:rollback [n]    # Rollback N migrations
tina4py seed                    # Run all seeders
tina4py seed:create "name"      # Create seeder file
tina4py test                    # Run test suite
tina4py routes                  # List all registered routes

# PHP
tina4php init [name]
tina4php serve [--port 7145]
tina4php migrate
# ... same subcommands

# Ruby
tina4rb init [name]
tina4rb serve [--port 7145]
# ... same subcommands

# Node.js
tina4js init [name]
tina4js serve [--port 7145]
# ... same subcommands
```

---

## Auto-CRUD
Models in `src/orm/` automatically generate these endpoints:
```
GET    /api/{table}          # List with filter/sort/paginate
GET    /api/{table}/{id}     # Get single record
POST   /api/{table}          # Create (with validation)
PUT    /api/{table}/{id}     # Update (with validation)
DELETE /api/{table}/{id}     # Delete
```

Query parameters for list endpoint:
```
?filter[name]=John           # Filter by field
?filter[age][gt]=25          # Filter with operator (gt, gte, lt, lte, ne, like)
?sort=name,-created_at       # Sort (prefix - for descending)
?page=2&per_page=20          # Pagination
?search=keyword              # Full-text search
```

---

## Swagger/OpenAPI
Auto-generated at:
- `/swagger` — Interactive Swagger UI
- `/swagger/openapi.json` — Raw OpenAPI 3.0.3 spec

Generated from route definitions and ORM model fields. No annotations or YAML files needed.

---

## Health Check
Auto-registered at `GET /health`:
```json
{
  "status": "ok",
  "database": "connected",
  "uptime_seconds": 3600,
  "version": "3.0.0",
  "framework": "tina4-python"
}
```

---

## Important Conventions
1. **Never use third-party dependencies** for core features — stdlib only
2. **SQL-first** — the ORM wraps SQL, it doesn't hide it
3. **Auto-escaping** — all Frond output is HTML-escaped by default
4. **Convention over configuration** — file location IS configuration
5. **Same tests everywhere** — identical test names and scenarios across all four languages
6. **Structured logging** — JSON in production, human-readable in dev
7. **DATABASE_URL** — single env var for database connection
8. **Graceful shutdown** — always handle SIGTERM/SIGINT
