# CLI, Debug, Deployment & Operations

## Unified CLI

A Rust-based `tina4` CLI auto-detects project language and dispatches to the correct runtime.
Each framework ships its own CLI, which `tina4` forwards to: `tina4python`, `tina4php`,
`tina4ruby`, `tina4nodejs` (`tina4-js` front-end projects use esbuild).

### Commands
The authority is `tina4 --help` and the `tina4-cli` skill; this is the shape (tina4 `src/main.rs`).
```bash
tina4 init [lang] [name]   # Scaffold a new project
tina4 serve                # Dev server: file watcher, SCSS, browser reload
tina4 scss                 # Compile src/scss -> src/public/css
tina4 build                # Production front-end assets: SCSS minify + bundle (--no-minify)
tina4 deploy <target>      # Generate deployment SCAFFOLDING only: docker | systemd | nginx | cpanel
                           #   (--runtime cli|fpm|swoole for PHP docker, --force to overwrite)
tina4 env | metrics | codeql | doctor | install | setup | skills | update | ...
```
Any command the client does not own is forwarded verbatim to the framework CLI, so
`tina4 migrate`, `tina4 migrate:create`, `tina4 seed`, `tina4 test`, `tina4 routes`, `tina4 lint`
and the rest are owned by each framework. The CLI does not build or push images, deploy to
Kubernetes, or promote between environments - that is the app's own pipeline (the `tina4-k8s`
skill covers the GitOps flow).

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

Error diagnostics as JSON files in `data/.broken/` (Python `tina4_python/core/server.py`).

- An unhandled route error, or a route file that fails to import during auto-discovery, writes a
  `.broken` sentinel with the error details.
- The **dev dashboard** reads them. They are diagnostics only: they do **not** drive the health
  endpoint's status code and are not in its body (ADR-0016).
- Sentinels from a previous run are cleared at startup, so they describe THIS run only.

---

## Structured Logging

The logger is a shared cross-framework contract:
`tina4-documentation/plan/v3/fixtures/logger_contract.json` (feature 2, ADR-0041). Python
`tina4_python/debug/__init__.py` documents every setting.

- **Format** (`TINA4_LOG_FORMAT`): `json` in production, `text` when `TINA4_DEBUG` is truthy.
- **Levels**: `ALL`, `DEBUG`, `INFO`, `WARNING`, `ERROR`, `CRITICAL`, `NONE`. `TINA4_LOG_LEVEL`
  gates the console (default `INFO`); `TINA4_LOG_FILE_LEVEL` gates the file sink (default `ALL`).
- **Sinks** (`TINA4_LOG_OUTPUT`): `stdout`, `file` or `both`; the file sink is on by default only in
  debug. `TINA4_LOG_DIR` (default `logs`), `TINA4_LOG_FILE`.
- **Rotation** is size-based only: `TINA4_LOG_ROTATE_SIZE` (bytes, default 10 MiB, minimum 1024)
  and `TINA4_LOG_ROTATE_KEEP` (backups, default 5). No time-based rotation, no compression, no
  channels.
- The request id is carried as the `request_id` key of each event.
- Removed settings hard-fail configuration: `TINA4_LOG_MAX_SIZE`, `TINA4_LOG_KEEP`,
  `TINA4_LOG_APPEND`, `TINA4_DEBUG_LEVEL`, `TINA4_LOG_CRITICAL`.

---

## Environment Configuration

`.env` file with standard KEY=VALUE pairs.

### Key Variables
```env
TINA4_SECRET=your-jwt-secret
TINA4_DATABASE_URL=sqlite:data/app.db
TINA4_DEBUG=true
TINA4_LOG_LEVEL=DEBUG
TINA4_LOCALE=en
TINA4_SESSION_BACKEND=file
TINA4_SWAGGER_TITLE=My API
TINA4_API_KEY=optional-key
```

Precedence: real environment > `.env.local` (gitignored, local-only keys such as the generated dev
secret) > `.env`. `TINA4_ENV_FILE` names a different `.env` file; a `.env.local` beside it still
applies. There are no `.env.development` / `.env.production` / `.env.testing` files.

---

## Docker

### Official Base Images (Docker Hub)

Sizes are the **compressed** Docker Hub sizes of `:v3` (amd64; arm64 within 0.5 MB), read from the
Hub API on 2026-11-17. Re-measure on the Hub API before you quote them.

| Framework | Image | Port | Size (compressed) | Base (repo `Dockerfile`) |
|-----------|-------|------|-------------------|--------------------------|
| Python | `tina4stack/tina4-python:v3` | 7146 | ~14 MB | `alpine:3.23` (built on `python:3.13-alpine3.23`) |
| PHP | `tina4stack/tina4-php:v3` | 7145 | ~43 MB | `php:8.4.27-cli-alpine3.23` |
| Ruby | `tina4stack/tina4-ruby:v3` | 7147 | ~42 MB | `ruby:3.3-alpine` |
| Node.js | `tina4stack/tina4-nodejs:v3` | 7148 | ~65 MB | `node:24-alpine` |

Base images ship with **SQLite only** and set `TINA4_OVERRIDE_CLIENT=true` and `TINA4_DEBUG=false`
(Python also sets `TINA4_NO_BROWSER=true`). All four use multi-stage builds. App Dockerfiles extend
them with `FROM tina4stack/tina4-<lang>:v3` and only add app code + any extra database drivers.
Publishing is manual: `tina4-documentation/scripts/publish-docker-images.sh` (see
`references/checklists/release.md`).

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
    path: /__health
```

The built-in health endpoint is a **liveness** probe only (ADR-0016; Python
`tina4_python/core/server.py` `_health_handler`):
- Default path `/__health` (override with `TINA4_HEALTH_PATH`); `/health` is also kept registered.
- It answers **200** whenever the process can answer. It checks no database, cache, queue or
  network, and `.broken` files do not affect it.
- Body: `{"status": "ok", "uptime": <seconds>, "framework": "tina4-<lang>"}`, plus `version` in
  debug only (ADR-0078).
- A **readiness** endpoint that probes dependencies and returns 503 is specified in ADR-0016 but
  **not built**. Until it exists, a readiness check is the app's own route.

---

## Development Workflow

### Local Development
```bash
tina4 init          # Creates project with SQLite, zero Docker needed
tina4 serve         # Hot reload, SCSS compilation, debug overlay, Swagger UI
```

**IMPORTANT:** Always use `tina4 serve` to run the dev server. Never use `python app.py`
or `uv run python app.py` directly. The `tina4` Rust binary handles SCSS compilation,
file watching, and browser auto-open — `python app.py` skips all of this.

**CLI Guard (`--managed`):** The tina4 CLI passes `--managed` as a CLI argument when spawning
the framework server. The framework checks `"--managed" in sys.argv` and refuses to start
without it. To bypass (e.g. Docker, CI), set `TINA4_OVERRIDE_CLIENT=true` in `.env`.

### Deploying an app
`tina4 deploy docker|systemd|nginx|cpanel` writes deployment files into the project. Building,
pushing and promoting images between environments is the app's own CI/CD or GitOps pipeline (the
`tina4-k8s` skill covers Flux image automation and promotion); the `tina4` CLI has no `stage` or
`promote` command.

### Branching (framework repos)
Feature branch → PR → `v3` (the four frameworks) or `main` (`tina4` CLI, `tina4-documentation`).
There is no `develop` or staging branch. Full model: `references/workflow-and-release.md`.

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
