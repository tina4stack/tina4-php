# Tina4 — Benchmark Results

Cross-framework HTTP benchmarks for Tina4 v3.10.40.

## Latest Results (April 1, 2026)

Benchmarked with `wrk` — 5,000 requests, 50 concurrent, median of 3 runs.

### All Tina4 Implementations

| | Python | PHP | Ruby | Node.js |
|---|--------|-----|------|---------|
| **JSON req/s** | 6,508 | 29,293 | 10,243 | 84,771 |
| **List req/s** | 4,177 | 17,309 | 8,105 | 25,459 |
| **Dependencies** | 0 | 0 | 0 | 0 |
| **Features** | 54 | 54 | 54 | 54 |

### Overall Ranking

| Rank | Framework | JSON req/s | Language | Deps | Features |
|------|-----------|-----------|----------|------|----------|
| 1 | Node.js raw | 91,110 | Node.js | 0 | 1 |
| **2** | **Tina4 Node.js** | **84,771** | Node.js | 0 | 54 |
| **3** | **Tina4 PHP** | **29,293** | PHP | 0 | 54 |
| 4 | FastAPI | 12,652 | Python | 12+ | ~8 |
| **5** | **Tina4 Ruby** | **10,243** | Ruby | 0 | 54 |
| 6 | Sinatra | 9,548 | Ruby | 5+ | ~4 |
| **7** | **Tina4 Python** | **6,508** | Python | 0 | 54 |
| 8 | Slim | 5,714 | PHP | 10+ | ~6 |
| 9 | Flask | 4,928 | Python | 6+ | ~7 |
| 10 | Bottle | 4,355 | Python | 0 | ~5 |
| 11 | Django | 4,050 | Python | 20+ | ~22 |
| 12 | Starlette | 2,529 | Python | 4+ | ~6 |
| 13 | Laravel | 445 | PHP | 50+ | ~25 |

## How to Run

The unified benchmark suite lives in the [tina4-python](https://github.com/tina4stack/tina4-python) repository:

```bash
cd tina4-python
python benchmarks/benchmark.py --all        # All 4 languages
python benchmarks/benchmark.py --python     # Python only
python benchmarks/benchmark.py --php        # PHP only
python benchmarks/benchmark.py --ruby       # Ruby only
python benchmarks/benchmark.py --nodejs     # Node.js only
```

Requires `wrk` (`brew install wrk`).

## Result Files

- `benchmark_results.json` — combined results for all languages
- `results/python.json` — Python framework results
- `results/php.json` — PHP framework results
- `results/ruby.json` — Ruby framework results
- `results/nodejs.json` — Node.js framework results

---

*https://tina4.com*
