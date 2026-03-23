# Tina4 PHP — Benchmark Report

**Date:** 2026-03-23 | **Machine:** Apple Silicon (ARM64), 8 cores | **Tool:** `hey` (5000 requests, 50 concurrent, 3 runs, median)

---

## 1. Performance

Real HTTP benchmarks — identical JSON endpoint. Tina4 uses its built-in `stream_select` server; competitors use their default dev servers.

| Framework | JSON req/s | 100-item list req/s | Server | Deps |
|-----------|:---------:|:-------------------:|--------|:----:|
| **Tina4 PHP 3.2** | **18,666** | **16,312** | **stream_select (built-in)** | **0** |
| Slim 4 | 5,082 | 3,312 | php -S | 2 |
| Symfony 7 | 1,589 | 1,305 | php -S | 30+ |
| CodeIgniter 4 | 1,311 | 1,288 | spark serve | 15+ |
| Laravel 11 | 257 | 313 | artisan serve | 70+ |

**Key takeaway:** Tina4 PHP dominates at 18,666 req/s — 3.7x faster than Slim, 11.7x faster than Symfony, and 73x faster than Laravel, while shipping 38 features with 0 dependencies. Tina4's custom `stream_select` non-blocking server outperforms even PHP's built-in `php -S` server.

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
| **Tina4** | **38/38** | **0** | **18,666** |
| Laravel | 25/38 | 70+ | 257 |
| Symfony | 18/38 | 30+ | 1,589 |
| CodeIgniter | 14/38 | 15+ | 1,311 |
| Slim | 6/38 | 2 | 5,082 |

---

## 3. Deployment Size

| Framework | Install Size | Dependencies |
|-----------|:----------:|:------------:|
| **Tina4 PHP** | **~1.5 MB** | **0** |
| Slim | ~3 MB | 2 |
| CodeIgniter | ~12 MB | 15+ |
| Symfony | ~25 MB | 30+ |
| Laravel | ~50 MB | 70+ |

Zero dependencies means core size **is** deployment size. No `vendor/` sprawl.

---

## 4. CO2 / Carbonah

Estimated emissions per HTTP benchmark run (5000 requests on Apple Silicon, 15W TDP).

Formula: `Energy(kWh) = (15W × seconds_for_5000_requests) / 3,600,000` | `CO2(g) = kWh × 475`

| Framework | JSON req/s | Est. Energy (kWh) | Est. CO2 (g) |
|-----------|:---------:|:-----------------:|:------------:|
| **Tina4** | 18,666 | 0.0000011 | 0.0005 |
| Slim | 5,082 | 0.0000041 | 0.0019 |
| Symfony | 1,589 | 0.0000131 | 0.0062 |
| CodeIgniter | 1,311 | 0.0000159 | 0.0075 |
| Laravel | 257 | 0.0000811 | 0.0385 |

*CO2 calculated at world average 475g CO2/kWh. Lower req/s = longer to serve 5000 requests = more energy.*

Laravel emits **77x more CO2** per benchmark run than Tina4.

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
