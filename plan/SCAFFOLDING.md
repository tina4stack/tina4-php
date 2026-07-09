# Rich Scaffolding Plan — PHP (scaffolding-first, secure-by-default)

Ports the tina4-python `feat/scaffolding-first` design (commit c0b2085) to tina4-php,
written idiomatically against the live PHP framework (the source is the authority).

## Status: Complete — 18 real boot-gate tests green (tests/CLIScaffoldingSecureTest.php)

## Commands

| Command | Output |
|---------|--------|
| `generate model Product --fields "name:string,price:float"` | `src/orm/Product.php` + migration |
| `generate route products --model Product [--public]` | `src/routes/products.php` — secure by default |
| `generate crud Product --fields "..." [--public]` | model + migration + routes + form + view + **secure gate test** |
| `generate migration add_category` | `migrations/TS_add_category.sql` + `.down.sql` |
| `generate middleware AuthCheck` | `src/middleware/AuthCheck.php` |
| `generate test Product` | `tests/ProductTest.php` |
| `generate form / view / auth` | templates + auth routes |
| `generate service Cleanup --every 5m \| --cron "..."` | `src/services/cleanup.php` (ServiceRunner descriptor) |
| `generate queue order-emails` | `src/services/order_emails_consumer.php` (producer + daemon consumer) |
| `generate validator CreateUser` | `src/validators/create_user.php` (Validator closure) |
| `generate seeder Product` | `src/seeds/product_seeder.php` (FakeData + SeedSummary) |
| `generate websocket chat` | `src/routes/ws_chat.php` (`Router::websocket`) |
| `generate listener user.created` | `src/listeners/user_created.php` (`Events::on`) |

## Secure-by-default (fix #1/#2)

- Route/CRUD generators emit **no** `->noAuth()` on any write or read. The router gates
  writes (POST/PUT/DELETE) by default (Router.php:796-820); reads (GET) are public.
- `--public` re-adds `->noAuth()` on the **write handlers only** — mirrors
  `AutoCrud.php:121-135` (`if ($isPublic) { $route->noAuth(); }`).
- Route bodies now emit **fully-qualified `\Tina4\Router::`** (real route-file idiom; the
  old bare `Router::` fatally failed to boot — no global alias exists) and grounded ORM
  calls (all/count/load/fill/save/delete/toDict).
- `generate auth` login/register are made **public** via `->noAuth()` (was bare `Router::`
  + `->noCache()` only — it neither booted nor stayed public); `/api/auth/me` stays
  `->secure()`.

## AI-FILL convention (fix #3)

- `aiFill()` emits a ≤6-line fill-spec (`Intent / Given / Use / Return / Ground`) + a loud
  `throw new \RuntimeException(...)`, inside a **closure** so the file requires cleanly and
  only throws when the handler is CALLED. `Use:` names only real, verified symbols.
- `extendMarker()` emits the lighter `// EXTEND:` marker (no throw) on working CRUD code.

## Six new generators — grounded symbols (fix #4)

| Generator | Real PHP wiring (file:line) |
|-----------|------------------------------|
| service   | returns `['name','handler','timing']` for `ServiceRunner::discover` (ServiceRunner.php:105,125-137); cron-only via `timing` (matchCron, ServiceRunner.php:385) |
| queue      | `Queue::push` (Queue.php:123), `Queue::process` (Queue.php:194) consumer as `['daemon'=>true]` service |
| validator  | `new Validator($data)` + rules (Validator.php:30,55-250) |
| seeder     | `FakeData` (FakeData.php:24) + `SeedSummary` (SeedSummary.php:27) + model `save()` |
| websocket  | `Router::websocket($path,$handler)` (Router.php:1318); handler `($connection,$data,$event)` (Server.php:808/919/1192) |
| listener   | `Events::on($event,$cb)` (Events.php:33) |

## Deviations flagged honestly

- **service scheduling**: PHP `ServiceRunner` is **cron-only** (`timing`) — there is no
  seconds-`interval` like Python's `register(interval=)`. `--every` is mapped to cron via
  `everyToCron()`; sub-minute (`30s`) floors to `* * * * *`.
- **websocket handler arg order** is `($connection, $data, $event)` — differs from Python's
  `(connection, event, data)`.
- **seeder**: PHP has no `seed_orm` orchestrator — the seeder builds rows directly from
  `FakeData` + `save()`. `tina4php seed` now invokes a returned callable so the
  closure-shaped seeder runs.

## Field Type Mapping

| CLI | PHP | SQL | nullable |
|-----|-----|-----|----------|
| string/str/text | string | VARCHAR(255)/TEXT | no |
| int/integer | ?int | INTEGER | yes |
| float/numeric/decimal | ?float | DECIMAL(10,2) | yes |
| bool/boolean | bool | TINYINT(1) | no |
| datetime/blob | ?string | DATETIME/BLOB | yes |

## Tests (real, no mocks)

- `tests/CLIScaffoldingTest.php` — helper + generator unit coverage (31 tests).
- `tests/CLIScaffoldingSecureTest.php` — **18 real boot-gate tests**: route gate
  (anon write→401, authed→201, anon read→200), `--public` opens writes, no `->noAuth()` by
  default, auth stays public, the generated CRUD test runs green in a real phpunit
  subprocess (R5), and each logic stub loads clean + throws-when-called + registers on the
  real ServiceRunner / Queue / Router / Events bus.
