# Tina4 v3 — Framework Feature & Performance Report

**Date:** 2026-03-21 | **Goal:** Outperform the competition on features and close the performance gap

---

## Performance Benchmarks

### Python — Tina4 vs Competition

Real HTTP benchmarks — identical JSON endpoint, 5000 requests, 50 concurrent.

| Framework | JSON req/s | 100-item list req/s | Server | Deps |
|-----------|:---------:|:-------------------:|--------|:----:|
| Starlette 0.52 | 16,202 | 7,351 | uvicorn (C) | 4 |
| FastAPI 0.115 | 11,855 | 2,476 | uvicorn (C) | 12+ |
| **Tina4 Python 3.0** | **8,316** | **5,688** | **built-in** | **0** |
| Bottle 0.13 | ~7,000 | ~5,000 | built-in | 0 |
| Flask 3.1 | 4,953 | 3,899 | Werkzeug | 6 |
| Django 5.2 | ~3,500 | ~2,800 | runserver | 20+ |

### PHP — Tina4 vs Competition

| Framework | Typical JSON req/s | Deps |
|-----------|:-----------------:|:----:|
| Swoole (async) | ~30,000 | ext |
| Slim 4 | ~5,000 | 10+ |
| **Tina4 PHP 3.0** | **TBD** | **0** |
| Symfony 7 | ~2,500 | 30+ |
| Laravel 11 | ~2,000 | 50+ |
| CodeIgniter 4 | ~3,500 | 15+ |

### Ruby — Tina4 vs Competition

| Framework | Typical JSON req/s | Deps |
|-----------|:-----------------:|:----:|
| Roda | ~15,000 | 1 |
| **Tina4 Ruby 3.0** | **TBD** | **0** |
| Sinatra 4 | ~4,000 | 5 |
| Hanami 2 | ~3,000 | 20+ |
| Rails 7 | ~1,500 | 40+ |

### Node.js — Tina4 vs Competition

| Framework | Typical JSON req/s | Deps |
|-----------|:-----------------:|:----:|
| Fastify | ~50,000 | 10+ |
| Koa | ~20,000 | 5 |
| **Tina4 Node.js 3.0** | **TBD** | **0** |
| Express 5 | ~15,000 | 3 |
| NestJS | ~12,000 | 20+ |
| Hapi | ~10,000 | 5 |

**TBD benchmarks:** Run `tina4 serve` on each framework and benchmark with `hey`. Coming in rc.3.

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
| **Tina4** | **38/38** | **0** | **8,316** |
| Django | 22/38 | 20+ | ~3,500 |
| Flask | 7/38 | 6 | 4,953 |
| FastAPI | 8/38 | 12+ | 11,855 |
| Starlette | 6/38 | 4 | 16,202 |
| Bottle | 5/38 | 0 | ~7,000 |

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

### v3.1 — Close the Gap
- [ ] Pre-compile Frond template expressions (target: 5x template rendering)
- [ ] Pre-compile regex in `_resolve()` and `_eval_expr()` (target: 3x variable lookup)
- [ ] Optional uvicorn/hypercorn detection for production (target: 16K+ req/s)
- [ ] Connection pooling for database adapters

### v3.2 — Overtake
- [ ] Compiled template bytecode (match Jinja2 speed)
- [ ] HTTP/2 support in built-in server
- [ ] Response streaming for large payloads
- [ ] Worker process support (multi-core)

### v3.3 — Lead
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

- Performance numbers are from development servers on Apple Silicon
- Production deployments with gunicorn/uvicorn/puma/php-fpm would be faster for all frameworks
- Tina4's competitive advantage is **features per dependency** — 38 features with 0 deps
- The zero-dep philosophy means Tina4 works anywhere Python/PHP/Ruby/Node.js runs — no compiler needed, no native extensions, no build step
