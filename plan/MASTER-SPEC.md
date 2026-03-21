# Tina4 v3.0 — Master Feature Specification

> **Version:** 3.0.0
> **Last updated:** 2026-03-21
> **Parity status:** ALL 4 FRAMEWORKS AT 100%
> **Test totals:** Python 1,165 | PHP 1,166 | Ruby 1,334 | Node.js 1,247 | Grand total: 4,912

This document is the single source of truth for every Tina4 v3 feature. Each entry defines what the feature does, its environment variables (with defaults), its language-agnostic API contract, the priority chain for configuration, and parity status across all four frameworks.

**Priority chain (applies to ALL features):** constructor argument > `.env` file > hardcoded default.

**Naming convention:** Python and Ruby use `snake_case`. PHP and Node.js use `camelCase`. The pseudocode below uses `snake_case`; each language adapts to its convention.

---

## Table of Contents

1. [Core Web](#1-core-web)
2. [Data](#2-data)
3. [Auth & Security](#3-auth--security)
4. [Queue & Background](#4-queue--background)
5. [Template & Frontend](#5-template--frontend)
6. [API & Integration](#6-api--integration)
7. [Communication](#7-communication)
8. [Caching](#8-caching)
9. [Developer Experience](#9-developer-experience)
10. [Architecture](#10-architecture)
11. [Configuration](#11-configuration)

---

## 1. Core Web

---

### 1.1 Router

**What it does:** Maps HTTP methods and URL patterns to handler functions with support for path parameters, middleware, caching, auth gating, and template responses.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| _None_ | — | Router is code-configured, not env-configured |

**API:**

```
# Registration (module-level decorators / static methods / DSL)
get(path, handler, options?)
post(path, handler, options?)
put(path, handler, options?)
patch(path, handler, options?)
delete(path, handler, options?)
any(path, handler, options?)

# Options / modifiers (chainable)
.middleware([fn, ...])       # Attach middleware functions
.secure()                    # Require valid JWT (401 if missing/invalid)
.cache(ttl_seconds?)         # Cache response (default TTL from TINA4_CACHE_TTL)
.noauth()                    # Explicitly skip auth even if global auth is on

# Path parameters
"/api/users/{id}"            # Basic parameter
"/api/users/{id:int}"        # Typed parameter (auto-validates)
"/pages/{slug:.*}"           # Catch-all / wildcard

# Route groups
group(prefix, middleware?, callback)

# Template shorthand
get("/", template: "index.html", data?)
```

**Handler signature:**
```
handler(request, response) -> response
```

**Priority chain:** Code registration only. File-based route discovery from `src/routes/` supplements decorator-based routes.

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 1.2 Request

**What it does:** Provides parsed access to the incoming HTTP request including body, URL parameters, headers, uploaded files, session, and client metadata.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| _None_ | — | Request is runtime-only |

**API:**

```
class Request:
    body: dict | string       # Parsed JSON/form body (auto-detected)
    params: dict              # Path parameters ({id} -> params["id"])
    query: dict               # Query string parameters (?page=2)
    headers: dict             # HTTP headers (case-insensitive keys)
    files: dict               # Uploaded files {name -> FileUpload}
    session: Session          # Session object (read/write)
    url: string               # Full request URL
    path: string              # URL path without query string
    method: string            # HTTP method (GET, POST, etc.)
    ip: string                # Client IP address
    content_type: string      # Content-Type header value
    request_id: string        # Unique request ID (from header or generated)
    cookies: dict             # Parsed cookies
    is_ajax: bool             # True if X-Requested-With: XMLHttpRequest
    is_json: bool             # True if Content-Type contains json
```

**Priority chain:** N/A (read-only from incoming request).

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 1.3 Response

**What it does:** Builds and sends HTTP responses with support for JSON, HTML, text, redirects, file downloads, template rendering, status codes, headers, and cookies.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| _None_ | — | Response is runtime-only |

**API:**

```
class Response:
    json(data, status_code=200)          # JSON response (auto Content-Type)
    html(content, status_code=200)       # HTML response
    text(content, status_code=200)       # Plain text response
    xml(content, status_code=200)        # XML response
    redirect(url, status_code=302)       # HTTP redirect (302 or 301)
    file(path)                           # File download (auto Content-Type)
    render(template, data={})            # Render Frond template to HTML
    template(name, data={})              # Alias for render()
    status(code) -> self                 # Set status code (chainable)
    header(name, value) -> self          # Set response header (chainable)
    cookie(name, value, options={})      # Set cookie (max_age, path, secure, httponly, samesite)
```

**Priority chain:** N/A (output-only).

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 1.4 Static Files

**What it does:** Serves static assets (JS, CSS, images, fonts) from `src/public/` with correct MIME types, ETag support, and framework asset fallback.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| _None_ | — | Convention-based: files in `src/public/` are served at `/` |

**API:**

```
# No explicit API — convention-based
# File at src/public/js/app.js -> served at /js/app.js
# File at src/public/css/style.css -> served at /css/style.css

# Framework fallback: if not found in src/public/, checks framework's
# built-in assets (frond.js, tina4css, swagger-ui, debug overlay)
```

**Priority chain:** Project `src/public/` > framework built-in assets.

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 1.5 CORS

**What it does:** Handles Cross-Origin Resource Sharing preflight (OPTIONS) and response headers based on environment configuration.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| `CORS_ORIGINS` | `*` | Comma-separated allowed origins |
| `CORS_METHODS` | `GET,POST,PUT,DELETE` | Allowed HTTP methods |
| `CORS_HEADERS` | `Content-Type,Authorization` | Allowed request headers |
| `CORS_CREDENTIALS` | `true` | Allow credentials (cookies, auth) |
| `CORS_MAX_AGE` | `86400` | Preflight cache duration in seconds |

**API:**

```
# Auto-configured from environment — no code needed
# Preflight OPTIONS requests are handled automatically
# Response headers set on every response:
#   Access-Control-Allow-Origin
#   Access-Control-Allow-Methods
#   Access-Control-Allow-Headers
#   Access-Control-Allow-Credentials
#   Access-Control-Max-Age
```

**Priority chain:** constructor > `.env` > defaults above.

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 1.6 Rate Limiter

**What it does:** Limits requests per IP address using a sliding window algorithm. Returns 429 with Retry-After header when exceeded.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| `TINA4_RATE_LIMIT` | `60` | Max requests per window per IP |
| `TINA4_RATE_WINDOW` | `60` | Window duration in seconds |

**API:**

```
# Auto-configured from environment — no code needed
# Per-route override via route modifier:
get("/api/expensive", handler).rate_limit(10, 60)  # 10 req/min for this route

# Response headers on every request:
#   X-RateLimit-Limit: 60
#   X-RateLimit-Remaining: 45
#   X-RateLimit-Reset: 1679512800

# When exceeded:
#   HTTP 429 Too Many Requests
#   Retry-After: 15
```

**Priority chain:** Per-route > `.env` > defaults above.

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 1.7 Health Check

**What it does:** Auto-registers a `GET /health` endpoint returning JSON with application status, database connectivity, uptime, version, and error count.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| _None_ | — | Always registered, no configuration needed |

**API:**

```
# Auto-registered — no code needed
# GET /health -> 200 (healthy) or 503 (unhealthy)

# Response format:
{
  "status": "ok" | "error",
  "database": "connected" | "disconnected",
  "uptime_seconds": 3600,
  "version": "3.0.0",
  "framework": "tina4-{language}",
  "errors": 0
}

# Returns 503 when .broken files exist (see Error Handling)
```

**Priority chain:** N/A (always active).

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

## 2. Data

---

### 2.1 Database

**What it does:** Provides a unified database adapter interface across 7 drivers (SQLite, PostgreSQL, MySQL, MSSQL, Firebird, ODBC, MongoDB) with connection string parsing, transactions, and schema introspection.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| `DATABASE_URL` | `sqlite:///data/app.db` | Connection string (see format below) |
| `DATABASE_USERNAME` | _(from URL)_ | Override username |
| `DATABASE_PASSWORD` | _(from URL)_ | Override password |

**Connection string formats:**
```
sqlite:///data/app.db
postgresql://user:pass@host:5432/dbname
mysql://user:pass@host:3306/dbname
mssql://user:pass@host:1433/dbname
firebird://user:pass@host:3050/path/to/db.fdb
odbc://DSN_NAME
mongodb://user:pass@host:27017/dbname
```

**API:**

```
class Database:
    constructor(connection_string, username?, password?)

    connect()
    close()
    execute(sql, params=[]) -> affected_rows
    fetch(sql, params=[]) -> [rows]
    fetch_one(sql, params=[]) -> row | null
    insert(table, data) -> last_id
    update(table, data, filter) -> affected_rows
    delete(table, filter) -> affected_rows
    start_transaction()
    commit()
    rollback()
    table_exists(name) -> boolean
    get_tables() -> [table_names]
    get_columns(table) -> [column_definitions]
    get_database_type() -> string
```

**Priority chain:** constructor args > `DATABASE_USERNAME`/`DATABASE_PASSWORD` env > credentials in `DATABASE_URL` > defaults.

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

**Notes:** MongoDB uses a built-in SQL-to-MongoDB translator. Developers write SQL; the adapter translates. Database drivers are the one exception to zero-dependency (native connectors are required per driver). Only install drivers you need.

---

### 2.2 DB Query Cache

**What it does:** Caches database query results in memory with TTL-based expiration to reduce repeated identical queries.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| `TINA4_DB_CACHE` | `true` | Enable query result caching |
| `TINA4_DB_CACHE_TTL` | `60` | Cache TTL in seconds |

**API:**

```
# Automatic — identical queries within TTL return cached results
# Writes (INSERT/UPDATE/DELETE) invalidate relevant cache entries

# Manual control on ORM:
Model.select(cache=false)           # Skip cache for this query
Model.select(cache_ttl=300)         # Custom TTL for this query
```

**Priority chain:** Per-query option > `.env` > defaults above.

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 2.3 ORM

**What it does:** SQL-first Active Record ORM with model definitions, CRUD operations, relationships, soft delete, scopes, field mapping, validation, pagination, and raw SQL access.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| _None_ | — | ORM uses the database configured via `DATABASE_URL` |

**API:**

```
class Model (extends ORM):
    # Class-level configuration
    table_name: string                    # Database table name
    primary_key: string = "id"            # Primary key column
    soft_delete: boolean = false          # Enable soft delete (deleted_at column)
    field_mapping: dict = {}              # {property_name: column_name}
    table_filter: string | null = null    # Default WHERE clause for all queries

    # Field definitions
    id = IntegerField(primary_key=true, auto_increment=true)
    name = StringField(max_length=100, required=true)
    email = StringField(max_length=255, required=true)
    created_at = DateTimeField(default="now")

    # Instance methods
    save() -> self                        # Insert or update (auto-detects)
    delete() -> boolean                   # Hard delete (or soft if enabled)
    load(filter, params=[]) -> self | null
    to_dict() -> dict                     # Serialize to dictionary
    to_json() -> string                   # Serialize to JSON string

    # Class methods (static)
    select(filter?, params?, page?, per_page?) -> PaginatedResult
    find(id) -> self | null
    where(filter, params=[]) -> [self]
    count(filter?) -> int
    create(data) -> self
    create_table() -> void                # Create table from field definitions
    fetch(sql, params=[]) -> [rows]       # Raw SQL, returns rows
    fetch_one(sql, params=[]) -> row | null

    # Relationships
    has_one(related_model, foreign_key) -> related | null
    has_many(related_model, foreign_key, options?) -> [related]
    belongs_to(related_model, foreign_key) -> related | null

    # Soft delete (when soft_delete=true)
    soft_delete() -> self                 # Set deleted_at timestamp
    restore() -> self                     # Clear deleted_at
    force_delete() -> boolean             # Permanent delete
    with_trashed() -> query modifier      # Include soft-deleted records

    # Scopes
    scope(name, filter_fn)                # Register reusable query filter

# PaginatedResult format:
{
  "data": [...],
  "total": 150,
  "page": 2,
  "per_page": 20,
  "total_pages": 8,
  "has_next": true,
  "has_prev": true
}
```

**Priority chain:** N/A (code-configured).

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 2.4 Migrations

**What it does:** Manages database schema changes through versioned SQL files with forward and rollback support.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| _None_ | — | Migrations live in `src/migrations/` by convention |

**API:**

```
# CLI commands
tina4 migrate                           # Run all pending migrations
tina4 migrate:create "description"      # Create migration pair (up + down)
tina4 migrate:rollback [n]              # Rollback last N migrations (default: 1)

# File naming convention:
# src/migrations/20260319100000_create_users_table.sql        (up)
# src/migrations/20260319100000_create_users_table.down.sql   (rollback)

# Migration tracking table (auto-created):
# tina4_migrations(id, filename, ran_at)
```

**Priority chain:** N/A (convention-based).

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 2.5 Seeder / FakeData

**What it does:** Generates realistic fake data for testing and development. Seed files in `src/seeds/` populate the database with sample records.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| _None_ | — | Seeds live in `src/seeds/` by convention |

**API:**

```
# CLI commands
tina4 seed                              # Run all seed files
tina4 seed:create "name"                # Create a seed file

# FakeData generator (50+ generators)
class FakeData:
    name() -> string                    # "John Smith"
    first_name() -> string              # "John"
    last_name() -> string               # "Smith"
    email() -> string                   # "john.smith@example.com"
    phone() -> string                   # "+1-555-0123"
    integer(min=0, max=100) -> int      # 42
    decimal(min=0, max=100, precision=2) -> float  # 42.17
    boolean() -> bool                   # true/false
    sentence(words=10) -> string        # "The quick brown fox..."
    paragraph(sentences=5) -> string
    date(start?, end?) -> string        # "2026-01-15"
    datetime(start?, end?) -> string    # "2026-01-15T10:30:00Z"
    uuid() -> string                    # "550e8400-e29b-41d4-a716-446655440000"
    address() -> string
    city() -> string
    country() -> string
    url() -> string
    ip_address() -> string
    color() -> string
    company() -> string
    job_title() -> string
    pick(list) -> any                   # Random item from list
```

**Priority chain:** N/A (code-configured).

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

## 3. Auth & Security

---

### 3.1 Auth (JWT)

**What it does:** Zero-dependency JWT implementation (HS256/RS256) for token creation, validation, and payload extraction, plus password hashing utilities.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| `JWT_SECRET` | _(required if auth used)_ | HMAC secret for HS256 |
| `JWT_ALGORITHM` | `HS256` | Algorithm: `HS256` or `RS256` |
| `JWT_EXPIRY_DAYS` | `7` | Default token expiration in days |

**API:**

```
class Auth:
    # Token operations
    get_token(payload, expires_in_days?) -> string       # Create JWT
    create_token(payload, expires_in_days?) -> string    # Alias for get_token
    valid_token(token) -> boolean                        # Validate JWT signature + expiry
    validate_token(token) -> boolean                     # Alias for valid_token
    get_payload(token) -> dict | null                    # Decode JWT payload

    # Password operations
    hash_password(plain_text) -> string                  # PBKDF2/bcrypt hash
    check_password(plain_text, hashed) -> boolean        # Verify password against hash
```

**Priority chain:** constructor args > `.env` > defaults above.

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 3.2 Sessions

**What it does:** Server-side session management with pluggable backends. Session data persists across requests via a session cookie.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| `TINA4_SESSION_HANDLER` | `file` | Backend: `file`, `redis`, `valkey`, `mongo`, `database` |
| `SESSION_SECRET` | _(required)_ | Secret for session cookie signing |
| `REDIS_URL` | `redis://localhost:6379` | Redis/Valkey connection URL |
| `MONGODB_URL` | `mongodb://localhost:27017` | MongoDB connection URL |
| `SESSION_TTL` | `3600` | Session expiry in seconds |

**API:**

```
# Accessed via request.session in route handlers
request.session["key"] = value           # Set
value = request.session["key"]           # Get
del request.session["key"]              # Delete
request.session.clear()                  # Clear all
request.session.regenerate()             # New session ID (prevent fixation)
```

**Priority chain:** constructor > `.env` > defaults above.

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 3.3 CSRF (form_token)

**What it does:** Generates and validates CSRF tokens for form submissions. Prevents cross-site request forgery.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| _None_ | — | Uses session secret for token generation |

**API:**

```
# In Frond templates:
{{ form_token() }}
# Renders: <input type="hidden" name="_token" value="...">

# In route handlers (auto-validated on POST/PUT/PATCH/DELETE):
# Returns 403 if token missing or invalid

# Manual validation:
Auth.validate_csrf(token) -> boolean
```

**Priority chain:** N/A (session-based).

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

## 4. Queue & Background

---

### 4.1 Queue

**What it does:** Production-grade message queue with database-backed core (zero-dep) and optional RabbitMQ/Kafka backends. Supports priority, delayed jobs, retry with exponential backoff, dead letter queues, batch processing, requeue, and fallback chains.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| `TINA4_QUEUE_BACKEND` | `database` | Backend: `database`, `rabbitmq`, `kafka` |
| `RABBITMQ_URL` | — | AMQP connection URL (when backend=rabbitmq) |
| `KAFKA_BROKERS` | — | Kafka broker list (when backend=kafka) |
| `KAFKA_GROUP_ID` | `tina4-workers` | Kafka consumer group |
| `QUEUE_DRIVER` | `database` | Alias for `TINA4_QUEUE_BACKEND` |
| `QUEUE_FALLBACK_DRIVER` | — | Fallback backend if primary is down |
| `QUEUE_FAILOVER_TIMEOUT` | `300` | Seconds without pop before failover |
| `QUEUE_FAILOVER_DEPTH` | `10000` | Max queue depth before failover |
| `QUEUE_FAILOVER_ERROR_RATE` | `50` | Error rate % before failover |
| `QUEUE_CIRCUIT_BREAKER_THRESHOLD` | `5` | Consecutive failures to trip breaker |
| `QUEUE_CIRCUIT_BREAKER_COOLDOWN` | `30` | Seconds before half-open retry |

**API:**

```
class Queue:
    constructor(queue_name, driver?, fallback_driver?)

    # Core operations
    push(queue_name, payload, options?) -> message_id
        # options: priority (int), delay (seconds), available_at (ISO timestamp), max_attempts (int)
    pop(queue_name, options?) -> Message | null
    pop_batch(queue_name, count) -> [Message]
    complete(message) -> void
    fail(message, reason) -> void
    retry(message) -> void
    size(queue_name) -> int
    purge(queue_name) -> void

    # Retrieval and search
    get(message_id) -> Message | null
    find(queue_name, filter?, options?) -> [Message]

    # Dead letter management
    dead_letters(queue_name) -> [Message]
    retry_failed(queue_name) -> int           # Retry all dead letters
    purge_dead_letters(queue_name) -> void

    # Requeue and failover
    requeue(message, target_queue, payload?, delay?) -> void
    set_fallback(queue_name, fallback_queue) -> void
    set_fallback_chain(queue_name, [queue_names]) -> void

    # Worker pattern
    worker(handler, concurrency=1, poll_interval=1)
    subscribe(queue_name, handler) -> void

# Message object:
{
    "id": "uuid-v7",
    "queue": "emails",
    "payload": { ... },
    "status": "pending|reserved|completed|failed|dead_letter",
    "attempts": 0,
    "max_attempts": 3,
    "original_queue": "emails",
    "priority": 0,
    "created_at": "ISO timestamp",
    "available_at": "ISO timestamp",
    "reserved_at": null,
    "completed_at": null,
    "failed_at": null,
    "error": null
}
```

**Priority chain:** constructor > `.env` > defaults above.

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 4.2 Producer / Consumer Pattern

**What it does:** Decorator/callback-based worker registration for queue consumers. Workers auto-complete on success and auto-fail with retry on exception.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| _None_ | — | Configured in code via worker registration |

**API:**

```
# Python
@queue.worker(concurrency=1, poll_interval=1)
def process(message):
    # process message
    # return without error = auto-complete
    # raise exception = auto-fail with retry

# PHP
$queue->worker(function($message) { ... }, concurrency: 1, pollInterval: 1);

# Ruby
queue.worker(concurrency: 1, poll_interval: 1) { |message| ... }

# Node.js
queue.worker(async (message) => { ... }, { concurrency: 1, pollInterval: 1 });
```

**Priority chain:** N/A (code-configured).

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 4.3 Service Runner

**What it does:** Cron-style scheduled task execution. Background services run at specified intervals within the application process.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| _None_ | — | Services are code-registered |

**API:**

```
# Python
@service(interval="*/5 * * * *")    # Every 5 minutes (cron syntax)
def cleanup():
    # do work

@service(interval=60)              # Every 60 seconds (simple interval)
def heartbeat():
    # do work

# PHP
Service::register("*/5 * * * *", function() { ... });

# Ruby
service("*/5 * * * *") { ... }

# Node.js
service("*/5 * * * *", async () => { ... });
```

**Priority chain:** N/A (code-configured).

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

## 5. Template & Frontend

---

### 5.1 Frond Template Engine

**What it does:** Zero-dependency, twig-like template engine implemented from scratch in each language. Identical syntax across all four frameworks. Includes lexer, parser, compiler, and cached runtime.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| _None_ | — | Template directory is `src/templates/` by convention |

**API:**

```
class Frond:
    constructor(template_dir="src/templates")

    render(template_name, data={}) -> string
    render_string(template_string, data={}) -> string
    add_filter(name, fn) -> void
    add_global(name, value) -> void
    add_test(name, fn) -> void
    add_function(name, fn) -> void
```

**Frond syntax features:**

| Feature | Syntax |
|---------|--------|
| Variables | `{{ variable }}`, `{{ obj.property }}`, `{{ items[0] }}` |
| Filters | `{{ name \| upper }}`, `{{ text \| truncate(100) }}` |
| If/Elseif/Else | `{% if cond %}...{% elseif cond %}...{% else %}...{% endif %}` |
| For loops | `{% for item in items %}...{% endfor %}` |
| Loop variables | `loop.index`, `loop.index0`, `loop.first`, `loop.last`, `loop.length` |
| Extends/Block | `{% extends "base.html" %}`, `{% block name %}...{% endblock %}` |
| Include | `{% include "partial.html" %}`, `{% include "x.html" with {data} %}` |
| Macro | `{% macro name(args) %}...{% endmacro %}` |
| From/Import | `{% from "macros.html" import input, select %}` |
| Set | `{% set x = value %}` |
| Comments | `{# comment #}` |
| Whitespace control | `{%- tag -%}`, `{{- var -}}` |
| Ternary | `{{ cond ? "yes" : "no" }}` |
| Inline if | `{{ value if condition }}` |
| Null coalescing | `{{ value ?? "default" }}` |
| Raw/Endraw | `{% raw %}{{ not parsed }}{% endraw %}` |
| Verbatim | `{% verbatim %}...{% endverbatim %}` (alias for raw) |
| Spaceless | `{% spaceless %}...{% endspaceless %}` |
| Autoescape | `{% autoescape "html" %}...{% endautoescape %}` |
| Cache | `{% cache "key" ttl %}...{% endcache %}` (fragment caching) |
| String concat | `{{ "Hello " ~ name }}` |
| Tests | `{% if x is defined %}`, `is empty`, `is null`, `is even`, `is odd`, `is iterable` |

**Built-in filters (~55):**
- **String:** `upper`, `lower`, `capitalize`, `title`, `trim`, `truncate(n)`, `truncatewords(n)`, `replace(a,b)`, `split(sep)`, `slug`, `nl2br`, `striptags`, `wordwrap(n)`, `urlize`
- **Array:** `join(sep)`, `first`, `last`, `length`, `reverse`, `sort`, `sort_natural`, `unique`, `keys`, `values`, `merge(other)`, `slice(start,len)`, `where(key,val)`, `find(key,val)`, `groupby(key)`, `map(key)`, `compact`, `flatten`, `batch(n)`, `sum`
- **Number:** `number_format(dec)`, `abs`, `round(prec)`, `ceil`, `floor`, `at_least(n)`, `at_most(n)`, `filesizeformat`
- **Date:** `date(format)`, `date_modify(mod)`, `timeago`
- **Encoding:** `escape`/`e`, `escape("js")`, `escape("url")`, `escape("css")`, `raw`, `url_encode`, `url_decode`, `json_encode`, `base64_encode`, `base64_decode`, `escape_once`
- **Utility:** `default(val)`, `type`

**Priority chain:** N/A (convention-based).

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 5.2 Template Route Integration

**What it does:** Shorthand for rendering templates directly from route definitions without a handler function.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| _None_ | — | Uses Frond engine |

**API:**

```
# Python — @template decorator or template keyword
@get("/")
@template("index.html")
def home():
    return {"title": "Home"}

# or
get("/", template="index.html", data={"title": "Home"})

# PHP
Route::get("/", template: "index.html", data: ["title" => "Home"]);

# Ruby
get "/", template: "index.html", data: { title: "Home" }

# Node.js — export const template
export const template = "index.html";
export default (req, res) => ({ title: "Home" });
```

**Priority chain:** N/A (code-configured).

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 5.3 tina4css

**What it does:** Built-in CSS framework shipped with every Tina4 project. Provides utility classes for layout, typography, and common UI patterns without external CSS dependencies.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| _None_ | — | Served from `src/public/css/tina4.css` |

**API:**

```
# No code API — CSS utility classes used in HTML templates
# Auto-included in scaffolded projects
```

**Priority chain:** N/A.

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 5.4 SCSS Compiler

**What it does:** Zero-dependency SCSS-to-CSS compiler. Auto-compiles `.scss` files from `src/scss/` (or `src/public/scss/`) to `src/public/css/` on file change during development.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| _None_ | — | Convention-based: `src/scss/` or `src/public/scss/` input, `src/public/css/` output |

**API:**

```
# Automatic in dev mode — file watcher triggers compilation
# Supports: variables, nesting, mixins, math, imports, partials
# No explicit API needed — convention-driven
```

**Priority chain:** N/A (convention-based).

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 5.5 frond.min.js

**What it does:** Lightweight (<10KB), zero-dependency JavaScript helper library for AJAX requests, WebSocket reconnection, form handling, CRUD tables, modals, toast notifications, auth token management, and live block integration.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| _None_ | — | Served from `src/public/js/frond.js` |

**API (client-side JavaScript):**

```
// HTTP client
Frond.get(url, options?) -> Promise
Frond.post(url, data, options?) -> Promise
Frond.put(url, data, options?) -> Promise
Frond.patch(url, data, options?) -> Promise
Frond.delete(url, options?) -> Promise

// Auth token (auto-attached to requests)
Frond.setToken(jwt)
Frond.getToken() -> string | null
Frond.clearToken()

// Form handling
Frond.submitForm(selector, options)
Frond.formData(selector) -> object
Frond.fillForm(selector, data)
Frond.resetForm(selector)

// CRUD table
Frond.crud(selector, { endpoint, columns, labels, searchable, paginate, pageSize, actions })
Frond.refreshCrud(selector)

// Modal / notifications
Frond.modal({ url, title, size, onClose })
Frond.confirm(message, options) -> Promise<boolean>
Frond.notify(message, type, options?)    // type: success|error|warning|info

// DOM utilities
Frond.el(selector) -> Element | null
Frond.els(selector) -> [Element]
Frond.on(selector, event, handler)
Frond.show(selector) / Frond.hide(selector) / Frond.toggle(selector)
Frond.load(selector, url) -> Promise

// Reconnecting WebSocket
Frond.ws(path, options?) -> WebSocketClient
    // options: reconnect, maxRetries, baseDelay, maxDelay, backoffMultiplier,
    //          heartbeat, protocols
    // Events: message, open, close, reconnecting, reconnected, error, max_retries
    // Methods: send(data), close(), destroy()

// Live block control (auto-powered from {% live %} tags)
Frond.live.pause(name)
Frond.live.resume(name)
Frond.live.refresh(name)

// Data binding (from {% data %} tags)
Frond.data(name) -> any | null

// Configuration
Frond.config({ baseUrl, tokenStorage, defaultHeaders, csrfToken, notificationPosition })
```

**Priority chain:** N/A (client-side).

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

## 6. API & Integration

---

### 6.1 Swagger / OpenAPI

**What it does:** Auto-generates OpenAPI 3.0.3 specification from route definitions and ORM model fields. Serves interactive Swagger UI at `/swagger` and raw spec at `/swagger/openapi.json`.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| `TINA4_DEBUG` | `true` | Swagger UI auto-registered when debug=true |

**API:**

```
# Auto-generated from routes — no code needed for basic docs

# Route annotations for enriched docs:
@get("/api/users")
@description("List all users with pagination")
@tags("Users")
@example({"data": [{"id": 1, "name": "John"}], "total": 1})
def get_users(request, response):
    ...

# Endpoints:
# GET /swagger          -> Interactive Swagger UI
# GET /swagger/openapi.json -> Raw OpenAPI 3.0.3 spec
```

**Priority chain:** Route annotations > auto-generated from route patterns.

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 6.2 GraphQL

**What it does:** Zero-dependency GraphQL engine with schema definition, ORM integration, query/mutation execution, and GraphiQL playground.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| _None_ | — | Code-configured |

**API:**

```
class GraphQL:
    constructor(schema?)

    # Schema definition
    from_orm(model_class) -> void         # Auto-generate type from ORM model
    add_type(name, fields) -> void
    add_query(name, resolver) -> void
    add_mutation(name, resolver) -> void

    # Execution
    execute(query, variables?, context?) -> result

# Routes auto-registered:
# POST /graphql          -> Execute query
# GET  /graphql          -> GraphiQL playground (debug mode)
```

**Priority chain:** N/A (code-configured).

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 6.3 SOAP / WSDL

**What it does:** Zero-dependency SOAP service endpoint with auto-generated WSDL from annotated operations.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| _None_ | — | Code-configured |

**API:**

```
# Python
@wsdl_operation("GetUser", input={"id": int}, output={"name": str, "email": str})
def get_user(id):
    user = User.find(id)
    return {"name": user.name, "email": user.email}

# Auto-registered endpoints:
# GET  /wsdl          -> Auto-generated WSDL XML
# POST /soap          -> SOAP request handler
```

**Priority chain:** N/A (code-configured).

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 6.4 HTTP Client (Api)

**What it does:** Stdlib-based HTTP client for making outbound requests. No third-party HTTP libraries (no requests, no axios, no curl wrappers).

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| _None_ | — | Code-configured per instance |

**API:**

```
class Api:
    constructor(base_url?)

    get(url, params?, headers?) -> Response
    post(url, data?, headers?) -> Response
    put(url, data?, headers?) -> Response
    patch(url, data?, headers?) -> Response
    delete(url, headers?) -> Response
    send(method, url, data?, headers?) -> Response

    add_headers(headers) -> self          # Set default headers for all requests
    set_basic_auth(username, password) -> self

# Response object:
{
    status_code: int,
    body: string,
    json: dict | null,
    headers: dict
}
```

**Priority chain:** Constructor args > per-request args.

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 6.5 WebSocket

**What it does:** Zero-dependency RFC 6455 WebSocket server implemented from scratch in each language. Supports text/binary frames, ping/pong, broadcasting, connection management, and channel-based routing.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| `TINA4_WS_MAX_FRAME_SIZE` | `1048576` | Max frame size in bytes (1MB) |
| `TINA4_WS_MAX_CONNECTIONS` | `10000` | Max concurrent connections |
| `TINA4_WS_PING_INTERVAL` | `30` | Server ping interval in seconds |
| `TINA4_WS_PING_TIMEOUT` | `10` | Pong timeout before disconnect |

**API:**

```
# Route registration (same pattern as HTTP routes)
websocket(path, handler)

# WebSocketConnection (provided to handler)
class WebSocketConnection:
    id: string                                # Unique connection ID
    ip: string                                # Client IP
    path: string                              # WebSocket route path
    headers: dict                             # Original HTTP headers
    params: dict                              # URL parameters
    connected_at: datetime

    send(message: string | bytes)             # Send to this connection
    send_json(data) -> void                   # Send JSON (auto-serialized)
    broadcast(message, exclude_self=false)     # Send to all on same path
    broadcast_to(path, message)               # Send to all on different path
    close(code=1000, reason="")               # Close connection
    ping()                                    # Send ping frame

    on_message(handler)
    on_close(handler)
    on_error(handler)

# WebSocketManager (internal, for server-side operations)
    connections: dict                          # {id -> connection}
    get(id) -> connection | null
    get_by_path(path) -> [connections]
    count() -> int
    broadcast(path, message)
    broadcast_all(message)
    disconnect(id)
```

**Priority chain:** `.env` > defaults above.

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

**Notes:** PHP auto-detects Swoole for better WebSocket performance. Falls back to stream_socket implementation without Swoole.

---

## 7. Communication

---

### 7.1 Messenger (Email)

**What it does:** SMTP email client for sending and reading email. Zero-dependency — uses stdlib socket/stream connections.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| `TINA4_MAIL_HOST` | — | SMTP server hostname |
| `TINA4_MAIL_PORT` | `587` | SMTP server port |
| `TINA4_MAIL_USERNAME` | — | SMTP username |
| `TINA4_MAIL_PASSWORD` | — | SMTP password |
| `TINA4_MAIL_FROM` | — | Default sender address |
| `TINA4_MAIL_ENCRYPTION` | `tls` | Encryption: `tls`, `ssl`, `none` |

**API:**

```
class Messenger:
    constructor(host?, port?, username?, password?, from?, encryption?)

    send(to, subject, body, options?) -> boolean
        # options: cc, bcc, reply_to, attachments, html (boolean), from
    inbox(folder="INBOX", limit=20) -> [Message]
    read(message_id) -> Message
```

**Priority chain:** constructor > `.env` > defaults above.

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

## 8. Caching

---

### 8.1 Response Cache

**What it does:** Caches full HTTP responses for routes marked with `.cache()`. Supports memory, Redis, and file backends. Includes ETag support for zero-bandwidth repeated requests.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| `TINA4_CACHE_BACKEND` | `memory` | Backend: `memory`, `redis`, `file` |
| `TINA4_CACHE_TTL` | `300` | Default cache TTL in seconds |
| `TINA4_CACHE_MAX_ENTRIES` | `1000` | Max cached entries (memory backend) |

**API:**

```
# Route-level caching:
get("/api/data", handler).cache(ttl=60)        # Cache for 60 seconds
get("/api/data", handler).cache()              # Cache with default TTL

# Manual cache control in handlers:
response.no_cache()                             # Skip caching for this response
response.cache_ttl(120)                         # Override TTL for this response

# ETag support (automatic):
# Response includes ETag header
# If-None-Match from client -> 304 Not Modified (zero body)
```

**Priority chain:** Per-route > `.env` > defaults above.

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

## 9. Developer Experience

---

### 9.1 CLI

**What it does:** Rust-based unified CLI (`tina4`) that auto-detects the project language and dispatches to the correct framework runtime. Identical commands across all four frameworks.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| _None_ | — | CLI reads `.env` and project files for context |

**API:**

```
tina4 init [dir]                # Scaffold a new project
tina4 serve [--port PORT]       # Start dev server with hot reload
tina4 migrate                   # Run pending migrations
tina4 migrate:create "desc"     # Create migration pair
tina4 migrate:rollback [n]      # Rollback last N migrations
tina4 seed                      # Run all seed files
tina4 seed:create "name"        # Create seed file
tina4 test                      # Run test suite
tina4 routes                    # List all registered routes
tina4 doctor                    # Check environment, deps, connectivity

# Per-language aliases also available:
# tina4py, tina4php, tina4rb, tina4js
```

**Priority chain:** CLI args > `.env` > defaults.

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 9.2 Dev Admin Dashboard

**What it does:** Built-in admin dashboard served at `/__dev` (or configurable path) with 11 panels for inspecting routes, database, queue, mailbox, requests, errors, WebSocket connections, and system info.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| `TINA4_CONSOLE` | `false` | Enable admin console |
| `TINA4_CONSOLE_TOKEN` | _(required)_ | Auth token for console access |
| `TINA4_CONSOLE_PATH` | `/tina4/console` | Console URL path |

**Panels:**
1. **Routes** — all registered routes with methods, paths, middleware
2. **Database** — connection status, tables, query log
3. **Queue** — pending/reserved/failed/dead counts per queue, retry/purge actions
4. **Mailbox** — sent emails (dev mode), SMTP status
5. **Requests** — recent request log with full detail drill-down
6. **Errors** — error log with .broken file management
7. **WebSocket** — active connections, message counts, broadcast controls
8. **Sessions** — active sessions (redacted in production)
9. **Templates** — rendered templates, render times
10. **Logs** — structured log viewer with filtering
11. **System** — version, uptime, memory, CPU, health status

**Priority chain:** `.env` > defaults above.

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 9.3 Dev Toolbar (Debug Overlay)

**What it does:** Injects a toolbar at the bottom of rendered HTML pages when `TINA4_DEBUG=true`. Shows request details, database queries, template renders, session data, and logs for the current request.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| `TINA4_DEBUG` | `false` | Enable debug overlay |

**Panels:** Request, Response, Database, Routes, Templates, Session, Logs, Memory.

**API:**

```
# Automatic — injected before </body> when TINA4_DEBUG=true
# No code needed
# Never shown when TINA4_DEBUG is false or absent
```

**Priority chain:** `.env` only.

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 9.4 Error Overlay

**What it does:** Full-page error display in debug mode with stack trace, source code context, request details, database queries, and environment info. In production, renders clean error pages and writes `.broken` files.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| `TINA4_DEBUG` | `false` | Show full traces (true) or generic errors (false) |
| `TINA4_BROKEN_DIR` | `data/.broken` | Directory for .broken error files |
| `TINA4_BROKEN_THRESHOLD` | `1` | .broken count before health returns 503 |
| `TINA4_BROKEN_AUTO_RESOLVE` | `0` | Auto-delete .broken files after N seconds (0=never) |
| `TINA4_BROKEN_MAX_FILES` | `100` | Max .broken files to keep |

**API:**

```
# Automatic — no code needed
# Custom error templates: src/templates/errors/404.html, 500.html, etc.
# JSON error responses for /api/* routes (auto-detected from Accept header)
```

**Priority chain:** `.env` > defaults above.

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 9.5 Gallery

**What it does:** 7 interactive code examples showcasing framework features, each with a "Try It" deploy button for instant experimentation.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| _None_ | — | Part of documentation/onboarding |

**API:**

```
# No runtime API — documentation feature
# Examples: Hello World, ORM CRUD, Auth Flow, Template Rendering,
#           Queue Worker, WebSocket Chat, GraphQL API
```

**Priority chain:** N/A.

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 9.6 Live Reload

**What it does:** WebSocket-based live reload during development. Detects file changes, restarts the server, and refreshes the browser. CSS changes are hot-reloaded without full page refresh.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| `TINA4_DEBUG` | `false` | Live reload only active when debug=true |

**API:**

```
# Automatic in dev mode — no code needed
# File watcher monitors src/ for changes
# WebSocket connection from frond.js triggers browser refresh
# CSS files trigger hot reload (no full page refresh)
# Hot patching for route handler changes (where supported)
```

**Priority chain:** N/A (active when debug=true).

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 9.7 Console Banner

**What it does:** Displays a styled ASCII art banner (Slant font) on server startup with framework name, version, language, port, and debug status. Each framework uses its own color.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| _None_ | — | Always shown on startup |

**API:**

```
# Automatic on server start — no code needed
# Output example:
#     __  _             __ __
#    / /_(_)___  ____ _/ // /
#   / __/ / __ \/ __ `/ // /_
#  / /_/ / / / / /_/ /__  __/
#  \__/_/_/ /_/\__,_/  /_/
#
#  tina4-python v3.0.0
#  Serving on http://0.0.0.0:7145
#  Debug: ON
```

**Priority chain:** N/A.

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

## 10. Architecture

---

### 10.1 DI Container

**What it does:** Dependency injection container for registering and resolving service instances. Supports singletons and transient registrations.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| _None_ | — | Code-configured |

**API:**

```
class Container:
    register(name, factory_fn) -> void        # Register transient
    singleton(name, factory_fn) -> void        # Register singleton (created once)
    get(name) -> instance                      # Resolve dependency
    has(name) -> boolean                       # Check if registered
    reset() -> void                            # Clear all registrations
```

**Priority chain:** N/A (code-configured).

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 10.2 Event System

**What it does:** Publish/subscribe event system with priority ordering and async emission. Decouples side effects from business logic.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| _None_ | — | Code-configured |

**API:**

```
class Events:
    on(event_name, handler, priority=0) -> void       # Subscribe
    once(event_name, handler, priority=0) -> void      # Subscribe (fires once)
    emit(event_name, data?) -> void                    # Synchronous emit
    emit_async(event_name, data?) -> void              # Asynchronous emit
    off(event_name, handler?) -> void                  # Unsubscribe (specific or all)
    listeners(event_name) -> [handlers]                # List handlers
    events() -> [event_names]                          # List all event names
    clear() -> void                                    # Remove all subscriptions
```

**Priority chain:** N/A (code-configured).

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 10.3 i18n (Internationalization)

**What it does:** Localization support using JSON translation files in `src/locales/`. Supports string interpolation and language fallback.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| `TINA4_LANGUAGE` | `en` | Default locale |

**API:**

```
# Translation files: src/locales/en.json, src/locales/fr.json, etc.
# Format: {"greeting": "Hello, {{name}}!", "items": "{{count}} items"}

# In code:
t("greeting", {name: "World"})  -> "Hello, World!"
t("items", {count: 5})          -> "5 items"

# In Frond templates:
{{ t("greeting", {name: user.name}) }}

# Language switching:
set_language("fr")
```

**Fallback chain:** Requested locale > `TINA4_LANGUAGE` > `en` > raw key.

**Priority chain:** `set_language()` call > `.env` > `en`.

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 10.4 Logging

**What it does:** Structured logging with JSON output (production) and human-readable colored output (development). Includes log rotation, separate error channel, and automatic request ID injection.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| `TINA4_LOG_LEVEL` | `ALL` | Minimum level: `ALL`, `DEBUG`, `INFO`, `WARNING`, `ERROR` |
| `TINA4_LOG_DIR` | `logs` | Log directory |
| `TINA4_LOG_FILE` | `tina4.log` | Main log file name |
| `TINA4_LOG_MAX_SIZE` | `10M` | Rotate when file exceeds this size |
| `TINA4_LOG_ROTATE` | `daily` | Rotation schedule: `daily`, `hourly`, `size-only` |
| `TINA4_LOG_RETAIN` | `30` | Keep rotated logs for N days |
| `TINA4_LOG_COMPRESS` | `true` | Gzip logs older than 2 days |
| `TINA4_LOG_SEPARATE_ERRORS` | `true` | Write errors to separate error.log |
| `TINA4_LOG_QUERY` | `false` | Log SQL queries (debug mode) |
| `TINA4_LOG_ACCESS` | `false` | HTTP access log |

**API:**

```
class Log:
    debug(message, context?) -> void
    info(message, context?) -> void
    warning(message, context?) -> void
    error(message, context?) -> void
```

**Output formats:**
```
# Development (human-readable, colored, ANSI):
2026-03-20T13:25:30.660Z [INFO   ] [request_id] Message {"key":"value"}

# Production (JSON lines):
{"timestamp":"2026-03-20T13:25:30.660Z","level":"INFO","message":"Message","request_id":"abc123","context":{"key":"value"}}
```

**Behavior:** File log always writes ALL levels regardless of `TINA4_LOG_LEVEL`. Console output is filtered by `TINA4_LOG_LEVEL`. ANSI codes are stripped from file output.

**Priority chain:** `.env` > defaults above.

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 10.5 DotEnv

**What it does:** Parses `.env` files into environment variables. Supports comments, quoted values, multiline values, and variable interpolation.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| _None_ | — | Reads from `.env` in project root |

**API:**

```
class DotEnv:
    load(path=".env") -> void               # Load .env file into environment
    get(key, default?) -> string | null      # Get env var with optional default
    is_truthy(key) -> boolean               # true for "true", "1", "yes", "on"
```

**Priority chain:** System environment > `.env` file values (system env wins, `.env` does not overwrite).

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 10.6 HtmlElement

**What it does:** Programmatic HTML builder for constructing HTML elements in code without string concatenation.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| _None_ | — | Code-only |

**API:**

```
class HtmlElement:
    constructor(tag, attributes={}, children=[])
    add_child(element | string) -> self
    set_attribute(name, value) -> self
    to_string() -> string                     # Render to HTML string

# Usage:
div = HtmlElement("div", {"class": "container"})
div.add_child(HtmlElement("h1", {}, ["Hello World"]))
div.add_child(HtmlElement("p", {}, ["Welcome"]))
html = div.to_string()
# <div class="container"><h1>Hello World</h1><p>Welcome</p></div>
```

**Priority chain:** N/A (code-only).

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 10.7 Inline Testing

**What it does:** Allows tests to be written alongside source code using a `@tests` block or equivalent. Tests run when `tina4 test` is invoked and are stripped from production builds.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| _None_ | — | Tests discovered by test runner |

**API:**

```
# Python
@tests
def test_my_feature():
    assert_equal(add(1, 2), 3)
    assert_raises(ValueError, lambda: add("a", "b"))

# PHP
// @tests
function test_my_feature() {
    assertEqual(add(1, 2), 3);
    assertThrows(ValueError::class, fn() => add("a", "b"));
}

# Ruby
tests do
  assert_equal add(1, 2), 3
  assert_raises(ArgumentError) { add("a", "b") }
end

# Node.js
// @tests
export function test_my_feature() {
  assertEqual(add(1, 2), 3);
  assertRaises(Error, () => add("a", "b"));
}
```

**Priority chain:** N/A.

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

### 10.8 AI Context

**What it does:** Generates and installs AI-readable context files (`CLAUDE.md`, `AI.md`, `llm.txt`) so AI assistants understand the Tina4 project structure and API conventions. Includes project status reporting.

**Environment variables:**
| Variable | Default | Description |
|----------|---------|-------------|
| _None_ | — | Code-configured |

**API:**

```
class AiContext:
    detect_ai() -> string | null              # Detect which AI tool is being used
    install_context(target_dir?) -> void       # Write CLAUDE.md/AI.md to project root
    status_report() -> dict                   # Return project health + feature status
```

**Priority chain:** N/A (code-configured).

**Parity status:**
| Python | PHP | Ruby | Node.js |
|--------|-----|------|---------|
| Done | Done | Done | Done |

---

## 11. Configuration

---

### 11.1 Debug Mode

**What it does:** Master toggle for all development features. When true: debug overlay, error traces, Swagger UI, live reload, query logging, and admin console are enabled.

| Variable | Default | Description |
|----------|---------|-------------|
| `TINA4_DEBUG` | `false` | `true` enables all dev features; `false` disables all |

**Features enabled by TINA4_DEBUG=true:**
- Debug toolbar injected into HTML responses
- Full stack traces in browser on errors
- Swagger UI at `/swagger`
- SQL query logging to `logs/query.log`
- Live reload via WebSocket
- Admin console (if `TINA4_CONSOLE_TOKEN` set)
- frond.js served unminified
- Template cache disabled (recompile on every request)

**Features enabled by TINA4_DEBUG=false (production):**
- Generic error pages (no stack traces)
- HTML minification
- Response compression
- Template caching
- No debug overlay
- No Swagger UI (unless explicitly enabled)

---

### 11.2 Log Level

| Variable | Default | Description |
|----------|---------|-------------|
| `TINA4_LOG_LEVEL` | `ALL` | `ALL`, `DEBUG`, `INFO`, `WARNING`, `ERROR` |

Console output is filtered by this level. File output always captures ALL levels regardless of this setting.

---

### 11.3 Default Ports

| Framework | Default Port |
|-----------|:----------:|
| Python | 7145 |
| PHP | 7146 |
| Ruby | 7147 |
| Node.js | 7148 |

Override with `TINA4_PORT` or `--port` CLI flag.

---

### 11.4 Default Host

| Variable | Default | Description |
|----------|---------|-------------|
| `TINA4_HOST` | `0.0.0.0` | Bind address (0.0.0.0 = all interfaces) |

---

### 11.5 Response Compression

| Variable | Default | Description |
|----------|---------|-------------|
| `TINA4_COMPRESS` | `true` | Enable gzip response compression |
| `TINA4_COMPRESS_THRESHOLD` | `1024` | Min bytes before compressing |
| `TINA4_COMPRESS_LEVEL` | `6` | gzip level 1-9 |
| `TINA4_MINIFY_HTML` | `true` | Minify HTML when debug=false |

---

### 11.6 Complete Environment Variable Reference

| Variable | Default | Section |
|----------|---------|---------|
| `DATABASE_URL` | `sqlite:///data/app.db` | Database |
| `DATABASE_USERNAME` | _(from URL)_ | Database |
| `DATABASE_PASSWORD` | _(from URL)_ | Database |
| `TINA4_DEBUG` | `false` | Configuration |
| `TINA4_PORT` | `7145` | Configuration |
| `TINA4_HOST` | `0.0.0.0` | Configuration |
| `TINA4_LOG_LEVEL` | `ALL` | Logging |
| `TINA4_LOG_DIR` | `logs` | Logging |
| `TINA4_LOG_FILE` | `tina4.log` | Logging |
| `TINA4_LOG_MAX_SIZE` | `10M` | Logging |
| `TINA4_LOG_ROTATE` | `daily` | Logging |
| `TINA4_LOG_RETAIN` | `30` | Logging |
| `TINA4_LOG_COMPRESS` | `true` | Logging |
| `TINA4_LOG_SEPARATE_ERRORS` | `true` | Logging |
| `TINA4_LOG_QUERY` | `false` | Logging |
| `TINA4_LOG_ACCESS` | `false` | Logging |
| `TINA4_LANGUAGE` | `en` | i18n |
| `CORS_ORIGINS` | `*` | CORS |
| `CORS_METHODS` | `GET,POST,PUT,DELETE` | CORS |
| `CORS_HEADERS` | `Content-Type,Authorization` | CORS |
| `CORS_CREDENTIALS` | `true` | CORS |
| `CORS_MAX_AGE` | `86400` | CORS |
| `TINA4_RATE_LIMIT` | `60` | Rate Limiter |
| `TINA4_RATE_WINDOW` | `60` | Rate Limiter |
| `JWT_SECRET` | _(required)_ | Auth |
| `JWT_ALGORITHM` | `HS256` | Auth |
| `JWT_EXPIRY_DAYS` | `7` | Auth |
| `TINA4_SESSION_HANDLER` | `file` | Sessions |
| `SESSION_SECRET` | _(required)_ | Sessions |
| `SESSION_TTL` | `3600` | Sessions |
| `REDIS_URL` | `redis://localhost:6379` | Sessions/Cache |
| `MONGODB_URL` | `mongodb://localhost:27017` | Sessions |
| `TINA4_QUEUE_BACKEND` | `database` | Queue |
| `QUEUE_DRIVER` | `database` | Queue |
| `QUEUE_FALLBACK_DRIVER` | — | Queue |
| `RABBITMQ_URL` | — | Queue |
| `KAFKA_BROKERS` | — | Queue |
| `KAFKA_GROUP_ID` | `tina4-workers` | Queue |
| `QUEUE_FAILOVER_TIMEOUT` | `300` | Queue |
| `QUEUE_FAILOVER_DEPTH` | `10000` | Queue |
| `QUEUE_FAILOVER_ERROR_RATE` | `50` | Queue |
| `QUEUE_CIRCUIT_BREAKER_THRESHOLD` | `5` | Queue |
| `QUEUE_CIRCUIT_BREAKER_COOLDOWN` | `30` | Queue |
| `TINA4_MAIL_HOST` | — | Messenger |
| `TINA4_MAIL_PORT` | `587` | Messenger |
| `TINA4_MAIL_USERNAME` | — | Messenger |
| `TINA4_MAIL_PASSWORD` | — | Messenger |
| `TINA4_MAIL_FROM` | — | Messenger |
| `TINA4_MAIL_ENCRYPTION` | `tls` | Messenger |
| `TINA4_CACHE_BACKEND` | `memory` | Response Cache |
| `TINA4_CACHE_TTL` | `300` | Response Cache |
| `TINA4_CACHE_MAX_ENTRIES` | `1000` | Response Cache |
| `TINA4_DB_CACHE` | `true` | DB Query Cache |
| `TINA4_DB_CACHE_TTL` | `60` | DB Query Cache |
| `TINA4_COMPRESS` | `true` | Compression |
| `TINA4_COMPRESS_THRESHOLD` | `1024` | Compression |
| `TINA4_COMPRESS_LEVEL` | `6` | Compression |
| `TINA4_MINIFY_HTML` | `true` | Compression |
| `TINA4_CONSOLE` | `false` | Dev Admin |
| `TINA4_CONSOLE_TOKEN` | _(required)_ | Dev Admin |
| `TINA4_CONSOLE_PATH` | `/tina4/console` | Dev Admin |
| `TINA4_BROKEN_DIR` | `data/.broken` | Error Handling |
| `TINA4_BROKEN_THRESHOLD` | `1` | Error Handling |
| `TINA4_BROKEN_AUTO_RESOLVE` | `0` | Error Handling |
| `TINA4_BROKEN_MAX_FILES` | `100` | Error Handling |
| `TINA4_WS_MAX_FRAME_SIZE` | `1048576` | WebSocket |
| `TINA4_WS_MAX_CONNECTIONS` | `10000` | WebSocket |
| `TINA4_WS_PING_INTERVAL` | `30` | WebSocket |
| `TINA4_WS_PING_TIMEOUT` | `10` | WebSocket |

---

## Project Directory Structure (All Frameworks)

```
project/
├── .env                        # Environment variables
├── .env.example                # Documented env var template
├── .gitignore                  # Pre-configured
├── bin/                        # CLI entry point (auto-updated)
├── data/                       # SQLite databases (gitignored)
│   └── .broken/                # Error files (gitignored)
├── logs/                       # Log files with rotation (gitignored)
├── secrets/                    # JWT keys (gitignored)
├── src/
│   ├── routes/                 # Route handlers (auto-discovered)
│   ├── orm/                    # ORM model definitions (auto-discovered)
│   ├── migrations/             # SQL migration files
│   ├── seeds/                  # Database seed files
│   ├── templates/              # Frond templates
│   │   └── errors/             # Custom error pages (404.html, 500.html)
│   ├── public/                 # Static files (served directly)
│   │   ├── js/
│   │   │   └── frond.js        # Auto-copied from framework
│   │   ├── css/
│   │   ├── scss/               # SCSS source files
│   │   ├── images/
│   │   └── icons/              # SVG icons
│   └── locales/                # Translation files (JSON)
│       └── en.json
└── tests/                      # Test files
```

---

## Universal Priority Chain

For every configurable feature in Tina4, the resolution order is:

```
1. Constructor argument / per-call parameter    (highest priority)
2. .env file / system environment variable
3. Hardcoded default value                      (lowest priority)
```

System environment variables always take precedence over `.env` file values (the `.env` loader does not overwrite existing system env vars).

---

## Parity Summary

All 78 features are implemented and tested across all four frameworks with identical behavior.

| Framework | Features | Tests | Status |
|-----------|:--------:|:-----:|--------|
| Python | 78/78 | 1,165 | 100% |
| PHP | 78/78 | 1,166 | 100% |
| Ruby | 78/78 | 1,334 | 100% |
| Node.js | 78/78 | 1,247 | 100% |
| **Total** | — | **4,912** | **Full parity** |
