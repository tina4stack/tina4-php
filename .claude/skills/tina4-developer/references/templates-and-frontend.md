# Templates & Frontend

## Frond Templates

Tina4 uses Frond, a Twig-compatible template engine. Templates go in `src/templates/`.

**Template caching:** In production, compiled templates are cached for performance. In
development mode, Frond uses live filesystem lookups so changes are reflected immediately
without restarting the server.

### Rendering
```python
@get("/")
async def home(request, response):
    return response.render("index.twig", {
        "title": "My App",
        "users": User().select("*").fetch()
    })
```

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
{{ name | upper }}                 → UPPERCASE
{{ name | lower }}                 → lowercase
{{ name | capitalize }}            → First letter cap
{{ text | truncate(100) }}         → Truncate
{{ list | join(", ") }}            → Join array
{{ value | default("N/A") }}       → Default if null
{{ html | raw }}                   → No auto-escaping
{{ price | number_format(2) }}     → 1,234.56
{{ date | date("Y-m-d") }}        → Formatted date
{{ text | slug }}                  → url-friendly-slug
{{ created | timeago }}            → "3 hours ago"
{{ data | base64encode }}          → Base64-encoded string
{{ encoded | base64decode }}       → Decoded from Base64
```

`base64encode` / `base64decode` are filter aliases — use them for encoding data in templates
(e.g., inline images, token payloads).

All filter names use **snake_case** regardless of language.

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

### Inline SQL Queries (Frond-unique)
```twig
{% query "SELECT * FROM products WHERE active = ?" params=[true] as products %}
{% for product in products.data %}
    <div>{{ product.name }} — ${{ product.price | number_format(2) }}</div>
{% endfor %}
<p>{{ products.total }} products found</p>
```

### Live Blocks (Real-time updates)
```twig
{# Polling — refreshes every 5 seconds #}
{% live "notifications" poll 5 %}
    <span>{{ notifications | length }} new</span>
{% endlive %}

{# WebSocket — instant updates #}
{% live "chat-messages" ws "/ws/chat" %}
    {% for msg in messages %}
        <div>{{ msg.user }}: {{ msg.text }}</div>
    {% endfor %}
{% endlive %}
```

### Cache Blocks
```twig
{% cache "sidebar" 300 %}
    {# This block is cached for 300 seconds #}
    {% query "SELECT * FROM popular_posts LIMIT 10" as posts %}
    {% for post in posts.data %}
        <a href="/posts/{{ post.id }}">{{ post.title }}</a>
    {% endfor %}
{% endcache %}
```

## frond.js — Frontend Helper

A lightweight (<10KB) JavaScript library that works with all Tina4 backends. Include it:
```html
<script src="/js/frond.js"></script>
```

## tina4js.min.js — Reactive Frontend Bundle

A bundled reactive frontend library (13.6KB) available in all backend repos. Use it for
signal-based reactivity and Web Components without a build step:
```html
<script src="/js/tina4js.min.js"></script>
```

This is the **tina4-js** library pre-bundled and ready to use. It provides signals, reactive
components, and Web Component support. Use `frond.js` for server-rendered helpers and
`tina4js.min.js` for reactive SPAs — do not use both in the same feature.

### HTTP Requests
```javascript
const users = await Frond.get("/api/users");
await Frond.post("/api/users", { name: "Alice" });
await Frond.put("/api/users/1", { name: "Alice Smith" });
await Frond.delete("/api/users/1");
```

### Forms
```javascript
Frond.submitForm("#user-form", "/api/users");
Frond.fillForm("#user-form", { name: "Alice", email: "alice@example.com" });
Frond.resetForm("#user-form");
```

### CRUD Table (auto-generated)
```javascript
Frond.crud({
    target: "#users-table",
    endpoint: "/api/users",
    columns: ["id", "name", "email"],
    searchable: true,
    paginated: true
});
```

### Notifications and Modals
```javascript
Frond.notify("Saved!", "success");
Frond.notify("Error!", "error");
Frond.confirm("Delete this item?").then(ok => { if (ok) { /* delete */ } });
Frond.modal({ title: "Edit User", body: "<form>...</form>" });
```

### Authentication
```javascript
Frond.config({ auth: true });
Frond.setToken(jwt);        // Stored in memory
// All subsequent requests auto-attach Bearer token
```

### WebSocket
```javascript
const ws = Frond.ws("/ws/chat", {
    reconnect: true,
    onMessage: (data) => console.log(data),
});
ws.send({ type: "message", text: "Hello" });
```
