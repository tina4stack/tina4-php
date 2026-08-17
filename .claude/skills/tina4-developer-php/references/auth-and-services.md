# Authentication & Services (PHP)

## OpenID Connect SSO

Configure SSO in `.env`. The issuer drives discovery; do not build provider-specific adapters.

```ini
TINA4_SSO_ISSUER=https://identity.example.com/realms/my-app
TINA4_SSO_CLIENT_ID=my-app
TINA4_SSO_CLIENT_SECRET=replace-me
TINA4_SSO_REDIRECT_URI=https://app.example.com/auth/callback
TINA4_SSO_SCOPES=["openid", "profile", "email"]
TINA4_SSO_VERIFY=introspection
```

When configured, Tina4 mounts `GET /auth/login`, `GET /auth/callback`, and
`POST /auth/logout`. A route collision stops startup. Existing secured routes receive the
normalized identity through `$request->user`; provider tokens stay inside reserved Session data.

```php
use Tina4\Sso;

$sso = Sso::fromIssuer();
$identity = $sso->identity($request->session);
```

Use a provider recipe only to find its standards-based issuer and client settings. The runtime API
stays provider-neutral. In 3.13.104, introspection is the supported verification mode; `jwks` fails
during configuration until the application supplies a cryptography capability.

## JWT Authentication

### Setup
Set your secret in `.env`:
```env
TINA4_SECRET=a-long-random-string-here
```

### Generating Tokens

Write routes are Bearer-protected by default, so the login route (the user has no token yet) opts
out with `->noAuth()`. In a `src/routes/` file, qualify framework classes with a leading backslash.

```php
// src/routes/auth.php
\Tina4\Router::post("/api/login", function ($request, $response) {
    $email    = $request->body["email"];
    $password = $request->body["password"];

    $user = (new User())->where("email = ?", [$email])[0] ?? null;
    if (!$user || !\Tina4\Auth::checkPassword($password, $user->password)) {
        return $response(["error" => "Invalid credentials"], 401);
    }

    $token = \Tina4\Auth::getToken(["user_id" => $user->id, "email" => $user->email]);
    return $response(["token" => $token]);
})->noAuth();
```

`getToken(array $payload, string|int|null $secret = null, int $expiresIn = 60): string` — pass just
the payload; it's signed with `TINA4_SECRET` and expires in `$expiresIn` minutes by default.

### Verifying / Protecting Routes

POST/PUT/PATCH/DELETE already require a valid Bearer token — you get a 401 automatically. Inside the
handler, read the verified payload:

```php
\Tina4\Router::get("/me", function ($request, $response) {
    // authenticateRequest() takes a plain array — $request->headers is a CaseInsensitiveArray,
    // so hand it ->toArray(). Returns the verified payload, or null.
    $auth = \Tina4\Auth::authenticateRequest($request->headers->toArray());
    if ($auth === null) {
        return $response(["error" => "Unauthorized"], 401);
    }
    return $response->json(User::findById($auth["user_id"]));
})->secure();   // ->secure() makes this GET route require a valid JWT (GET is public by default)
```

Equivalent lower-level checks:
```php
$token   = $request->bearerToken();                            // the raw token, or null
$payload = $token ? \Tina4\Auth::getPayload($token) : null;    // decoded payload, or null
$payload = \Tina4\Auth::validToken($token);                    // verify + decode, or null
```

### Auth API reference

| Method | Signature |
|--------|-----------|
| `getToken` | `getToken(array $payload, string|int|null $secret = null, int $expiresIn = 60): string` |
| `validToken` | `validToken(string $token, ?string $secret = null): ?array` |
| `getPayload` | `getPayload(string $token): ?array` |
| `authenticateRequest` | `authenticateRequest(array $headers, ?string $secret = null, string $algorithm = 'HS256'): ?array` |
| `refreshToken` | `refreshToken(string $token, int $expiresIn = 60): ?string` |
| `hashPassword` | `hashPassword(string $password, ?string $salt = null, int $iterations = 260000): string` |
| `checkPassword` | `checkPassword(string $password, string $hash): bool` |

> `refreshToken` takes **`($token, $expiresIn = 60)`** — there is no `$secret` parameter; it re-signs
> with `TINA4_SECRET`. `refreshToken($token, $secret, $expiresIn)` is wrong.

### Password Hashing
```php
$hashed  = \Tina4\Auth::hashPassword("mypassword");
$matches = \Tina4\Auth::checkPassword("mypassword", $hashed);  // true
```

## Auth footguns

Tina4's default is **secure**: `POST`/`PUT`/`PATCH`/`DELETE` require a Bearer token;
`GET`/`HEAD`/`OPTIONS` are public (`Router.php:812-823`). `->noAuth()` opens a write route;
`->secure()` locks a read route. Verified against source — get these wrong and you either ship an
unauthenticated write or fight phantom 401s.

### An unexpected 401 means "authenticate the request", not "open the route"

**`->noAuth()` is a LAST RESORT.** When a write route returns 401 in dev or from a client, the fix
is almost always to **send the Bearer token** the route legitimately requires — not to strip its
auth. The client carries it for you: frond.js sends the current `Authorization: Bearer` on every
`saveForm` / `sendRequest`, and the `\Tina4\Api` client does too (`setBearerToken(...)`). Reserve
`->noAuth()` for endpoints that are *genuinely* public — login, register, health-check, inbound
webhooks (validated by signature) — or a handler that authenticates another way *inside* it.

```php
// Public login route — mints a token; the caller has no token YET, so it opts out.
\Tina4\Router::post("/api/login", function ($request, $response) {
    // ... verify credentials, then:
    return $response(["token" => \Tina4\Auth::getToken(["user_id" => $user->id])]);
})->noAuth();

// Protected write route — Bearer-protected automatically; write NOTHING extra.
\Tina4\Router::post("/api/orders", function ($request, $response) {
    $auth = \Tina4\Auth::authenticateRequest($request->headers->toArray());   // verified payload, or null
    $data = $request->body;
    $data["user_id"] = $auth["user_id"];        // trust the token, not the client body
    return $response((new Order($data))->save(), 201);
});
```

* **Never blanket `->noAuth()` to silence 401s.** Slapping it on every write route that returns
  401 doesn't "fix auth" — it **ships unauthenticated writes**. A 401 on `POST /orders` means the
  request arrived without a valid token; authenticate it, don't open the route. More than 2–3
  `->noAuth()` write routes in a whole app means the auth flow is wrong — stop and fix it.
* **Before you type `->noAuth()`, ask:** can it modify data / cost money / be bot-abused / expose
  private data? Yes to any → it needs auth, not `->noAuth()`. If you *must* use it (webhook,
  protocol endpoint), the handler MUST still authenticate (signature, header scheme).

### Lock a GET with `->secure()`; there is no docs-only auth annotation (differs from Python)

GET is public by default — require a token on one by chaining `->secure()` (or the `@secured`
docblock tag). Both **actually enforce**. Unlike Python — whose swagger `@security("bearerAuth")`
*documents* auth without gating it (a real docs-vs-enforcement trap) — PHP has **no docs-only auth
decorator**: the `@noauth` / `@secured` docblock tags set the real enforcement flag
(`Router.php:1438-1443`), and the OpenAPI security shown at `/swagger` is *derived from* that flag
(`Router.php:1203`). So Swagger can't claim a route is secured while it's actually open.

```php
\Tina4\Router::get("/reports", function ($request, $response) {
    $auth = \Tina4\Auth::authenticateRequest($request->headers->toArray());
    if ($auth === null) {
        return $response(["error" => "Unauthorized"], 401);
    }
    return $response->json(buildReports());
})->secure();   // THIS enforces auth on the GET
```

* **Breaks:** leaving a private-data `GET` public because "it only reads" — a read route that
  returns another user's data needs `->secure()` + an in-handler ownership check.

### `->noAuth()` / `->secure()` are interchangeable with their docblock tags

The fluent methods and the docblock annotations are equivalent — pick one:

```php
\Tina4\Router::post("/webhook", function ($request, $response) {
    /** @noauth */                    // same effect as ->noAuth()
    // ... validate the webhook signature INSIDE the handler ...
})->noAuth();
```

There is **no decorator-order footgun** (as there is in Python): the route method
(`Router::post(...)`) and the auth modifier (`->noAuth()` / `->secure()`) are one fluent chain,
not a stack of decorators that can be ordered wrong.

## Sessions

Configure in `.env`:
```env
TINA4_SESSION_BACKEND=file    # file, redis, valkey, mongodb, database
```

Access the session through `$request->session`:
```php
\Tina4\Router::post("/login", function ($request, $response) {
    // After validating credentials...
    $request->session->set("user_id", $user->id);
    $request->session->set("role", "admin");
    return $response->redirect("/dashboard");
})->noAuth();

\Tina4\Router::get("/dashboard", function ($request, $response) {
    $userId = $request->session->get("user_id");
    if (!$userId) {
        return $response->redirect("/login");
    }
    return $response->render("dashboard.twig", ["user" => User::findById($userId)]);
});

\Tina4\Router::get("/logout", function ($request, $response) {
    $request->session->clear();
    return $response->redirect("/");
});
```

Session methods: `set($key, $value)`, `get($key, $default)`, `has($key)`, `clear()`, `destroy()`,
`all()`.

## Queue System

For background jobs like sending emails, processing uploads, etc. Construct a `\Tina4\Queue` with a
topic (named argument), `produce(...)` messages, and `consume(...)` them in a worker.

### Producing Messages
```php
\Tina4\Router::post("/orders", function ($request, $response) {
    $order = (new Order($request->body))->save();

    // Queue an email notification for background processing
    (new \Tina4\Queue(topic: "order-emails"))->produce("order-emails", [
        "order_id" => $order->id,
        "email"    => $request->body["email"],
        "type"     => "confirmation",
    ]);

    return $response($order, 201);
});
```

### Consuming Messages
```php
// Run as a background worker. consume() is a generator that polls the topic.
$queue = new \Tina4\Queue(topic: "order-emails");
foreach ($queue->consume("order-emails") as $job) {
    sendOrderEmail($job->payload);   // the produced data
    $job->complete();                // ack; use $job->fail("reason") to nack/retry
}
```

### Priority and Delayed Jobs
```php
$queue = new \Tina4\Queue(topic: "order-emails");

// signature: produce(string $topic, mixed $payload, int $priority = 0, int $delaySeconds = 0)
$queue->produce("order-emails", $data, priority: 10);          // high priority
$queue->produce("order-emails", $data, delaySeconds: 300);     // process after 5 minutes
```

## Email (Messenger)

```php
\Tina4\Router::post("/contact", function ($request, $response) {
    (new \Tina4\Messenger())->send(
        to: $request->body["email"],
        subject: "Thanks for reaching out",
        body: "<h1>We received your message</h1>",
        html: true,
    );
    return $response(["status" => "sent"]);
})->noAuth();
```

## WebSocket

Register a WebSocket route with `\Tina4\Router::websocket($path, $handler)`:
```php
\Tina4\Router::websocket("/ws/chat", function ($connection) {
    foreach ($connection as $message) {
        $connection->broadcast($message->data);   // broadcast to all connected clients
    }
});
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

Build a GraphQL API from your ORM models and register the endpoint:
```php
$gql = new \Tina4\GraphQL();
$gql->fromOrm(new User())        // fromOrm() is chainable
    ->fromOrm(new Post());
$gql->register("/graphql");      // GET = GraphiQL IDE, POST = queries
```

Register a custom resolver (at bootstrap / import time):
```php
\Tina4\GraphQL::resolve("Query", "userByEmail", function ($root, $args, $ctx) {
    return (new User())->where("email = ?", [$args["email"]])[0] ?? null;
});
```

Visit `/graphql` in the browser for the GraphiQL IDE.

## Events

Decouple app logic with events. `\Tina4\Events::on(...)` registers a listener; `emit(...)` fires it.
```php
\Tina4\Events::on("user.created", function ($data) {
    (new \Tina4\Messenger())->send(
        to: $data["email"], subject: "Welcome!", body: "...", html: true
    );
});

\Tina4\Events::on("user.created", function ($data) {
    (new Settings(["user_id" => $data["id"], "theme" => "light"]))->save();
});

// Fire the event:
\Tina4\Router::post("/register", function ($request, $response) {
    $user = (new User($request->body))->save();
    \Tina4\Events::emit("user.created", ["id" => $user->id, "email" => $user->email]);
    return $response($user, 201);
})->noAuth();
```

## i18n / Localization

Translation files go in `src/locales/` as JSON:
```json
// src/locales/en.json
{ "welcome": "Welcome, {name}!", "logout": "Sign out" }
```
```json
// src/locales/fr.json
{ "welcome": "Bienvenue, {name}!", "logout": "Déconnexion" }
```

Set the language in `.env`:
```env
TINA4_LOCALE=en
```

Translate in PHP with `\Tina4\I18n`:
```php
$i18n = new \Tina4\I18n();
$i18n->translate("welcome", ["name" => $user->name]);   // "Welcome, Alice!"
$i18n->setLocale("fr");
$i18n->translate("logout");                              // "Déconnexion"
```

## Caching

Built-in, zero-dep caching. Use `{% cache "name" ttl %}` blocks in Frond templates (see
`templates-and-frontend.md`), or the static API in code for expensive operations:
```php
$key   = "report:monthly:" . $month;
$value = \Tina4\Cache::cacheGet($key);
if ($value === null) {
    $value = buildExpensiveReport($month);
    \Tina4\Cache::cacheSet($key, $value, 300);   // cache for 300 seconds
}
```
Methods: `cacheGet($key)`, `cacheSet($key, $value, $ttl = null)`, `cacheDelete($key)`,
`cacheClear()`.
