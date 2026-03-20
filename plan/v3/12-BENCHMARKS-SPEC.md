# Tina4 v3.0 — Benchmark Suite

## Purpose
Automated benchmarks comparing Tina4 against the top 10 frameworks in each language. Results published on every release to prove Tina4's efficiency claims. Not marketing — real, reproducible numbers.

## Benchmark Categories

### 1. JSON Serialization (Hello World)
Simplest possible endpoint — return `{"message": "Hello, World!"}`.
Measures raw framework overhead.

### 2. Single Database Query
Fetch one row by ID from a database table.
Measures ORM/driver efficiency.

### 3. Multiple Database Queries
Fetch 20 rows from a database table.
Measures query throughput.

### 4. Template Rendering
Render an HTML page with 100 items from database, using the framework's template engine.
Measures Frond vs Twig/Jinja2/ERB/EJS etc.

### 5. JSON Serialization (Large Payload)
Serialize an array of 500 objects to JSON.
Measures serialization performance.

### 6. Plaintext
Return a plain text response "Hello, World!".
Absolute minimum overhead measurement.

### 7. CRUD Operations
Full create → read → update → delete cycle on a single record.
Measures ORM write path.

### 8. Paginated Query
Fetch page 5 of 20 results from a 10,000 row table with sorting.
Measures real-world query patterns.

## Metrics Collected

| Metric | Unit | Tool |
|--------|------|------|
| Requests/sec | req/s | wrk / bombardier / hey |
| Latency (p50, p95, p99) | ms | wrk / bombardier |
| Memory usage (idle) | MB | OS metrics |
| Memory usage (under load) | MB | OS metrics |
| Startup time | ms | time command |
| Binary/install size | MB | disk usage |
| Lines of code (framework) | LOC | cloc / tokei |

## Frameworks to Benchmark Against

### Python (tina4py vs)
| # | Framework | Why included |
|---|-----------|-------------|
| 1 | FastAPI | #1 async Python framework |
| 2 | Django | Most popular, full-stack |
| 3 | Flask | Lightweight classic |
| 4 | Starlette | FastAPI's foundation, raw performance |
| 5 | Litestar | Modern, high-performance |
| 6 | Sanic | Async pioneer |
| 7 | Tornado | Mature async |
| 8 | Falcon | API-focused, fast |
| 9 | Bottle | Single-file micro-framework |
| 10 | Quart | Flask-compatible async |

### PHP (tina4php vs)
| # | Framework | Why included |
|---|-----------|-------------|
| 1 | Laravel | #1 PHP framework |
| 2 | Symfony | Enterprise standard |
| 3 | Slim | Micro-framework |
| 4 | Lumen | Laravel's micro variant |
| 5 | CodeIgniter 4 | Lightweight full-stack |
| 6 | Yii2 | Performance-focused |
| 7 | CakePHP | Convention-based |
| 8 | Laminas (Zend) | Enterprise |
| 9 | FrankenPHP | Modern PHP server |
| 10 | Hyperf | Swoole-based, async |

### Ruby (tina4rb vs)
| # | Framework | Why included |
|---|-----------|-------------|
| 1 | Rails | #1 Ruby framework |
| 2 | Sinatra | Micro-framework classic |
| 3 | Hanami | Modern full-stack |
| 4 | Roda | Fast, tree-routing |
| 5 | Grape | API-focused |
| 6 | Padrino | Sinatra-extended |
| 7 | Cuba | Micro, Rack-based |
| 8 | Camping | Ultra-minimal |
| 9 | Ramaze | Lightweight |
| 10 | Dry-web | Modern, dry-rb ecosystem |

### Node.js/TypeScript (tina4js vs)
| # | Framework | Why included |
|---|-----------|-------------|
| 1 | Express | Most popular |
| 2 | Fastify | Performance-focused |
| 3 | Hono | Multi-runtime, fast |
| 4 | Elysia | Bun-native, type-safe |
| 5 | NestJS | Enterprise, DI-based |
| 6 | Koa | Express successor |
| 7 | Hapi | Enterprise |
| 8 | AdonisJS | Full-stack, Laravel-inspired |
| 9 | Nitro | UnJS, universal |
| 10 | tRPC | Type-safe API layer |

## Benchmark Infrastructure

### Docker-Based Reproducibility
Each framework runs in its own Docker container with identical resources:

```yaml
# docker-compose.bench.yml
services:
  tina4py:
    build: ./benchmarks/python/tina4
    deploy:
      resources:
        limits:
          cpus: "2"
          memory: 512M
    ports: ["8001:8000"]

  fastapi:
    build: ./benchmarks/python/fastapi
    deploy:
      resources:
        limits:
          cpus: "2"
          memory: 512M
    ports: ["8002:8000"]

  # ... repeat for each framework

  postgres:
    image: postgres:16
    environment:
      POSTGRES_DB: bench
      POSTGRES_USER: bench
      POSTGRES_PASSWORD: bench

  load-generator:
    build: ./benchmarks/load-generator
    depends_on:
      - tina4py
      - fastapi
      # ... all frameworks
```

### Load Generator
```bash
# Each benchmark runs for 30 seconds with 256 concurrent connections
wrk -t4 -c256 -d30s http://framework:8000/json
wrk -t4 -c256 -d30s http://framework:8000/db
wrk -t4 -c256 -d30s http://framework:8000/queries?n=20
wrk -t4 -c256 -d30s http://framework:8000/template
wrk -t4 -c256 -d30s http://framework:8000/json-large
wrk -t4 -c256 -d30s http://framework:8000/plaintext
```

### Warmup
Each framework gets a 10-second warmup period before measurement begins.
JIT-compiled languages (PHP 8 JIT, PyPy) get 30 seconds.

### Database
All frameworks connect to the same PostgreSQL instance.
Same schema, same data (10,000 rows pre-seeded).

```sql
CREATE TABLE world (
    id SERIAL PRIMARY KEY,
    random_number INTEGER NOT NULL
);

CREATE TABLE fortune (
    id SERIAL PRIMARY KEY,
    message TEXT NOT NULL
);
```

This matches TechEmpower's schema for comparability.

## Result Format

### JSON Output
```json
{
  "timestamp": "2026-03-19T10:00:00Z",
  "platform": "Linux x86_64, 4 cores, 8GB RAM",
  "language": "python",
  "results": [
    {
      "framework": "tina4py",
      "version": "3.0.0",
      "benchmarks": {
        "json": {
          "requests_per_sec": 45000,
          "latency_p50_ms": 2.1,
          "latency_p95_ms": 5.3,
          "latency_p99_ms": 12.1
        },
        "db_single": { ... },
        "db_multi": { ... },
        "template": { ... },
        "json_large": { ... },
        "plaintext": { ... },
        "crud": { ... },
        "paginated": { ... }
      },
      "resources": {
        "memory_idle_mb": 18,
        "memory_load_mb": 45,
        "startup_ms": 120,
        "install_size_mb": 2.1,
        "framework_loc": 4800
      }
    },
    {
      "framework": "fastapi",
      "version": "0.115.0",
      "benchmarks": { ... }
    }
  ]
}
```

### Visual Report
Auto-generated HTML report with:
- Bar charts (req/s per framework, per benchmark)
- Latency distribution charts
- Memory comparison
- LOC comparison
- Startup time comparison
- Overall ranking table

Published to `benchmarks/results/` and optionally to GitHub Pages.

### CI Integration
Benchmarks run on:
- Every tagged release
- Weekly scheduled CI job
- Manual trigger via `tina4py bench` CLI command

```bash
# Run benchmarks locally
tina4py bench                    # All benchmarks, all competitors
tina4py bench --category json    # Just JSON benchmark
tina4py bench --compare fastapi  # Compare against one framework
tina4py bench --report html      # Generate HTML report
```

## Directory Structure
```
benchmarks/
├── docker-compose.bench.yml
├── load-generator/
│   ├── Dockerfile
│   └── run.sh
├── python/
│   ├── tina4/
│   │   ├── Dockerfile
│   │   └── app.py
│   ├── fastapi/
│   │   ├── Dockerfile
│   │   └── app.py
│   ├── django/
│   ├── flask/
│   └── ... (10 frameworks)
├── php/
│   ├── tina4/
│   ├── laravel/
│   ├── symfony/
│   └── ... (10 frameworks)
├── ruby/
│   ├── tina4/
│   ├── rails/
│   ├── sinatra/
│   └── ... (10 frameworks)
├── nodejs/
│   ├── tina4/
│   ├── express/
│   ├── fastify/
│   └── ... (10 frameworks)
├── results/
│   ├── latest.json
│   ├── history/
│   └── report.html
└── README.md
```

## Benchmark Endpoint Implementations

Each framework implements these identical endpoints:

```
GET /json           → {"message": "Hello, World!"}
GET /plaintext      → Hello, World!
GET /db             → fetch random row from world table
GET /queries?n=20   → fetch N random rows
GET /template       → render HTML with 100 fortunes from DB
GET /json-large     → serialize 500 world rows to JSON
POST /crud          → create record, return it
PUT /crud/:id       → update record
DELETE /crud/:id    → delete record
GET /paginated?page=5&per_page=20&sort=-id → paginated query
```

## 9. Carbon Ranking (Carbonah)

Tina4 v3 integrates with **Carbonah** — a green measurement tool — to benchmark carbon efficiency alongside performance.

### Metrics
| Metric | Unit | Source |
|--------|------|--------|
| Energy per request | mJ/req | Carbonah |
| Carbon per 1M requests | gCO2e | Carbonah |
| Idle power consumption | watts | Carbonah |
| Carbon efficiency score | score | Carbonah (composite) |

### How It Works
- Each benchmark run feeds performance data + resource usage to Carbonah
- Carbonah calculates energy consumption based on CPU usage, memory, and duration
- Results include a carbon ranking alongside the performance ranking
- Lower LOC + lower memory + fewer dependencies = greener framework

### Benchmark Addition
```
GET /carbon-report    → Carbonah carbon metrics for this framework instance
```

Each framework's benchmark container is instrumented with Carbonah's agent. After the benchmark completes, Carbonah produces a per-framework carbon report.

### Why This Matters
- Cloud computing accounts for ~2% of global carbon emissions
- Framework efficiency directly impacts energy consumption at scale
- Tina4's zero-dep, ~5000 LOC philosophy should produce best-in-class carbon scores
- This is a differentiator no other framework benchmarks

### Result Format Addition
```json
{
  "framework": "tina4py",
  "carbon": {
    "energy_per_request_mj": 0.42,
    "carbon_per_1m_requests_gco2e": 18.5,
    "idle_power_watts": 2.1,
    "carbon_efficiency_score": 92,
    "carbonah_rating": "A+"
  }
}
```

---

## What We're Proving

| Claim | Benchmark |
|-------|-----------|
| Tina4 has minimal overhead | plaintext, json |
| Zero-dep doesn't mean slow | All benchmarks |
| Frond is competitive with Twig/Jinja2 | template |
| SQL-first ORM is fast | db_single, db_multi, paginated |
| ~5000 LOC is enough | framework_loc metric |
| Greenest framework | Carbonah carbon ranking |
| Low memory footprint | memory_idle, memory_load |
| Fast startup | startup_ms |
| Small install | install_size_mb |
