# Tina4 v3 — Framework Feature & Performance Report

**Date:** 2026-03-21 | **Goal:** Outperform the competition on features and close the performance gap

---

## Performance Benchmarks

### Python — Tina4 vs Competition

Real HTTP benchmarks — identical JSON endpoint, 5000 requests, 50 concurrent.

| Framework | JSON req/s | 100-item list req/s | Server | Deps |
|-----------|:---------:|:-------------------:|--------|:----:|
| **Tina4 Python 3.0** | **16,233** | **5,858** | **built-in** | **0** |
| Starlette 0.52 | 15,978 | 7,493 | uvicorn (C) | 4 |
| FastAPI 0.115 | 11,886 | 2,464 | uvicorn (C) | 12+ |
| Flask 3.1 | 4,767 | 1,644 | Werkzeug | 6 |
| Django 5.2 | 3,747 | 3,305 | runserver | 20+ |
| Bottle 0.13 | 1,251 | 676 | built-in | 0 |

### PHP — Tina4 vs Competition

Real HTTP benchmarks — identical JSON endpoint, 5000 requests, 50 concurrent.

| Framework | JSON req/s | 100-item list req/s | Server | Deps |
|-----------|:---------:|:-------------------:|--------|:----:|
| **Tina4 PHP 3.0** | **27,299** | **16,555** | **built-in** | **0** |
| Slim 4 | 5,033 | 4,520 | built-in | 10+ |
| Symfony 7 | 1,840 | 1,702 | built-in | 30+ |
| Laravel 11 | 370 | 364 | artisan | 50+ |

### Ruby — Tina4 vs Competition

Real HTTP benchmarks — identical JSON endpoint, 5000 requests, 50 concurrent.

| Framework | JSON req/s | 100-item list req/s | Server | Deps |
|-----------|:---------:|:-------------------:|--------|:----:|
| Roda | 20,964 | 12,265 | Puma | 1 |
| Sinatra | 9,909 | 7,229 | Puma | 5 |
| **Tina4 Ruby 3.0** | **9,504** | **7,648** | **WEBrick** | **0** |
| Rails 7 | 4,754 | 4,052 | Puma | 69 |

### Node.js — Tina4 vs Competition

Real HTTP benchmarks — identical JSON endpoint, 5000 requests, 50 concurrent.

| Framework | JSON req/s | 100-item list req/s | Server | Deps |
|-----------|:---------:|:-------------------:|--------|:----:|
| Node.js raw http | 86,662 | 24,598 | http | 0 |
| Fastify | 79,505 | 23,395 | http | 10+ |
| Koa | 60,400 | 23,433 | http | 5 |
| **Tina4 Node.js 3.0** | **57,035** | **25,088** | **http** | **0** |
| Express 5 | 56,687 | 20,720 | http | 3 |

### Production Server Results

| Framework | Dev Server | Dev JSON/s | Prod Server | Prod JSON/s | Change |
|-----------|-----------|:---------:|-------------|:---------:|:------:|
| **Tina4 PHP** | php -S | 27,299 | php + OPcache | **27,486** | ~same |
| **Tina4 Ruby** | WEBrick | 8,139 | Puma | **22,784** | **2.8x** |
| **Tina4 Node.js** | http single | 57,035 | cluster (8 workers) | 12,488 | see note |
| **Tina4 Python** | asyncio | 16,233 | uvicorn | 9,801 | see note |

Note: Production servers excel under sustained high concurrency. Burst benchmarks from localhost don't show their full advantage.

---

## Out-of-Box Feature Comparison (38 features)

✅ = ships with core install, no extra packages | ❌ = requires additional install

### Python Frameworks

| Feature | Tina4 | Flask | FastAPI | Django | Starlette | Bottle |
|---------|:-----:|:-----:|:-------:|:------:|:---------:|:------:|
| **CORE WEB** | | | | | | |
| Routing (decorators) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Typed path parameters | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Middleware system | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Static file serving | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| CORS built-in | ✅ | ❌ | ✅ | ❌ | ✅ | ❌ |
| Rate limiting | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| WebSocket | ✅ | ❌ | ✅ | ❌ | ✅ | ❌ |
| **DATA** | | | | | | |
| ORM | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ |
| 5 database drivers | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ |
| Migrations | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ |
| Seeder / fake data | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Sessions | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ |
| Response caching | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ |
| **AUTH** | | | | | | |
| JWT built-in | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Password hashing | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ |
| CSRF protection | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ |
| **FRONTEND** | | | | | | |
| Template engine | ✅ | ✅ | ❌ | ✅ | ❌ | ✅ |
| CSS framework | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| SCSS compiler | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Frontend JS helpers | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **API** | | | | | | |
| Swagger/OpenAPI | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ |
| GraphQL | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| SOAP/WSDL | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| HTTP client | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Queue system | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **DEV EXPERIENCE** | | | | | | |
| CLI scaffolding | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ |
| Dev admin dashboard | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ |
| Error overlay | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ |
| Live reload | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ |
| Auto-CRUD generator | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ |
| Gallery / examples | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| AI assistant context | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Inline testing | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **ARCHITECTURE** | | | | | | |
| Zero dependencies | ✅ | ❌ | ❌ | ❌ | ❌ | ✅ |
| Dependency injection | ✅ | ❌ | ✅ | ❌ | ❌ | ❌ |
| Event system | ✅ | ✅ | ❌ | ✅ | ❌ | ❌ |
| i18n / translations | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ |
| HTML builder | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |

### Feature Count — Python

| Framework | Features | Deps | JSON req/s |
|-----------|:-------:|:----:|:---------:|
| **Tina4** | **38/38** | **0** | **16,233** |
| Django | 22/38 | 20+ | 3,747 |
| Flask | 7/38 | 6 | 4,767 |
| FastAPI | 8/38 | 12+ | 11,886 |
| Starlette | 6/38 | 4 | 15,978 |
| Bottle | 5/38 | 0 | 1,251 |

### Cross-Language Feature Count

| Framework | Language | Features | Deps |
|-----------|---------|:-------:|:----:|
| **Tina4** | Python/PHP/Ruby/Node.js | **38/38** | **0** |
| Laravel | PHP | 25/38 | 50+ |
| Rails | Ruby | 24/38 | 40+ |
| Django | Python | 22/38 | 20+ |
| NestJS | Node.js | 16/38 | 20+ |
| FastAPI | Python | 8/38 | 12+ |
| Flask | Python | 7/38 | 6 |
| Starlette | Python | 6/38 | 4 |
| Bottle | Python | 5/38 | 0 |
| Express | Node.js | 4/38 | 3 |
| Sinatra | Ruby | 4/38 | 5 |

---

## Tina4 Performance Roadmap

### v3.0 — Achieved
- [x] Pre-compile Frond templates (2.8x render improvement)
- [x] Production server auto-detection (uvicorn/Puma/cluster)
- [x] DB query caching (TINA4_DB_CACHE=true, 4x speedup)
- [x] ORM relationships with eager loading

### v3.1 — Next
- [ ] Compiled template bytecode (match Jinja2 speed)
- [ ] Connection pooling for database adapters
- [ ] HTTP/2 support in built-in server
- [ ] Response streaming for large payloads

### v3.2 — Future
- [ ] HTTP/3 (QUIC) support
- [ ] gRPC built-in
- [ ] Edge runtime support (Cloudflare Workers, Deno Deploy)

---

## Deployment Size

| Framework | Core Size | With Deps | Competitors |
|-----------|:---------:|:---------:|------------|
| Tina4 Python | ~2.4 MB | 2.4 MB (0 deps) | Django 25 MB, Flask 4.2 MB |
| Tina4 PHP | ~1.0 MB | 1.0 MB (0 deps) | Laravel 50+ MB, Symfony 30+ MB |
| Tina4 Ruby | ~892 KB | 892 KB (0 deps) | Rails 40+ MB, Sinatra 5 MB |
| Tina4 Node.js | ~1.8 MB | 1.8 MB (0 deps) | Express 2 MB, NestJS 20+ MB |

Zero dependencies means core size **is** deployment size. No `node_modules` bloat, no `vendor/` sprawl, no `site-packages` explosion.

---

## Cross-Platform Support

All 4 Tina4 frameworks run on:

- **macOS** (Intel + Apple Silicon)
- **Linux** (x86_64 + ARM64)
- **Windows** (10/11)
- **Docker** (any base image with the language runtime)
- **WSL2**

No C extensions, no native binaries, no compile step required. Pure Python/PHP/Ruby/JavaScript.

---

## Notes

- Performance numbers are from `hey` benchmarks (5000 requests, 50 concurrent) on Apple M3
- Production server results included: `tina4 serve --production` auto-installs the best server per language
- Tina4's competitive advantage is **features per dependency** — 38 features with 0 deps
- Total test suite: **6,183 tests** across all 4 languages (Python 1633, PHP 1304, Ruby 1577, Node.js 1669)
- The zero-dep philosophy means Tina4 works anywhere Python/PHP/Ruby/Node.js runs — no compiler needed, no native extensions, no build step
