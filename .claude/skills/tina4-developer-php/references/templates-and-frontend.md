# Templates & Frontend (PHP)

## Frond Templates

Tina4 uses Frond, a Twig-compatible template engine. Templates go in `src/templates/`. URL-exposed
page templates live in `src/templates/pages/`; partials, layouts, and `base.twig` stay elsewhere
under `src/templates/` and are only reachable via `{% include %}` / `{% extends %}` /
`$response->render(...)`.

### Rendering
```php
\Tina4\Router::get("/", function ($request, $response) {
    return $response->render("index.twig", [
        "title" => "My App",
        "users" => (new User())->all(),
    ]);
});
```

Need the rendered HTML as a string instead of a response (e.g. an email body)? Use the Frond engine
directly: `$html = (new \Tina4\Frond())->render("emails/welcome.twig", $data);`

### Basic Syntax
```twig
{# Output variables #}
<h1>{{ title }}</h1>
<p>{{ user.name }}</p>
<p>{{ user.email | upper }}</p>

{# Conditionals #}
{% if user.is_active %}
    <span class="badge-green">Active</span>
{% else %}
    <span class="badge-red">Inactive</span>
{% endif %}

{# Loops #}
{% for user in users %}
    <div>{{ loop.index }}. {{ user.name }}</div>
{% else %}
    <p>No users found.</p>
{% endfor %}

{# Template inheritance #}
{% extends "base.twig" %}
{% block content %}
    <h1>Page Title</h1>
{% endblock %}
```

### Useful Filters
```twig
{{ name | upper }}                 -> UPPERCASE
{{ name | lower }}                 -> lowercase
{{ name | capitalize }}            -> First letter cap
{{ text | truncate(100) }}         -> Truncate
{{ list | join(", ") }}            -> Join array
{{ value | default("N/A") }}       -> Default if null
{{ html | raw }}                   -> No auto-escaping
{{ price | number_format(2) }}     -> 1,234.56
{{ date | date("Y-m-d") }}         -> Formatted date
{{ text | slug }}                  -> url-friendly-slug
```

All filter names use **snake_case**. Need a custom filter? Register one at app startup:
`\Tina4\Frond::addFilter("money", fn ($v) => "$" . number_format($v, 2));` — then use it as
`{{ price | money }}`.

### Includes and Macros
```twig
{# Include a partial #}
{% include "partials/header.twig" %}
{% include "partials/card.twig" with {"title": "Hello"} %}

{# Reusable macros #}
{% macro input(name, value, type) %}
    <input type="{{ type | default('text') }}" name="{{ name }}" value="{{ value }}">
{% endmacro %}

{% import "macros/forms.twig" as forms %}
{{ forms.input("email", "", "email") }}
```

### CSRF Form Token
```twig
<form method="post" action="/contact">
    {{ formToken() }}          {# hidden input with a signed token; {{ form_token() }} also works #}
    <input type="text" name="name">
    <button type="submit">Send</button>
</form>
```
The framework validates the token automatically on POST/PUT/DELETE. Need the raw token string for a
meta tag or a manual fetch? Use `{{ formTokenValue() }}` / `{{ form_token_value() }}`.

### Inline SQL Queries (Frond-unique)
```twig
{# Frond has no `{% query %}` tag. Fetch in the route and pass the rows to the template. #}
{# Route: `$products = \Tina4\Database\Database::fromEnv()->fetch("SELECT * FROM products WHERE active = ?", [true]);` #}
{% for product in products %}
    <div>{{ product.name }} — ${{ product.price | number_format(2) }}</div>
{% endfor %}
<p>{{ products.total }} products found</p>
```

### Live Blocks (server-rendered, self-refreshing)

A live block renders on the server for first paint, then re-fetches its own HTML and swaps it in
place. Pick a transport: **`poll N`** (every N seconds) or **`ws "path"`** for live updates.
`sse` is parsed by Frond but the client only paints once and does not re-fetch — treat it as
first-paint only for now (`src/public/js/frond.js` warns "sse transport is not wired yet (v1
supports poll and ws)"; use `poll` or `ws` for a live experience). frond.js (already loaded)
wires the marker and morphs the result, so a focused input survives the swap.

```twig
{# Poll every 5 seconds #}
{% live "cart" poll 5 %}
    <strong>{{ count }}</strong> items
{% endlive %}

{# WebSocket - the server pushes updates #}
{% live "chat" ws "/ws/chat" %}
    {% for msg in messages %}<div>{{ msg.user }}: {{ msg.text }}</div>{% endfor %}
{% endlive %}
```

Supply the data with a provider registered by name. It runs on every refresh with the live request,
so auth re-applies each time (an unauthenticated caller never sees another user's data):

```php
\Tina4\Frond::liveSource("cart", function ($request) {
    return ["count" => cartCount($request), "items" => cartItems($request)];
});
```

The provider feeds the always-on `GET /__frond/live/{name}` endpoint — the block name is the route.
For a `ws` block, push a fresh render the instant data changes with
`\Tina4\Frond::pushLive("cart", [...])`. Nested live blocks are rejected.

### Cache Blocks
```twig
{% cache "sidebar" 300 %}
    {# This block is cached for 300 seconds — fetch in the route, pass `posts` into the template. #}
    {% for post in posts %}
        <a href="/posts/{{ post.id }}">{{ post.title }}</a>
    {% endfor %}
{% endcache %}
```

## frond.js — Frontend Helper

A lightweight JavaScript library that ships with every Tina4 backend at `/js/frond.js`. It exposes a
global `frond` object, auto-attaches the current `Authorization: Bearer` token to every request, and
rotates it from any `FreshToken` response header. Include it:
```html
<script src="/js/frond.js"></script>
```

### HTTP + injecting HTML
```javascript
// Low-level request — auto-sends the bearer token; parses JSON responses.
frond.request("/api/users", {
    method: "POST",
    body: { name: "Alice" },              // object -> JSON body
    onSuccess: (data, status) => console.log(data),
    onError:   (status) => console.warn("failed", status),
});

// GET a partial and inject its HTML into an element:
frond.load("/users/list", "usersTable");

// POST data and inject the response HTML into a target element:
frond.post("/api/users", { name: "Alice" }, "message");
```

### Forms
```javascript
// Collect a form's fields (fills the formToken automatically) into a FormData:
const data = frond.form.collect("userForm");

// Collect + POST + inject the response into a target element (default: "message"):
frond.form.submit("userForm", "/api/users", "message");

// Load an edit/create/delete form into a target element:
frond.form.show("edit", "/users/1/edit", "form");
```

### Auth token
```javascript
frond.token = jwt;      // set the bearer token; all subsequent requests attach it
const current = frond.token;
```

### Messages, popups, GraphQL
```javascript
frond.message("Saved!", "success");                 // renders into the #message element
frond.popup("/preview", "Preview", 800, 600);       // centred popup window
frond.graphql("/graphql", "{ users { id name } }", {}, (res) => console.log(res.data));
```

### WebSocket / SSE (auto-reconnect)
```javascript
const ws = frond.ws("/ws/chat", {
    onOpen:    () => console.log("connected"),
    onMessage: (data) => console.log(data),
});
ws.send({ type: "message", text: "Hello" });

const stream = frond.sse("/events");
stream.on("message", (data) => console.log(data));
```

Live blocks in templates are wired automatically by `frond.live` on `DOMContentLoaded` — you don't
call it yourself.
