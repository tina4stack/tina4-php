# Tina4 v3 — Cross-Framework Parity Checklist

Every feature must produce **identical results** across all 4 frameworks.
Each framework gets a color: Python (blue), PHP (purple), Ruby (red), Node.js (green).

## Core Features (Must be identical)

| # | Feature | Python | PHP | Ruby | Node.js | Notes |
|---|---------|--------|-----|------|---------|-------|
| 1 | **CLI: init [dir]** | ✅ | ✅ | ✅ | ✅ | Same directory structure |
| 2 | **CLI: serve [port]** | ✅ | ✅ | ✅ | ✅ | Default 7145 |
| 3 | **CLI: start [port]** | ✅ | ✅ | ✅ | ✅ | Alias for serve |
| 4 | **CLI: migrate** | ✅ | ✅ | ✅ | ✅ | |
| 5 | **CLI: migrate:create** | ✅ | ✅ | ✅ | ✅ | |
| 6 | **CLI: migrate:rollback** | ✅ | ❌ stub | ✅ | ✅ | |
| 7 | **CLI: seed** | ✅ | ✅ | ✅ | ✅ | |
| 8 | **CLI: routes** | ✅ | ✅ | ✅ | ✅ | |
| 9 | **CLI: test** | ✅ | ✅ | ✅ | ✅ | |
| 10 | **CLI: help** | ✅ | ✅ | ✅ | ✅ | |
| 11 | **Default Landing Page** (no / route, no index template) | ✅ | ✅ | ✅ | ✅ | Just added |
| 12 | **Health Check** `/health` | ✅ | ✅ | ✅ | ✅ | Same JSON format |
| 13 | **Dev Overlay** (injected into HTML in debug mode) | ✅ | ❌ | ❌ | ❌ | Python only |
| 14 | **`/__dev` Dashboard** | ✅ | ❌ | ❌ | ❌ | Python only |

## Routing (Must be identical)

| # | Feature | Python | PHP | Ruby | Node.js |
|---|---------|--------|-----|------|---------|
| 15 | GET/POST/PUT/PATCH/DELETE/ANY | ✅ | ✅ | ✅ | ✅ |
| 16 | Path params `{id}` | ✅ | ✅ | ✅ | ✅ |
| 17 | Typed params `{id:int}` | ✅ | ❌ | ✅ | ❌ |
| 18 | Catch-all `{slug:.*}` | ✅ | ✅ | ✅ | ✅ |
| 19 | Route groups | ✅ | ✅ | ✅ | ✅ |
| 20 | Per-route middleware | ✅ | ✅ | ✅ | ✅ |
| 21 | `.cache()` modifier | ✅ | ✅ | ❌ | ✅ |
| 22 | `.secure()` modifier | ✅ | ✅ | ✅ | ✅ |
| 23 | Route discovery (file-based) | ✅ | ✅ | ✅ | ✅ |

## Data Layer (Must be identical API)

| # | Feature | Python | PHP | Ruby | Node.js |
|---|---------|--------|-----|------|---------|
| 24 | Database abstraction | ✅ | ✅ | ✅ | ✅ |
| 25 | SQLite driver | ✅ | ✅ | ✅ | ✅ |
| 26 | PostgreSQL driver | ✅ | ❌ iface | ✅ | ❌ stub |
| 27 | MySQL driver | ✅ | ❌ iface | ✅ | ❌ stub |
| 28 | ORM (Active Record) | ✅ | ✅ | ✅ | ✅ |
| 29 | Auto-CRUD (REST from models) | ❌ stub | ✅ | ✅ | ✅ |
| 30 | Migrations | ✅ | ✅ | ✅ | ✅ |
| 31 | Seeder / Fake Data | ✅ | ✅ | ✅ | ✅ |

## Auth & Security

| # | Feature | Python | PHP | Ruby | Node.js |
|---|---------|--------|-----|------|---------|
| 32 | JWT generation/validation | ✅ | ✅ | ✅ | ✅ |
| 33 | Password hashing (PBKDF2/BCrypt) | ✅ | ✅ | ✅ | ✅ |
| 34 | CORS middleware | ✅ | ✅ | ✅ | ✅ |
| 35 | Rate limiter | ✅ | ✅ | ✅ | ✅ |
| 36 | Sessions | ✅ | ✅ | ✅ | ✅ |

## Template & Frontend

| # | Feature | Python | PHP | Ruby | Node.js |
|---|---------|--------|-----|------|---------|
| 37 | Frond/Twig template engine | ✅ | ✅ | ✅ | ✅ |
| 38 | SCSS compiler | ✅ | ✅ | ✅ | ✅ |
| 39 | Static file serving | ✅ | ❌ | ✅ | ✅ |

## Advanced Features

| # | Feature | Python | PHP | Ruby | Node.js |
|---|---------|--------|-----|------|---------|
| 40 | GraphQL | ✅ | ✅ | ✅ | ✅ |
| 41 | WebSocket | ✅ | ✅ | ✅ | ✅ |
| 42 | WSDL/SOAP | ✅ | ❌ | ✅ | ❌ |
| 43 | Queue system | ✅ | ✅ | ✅ | ✅ |
| 44 | i18n / Localization | ✅ | ✅ | ✅ | ✅ |
| 45 | Swagger / OpenAPI | ✅ | ❌ | ✅ | ✅ |
| 46 | HTTP Client (Api) | ✅ | ❌ | ✅ | ❌ |
| 47 | Email (Messenger) | ✅ | ❌ | ❌ | ❌ |
| 48 | Background Services | ❌ | ✅ | ✅ | ✅ |
| 49 | Events / Observer | ✅ | ❌ | ❌ | ❌ |
| 50 | DI Container | ❌ | ❌ | ✅ | ❌ |

## Logging (Must produce identical output format)

Target format (human-readable / dev mode):
```
2026-03-20T13:25:30.660Z [INFO   ] [request_id] Message {"key":"value"}
```

Target format (production / JSON lines):
```json
{"timestamp":"2026-03-20T13:25:30.660Z","level":"INFO","message":"Message","request_id":"abc123","context":{"key":"value"}}
```

| # | Feature | Python | PHP | Ruby | Node.js |
|---|---------|--------|-----|------|---------|
| 51 | ISO 8601 UTC timestamps with ms | ❌ local HH:MM:SS | ✅ ref | ❌ local Y-m-d H:M:S | ✅ |
| 52 | Level names: DEBUG/INFO/WARNING/ERROR | ✅ | ✅ | ❌ uses WARN | ✅ |
| 53 | 7-char padded level `[INFO   ]` | ✅ | ✅ | ❌ | ❌ |
| 54 | Request ID `[abc123]` | ✅ | ✅ | ✅ | ✅ |
| 55 | Structured context `{"key":"val"}` | ✅ kwargs | ✅ array | ❌ | ✅ data param |
| 56 | Color-coded stdout | ❌ | ✅ | ✅ | ✅ |
| 57 | Log rotation (10MB + daily) | ✅ | ✅ | ✅ | ✅ |
| 58 | JSON lines mode (production) | ✅ | ✅ | ✅ | ✅ |

## Health Check Response (Must be identical JSON)

```json
{
  "status": "ok",
  "version": "3.0.0",
  "uptime": 123.45,
  "framework": "tina4-{language}"
}
```

| Field | Python | PHP | Ruby | Node.js |
|-------|--------|-----|------|---------|
| status | ✅ | ✅ | ✅ | ✅ |
| version | ✅ | ✅ | ✅ | ✅ |
| uptime | ✅ uptime_seconds | ⚠️ uptime | ✅ uptime | ✅ uptime |
| framework | ✅ tina4py | ❌ missing | ✅ tina4-ruby | ✅ tina4-nodejs |

## Priority Fixes

1. **Logger alignment** — all 4 must produce identical format
2. **Dev overlay** — all 4 must inject debug toolbar in dev mode
3. **`/__dev` dashboard** — all 4 need it
4. **Health check JSON** — normalize field names
5. **Static file serving** — PHP needs built-in serving
6. **Swagger/OpenAPI** — PHP needs it
7. **WSDL/SOAP** — PHP and Node.js need it (or explicitly mark as not planned)
8. **HTTP Client (Api)** — PHP and Node.js need it
