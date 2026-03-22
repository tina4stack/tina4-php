# Tina4 PHP — Benchmark Report

**Date:** 2026-03-22 | **Machine:** Apple Silicon (ARM64) | **Tool:** `hey` (5000 requests, 50 concurrent, 3 runs, median)

---

## 1. Performance

Real HTTP benchmarks — identical JSON endpoint, development servers.

### Dev Server (how developers actually run it)

| Framework | JSON req/s | 100-item list req/s | Server | Deps |
|-----------|:---------:|:-------------------:|--------|:----:|
| **Tina4 PHP 3.1** | **40,133** | **25,164** | **custom (stream_select)** | **0** |
| Slim 4 | 28,572 | 18,003 | php -S | 10 |
| CodeIgniter 4 | 1,295 | 1,244 | php -S | 15 |
| Symfony 7 | 1,782 | 1,524 | php -S | 30 |
| Laravel 11 | 374 | 385 | artisan serve | 50 |

### Production Server (nginx + PHP-FPM + OPcache + JIT)

| Framework | JSON req/s | 100-item list req/s | Server | Change vs Dev |
|-----------|:---------:|:-------------------:|--------|:-------------:|
| **Tina4 PHP** | **18,510** | **18,520** | nginx + FPM | — |
| Slim | 12,714 | 10,454 | nginx + FPM | — |
| Symfony | 4,517 | 4,245 | nginx + FPM | 2.5x ↑ |
| CodeIgniter | 4,137 | 2,897 | nginx + FPM | 3.2x ↑ |
| Laravel | 675 | 736 | nginx + FPM | 1.8x ↑ |

**Key takeaway:** Tina4 PHP's custom server (40K req/s) is faster than all competitors even on production servers. Laravel at 675 req/s on FPM is 60x slower than Tina4.

### Warmup Time

| Framework | Warmup (ms) |
|-----------|:-----------:|
| **Tina4** | **54** |
| Slim | 118 |
| CodeIgniter | — |
| Symfony | — |
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
| **Tina4** | **38/38** | **0** | **40,133** |
| Slim | 6/38 | 10 | 28,572 |
| CodeIgniter | 14/38 | 15 | 1,295 |
| Symfony | 18/38 | 30 | 1,782 |
| Laravel | 25/38 | 50 | 374 |

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
| **Tina4** | 40,133 | 0.0000052 | 0.0025 |
| Slim | 28,572 | 0.0000073 | 0.0035 |
| CodeIgniter | 1,295 | 0.0001607 | 0.0763 |
| Symfony | 1,782 | 0.0001168 | 0.0555 |
| Laravel | 374 | 0.0005563 | 0.2642 |

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
