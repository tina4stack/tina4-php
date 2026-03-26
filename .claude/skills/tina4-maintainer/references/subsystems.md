# Extended Subsystems

## Queue System

Database-backed, production-grade. Designed to handle 1M visitors without RabbitMQ/Kafka.

### Backends
- **DatabaseQueue** (core, zero-dep) — uses atomic SQL operations per database
- **RabbitMQQueue** (optional)
- **KafkaQueue** (optional)

All implement the same QueueAdapter interface.

### Usage (Python)
```python
from tina4 import Queue

# Produce
queue = Queue(topic="emails")
queue.produce("emails", {"to": "alice@example.com", "subject": "Welcome"})

# Consume
queue.consume("emails", lambda message: (
    send_email(message.data),
    message.ack()
))
```

### Features
- Priority queues (higher number = higher priority)
- Delayed jobs (`delay_until` timestamp)
- Retry with exponential backoff (configurable max retries)
- Dead letter queue for permanently failed messages
- Stale job recovery (60-second sweep)
- Retrieve by ID, search/filter messages
- Requeue to different queues (preserves `original_queue`)
- Failover chains between backends
- Circuit breaker for external backends
- Batch processing
- Auto-cleanup (completed messages purged after 7 days)
- Queue file extension: `.queue-data` (changed from `.json` in v3.7.1)

### Atomic Pop Operations
- SQLite/PostgreSQL/Firebird: `UPDATE ... RETURNING`
- PostgreSQL/MySQL: `FOR UPDATE SKIP LOCKED`
- MSSQL: `READPAST`

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

**BREAKING (v3.7.1):** `token_expiry` renamed to `expires_in`. `validate_token` renamed to `valid_token`.

### Python
```python
from tina4 import tina4_auth

token = tina4_auth.get_token({"user_id": 42}, expires_in=3600)  # seconds
is_valid = tina4_auth.valid_token(token)
payload = tina4_auth.get_payload(token)
hashed = tina4_auth.hash_password("mypassword")
matches = tina4_auth.check_password(hashed, "mypassword")
```

### Algorithm Auto-Selection
- If `SECRET` env var is set → **HS256** (symmetric, simpler)
- If `secrets/private.pem` + `secrets/public.pem` exist → **RS256** (asymmetric, production)
- Both HS256 and RS256 are fully supported across all four languages

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
