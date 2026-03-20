# Tina4 v3.0 — Architecture & Zero-Dependency Strategy

## Repository Structure

Each framework lives in its own existing repo on a `v3` branch. Shared specs (Frond syntax, frond.js, debug overlay, test specs) are defined in `plan/v3/` and implemented identically in each repo.

```
github.com/tina4stack/
├── tina4-python     (v3 branch)
├── tina4-php        (v3 branch)  ← consolidated monorepo (no split packages)
├── tina4-ruby       (v3 branch)
├── tina4-nodejs     (v3 branch)
├── tina4-js         (frontend)
├── tina4press       (docs site)
└── frond.js         (shared JS, submoduled or npm published)
```

### Per-Repo Structure (identical layout)

```
tina4-python/ (v3 branch)
├── tina4_python/               # tina4-python v3
│   ├── tina4_python/
│   │   ├── core/               # Router, Server, Request, Response, Middleware
│   │   ├── orm/                # ORM, Fields, Relationships, SoftDelete
│   │   ├── database/           # Adapter interface + all drivers
│   │   │   ├── adapter.py      # Base adapter interface
│   │   │   ├── sqlite.py
│   │   │   ├── firebird.py
│   │   │   ├── mysql.py
│   │   │   ├── mssql.py
│   │   │   ├── postgresql.py
│   │   │   └── odbc.py
│   │   ├── frond/              # Template engine
│   │   ├── auth/               # JWT, session, rate limiting
│   │   ├── queue/              # Database-backed queue
│   │   ├── migration/          # Migrations + rollback
│   │   ├── swagger/            # OpenAPI generation
│   │   ├── graphql/            # GraphQL engine
│   │   ├── websocket/          # WebSocket server
│   │   ├── wsdl/               # SOAP/WSDL
│   │   ├── scss/               # SCSS compiler
│   │   ├── dotenv/             # .env parser
│   │   ├── i18n/               # Localization
│   │   ├── api/                # HTTP client
│   │   ├── messenger/          # Email
│   │   ├── seeder/             # Seeder + FakeData
│   │   ├── crud/               # Auto-CRUD
│   │   ├── debug/              # Debug overlay + structured logging
│   │   └── cli/                # CLI commands
│   └── tests/                  # Mirror test structure
│       ├── test_frond/
│       ├── test_orm/
│       ├── test_database/
│       ├── test_router/
│       └── ...
│
├── php/                        # tina4-php v3 (monorepo, no split packages)
│   ├── src/
│   │   ├── Core/
│   │   ├── ORM/
│   │   ├── Database/
│   │   ├── Frond/
│   │   ├── Auth/
│   │   ├── Queue/
│   │   ├── Migration/
│   │   ├── Swagger/
│   │   ├── GraphQL/
│   │   ├── WebSocket/          # Swoole-based
│   │   ├── WSDL/
│   │   ├── SCSS/
│   │   ├── DotEnv/
│   │   ├── I18n/
│   │   ├── Api/
│   │   ├── Messenger/
│   │   ├── Seeder/
│   │   ├── CRUD/
│   │   ├── Debug/
│   │   └── CLI/
│   └── tests/
│
├── ruby/                       # tina4-ruby v3
│   ├── lib/tina4/
│   │   ├── core/
│   │   ├── orm/
│   │   ├── database/
│   │   ├── frond/
│   │   ├── auth/
│   │   ├── queue/
│   │   ├── migration/
│   │   ├── swagger/
│   │   ├── graphql/
│   │   ├── websocket/
│   │   ├── wsdl/
│   │   ├── scss/
│   │   ├── dotenv/
│   │   ├── i18n/
│   │   ├── api/
│   │   ├── messenger/
│   │   ├── seeder/
│   │   ├── crud/
│   │   ├── debug/
│   │   └── cli/
│   └── test/
│
├── nodejs/                     # tina4-nodejs v3
│   ├── packages/
│   │   ├── core/               # Router, Server, Request, Response
│   │   ├── orm/
│   │   ├── database/
│   │   ├── frond/
│   │   ├── auth/
│   │   ├── queue/
│   │   ├── migration/
│   │   ├── swagger/
│   │   ├── graphql/
│   │   ├── websocket/
│   │   ├── wsdl/
│   │   ├── scss/
│   │   ├── dotenv/
│   │   ├── i18n/
│   │   ├── api/
│   │   ├── messenger/
│   │   ├── seeder/
│   │   ├── crud/
│   │   ├── debug/
│   │   └── cli/
│   └── test/
│
├── shared/                     # Shared across all frameworks
│   ├── frond.js          # Unified JS helper
│   ├── frond.min.js
│   ├── debug-overlay/          # Debug overlay HTML/CSS/JS
│   ├── swagger-ui/             # Bundled Swagger UI assets
│   └── test-specs/             # Test specifications (language-agnostic)
│       ├── frond-tests.yaml    # Test cases for Frond
│       ├── orm-tests.yaml      # Test cases for ORM
│       ├── router-tests.yaml
│       ├── auth-tests.yaml
│       ├── queue-tests.yaml
│       ├── migration-tests.yaml
│       └── ...
│
└── plan/v3/                    # This planning documentation
```

## Project Folder Auto-Layout

### On `init` — Full Scaffold
```
project/
├── .env.example                # Documented env vars
├── .gitignore                  # Preconfigured for the language
├── bin/                        # CLI entry point + scripts (auto-updated)
├── data/                       # SQLite databases, .broken files (gitignored)
│   └── .broken/
├── logs/                       # Log files with rotation (gitignored)
├── secrets/                    # JWT keys (gitignored)
├── src/
│   ├── routes/                 # Route handlers (auto-discovered)
│   ├── orm/                    # ORM models (auto-discovered)
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
│   │   └── icons/              # SVG icons for icon() function
│   └── locales/                # Translation files (JSON)
│       └── en.json
└── tests/                      # Test files
```

### On Run — Auto-Repair
Every time the framework starts, it verifies the folder structure and silently creates any missing directories. This handles:

1. **Manual scaffolding** — developer creates project by hand, misses folders
2. **Partial clone** — git doesn't track empty directories
3. **Container restart** — ephemeral filesystem may lose gitignored dirs
4. **Upgrade from v2** — folders may be named differently

```
Startup check:
  ✓ src/routes/          exists or create
  ✓ src/orm/             exists or create
  ✓ src/migrations/      exists or create
  ✓ src/seeds/           exists or create
  ✓ src/templates/       exists or create
  ✓ src/templates/errors/ exists or create
  ✓ src/public/          exists or create
  ✓ src/public/js/       exists or create
  ✓ src/public/css/      exists or create
  ✓ src/public/icons/    exists or create
  ✓ src/locales/         exists or create
  ✓ data/                exists or create
  ✓ data/.broken/        exists or create
  ✓ logs/                exists or create
  ✓ secrets/             exists or create
  ✓ tests/               exists or create
```

### bin/ Auto-Update
The `bin/` directory contains the CLI entry point and any framework scripts. On every run, the framework checks if the bin files are outdated and updates them silently:

```python
# Pseudocode — runs on startup
if bin_version() < framework_version():
    copy_bin_files()       # Update CLI entry point
    copy_frond_js()        # Update frond.js to src/public/js/
    log.info("Updated bin/ and frond.js to v" + framework_version())
```

This ensures:
- CLI commands always match the installed framework version
- `frond.js` stays in sync with the server-side Frond engine
- No manual copy/paste after framework upgrades

### .gitignore (auto-generated on init)
```
data/
logs/
secrets/
node_modules/
__pycache__/
vendor/
*.pyc
*.db
*.fdb
.env
```

## Future: Report Engine (v3.x)

A browser-based WYSIWYG report template builder is planned for a future release (post v3.0 stable):

- **Browser UI** — drag-and-drop report designer, outputs XML template definition
- **XML template format** — portable report definition (headers, footers, tables, charts, images, expressions)
- **Native compiler** — Rust or native language interpreter compiles XML templates to pixel-perfect output:
  - PDF (flawless rendering, print-ready)
  - CSV (data export)
  - Image (PNG/JPEG for thumbnails/previews)
- **All backends** — the report engine will be included in all four frameworks
- **Template storage** — report templates stored as `.xml` files in `src/reports/`

This is tracked but explicitly **out of scope for v3.0**. The folder `src/reports/` will be created on init as a placeholder.

---

## Zero-Dependency Strategy

### What We Build From Scratch (per language)

| Component | What It Replaces | Approx Lines/Lang | Difficulty |
|-----------|-----------------|-------------------|------------|
| **Frond** | twig/twig, Twig.js, twig npm | 2000-3000 | Medium |
| **JWT** | php-jwt, PyJWT, ruby-jwt | 300-500 | Low |
| **SCSS compiler** | scssphp, libsass | 1500-2000 | Medium |
| **DotEnv parser** | vlucas/phpdotenv, python-dotenv | 50-100 | Trivial |
| **Queue (DB-backed)** | litequeue, various | 300-500 | Low |
| **HTTP client** | requests (Python), curl wrappers | 200-400 | Low |
| **GraphQL parser** | graphql-php, graphql-core | 800-1200 | Medium |
| **WSDL/SOAP** | built-in soap ext | 400-600 | Medium |
| **Structured logger** | monolog, logging libs | 200-300 | Low |
| **Rate limiter** | various middleware | 100-200 | Low |

### What We Use From Language Stdlib Only

| Feature | Python | PHP | Ruby | Node.js |
|---------|--------|-----|------|---------|
| HTTP server | asyncio/ASGI | built-in / Swoole | WEBrick/Puma | node:http |
| Crypto (JWT) | hashlib, hmac | openssl ext | openssl | node:crypto |
| SQLite | sqlite3 | ext-sqlite3 | sqlite3 gem | better-sqlite3* |
| JSON | json (stdlib) | ext-json | json (stdlib) | JSON (global) |
| File I/O | os, pathlib | filesystem funcs | File, Dir | node:fs |
| Regex | re | preg_* | Regexp | RegExp |
| URL parsing | urllib | parse_url | URI | node:url |
| Date/Time | datetime | DateTime | Time | Date |

*Note: Node.js `better-sqlite3` is a native binding — acceptable since Node.js has no built-in SQLite. All other database drivers use language-native connectors.*

### Database Drivers — Minimal Dependencies

| Database | Python | PHP | Ruby | Node.js |
|----------|--------|-----|------|---------|
| SQLite | `sqlite3` (stdlib) | `ext-sqlite3` | `sqlite3` gem | `better-sqlite3` |
| PostgreSQL | `psycopg2` | `ext-pgsql` | `pg` gem | `pg` npm |
| MySQL | `mysql-connector` | `ext-mysqli` | `mysql2` gem | `mysql2` npm |
| MSSQL | `pymssql` | `ext-sqlsrv` | `tiny_tds` gem | `tedious` npm |
| Firebird | `firebird-driver` | `ext-interbase` | `fb` gem | `node-firebird` |
| ODBC | `pyodbc` | `ext-odbc` | `ruby-odbc` gem | `odbc` npm |

Database drivers are the ONE exception to zero-dependency — they're native connectors to external systems. Each driver is optional; only install what you need.

## Adapter Interface Contract

Every database adapter across all four languages implements the same contract:

```
interface DatabaseAdapter:
    connect(connection_string)
    close()
    execute(sql, params) → affected_rows
    fetch(sql, params) → [rows]
    fetch_one(sql, params) → row | null
    insert(table, data) → last_id
    update(table, data, filter) → affected_rows
    delete(table, filter) → affected_rows
    start_transaction()
    commit()
    rollback()
    table_exists(name) → boolean
    get_tables() → [table_names]
    get_columns(table) → [column_definitions]
    get_database_type() → string
```

## ORM Contract (SQL-First)

```
class Model:
    # Class-level
    table_name: string
    primary_key: string = "id"
    fields: { name → FieldDefinition }
    soft_delete: boolean = false
    field_mapping: { property → column }
    table_filter: string | null

    # Instance methods
    save() → self
    delete() → boolean
    load(filter, params) → self | null
    to_dict() → dict
    to_json() → string

    # Class methods (static)
    select(filter, params, options) → PaginatedResult
    fetch_one(sql, params) → row
    fetch(sql, params) → [rows]
    find(id) → self | null
    where(filter, params) → [self]
    count(filter) → int
    create(data) → self
    create_table() → void

    # Relationships
    has_one(related_model, foreign_key) → related | null
    has_many(related_model, foreign_key, options) → [related]

    # Scopes
    scope(name, filter_fn) → registers reusable query filter

    # Soft delete (when enabled)
    soft_delete() → sets deleted_at
    restore() → clears deleted_at
    force_delete() → permanent delete
    with_trashed() → includes soft-deleted in queries
```

## PaginatedResult Format (standardized JSON)

```json
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

## Response Compression

All output is compressed automatically. Zero config, zero dependencies — built into the framework's response pipeline.

### How It Works

1. **Check** `Accept-Encoding` header from the client
2. **Compress** response body if size > 1KB (configurable threshold)
3. **Set** `Content-Encoding` header + `Vary: Accept-Encoding`
4. **Stream** compressed bytes to client

### Supported Encodings (priority order)
| Encoding | Algorithm | When Used |
|----------|-----------|-----------|
| `gzip` | DEFLATE + headers | Default — universally supported |
| `deflate` | Raw DEFLATE | Fallback if gzip not accepted |
| `identity` | None | Below threshold or client doesn't accept compression |

All four languages have gzip in stdlib — no third-party needed:
- **Python:** `gzip` / `zlib` (stdlib)
- **PHP:** `gzencode()` / `gzcompress()` (ext-zlib, bundled)
- **Ruby:** `Zlib::GzipWriter` (stdlib)
- **Node.js:** `node:zlib` (stdlib)

### What Gets Compressed

| Content-Type | Compressed | Reason |
|-------------|-----------|--------|
| `application/json` | Yes | API responses, often large |
| `text/html` | Yes | Rendered pages |
| `text/xml` / `application/xml` | Yes | XML/SOAP responses |
| `text/plain` | Yes | Plain text |
| `text/css` | Yes | Stylesheets |
| `application/javascript` | Yes | JS files |
| `image/*` | No | Already compressed (JPEG, PNG, WebP) |
| `application/octet-stream` | No | Binary, unknown format |
| `font/*` | No | Already compressed (woff2) |

### HTML Minification (Production Only)

When `TINA4_DEBUG=false`, Frond automatically minifies HTML output:
- Strip HTML comments (`<!-- -->` but preserve conditional comments `<!--[if`)
- Collapse whitespace (multiple spaces/newlines → single space)
- Remove whitespace between tags where safe (`</div>  <div>` → `</div><div>`)
- Preserve `<pre>`, `<code>`, `<script>`, `<style>`, `<textarea>` whitespace

Not a full minifier — just safe whitespace reduction. ~15-25% size reduction on typical HTML.

### JSON Compaction

JSON responses are always compact (no pretty-printing) unless `?pretty=true` query param is present (dev convenience):

```
GET /api/users           → {"data":[{"id":1,"name":"John"}]}    (compact)
GET /api/users?pretty=1  → {                                     (pretty, dev only)
                              "data": [
                                { "id": 1, "name": "John" }
                              ]
                            }
```

### Configuration

```env
TINA4_COMPRESS=true                        # Enable response compression (default: true)
TINA4_COMPRESS_THRESHOLD=1024              # Min bytes before compressing (default: 1KB)
TINA4_COMPRESS_LEVEL=6                     # gzip level 1-9, 6 = good balance (default: 6)
TINA4_MINIFY_HTML=true                     # Minify HTML in production (default: true when debug=false)
```

### Response Pipeline Order

```
Route handler returns data
    ↓
Frond template rendering (if .render())
    ↓
HTML minification (production only)
    ↓
JSON compaction (always)
    ↓
gzip compression (if client accepts, size > threshold)
    ↓
Set Content-Encoding, Content-Length, Vary headers
    ↓
Send to client
```

### ETag Support

After compression, an ETag header is generated from the response body hash:

```
ETag: "a1b2c3d4"
```

On subsequent requests, if the client sends `If-None-Match: "a1b2c3d4"`, the server returns `304 Not Modified` with no body — zero bandwidth.

Combined with route caching, this means repeated requests for the same data transfer zero bytes.

---

## Standardized Response Types

All frameworks return responses with the same method names:

```
response.json(data, status_code)          → Content-Type: application/json
response.html(content, status_code)       → Content-Type: text/html
response.xml(content, status_code)        → Content-Type: application/xml
response.file(path)                       → Content-Type: auto-detected
response.redirect(url, status_code)       → 302/301 redirect
response.render(template, data)           → Frond-rendered HTML
response.text(content, status_code)       → Content-Type: text/plain
response.status(code)                     → set status code (chainable)
response.header(name, value)              → set response header
```

## Debug Overlay (Identical Across All Frameworks)

The debug overlay is a shared HTML/CSS/JS component served by every backend when `TINA4_DEBUG=true`. It appears as a toolbar at the bottom of rendered HTML pages.

### Panels:
1. **Request** — method, URL, params, headers, duration
2. **Response** — status code, content type, size
3. **Database** — queries executed, time per query, total query time
4. **Routes** — matched route, middleware chain, auth status
5. **Templates** — templates rendered, render time
6. **Session** — session data (redacted in production)
7. **Logs** — recent log entries
8. **Memory** — memory usage, peak memory

### Implementation:
- `shared/debug-overlay/debug.html` — the overlay template
- `shared/debug-overlay/debug.css` — styling
- `shared/debug-overlay/debug.js` — panel switching, collapsing
- Each backend injects debug data as a JSON blob before `</body>`
- The overlay JS reads the blob and populates panels
- **Never shown in production** — stripped when `TINA4_DEBUG != true`

## Production-Ready Features (All Frameworks)

### 1. DATABASE_URL Parsing
```
DATABASE_URL=sqlite:///data/app.db
DATABASE_URL=mysql://user:pass@host:3306/dbname
DATABASE_URL=postgresql://user:pass@host:5432/dbname
DATABASE_URL=firebird://user:pass@host:3050/path/to/db.fdb
DATABASE_URL=mssql://user:pass@host:1433/dbname
DATABASE_URL=odbc://DSN_NAME
```

### 2. Health Check
```
GET /health → 200
{
  "status": "ok",
  "database": "connected",
  "uptime_seconds": 3600,
  "version": "3.0.0",
  "framework": "tina4-python"
}
```

### 3. Graceful Shutdown
- Trap SIGTERM/SIGINT
- Stop accepting new connections
- Finish in-flight requests (30s timeout)
- Close database connections
- Flush logs
- Exit cleanly

### 4. Request ID Tracking
- Read `X-Request-ID` header if present
- Generate UUID if not
- Attach to all log entries
- Pass to downstream HTTP calls
- Return in response headers

### 5. Structured Logging with Rotation

```json
// Production (JSON)
{"timestamp":"2026-03-19T10:30:00Z","level":"info","message":"Request completed","request_id":"abc-123","method":"GET","path":"/api/users","status":200,"duration_ms":45}

// Development (human-readable)
[10:30:00] INFO  GET /api/users → 200 (45ms) [abc-123]
```

#### Log Directory Structure
```
logs/
├── tina4.log                    ← current log file
├── tina4.2026-03-18.log         ← rotated (yesterday)
├── tina4.2026-03-17.log         ← rotated (2 days ago)
├── tina4.2026-03-16.log.gz      ← compressed (3+ days old)
├── error.log                    ← errors only (current)
├── error.2026-03-18.log         ← rotated errors
└── query.log                    ← SQL queries (debug mode only)
```

#### Rotation Configuration
```env
TINA4_LOG_DIR=logs                         # Log directory (default: logs/)
TINA4_LOG_FILE=tina4.log                   # Main log file name
TINA4_LOG_MAX_SIZE=10M                     # Rotate when file exceeds size (default: 10MB)
TINA4_LOG_ROTATE=daily                     # Rotation schedule: daily | hourly | size-only
TINA4_LOG_RETAIN=30                        # Keep rotated logs for N days (default: 30)
TINA4_LOG_COMPRESS=true                    # Gzip logs older than 2 days (default: true)
TINA4_LOG_LEVEL=info                       # Minimum level: debug | info | warning | error
TINA4_LOG_SEPARATE_ERRORS=true             # Write errors to separate error.log (default: true)
TINA4_LOG_QUERY=false                      # Log SQL queries (default: false, enable in debug)
```

#### Rotation Logic (zero-dep, built-in)
1. **On every write**: check current file size against `TINA4_LOG_MAX_SIZE`
2. **On date boundary**: if `daily` or `hourly`, rotate regardless of size
3. **Rotate**: rename `tina4.log` → `tina4.{date}.log`, open new `tina4.log`
4. **Compress**: background gzip of files older than 2 days
5. **Prune**: delete files older than `TINA4_LOG_RETAIN` days
6. **Atomic**: rotation is atomic (rename + open) — no lost log lines

#### Log Channels
| Channel | File | When |
|---------|------|------|
| `main` | `tina4.log` | All log entries at configured level |
| `error` | `error.log` | Errors and exceptions only (always, if `TINA4_LOG_SEPARATE_ERRORS=true`) |
| `query` | `query.log` | SQL queries with timing (debug mode only) |
| `access` | `access.log` | HTTP request log in standard format (optional, `TINA4_LOG_ACCESS=true`) |

#### API (per language)
```python
# Python
from tina4_python import Log

Log.debug("Processing user", user_id=42)
Log.info("Request completed", method="GET", path="/api/users", duration_ms=45)
Log.warning("Rate limit approaching", ip="10.0.0.1", remaining=5)
Log.error("Database connection failed", error=str(e), host="db.example.com")

# PHP
Log::debug("Processing user", ["user_id" => 42]);
Log::info("Request completed", ["method" => "GET", "path" => "/api/users"]);
Log::warning("Rate limit approaching", ["ip" => "10.0.0.1"]);
Log::error("Database connection failed", ["error" => $e->getMessage()]);

# Ruby
Tina4::Log.debug("Processing user", user_id: 42)
Tina4::Log.info("Request completed", method: "GET", path: "/api/users")

# TypeScript
Log.debug("Processing user", { userId: 42 });
Log.info("Request completed", { method: "GET", path: "/api/users" });
```

Request ID is automatically injected into every log entry within a request context — developers never need to pass it manually.

### 6. Rate Limiting
- Default: 60 requests/minute per IP
- Configurable per-route
- Headers: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`
- 429 response with `Retry-After` header
- Storage: in-memory (single instance), database (distributed)

### 7. CORS Configuration
```env
CORS_ORIGINS=https://app.example.com,https://admin.example.com
CORS_METHODS=GET,POST,PUT,DELETE
CORS_HEADERS=Content-Type,Authorization
CORS_CREDENTIALS=true
CORS_MAX_AGE=86400
```

### 8. Environment Validation
On startup, validate required environment variables exist and are valid:
```
[FATAL] Missing required environment variables:
  - DATABASE_URL: not set
  - JWT_SECRET: not set (required when AUTH_ENABLED=true)
```

## Test Strategy

### Shared Test Specifications
Test cases are defined in `shared/test-specs/*.yaml` as language-agnostic specifications. Each framework implements these specs in its native test framework.

```yaml
# shared/test-specs/orm-tests.yaml
tests:
  - name: test_save_and_load
    type: positive
    description: Save a record and load it back
    steps:
      - create model instance with {name: "John", email: "john@example.com"}
      - call save()
      - call find(saved_id)
      - assert name equals "John"
      - assert email equals "john@example.com"

  - name: test_save_missing_required_field
    type: negative
    description: Save without required field should error
    steps:
      - create model instance with {name: null}
      - call save()
      - assert raises validation error
      - assert error mentions "name"
```

### Test Categories
1. **Unit tests** — individual functions/methods in isolation
2. **Integration tests** — feature interactions (ORM + database, router + middleware)
3. **Cross-framework tests** — same HTTP requests against all four backends, expect identical responses
4. **Performance tests** — response time, throughput, memory benchmarks

### Database Test Infrastructure (Docker)
Database integration tests run against real databases via Docker containers. A `docker-compose.test.yml` in the monorepo root spins up all required databases:

```yaml
services:
  postgres:
    image: postgres:16
    environment:
      POSTGRES_DB: tina4_test
      POSTGRES_USER: tina4
      POSTGRES_PASSWORD: tina4test
    ports: ["5432:5432"]

  mysql:
    image: mysql:8
    environment:
      MYSQL_DATABASE: tina4_test
      MYSQL_ROOT_PASSWORD: tina4test
    ports: ["3306:3306"]

  mssql:
    image: mcr.microsoft.com/mssql/server:2022-latest
    environment:
      ACCEPT_EULA: "Y"
      SA_PASSWORD: "Tina4Test!"
    ports: ["1433:1433"]

  firebird:
    image: jacobalberty/firebird:v4
    environment:
      ISC_PASSWORD: tina4test
      FIREBIRD_DATABASE: tina4_test.fdb
    ports: ["3050:3050"]

  mongodb:
    image: mongo:7
    ports: ["27017:27017"]

  redis:
    image: redis:7
    ports: ["6379:6379"]

  memcached:
    image: memcached:1.6
    ports: ["11211:11211"]
```

Run all database tests: `docker compose -f docker-compose.test.yml up -d && tina4py test --db-all`

SQLite tests run without Docker (zero-config).

### Test Coverage Targets
- **Frond:** 100% of syntax features, all filters, all edge cases
- **ORM:** 100% of CRUD operations, relationships, soft delete, scopes
- **Database:** 100% of adapter methods, per-driver
- **Router:** 100% of route patterns, middleware, caching
- **Auth:** 100% of JWT operations, session backends
- **Queue:** 100% of enqueue/dequeue/retry/dead-letter
- **Overall target:** >95% line coverage per framework
