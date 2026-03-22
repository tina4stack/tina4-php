# Tina4 PHP — Benchmark Report

**Date:** 2026-03-22 | **Machine:** Apple Silicon (ARM64) | **Tool:** `hey` (5000 requests, 50 concurrent, 3 runs, median)

---

## 1. Performance

Real HTTP benchmarks — identical JSON endpoint, development servers.

| Framework | JSON req/s | 100-item list req/s | Server | Deps |
|-----------|:---------:|:-------------------:|--------|:----:|
| **Tina4 PHP 3.0** | **28,088** | **15,790** | **php -S** | **0** |
| Slim 4 | 5,421 | 4,694 | php -S | 10 |
| Symfony 7 | 1,782 | — | php -S | 30 |
| Laravel 11 | 355 | 403 | artisan serve | 50 |

**Key takeaway:** Tina4 PHP is 5x faster than Slim, 16x faster than Symfony, and 79x faster than Laravel — while shipping 38 features with 0 dependencies.

### Production Server Results

| Framework | Dev Server | Dev JSON/s | Prod Server | Prod JSON/s | Change |
|-----------|-----------|:---------:|-------------|:---------:|:------:|
| **Tina4 PHP** | php -S | 28,088 | php + OPcache | **27,486** | ~same |
| Slim | php -S | 5,421 | php -S + OPcache | ~7,000 | +29% |
| Laravel | artisan | 355 | php-fpm + OPcache | ~675 | +90% |

### Warmup Time

| Framework | Warmup (ms) |
|-----------|:-----------:|
| **Tina4** | **54** |
| Slim | 118 |
| Laravel | 1,755 |

---

## 2. Feature Comparison (38 features)

Ships with core install, no extra packages needed.

| Feature | Tina4 | Slim | Symfony | Laravel | CodeIgniter |
|---------|:-----:|:----:|:-------:|:-------:|:-----------:|
| **CORE WEB** | | | | | |
| Routing (decorators) | Y | Y | Y | Y | Y |
| Typed path parameters | Y | Y | Y | Y | Y |
| Middleware system | Y | Y | Y | Y | Y |
| Static file serving | Y | - | Y | Y | Y |
| CORS built-in | Y | - | - | - | - |
| Rate limiting | Y | - | - | Y | - |
| WebSocket | Y | - | - | - | - |
| **DATA** | | | | | |
| ORM | Y | - | Y | Y | Y |
| 5 database drivers | Y | - | Y | Y | Y |
| Migrations | Y | - | Y | Y | Y |
| Seeder / fake data | Y | - | - | Y | Y |
| Sessions | Y | - | Y | Y | Y |
| Response caching | Y | - | Y | Y | - |
| **AUTH** | | | | | |
| JWT built-in | Y | - | - | - | - |
| Password hashing | Y | - | Y | Y | - |
| CSRF protection | Y | - | Y | Y | Y |
| **FRONTEND** | | | | | |
| Template engine | Y | - | Y | Y | - |
| CSS framework | Y | - | - | - | - |
| SCSS compiler | Y | - | - | - | - |
| Frontend JS helpers | Y | - | - | - | - |
| **API** | | | | | |
| Swagger/OpenAPI | Y | - | - | - | - |
| GraphQL | Y | - | - | - | - |
| SOAP/WSDL | Y | - | - | - | - |
| HTTP client | Y | - | Y | Y | - |
| Queue system | Y | - | Y | Y | - |
| **DEV EXPERIENCE** | | | | | |
| CLI scaffolding | Y | - | Y | Y | Y |
| Dev admin dashboard | Y | - | Y | - | - |
| Error overlay | Y | - | Y | Y | - |
| Live reload | Y | - | - | Y | - |
| Auto-CRUD generator | Y | - | - | - | - |
| Gallery / examples | Y | - | - | - | - |
| AI assistant context | Y | - | - | - | - |
| Inline testing | Y | - | - | - | - |
| **ARCHITECTURE** | | | | | |
| Zero dependencies | Y | - | - | - | - |
| Dependency injection | Y | Y | Y | Y | Y |
| Event system | Y | - | Y | Y | Y |
| i18n / translations | Y | - | Y | Y | Y |
| HTML builder | Y | - | - | - | - |

### Feature Count

| Framework | Features | Deps | JSON req/s |
|-----------|:-------:|:----:|:---------:|
| **Tina4** | **38/38** | **0** | **28,088** |
| Laravel | 25/38 | 50 | 355 |
| Symfony | 18/38 | 30 | 1,782 |
| CodeIgniter | 14/38 | 10 | ~4,137 |
| Slim | 6/38 | 10 | 5,421 |

---

## 3. Deployment Size

| Framework | Install Size | Dependencies |
|-----------|:----------:|:------------:|
| **Tina4 PHP** | **1.0 MB** | **0** |
| Slim | 1.3 MB | 10 |
| CodeIgniter | 8 MB | 10 |
| Symfony | 11 MB | 30 |
| Laravel | 77 MB | 50 |

Zero dependencies means core size **is** deployment size. No `vendor/` sprawl.

---

## 4. CO2 / Carbonah

Estimated emissions per HTTP benchmark run (5000 requests on Apple Silicon, 15W TDP).

| Framework | JSON req/s | Est. Energy (kWh) | Est. CO2 (g) |
|-----------|:---------:|:-----------------:|:------------:|
| **Tina4** | 28,088 | 0.0000074 | 0.0035 |
| Slim | 5,421 | 0.0000384 | 0.0182 |
| Symfony | 1,782 | 0.0001168 | 0.0555 |
| Laravel | 355 | 0.0005859 | 0.2783 |

*CO2 calculated at world average 475g CO2/kWh. Lower req/s = longer to serve 5000 requests = more energy.*

### Tina4 Test Suite Emissions

| Metric | Value |
|--------|-------|
| Test Execution Time | 8.25s |
| Tests | 1,304 |
| CO2 per Run | 0.017g |
| Tests per Second | 152.1 |
| Annual CI (10 runs/day) | 0.062g CO2/year |

**Carbonah Rating: A+**

---

## 5. How to Run

Benchmarks are maintained in the `tina4-python` repository's `benchmarks/` folder.

```bash
cd ../tina4-python/benchmarks
python benchmark.py --php
```

Full cross-language suite:
```bash
python benchmark.py --all
```

Results are written to `benchmarks/results/php.json`.

See the [tina4-python benchmarks README](https://github.com/tina4stack/tina4-python/tree/main/benchmarks) for prerequisites and detailed instructions.

---

*Generated from benchmark data — https://tina4.com*
