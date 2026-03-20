# Tina4 v3.0 — Vision & Principles

## Mission
Tina4 v3.0 reconciles tina4-python, tina4-php, tina4-ruby, and tina4-nodejs into a unified framework family with **full feature parity**, **zero unnecessary third-party dependencies**, and **language-idiomatic best practices**.

## Core Principles

### 0. Simple and Easy to Understand — For Humans AND AI
Everything in Tina4 must be immediately readable by a human developer AND by an AI assistant. No magic, no hidden behavior, no clever abstractions that require archaeology to understand. If you can't explain a feature in one sentence, redesign it.

### 0b. DX Is Utmost — Zero Friction
Developers must never struggle with finding libraries, referencing libraries, namespaces, imports, or configuration. Everything is one import away:

**Python:** `from tina4_python import get, post, ORM, Queue, Frond, Auth, Database`
**PHP:** `use Tina4\{Route, ORM, Queue, Frond, Auth, Database};`
**Ruby:** `require 'tina4'` — everything is under `Tina4::` module
**Node.js:** `import { get, post, ORM, Queue, Frond, Auth } from 'tina4';`

No hunting for sub-packages. No version conflicts. No namespace archaeology. One package, one import, everything works.

### 1. Code Efficiency Above All
Every line of code must earn its place. Learn from Laravel, FastAPI, Rails, and Hono — but only adopt patterns that reduce developer code, not framework complexity.

### 2. SQL-First ORM
The ORM embraces SQL as a first-class citizen. Results are paginated and cacheable. The developer writes less code, not less SQL. No magic query builders that hide what's happening.

### 3. Zero Third-Party for Core Features
- **Frond** (template engine) — built from scratch, twig-like syntax, identical across all four languages
- **Queue system** — built from scratch, no RabbitMQ/Kafka client libraries required for the lite backend
- **JWT Auth** — built from scratch per language (use only standard crypto libraries)
- **SCSS compilation** — built from scratch or use language-native tools only

### 4. Same Concepts, Language-Idiomatic Names
All frameworks share the same concept names but follow each language's casing convention:
- Python: `snake_case` — `fetch_one()`, `soft_delete`, `created_at`
- PHP: `camelCase` — `fetchOne()`, `softDelete`, `createdAt`
- Ruby: `snake_case` — `fetch_one`, `soft_delete`, `created_at`
- Node.js: `camelCase` — `fetchOne()`, `softDelete`, `createdAt`

### 5. Convention Over Configuration
- Routes in `src/routes/`
- Models/ORM in `src/orm/` or `src/models/`
- Migrations in `src/migrations/`
- Seeds in `src/seeds/`
- Templates in `src/templates/`
- Static files in `src/public/`
- Translations in `src/locales/`

### 6. Unified Frontend
- **frond.js** — identical JS helper library works on all four backends
- **tina4-js** — frontend framework works seamlessly with any tina4 backend

### 7. Production-Ready by Default
Every framework ships with:
- DATABASE_URL connection string parsing
- `/health` endpoint
- Graceful shutdown
- Request ID tracking
- Structured logging (JSON in production, human-readable in dev)
- Rate limiting
- CORS configuration
- Environment validation at startup

### 8. Full Test Parity
Every feature has the **same positive and negative tests** across all four frameworks. Test names and scenarios are identical; only the language syntax differs.

### 9. Independent v2 Maintenance
v2 branches remain independently maintainable until v3.0 is stable. No breaking changes to v2. v3 development happens on `v3` branches.

## What Makes Tina4 Great (Lessons Learned)

### From Laravel (PHP)
- Soft deletes as a model trait/mixin — one flag, automatic query filtering
- Route model binding — URL params auto-resolve to ORM instances
- Scopes — reusable query filters on the model itself
- Event/listener system — decouple side-effects from business logic
- Factory/seeder system — one-line test data generation

### From FastAPI (Python)
- Type hints drive validation AND documentation simultaneously
- Automatic OpenAPI generation from route definitions
- Dependency injection via function parameters
- Background tasks without full queue infrastructure
- Lifespan events for startup/shutdown

### From Rails (Ruby)
- Convention over configuration — file location IS configuration
- Generators that scaffold entire features
- Concerns/mixins for composable model behavior
- Turbo/Hotwire pattern — HTML-over-the-wire for real-time
- Migrations with explicit rollback

### From Hono/Fastify (Node.js)
- Schema validation at the router level
- Hook system (onRequest, beforeResponse, onError) vs linear middleware
- Multi-runtime support via standard APIs
- Minimal dependency philosophy

## Code Efficiency Targets

The goal is the **lightest possible codebase**. Simplify the patterns, don't over-engineer.

### Target Lines of Code Per Feature (per language)
| Feature | Target LOC | Principle |
|---------|-----------|-----------|
| DotEnv parser | ~50 | It's just key=value parsing |
| Structured logger | ~100 | Formatter + writer, that's it |
| Database adapter interface | ~50 | Define contract, not implementation |
| Each DB driver | ~150 | Implement the contract, driver-specific SQL |
| Router | ~300 | Pattern matching + handler dispatch |
| Middleware | ~50 | Just a function chain |
| ORM | ~400 | SQL builder + model hydration |
| Frond template engine | ~1500 | Lexer + parser + compiler + runtime (this is the big one) |
| JWT auth | ~100 | HMAC-SHA256 + base64, that's all JWT is |
| Sessions | ~100 per backend | Read/write a key-value store |
| Queue (DB) | ~200 | Push/pop/complete — it's just SQL |
| SCSS compiler | ~800 | Variables, nesting, mixins, math |
| Rate limiter | ~50 | Counter + timestamp per key |
| CORS | ~30 | Set headers on preflight |
| Health check | ~20 | Return JSON with status |
| Swagger | ~300 | Walk routes, emit OpenAPI JSON |
| GraphQL | ~600 | Parser + executor |
| WebSocket | ~100 | HTTP upgrade + frame protocol |
| API client | ~100 | HTTP request wrapper |
| **Total framework** | **~4000-5000** | **That's the target — under 5000 lines per language** |

### Simplification Rules
1. **If a feature can be expressed as SQL, express it as SQL** — don't build abstractions
2. **If two features share a pattern, extract it ONCE** — don't duplicate
3. **If a feature needs >500 lines, the design is wrong** — rethink the approach
4. **Remove code, don't add code** — every line must justify its existence
5. **The best code is no code** — if the stdlib does it, use the stdlib

### MongoDB — SQL Integration
All four frameworks include a SQL-to-MongoDB translator (ported from Python's `SQLToMongo`). Developers write SQL, and the MongoDB adapter translates queries. MongoDB is just another database adapter — same API, same ORM, same everything.

## Database Support (All Frameworks)
1. SQLite — default, zero-config
2. Firebird — full support
3. MySQL/MariaDB — full support
4. MSSQL — full support
5. PostgreSQL — full support
6. ODBC — generic driver for extensibility
7. Extensible adapter interface for custom drivers

## Session Backends (All Frameworks)
- **File** — default for local development
- **Redis** — for microservice deployments
- **Memcache** — for microservice deployments
- **MongoDB** — for microservice deployments
- **Database** — use the connected database as session store

## WebSocket Support
- **Python** — native async WebSocket
- **PHP** — via Swoole (OpenSwoole) WebSocket server
- **Ruby** — via Rack hijack / Puma
- **Node.js** — native `ws` or built-in WebSocket API
