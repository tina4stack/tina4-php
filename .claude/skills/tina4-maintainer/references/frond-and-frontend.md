# Frond Template Engine & Frontend

## Frond Overview

Frond is Tina4's native, zero-dependency, Twig-compatible template engine. Implemented from scratch
in each language with identical syntax. Architecture: Lexer → Parser → Compiler → Runtime → Cache.

## Syntax

### Output and Logic
```twig
{{ variable }}              {# Output with auto-escaping #}
{{ variable|raw }}          {# Unescaped output #}
{% if condition %}...{% endif %}  {# Logic blocks #}
{# This is a comment #}
```

### Variables and Access
```twig
{{ user.name }}             {# Dot notation #}
{{ items[0] }}              {# Array index #}
{{ data.users[0].name }}    {# Chained access #}
{{ value ?? "default" }}    {# Null coalescing #}
{{ user?.address?.city }}   {# Null-safe access #}
{{ "Hello " ~ name }}       {# String concatenation #}
{{ condition ? "yes" : "no" }}  {# Ternary #}
```

### Control Structures
```twig
{% if user.is_active %}
    Active
{% elseif user.is_pending %}
    Pending
{% else %}
    Inactive
{% endif %}

{% for user in users %}
    {{ loop.index }}. {{ user.name }}
{% else %}
    No users found.
{% endfor %}

{# Loop variables: loop.index, loop.index0, loop.first, loop.last,
   loop.length, loop.parent, loop.remaining, loop.depth #}

{% switch status %}
    {% case "active" %}Active{% endcase %}
    {% case "pending" %}Pending{% endcase %}
    {% default %}Unknown{% enddefault %}
{% endswitch %}

{% set greeting = "Hello " ~ name %}
{% capture content %}...{% endcapture %}
```

### Template Composition
```twig
{# Inheritance #}
{% extends "base.twig" %}
{% block content %}Page content{% endblock %}
{{ parent() }}  {# Include parent block content #}

{# Includes #}
{% include "header.twig" %}
{% include "sidebar.twig" with {"menu": items} %}
{% include "optional.twig" ignore missing %}

{# Macros #}
{% macro input(name, value, type) %}
    <input type="{{ type|default('text') }}" name="{{ name }}" value="{{ value }}">
{% endmacro %}
{% import "forms.twig" as forms %}
{{ forms.input("email", "", "email") }}

{# Embed (include + override blocks) #}
{% embed "card.twig" %}
    {% block title %}Custom Title{% endblock %}
{% endembed %}

{# Fragments (for HTMX) #}
{% fragment "user-list" %}...{% endfragment %}

{# Push/Stack #}
{% push "scripts" %}<script src="app.js"></script>{% endpush %}
{% stack "scripts" %}
```

## Frond-Unique Features ("Quirks")

These set Frond apart from standard Twig:

### 1. Inline SQL Queries
```twig
{% query "SELECT * FROM users WHERE active = ?" params=[true] as users %}
{% for user in users.data %}
    {{ user.name }}
{% endfor %}
Total: {{ users.total }}

{# With pagination #}
{% query "SELECT * FROM products" page=1 limit=20 as products %}
```

### 2. Live Reload Blocks
```twig
{# Polling-based #}
{% live "notifications" poll 5 %}
    {{ notifications|length }} new
{% endlive %}

{# WebSocket-based #}
{% live "chat-messages" ws "/ws/chat" %}
    {% for msg in messages %}
        {{ msg.text }}
    {% endfor %}
{% endlive %}
```
Live blocks re-render server-side and push HTML fragments to the client via frond.js.

### 3. Conditional CSS Classes
```twig
<div class="{{ classes('btn', active: 'btn-active', disabled: 'btn-disabled') }}">
```

### 4. Inline Markdown
```twig
{% markdown %}
# Hello {{ name }}
This is **bold** with variable interpolation.
{% endmarkdown %}
```

### 5. Data Attributes for JS
```twig
{% data config as "app-config" %}
{# Outputs: <script type="application/json" data-name="app-config">...</script> #}
```

### 6. Inline SVG Icons
```twig
{{ icon("chevron-right", size=24, class="icon-sm") }}
```

## Filters (~59 total)

ALL filter names use **snake_case** inside templates, regardless of host language.

### String
`upper`, `lower`, `capitalize`, `title`, `trim`, `ltrim`, `rtrim`, `truncate`, `wordwrap`,
`pad`, `repeat`, `reverse`, `replace`, `split`, `slug`, `nl2br`, `striptags`, `urlize`,
`spaceless`, `indent`

### Array/Collection
`join`, `first`, `last`, `sort`, `sort_by`, `reverse`, `unique`, `where`, `find`, `reject`,
`group_by`, `map`, `pluck`, `flatten`, `batch`, `merge`, `slice`, `chunk`, `keys`, `values`,
`length`, `sum`, `min`, `max`, `average`, `column`

### Number
`number_format`, `abs`, `round`, `ceil`, `floor`, `at_least`, `at_most`, `filesizeformat`

### Date
`date`, `date_modify`, `timeago`

### Encoding/Security
`escape` (strategies: html, js, css, url), `raw`, `url_encode`, `url_decode`, `json_encode`,
`json_decode`, `base64_encode`, `base64_decode`

### Utility
`default`, `type`, `dump`

## Tests (in conditionals)
```twig
{% if value is defined %}
{% if list is empty %}
{% if value is null %}
{% if num is even %}
{% if num is odd %}
{% if items is iterable %}
{% if name is string %}
{% if count is number %}
{% if flag is boolean %}
{% if num is divisible by(3) %}
```

## Filter Registration API

The template-facing name is always snake_case. The registration API follows host language convention:

```python
# Python
frond.add_filter("my_filter", lambda value, arg: ...)

# PHP
$frond->addFilter("my_filter", function($value, $arg) { ... });

# Ruby
frond.add_filter("my_filter") { |value, arg| ... }

# Node.js
frond.addFilter("my_filter", (value, arg) => ...);
```

---

## frond.js Frontend Helper

Lightweight (<10KB minified), zero-dependency, framework-agnostic JavaScript library.
The unified frontend for all Tina4 backends. Supports ES Module + IIFE.

### HTTP Client
```javascript
const users = await Frond.get("/api/users", { params: { page: 1 } });
await Frond.post("/api/users", { name: "Alice" });
await Frond.put("/api/users/1", { name: "Alice Smith" });
await Frond.delete("/api/users/1");
```

### Form Handling
```javascript
Frond.submitForm("#user-form", "/api/users");
const data = Frond.formData("#user-form");
Frond.fillForm("#user-form", { name: "Alice", email: "alice@example.com" });
Frond.resetForm("#user-form");
```

### CRUD Table
```javascript
Frond.crud({
    target: "#users-table",
    endpoint: "/api/users",
    columns: ["id", "name", "email"],
    searchable: true,
    paginated: true
});
```

### UI Utilities
```javascript
Frond.modal({ title: "Confirm", body: "Are you sure?", onConfirm: () => {} });
Frond.confirm("Delete this item?").then(ok => {});
Frond.notify("Saved!", "success");  // success, error, warning, info

Frond.el("#my-id");         // querySelector
Frond.els(".my-class");     // querySelectorAll
Frond.on("#btn", "click", handler);
Frond.show("#panel"); Frond.hide("#panel"); Frond.toggle("#panel");
Frond.load("#container", "/api/partial");
```

### Authentication
```javascript
Frond.setToken(jwt);    // Stored in memory by default
Frond.getToken();
Frond.clearToken();
// All HTTP methods auto-attach Bearer token when Frond.config({ auth: true })
```

### WebSocket
```javascript
const ws = Frond.ws("/ws/chat", {
    reconnect: true,
    maxRetries: 10,
    heartbeat: true,
    onMessage: (data) => {},
    onOpen: () => {},
    onClose: () => {}
});
ws.send({ type: "message", text: "Hello" });
```

### Configuration
```javascript
Frond.config({
    baseUrl: "/api",
    auth: true,
    tokenStorage: "memory",  // or "localStorage"
    csrfToken: "...",
    defaultHeaders: { "X-Custom": "value" }
});
```
