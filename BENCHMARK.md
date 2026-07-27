# Tina4 PHP — Benchmark Report

**Date:** 2026-03-25 | **Machine:** Apple Silicon (ARM64), 8 cores | **Tool:** `hey` (5000 requests, 50 concurrent, 3 runs, averaged)

---

## 1. Performance

Real HTTP benchmarks — identical JSON endpoint. Tina4 uses its built-in `stream_select` server; competitors use their default dev servers.

| Framework | JSON req/s | 100-item list req/s | Server | Deps |
|-----------|:---------:|:-------------------:|--------|:----:|
| **Tina4 PHP 3.2** | **28,158** | **18,191** | **stream_select (built-in)** | **0** |
| Slim 4 | 5,082 | 3,312 | php -S | 2 |
| Symfony 7 | 1,589 | 1,305 | php -S | 30+ |
| CodeIgniter 4 | 1,311 | 1,288 | spark serve | 15+ |
| Laravel 11 | 257 | 313 | artisan serve | 70+ |

**Key takeaway:** Tina4 PHP dominates at 28,158 req/s — 5.5x faster than Slim, 17.7x faster than Symfony, and 110x faster than Laravel, while shipping 98 features with 0 dependencies. Tina4's custom `stream_select` non-blocking server outperforms even PHP's built-in `php -S` server.

---

## 1b. Template rendering, Frond vs Twig and Blade

**Date:** 2026-07-27 | **Machine:** Apple Silicon (ARM64), macOS | **PHP:** 8.5.7 | **Tool:** `benchmarks/bench_templates.php` (p50 over batched samples, min 0.25s / 200 iterations)

This category used to be missing, and its absence flattered us. Sections 1 and 2 above
measure request throughput and feature count, where Tina4 wins. Neither says anything
about template rendering, the one axis where Frond competes head-on with the engine it
replaced. Here are the numbers.

Same page (20-row product list: loop, index, even/odd class, uppercase, 2-decimal
money, conditional footer). **Every engine's output is compared and proven identical
before anything is timed**; a mismatch aborts the run. Each template is compiled ONCE
outside the clock, so this is steady-state render throughput, not compilation.

| Engine | Renders/s (p50) | Renders/s (mean) | Deps |
|--------|:---------------:|:----------------:|:----:|
| Twig 3 | **27,460** | 26,533 | 1 |
| Blade (Laravel) | **21,680** | 20,156 | 1+ |
| **Frond (Tina4)** | **7,419** | 7,147 | **0** |

**Key takeaway, stated plainly: Frond is 3.70x slower than Twig and 2.92x slower than
Blade on identical output.** This is Frond's fastest path, not a strawman, the harness
reports that the AOT compiler (`FrondCompiler`) engaged, and production mode buys only
~14% over dev mode, so the gap is the render path itself rather than parse or compile
overhead. Twig and Blade compile a template to a PHP class and let the PHP VM execute
it; Frond walks a tree and calls back into engine primitives per hole.

What Frond does buy is the zero in the Deps column, and the fact that the same template
syntax renders in all four Tina4 languages. That is a real trade, but it is a trade -
not a win. Closing this gap is tracked as the ahead-of-time compile layer (ADR-0001).

Reproduce: `cd benchmarks && composer install && php bench_templates.php`

---

## 2. Feature Comparison (41 of 98 built-in features)

Tina4 ships **98 built-in features**. The table below compares the subset that has a
meaningful equivalent in the competing frameworks, so it is a like-for-like comparison
rather than the full inventory. Everything listed ships with the core install, with no
extra packages needed.

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
| **Tina4** | **41/41** | **0** | **28,158** |
| Laravel | 25/41 | 70+ | 257 |
| Symfony | 18/41 | 30+ | 1,589 |
| CodeIgniter | 14/41 | 15+ | 1,311 |
| Slim | 6/41 | 2 | 5,082 |

---

## 3. Deployment Size

**Measured 2026-07-27** on macOS (Apple Silicon) by installing each package for real.
Nothing in this table is estimated. The command that produced it is named below.

Command: `composer install --no-dev` into an empty project, then `du -sh vendor`.

| Framework | Install Size | Vendor packages |
|-----------|:----------:|:---------------:|
| slim/slim | **1 MB** | 8 |
| **Tina4 PHP** | **4.9 MB** | **1 (itself)** |
| codeigniter4/framework | 6 MB | 3 |
| symfony/framework-bundle | 9 MB | 25 |
| laravel/framework | 36 MB | 73 |

**Correction.** This table claimed Tina4 PHP was ~1.5 MB and Slim ~3 MB. Both were wrong,
both in the direction that flattered us. Measured, **Tina4 is 4.9 MB and Slim is 1 MB**,
so Slim is the smaller install. Most of the difference is that the published package ships
its own `src/` example application (1.3 MB) plus `composer.lock`, tracked as php#181.

Zero dependencies still means no `vendor/` sprawl: Tina4 installs exactly **one** package
against Laravel's 73. That claim survives measurement. "Smallest install" does not.

## 4. CO2 / Carbonah

Estimated emissions per HTTP benchmark run (5000 requests on Apple Silicon, 15W TDP).

Formula: `Energy(kWh) = (15W × seconds_for_5000_requests) / 3,600,000` | `CO2(g) = kWh × 475`

| Framework | JSON req/s | Est. Energy (kWh) | Est. CO2 (g) |
|-----------|:---------:|:-----------------:|:------------:|
| **Tina4** | 28,158 | 0.0000007 | 0.0004 |
| Slim | 5,082 | 0.0000041 | 0.0019 |
| Symfony | 1,589 | 0.0000131 | 0.0062 |
| CodeIgniter | 1,311 | 0.0000159 | 0.0075 |
| Laravel | 257 | 0.0000811 | 0.0385 |

*CO2 calculated at world average 475g CO2/kWh. Lower req/s = longer to serve 5000 requests = more energy.*

Laravel emits **96x more CO2** per benchmark run than Tina4.

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
