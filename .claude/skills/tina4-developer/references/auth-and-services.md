# Authentication & Services

## JWT Authentication

### Setup
Set your secret in `.env`:
```env
SECRET=a-long-random-string-here
```

Auth auto-selects the signing algorithm: **HS256** if a `SECRET` env var is set, or **RS256** if
RSA key files exist. No manual algorithm configuration needed.

### Generating Tokens (Python)
```python
from tina4 import tina4_auth

# Login route
@post("/login")
async def login(request, response):
    email = request.body["email"]
    password = request.body["password"]

    user = User().fetch_one("email = ?", [email])
    if not user or not tina4_auth.check_password(user.password_hash, password):
        return response.json({"error": "Invalid credentials"}, 401)

    token = tina4_auth.get_token({"user_id": user.id, "email": user.email}, expires_in=3600)
    return response.json({"token": token})
```

### Protecting Routes
```python
class AuthRequired:
    @staticmethod
    async def before(request, response):
        token = request.headers.get("Authorization", "").replace("Bearer ", "")
        if not tina4_auth.valid_token(token):
            return request, response.json({"error": "Unauthorized"}, 401)
        request.user = tina4_auth.get_payload(token)
        return request, response

@middleware(AuthRequired)
@get("/me")
async def get_profile(request, response):
    user = User().find(request.user["user_id"])
    return response(user)
```

### Password Hashing
```python
hashed = tina4_auth.hash_password("mypassword")
matches = tina4_auth.check_password(hashed, "mypassword")  # True
```

## Sessions

Configure in `.env`:
```env
TINA4_SESSION_HANDLER=file    # file, redis, valkey, mongodb, database
```

A **Redis session handler** is available out of the box — set `TINA4_SESSION_HANDLER=redis`
and point to your Redis instance. No extra dependencies required.

### Usage
```python
@post("/login")
async def login(request, response):
    # After validating credentials...
    request.session.set("user_id", user.id)
    request.session.set("role", "admin")
    return response.redirect("/dashboard")

@get("/dashboard")
async def dashboard(request, response):
    user_id = request.session.get("user_id")
    if not user_id:
        return response.redirect("/login")
    user = User().find(user_id)
    return response.render("dashboard.twig", {"user": user})

@get("/logout")
async def logout(request, response):
    request.session.clear()   # Wipes session data without destroying the session
    return response.redirect("/")
```

`session.clear()` wipes all session data but keeps the session itself alive (the session ID
and cookie persist). Use this for logout flows where you want to reset state without
forcing a new session.

## Queue System

For background jobs like sending emails, processing uploads, etc.
Queue files use the `.queue-data` extension on disk.

### Producing Messages
```python
from tina4 import Queue

@post("/orders")
async def create_order(request, response):
    order = Order(request.body)
    order.save()

    # Queue email notification for background processing
    queue = Queue(topic="order-emails")
    queue.produce("order-emails", {
        "order_id": order.id,
        "email": request.body["email"],
        "type": "confirmation"
    })

    return response(order, 201)
```

### Consuming Messages
```python
from tina4 import Queue

# Run as a background worker
queue = Queue(topic="order-emails")
queue.consume("order-emails", lambda message: (
    send_order_email(message.data),
    message.ack()
))
```

### Priority and Delayed Jobs
```python
# High priority
queue.produce("emails", data, priority=10)

# Delayed (process after 5 minutes)
queue.push(data, delay_seconds=300)
```

## Email

```python
from tina4 import Email

@post("/contact")
async def contact(request, response):
    Email().send(
        to=request.body["email"],
        subject="Thanks for reaching out",
        body="<h1>We received your message</h1>",
        content_type="text/html"
    )
    return response({"status": "sent"})
```

## WebSocket

### Server (Python)
```python
from tina4 import websocket

@websocket("/ws/chat")
async def chat(connection):
    async for message in connection:
        # Broadcast to all connected clients
        await connection.broadcast(message.data)
```

### Client (frond.js)
```javascript
const ws = Frond.ws("/ws/chat", {
    onMessage: (data) => {
        document.getElementById("messages").innerHTML += `<p>${data.text}</p>`;
    }
});

document.getElementById("send").onclick = () => {
    ws.send({ text: document.getElementById("input").value });
};
```

## GraphQL

Auto-generate a GraphQL API from your ORM models:
```python
from tina4 import gql

gql.schema.from_orm(User)
gql.schema.from_orm(Post)
gql.register_route("/graphql")  # GET = GraphiQL IDE, POST = queries
```

Visit `/graphql` in the browser for the GraphiQL IDE.

## Events

Decouple your app logic with events:
```python
from tina4 import event, listener

@listener("user.created")
async def send_welcome(data):
    Email().send(to=data["email"], subject="Welcome!", body="...")

@listener("user.created")
async def setup_defaults(data):
    Settings({"user_id": data["id"], "theme": "light"}).save()

# Fire the event:
@post("/register")
async def register(request, response):
    user = User(request.body)
    user.save()
    event.fire("user.created", {"id": user.id, "email": user.email})
    return response(user, 201)
```

## i18n / Localization

Translation files go in `src/locales/` as JSON:
```json
// src/locales/en.json
{ "welcome": "Welcome, {name}!", "logout": "Sign out" }

// src/locales/fr.json
{ "welcome": "Bienvenue, {name}!", "logout": "Déconnexion" }
```

Set language in `.env`:
```env
TINA4_LANGUAGE=en
```

Use in templates:
```twig
{{ "welcome" | trans({"name": user.name}) }}
{{ "logout" | trans }}
```

## Caching

Built-in, zero-dep caching. Use in templates with `{% cache %}` blocks,
or in code with the cache API for expensive operations.
