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

q = Queue(topic="emails", path="./data")
job_id = q.push({"to": "alice@example.com"}, delay=0, priority=0)
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
```python
from tina4 import websocket

@websocket("/ws/chat")
async def chat(connection):
    async for message in connection:
        await connection.broadcast(message.data)
```

### WebSocketConnection API
`send(data)`, `send_json(obj)`, `broadcast(data)`, `broadcast_to(path, data)`,
`close(code, reason)`, `ping()`

### WebSocketManager
Tracks all connections by ID and path. Handles upgrade handshake, frame protocol
(FIN, opcodes, masking/unmasking, payload encoding), and auto ping/pong.

### Integration with Frond Live Blocks
`{% live "name" ws "/ws/path" %}` auto-registers WebSocket endpoint, watches for data
changes via event system, re-renders Frond blocks server-side, pushes HTML fragments to client.

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
from tina4 import tina4_auth

token    = tina4_auth.get_token({"user_id": 42})        # HS256 signed with SECRET env var
is_valid = tina4_auth.valid_token(token)
payload  = tina4_auth.get_payload(token)
hashed   = tina4_auth.hash_password("mypassword")
matches  = tina4_auth.check_password(hashed, "mypassword")
```

Supports both HS256 and RS256 algorithms. Node `Auth` class wraps standalone functions:
```typescript
import { Auth, getToken, validToken } from "tina4-nodejs";
Auth.getToken(payload, secret);   // same as standalone getToken()
Auth.validToken(token, secret);   // same as standalone validToken()
```

---

## Sessions

Pluggable backends, configured via `TINA4_SESSION_HANDLER` env var.

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
from tina4 import gql

gql.schema.from_orm(User)
gql.schema.from_orm(Post)
gql.register_route("/graphql")  # GET = GraphiQL IDE, POST = queries
```

---

## WSDL / SOAP

Auto-generated WSDL from decorated classes.

```python
from tina4 import WSDL, wsdl_operation

class Calculator(WSDL):
    @wsdl_operation({"Result": int})
    def add(self, a: int, b: int) -> int:
        return a + b
```

---

## SCSS Compiler

Built-in, zero-dependency. Compiles `.scss` files to CSS.

---

## i18n / Localization

Translation files in `src/locales/` (JSON format). Language set via `TINA4_LANGUAGE` env var.

```twig
{{ "welcome_message"|trans }}
{{ "greeting"|trans({"name": user.name}) }}
```

---

## Email / SMTP

Built-in SMTP client.

```python
from tina4 import Email

email = Email()
email.send(
    to="alice@example.com",
    subject="Welcome",
    body="<h1>Hello!</h1>",
    content_type="text/html"
)
```

---

## Data Seeder

Zero-dep fake data generation with 50+ generators.

```python
from tina4 import FakeData, seed_orm

fake = FakeData()
fake.name()    # "Alice Johnson"
fake.email()   # "alice.johnson@example.com"
fake.phone()   # "+1-555-0123"

seed_orm(User, count=50)  # Bulk seed from ORM field types
```

CLI: `tina4 seed`, `tina4 seed:create "initial users"`

---

## Auto-CRUD

Single-call admin interface generation.

```python
from tina4 import CRUD

@get("/api/users/crud")
async def user_crud(request, response):
    return CRUD.to_crud(request, {
        "sql": "SELECT * FROM users",
        "title": "User Management",
        "primary_key": "id"
    })
```

---

## Event / Listener System

Pub/sub within the application.

```python
from tina4 import event, listener

@listener("user.created")
async def send_welcome_email(data):
    await Email().send(to=data["email"], subject="Welcome!")

# Elsewhere in a route:
event.fire("user.created", {"email": user.email})
```

---

## REST API Client

```python
from tina4 import Api

api = Api("https://api.example.com", auth_header="Bearer xyz")
result = api.send_request("/users", method="GET")
```

---

## Swagger / OpenAPI

Auto-generated at `/swagger`. Uses route decorators for metadata:

```python
@get("/users")
@description("List all users")
@tags(["Users"])
@example(200, [{"id": 1, "name": "Alice"}])
async def list_users(request, response):
    ...
```
