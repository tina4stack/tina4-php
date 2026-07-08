# Extended Subsystems

## Queue System

File-backed, production-grade, zero-dependency. Designed to handle 1M visitors without RabbitMQ/Kafka.

### Backends
- **LiteBackend** (core, zero-dep) — file-based queue (JSON files on disk), lives in `queue_backends/lite_backend.*` in each framework
- **RabbitMQBackend** (optional)
- **KafkaBackend** (optional)
- **MongoBackend** (optional)

All implement the same `QueueBackend` interface.

### File Structure (each framework)
```
queue/
  __init__.py (or Queue.php / queue.rb / queue.ts)  — Queue class
  job.py (or Job.php / job.rb / job.ts)              — Job class (separate file)
  lite_backend.py (or LiteBackend.php / lite_backend.rb / liteBackend.ts)  — LiteBackend (separate file)
```

### Instance-Scoped API (all 4 frameworks)

Queue methods use `self.topic` / `@topic` / `$this->topic` — **no queue name params on public methods**.

```python
# Python
from tina4_python import Queue

q = Queue(topic="emails")          # no path= param — storage path is the env var TINA4_QUEUE_PATH
job_id = q.push({"to": "alice@example.com"}, priority=0, delay_seconds=0)
job    = q.pop()                   # atomically claim next pending job
job    = q.pop_by_id(job_id)       # claim a specific job by ID
count  = q.size("pending")
q.clear()                          # remove all pending jobs
jobs   = q.failed()                # list failed jobs
q.retry(job_id, delay_seconds=0)   # requeue a failed job
q.retry_failed()                   # requeue all failed jobs
jobs   = q.dead_letters()          # jobs exceeding max retries
q.purge("failed")                  # delete jobs by status
```

### Job Serialisation (all 4 frameworks)
```python
job.to_array()   # [id, topic, payload, priority, attempts]
job.to_hash()    # {"id": ..., "topic": ..., "payload": ..., ...}
job.to_json()    # JSON string of to_hash()
```

### Consume (generator / async generator)
```python
# Python — consume all pending jobs forever
for job in q.consume():
    process(job.payload)
    job.complete()

# with ID shortcut
for job in q.consume(job_id="abc123"):
    process(job.payload)
    job.complete()
```

### Features
- Priority queues (higher number = higher priority)
- Delayed jobs (`delay` seconds)
- Retry with configurable max retries
- Dead letter queue for permanently failed messages
- Retrieve by ID (`pop_by_id`)
- `failed()`, `clear()`, `retry()`, `retry_failed()`, `dead_letters()`, `purge()`
- Auto-cleanup (completed messages purged after 7 days)

### Performance Targets
5,000 push/sec | 2,000 pop/sec | 10+ concurrent workers | 1M+ queue depth | <10ms latency

---

## WebSocket

Fully native RFC 6455 implementation (~300-400 lines per language). No third-party libraries.

### Route-Based Registration (Python)

`websocket` is NOT a top-level export — `from tina4_python import websocket` imports the
`tina4_python.websocket` **module**, not the decorator. Import it from the router:

```python
from tina4_python.core.router import websocket

# The handler is event-based: (connection, event, data). `event` is "open",
# "message", or "close"; `data` is the payload (str for "message", None otherwise).
# It is NOT a single-arg `async for message in connection:` loop.
@websocket("/ws/chat")
async def chat(connection, event, data):
    if event == "message":
        await connection.broadcast(data)
    elif event == "open":
        await connection.send("welcome")
```

### WebSocketConnection API
`send(data)`, `send_json(obj)`, `broadcast(data, exclude_self=False)`,
`broadcast_to(path, data)`, `broadcast_to_room(room, data)`, `join_room(name)`,
`leave_room(name)`, `ping(data=b"")`, `close(code, reason)`.

### WebSocketManager
Tracks all connections by ID and path. Handles upgrade handshake, frame protocol
(FIN, opcodes, masking/unmasking, payload encoding), and auto ping/pong.

### WebSocketBackplane rename
`create()` renamed to `create_backplane()` (Python/Ruby) / `createBackplane()` (PHP/Node) across all frameworks.

### Integration with Frond Live Blocks
`{% live "name" ws "/ws/path" %}` declares a WebSocket transport for a live block. The block
first-paints server-side, then `push_live(name, data)` re-renders it and broadcasts the HTML
fragment to every client on that ws path. The developer owns the ws route; the block only
names it. `poll` and `sse` blocks refresh through the always-on `GET /__frond/live/{name}`
endpoint instead (fed by the `@live_source` provider, which re-runs with the live request so
auth re-applies).

---

## Authentication / JWT

### Canonical method names (all 4 frameworks — no aliases)

| Method | Python | PHP | Ruby | Node |
|--------|--------|-----|------|------|
| Create token | `get_token(payload, expires_in)` | `Auth::getToken($payload, $secret, $expiresIn)` | `get_token(payload, expires_in:)` | `getToken(payload, secret, expiresIn)` |
| Validate token | `valid_token(token)` | `Auth::validToken($token, $secret)` | `valid_token(token)` | `validToken(token, secret)` |
| Get payload | `get_payload(token)` | `Auth::getPayload($token)` | `get_payload(token)` | `getPayload(token)` |
| Hash password | `hash_password(pw)` | `Auth::hashPassword($pw)` | `hash_password(pw)` | `hashPassword(pw)` |
| Check password | `check_password(pw, hash)` | `Auth::checkPassword($pw, $hash)` | `check_password(pw, hash)` | `checkPassword(pw, hash)` |
| Validate API key | `validate_api_key(provided, expected)` | `Auth::validateApiKey($provided, $expected)` | `validate_api_key(provided, expected)` | `validateApiKey(provided, expected)` |
| Authenticate request | `authenticate_request(headers)` | `Auth::authenticateRequest($headers)` | `authenticate_request(headers)` | `authenticateRequest(headers)` |
| Refresh token | `refresh_token(token, expires_in)` | `Auth::refreshToken($token, $secret, $expiresIn)` | `refresh_token(token)` | `refreshToken(token, secret, expiresIn)` |

**No `createToken` or `validateToken` aliases exist in any framework** — they were removed. Use `getToken`/`validToken`.

### Python
```python
from tina4_python import Auth      # the class is `Auth` — there is no `tina4_auth` export

auth     = Auth()
token    = auth.get_token({"user_id": 42})   # HS256, signed with the TINA4_SECRET env var
is_valid = auth.valid_token(token)
payload  = auth.get_payload(token)
# hash_password / check_password are STATIC methods:
hashed   = Auth.hash_password("mypassword")
matches  = Auth.check_password("mypassword", hashed)   # (plaintext, hashed) — NOT (hashed, plaintext)
```

Supports both HS256 and RS256 algorithms. Node `Auth` class wraps standalone functions:
```typescript
import { Auth, getToken, validToken } from "tina4-nodejs";
Auth.getToken(payload, secret);   // same as standalone getToken()
Auth.validToken(token, secret);   // same as standalone validToken()
```

---

## Sessions

Pluggable backends, configured via `TINA4_SESSION_BACKEND` env var.

### Backends
- File (default)
- Redis
- Valkey
- MongoDB
- Database

### Usage (Python)
```python
request.session.set("user_id", 42)
user_id = request.session.get("user_id")
request.session.delete("user_id")
request.session.clear()
```

---

## GraphQL

Zero-dependency engine. Auto-generates schema from ORM models.

```python
from tina4_python import GraphQL     # the class is `GraphQL` — there is no `gql` singleton export

gql = GraphQL()
gql.schema.from_orm(User)            # or gql.auto_register(User, Post) (honours TINA4_GRAPHQL_AUTO_SCHEMA)
gql.schema.from_orm(Post)
GraphQL.set_default(gql)             # register as default so @GraphQL.resolve(...) decorators wire in

# There is NO register_route(). The HTTP path is the `.endpoint` attribute
# (default "/graphql", override via the TINA4_GRAPHQL_ENDPOINT env var).
# Run a query with gql.execute(query_string) → {"data": ..., "errors": [...]}.
```

---

## WSDL / SOAP

Auto-generated WSDL from decorated classes.

```python
from tina4_python import WSDL, wsdl_operation

class Calculator(WSDL):
    @wsdl_operation({"Result": int})
    def add(self, a: int, b: int) -> int:
        return a + b
```

---

## SCSS Compiler

Built-in, zero-dependency. Compiles `.scss` files to CSS.

### ScssCompiler parity
- Python: `ScssCompiler` class with `compile()`, `compile_file()`, `add_import_path()`, `set_variable()`
- PHP: Added `compileScss()`
- Ruby: Added `compile`, `add_import_path`, `set_variable`
- Node: Added `compileScss()`

---

## i18n / Localization

Translation files in `src/locales/` (JSON format). Language set via `TINA4_LOCALE` env var.

```twig
{{ "welcome_message"|trans }}
{{ "greeting"|trans({"name": user.name}) }}
```

---

## Email / SMTP (Messenger)

Built-in SMTP client. The class is `Messenger` — there is no `Email` export.

```python
from tina4_python import Messenger

messenger = Messenger()
messenger.send(
    to="alice@example.com",           # str or list[str]
    subject="Welcome",
    body="<h1>Hello!</h1>",
    html=True,                        # html=True sends HTML — there is NO content_type= param
)
```

---

## Data Seeder

Zero-dep fake data generation with 50+ generators.

```python
from tina4_python import FakeData, seed_orm

fake = FakeData()
fake.name()    # "Alice Johnson"
fake.email()   # "alice.johnson@example.com"
fake.phone()   # "+1-555-0123"

seed_orm(User, count=50)  # Bulk seed from ORM field types
```

CLI: `tina4 seed`, `tina4 seed:create "initial users"`

---

## Auto-CRUD

Auto-generates REST endpoints (GET list, GET by id, POST, PUT, DELETE) from ORM models.
The class is `AutoCrud` — there is no `CRUD` class and no `to_crud()`.

```python
from tina4_python import AutoCrud, ORM

# Option A — declare on the model; it auto-registers its CRUD routes on startup:
class User(ORM):
    auto_crud = True
    # ...fields...

# Option B — register a model explicitly (default prefix "/api"):
AutoCrud.register(User)                    # → GET/POST /api/user, GET/PUT/DELETE /api/user/{id}
AutoCrud.register(Product, prefix="/api/v2")

# Option C — auto-discover every model in a directory:
AutoCrud.discover("src/orm", prefix="/api")
```

---

## Event / Listener System

Pub/sub within the application. The API is the `on` / `emit` functions (plus `once`, `off`) —
there is no `event` object or `listener` decorator, and no `event.fire()`.

```python
from tina4_python import on, emit

@on("user.created")                        # subscribe (also: once(...) for one-shot)
async def send_welcome_email(data):
    Messenger().send(to=data["email"], subject="Welcome!", body="Hi!", html=True)

# Elsewhere in a route — publish:
emit("user.created", {"email": user.email})
```

---

## REST API Client

```python
from tina4_python import Api

api = Api("https://api.example.com", auth_header="Bearer xyz")
result = api.get("/users")                 # verb methods: get / post / put / delete
result = api.send("GET", "/users")         # generic form — send_request() no longer exists
```

---

## RateLimiterMiddleware

Wrapper class around `RateLimiter` for use as route middleware. All 4 frameworks have both `RateLimiter` and `RateLimiterMiddleware`.

- Python: `from tina4_python.core.rate_limiter import RateLimiter` (extracted to own file). `RateLimiterMiddleware` wraps it.
- PHP: `RateLimiterMiddleware` class with `beforeRateLimit()`, `check()`, `reset()`.
- Ruby/Node: Same dual-class pattern.

---

## ErrorOverlay renames

Old names removed across all frameworks: `render()`, `renderProduction()`, `render_production()`, `debug_mode?`.

| Method | Python | PHP | Ruby | Node |
|--------|--------|-----|------|------|
| Render overlay | `render_error_overlay()` | `renderErrorOverlay()` | `render_error_overlay` | `renderErrorOverlay()` |
| Production error | `render_production_error()` | `renderProductionError()` | `render_production_error` | `renderProductionError()` |
| Check debug | `is_debug_mode()` | `isDebugMode()` | `is_debug_mode` | `isDebugMode()` |

---

## Server parity

- Python/Node: Added `start()` and `stop()` methods.
- PHP/Ruby: Added `handle()` method.

---

## DatabaseResult parity

- Python: Added `size()`, `to_array()`, `to_json()`, `to_csv()`.
- PHP/Node: Added `size()`.

---

## QueryBuilder rename

`from()` renamed to `from_table()` (Python/Ruby) / `fromTable()` (PHP/Node) across all frameworks.

---

## DevReload parity

- Node: Added `start()` and `stop()` methods.

---

## DevAdmin parity

- Python: Added `unresolved_count()`, `clear_all()`, `reset()`, `capture()` (5-param), `register()`.
- PHP: Added `health()`.
- Ruby: Added `register`.
- Node: Added `capture()`, `clearAll()`, `health()`, `unresolvedCount()`, `reset()`, `register()`.

---

## Swagger / OpenAPI

Auto-generated at `/swagger`. Uses route decorators for metadata — import them from
`tina4_python.swagger` (they are NOT top-level exports):

```python
from tina4_python.swagger import description, tags, example, example_response

@get("/users")
@description("List all users")
@tags(["Users"])
@example_response(200, [{"id": 1, "name": "Alice"}])   # RESPONSE example, keyed by status
async def list_users(request, response):
    ...

# example() documents the REQUEST body instead: @example({"name": "Alice"}) — no status arg
#   signature: example(data, content_type="application/json")
```
