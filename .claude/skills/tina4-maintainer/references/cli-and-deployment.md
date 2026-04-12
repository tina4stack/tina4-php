# CLI, Debug, Deployment & Operations

## Unified CLI

A Rust-based `tina4` CLI auto-detects project language and dispatches to the correct runtime.
Language-specific aliases: `tina4py`, `tina4php`, `tina4rb`, `tina4js`.

### Commands
```bash
tina4 init              # Scaffold new project
tina4 serve             # Start dev server with hot reload
tina4 test              # Run test suite
tina4 migrate           # Run pending migrations
tina4 migrate:create    # Create new migration file
tina4 seed              # Run all seeders
tina4 seed:create       # Create new seeder file
tina4 routes            # List all registered routes
tina4 build             # Build Docker image
tina4 deploy push       # Push image to registry
tina4 deploy k8s        # Deploy to Kubernetes
tina4 stage             # Build + push + deploy (~30s)
tina4 stage --watch     # Stage with file watching
tina4 deploy promote staging production  # Promote exact image
```

---

## Debug Overlay

Activated when `TINA4_DEBUG=true`. 8 panels injected into HTML responses:

1. **Request** — Method, URL, headers, params, body
2. **Response** — Status, headers, body size, render time
3. **Database** — All queries with timing, bindings, explain plans
4. **Routes** — Registered routes, matched route, middleware chain
5. **Templates** — Rendered templates, variables passed, render time
6. **Session** — Session data, storage backend
7. **Logs** — Log entries for current request
8. **Memory** — Memory usage, peak, allocations

Shared HTML/CSS/JS across all four languages.

---

## .broken File System

Health monitoring via JSON files in `data/.broken/`.

### How It Works
- Global exception handler catches unhandled errors
- Creates a `.broken` file with error details, request context, environment snapshot
- Deduplicated by error type + location (same error doesn't create multiple files)
- Health check endpoint returns 503 when .broken files exist
- K8s liveness/readiness probes integrate with this

### Configuration
- Auto-resolve: optionally clear .broken files after N successful requests
- Max files: cap to prevent disk fill
- Threshold: N errors before marking unhealthy

---

## Structured Logging

### Format
- **Development**: Human-readable, colorized
- **Production**: JSON (one object per line)

### Channels
`main`, `error`, `query`, `access`

### Rotation
Daily, hourly, or size-based. Old logs compressed. Configurable retention period.

### Log Levels
EMERGENCY, ALERT, CRITICAL, ERROR, WARNING, NOTICE, INFO, DEBUG

Request ID auto-injected into all log entries for tracing.

---

## Environment Configuration

`.env` file with standard KEY=VALUE pairs.

### Key Variables
```env
SECRET=your-jwt-secret
DATABASE_NAME=sqlite3:data/app.db
TINA4_DEBUG=true
TINA4_DEBUG_LEVEL=DEBUG
TINA4_LANGUAGE=en
TINA4_SESSION_HANDLER=file
SWAGGER_TITLE=My API
API_KEY=optional-key
```

Supports environment-specific overrides: `.env.development`, `.env.production`, `.env.testing`.

---

## Docker

### Official Base Images (Docker Hub)

| Framework | Image | Port | Size | Base |
|-----------|-------|------|------|------|
| Python | `tina4stack/tina4-python:v3` | 7146 | ~56MB | Alpine 3.23, Python 3.13 |
| PHP | `tina4stack/tina4-php:v3` | 7145 | ~154MB | Alpine 3.23, PHP 8.4 |

Base images ship with **SQLite only** and include:
- Python: libffi, sqlite-libs, PYTHONUNBUFFERED=1, TINA4_OVERRIDE_CLIENT=true, TINA4_NO_BROWSER=true
- PHP: sqlite3, pdo_sqlite, OPcache (production settings), TINA4_OVERRIDE_CLIENT=true

Both use multi-stage builds. App Dockerfiles extend these with `FROM tina4stack/tina4-python:v3`
or `FROM tina4stack/tina4-php:v3` and only add app code + any extra database drivers.

Database driver installation recipes are in the tina4-developer skill at
`references/deployment.md` — covers PostgreSQL, MySQL, MSSQL, and Firebird for both Python and PHP.

### Docker Compose
Provided for integration testing with real databases (PostgreSQL, MySQL, MSSQL, etc.).

---

## Kubernetes

### Manifests
- **Deployment**: Rolling update strategy, zero-downtime
- **Service**: ClusterIP
- **Ingress**: TLS termination
- **HPA**: Auto-scaling 2-20 replicas based on CPU/memory

### Health Probes
```yaml
livenessProbe:
  httpGet:
    path: /health
readinessProbe:
  httpGet:
    path: /health
```

The `/health` endpoint checks:
- Application is running
- Database is connected
- No .broken files exist

---

## Development Workflow

### Golden Path
Local dev → Staging → Production. No shortcuts.

### Local Development
```bash
tina4 init          # Creates project with SQLite, zero Docker needed
tina4 serve         # Hot reload, debug overlay, Swagger UI, console
```

### Staging
```bash
tina4 stage         # Build + push + deploy in ~33 seconds
```
Same database engine as production. Full integration testing.

### Production
```bash
tina4 deploy promote staging production
```
Promotes the exact tested image — no rebuild. Zero-downtime rolling update.
Auto-rollback on failed health check.

### Branching
- `main` — production
- `develop` — staging
- `feature/*` — feature branches off develop
- `hotfix/*` — merge to both main and develop

---

## Backend Console

Available at `/tina4/console` (token-protected). Static HTML + frond.js.

### Panels (11 tabs in dev admin dashboard)
- Queue manager
- WebSocket monitor
- Request inspector
- Error log viewer
- System overview
- Route browser
- Database explorer
- Template preview
- Session viewer
- Log viewer
- Benchmark runner

---

## Response Compression

Automatic, using stdlib zlib:
- gzip/deflate based on Accept-Encoding
- Configurable threshold (default 1KB)
- HTML minification in production
- JSON compaction (no whitespace)
- ETag support with 304 Not Modified

---

## Auto-Repair on Startup

Framework verifies folder structure and silently creates missing directories.
`bin/` files are checked against framework version and updated if stale.
