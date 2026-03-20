# Tina4 Frond — Feature Set Decision

## Philosophy
- Security first: auto-escape by default, no arbitrary code execution
- Include everything needed to get the job done
- Extensible for edge cases
- No bloat — if a feature doesn't solve a real problem, it's out

---

## PROPOSED FEATURES — Please mark Y (yes) or N (no)

### Output & Variables
| # | Feature | Description | Recommended |
|---|---------|-------------|-------------|
| 1 | `{{ variable }}` | Output with auto-escaping | Y — core |
| 2 | `{{ obj.property }}` | Dot notation access | Y — core |
| 3 | `{{ arr[0] }}` | Array index access | Y — core |
| 4 | `{{ var \| filter }}` | Pipe filter syntax | Y — core |
| 5 | `{{ var \| filter1 \| filter2 }}` | Chained filters | Y — core |
| 6 | `{{ a ~ b }}` | String concatenation | Y — useful |
| 7 | `{{ cond ? "yes" : "no" }}` | Ternary expression | Y — reduces template verbosity |
| 8 | `{{ value ?? "default" }}` | Null coalescing | Y — cleaner than `\| default` |
| 9 | `{{ obj?.prop }}` | Null-safe access | Y — prevents template errors |
| 10 | `{# comment #}` | Comments (not rendered) | Y — core |
| 10b | `{{ a + b }}`, `{{ a * b }}`, etc. | Math/arithmetic in expressions | Y — core |

**Note:** All operators (math, comparison, logic, string) work inside `{{ }}` output, `{% if %}` conditions, and `{% set %}` assignments. Examples: `{{ price * qty }}`, `{{ total + tax }}`, `{{ 2 ** 10 }}`.

### Control Structures
| # | Feature | Description | Recommended |
|---|---------|-------------|-------------|
| 11 | `{% if %}` / `{% elseif %}` / `{% else %}` / `{% endif %}` | Conditionals | Y — core |
| 12 | `{% for item in items %}` / `{% endfor %}` | Iteration | Y — core |
| 13 | `{% for key, value in map %}` | Key-value iteration | Y — core |
| 14 | `{% for %}` ... `{% else %}` | Empty collection fallback | Y — very useful |
| 15 | `{% switch var %}` / `{% case %}` / `{% default %}` | Switch statement | Y — cleaner than chained if/elseif |
| 16 | `{% set var = value %}` | Variable assignment | Y — core |
| 17 | `{% capture varname %}...{% endcapture %}` | Capture block output to variable | Y — more powerful than set for HTML chunks |

### Loop Variables (available inside `{% for %}`)
| # | Variable | Description | Recommended |
|---|----------|-------------|-------------|
| 18 | `loop.index` | 1-based counter | Y |
| 19 | `loop.index0` | 0-based counter | Y |
| 20 | `loop.first` | true on first iteration | Y |
| 21 | `loop.last` | true on last iteration | Y |
| 22 | `loop.length` | total items | Y |
| 23 | `loop.parent` | parent loop (nested loops) | Y — needed for nested tables |
| 24 | `loop.remaining` | items left after current | Y |
| 25 | `loop.depth` | recursion depth (nested/recursive loops) | Y |

### Template Inheritance & Composition
| # | Feature | Description | Recommended |
|---|---------|-------------|-------------|
| 26 | `{% extends "base.html" %}` | Template inheritance | Y — core |
| 27 | `{% block name %}` / `{% endblock %}` | Define/override blocks | Y — core |
| 28 | `{{ parent() }}` | Render parent block content | Y — needed for extension |
| 29 | `{% include "partial.html" %}` | Include another template | Y — core |
| 30 | `{% include "x.html" with { key: val } %}` | Include with variables | Y — useful |
| 31 | `{% include "x.html" ignore missing %}` | Skip if file missing | Y — graceful degradation |
| 32 | `{% embed "card.html" %}` | Include + override blocks | Y — powerful component pattern |
| 33 | `{% macro name(args) %}` / `{% endmacro %}` | Reusable template functions | Y — DRY |
| 34 | `{% import "macros.html" as m %}` | Import macros from file | Y — organize macros |
| 35 | `{% fragment name %}` | Renderable fragment (for HTMX/partial updates) | Y — modern pattern |
| 36 | `{% push "scripts" %}` / `{% stack "scripts" %}` | Content stacking (JS/CSS from children) | Y — solves real pain point |
| 37 | `{% once %}` | Render exactly once per request | Y — dedup scripts/styles |
| 37b | `{% redirect "/path" %}` / `{% redirect "/path" 301 %}` | Stop rendering, trigger HTTP redirect (302 default, or 301) | Y — template-level redirect |

### Filter Naming Convention
All filter names inside Frond templates use `snake_case` — this is the **template language convention**, not the host language convention. The same `.html` file must work identically on Python, PHP, Ruby, and Node.js. Filter names are part of Frond's syntax, not the host language.

Examples: `json_encode`, `json_decode`, `number_format`, `url_encode`, `base64_encode`, `escape_once`, `date_modify`, `sort_natural`

The framework's **registration API** follows each language's convention (`addFilter` in PHP/JS, `add_filter` in Python/Ruby), but the template syntax is universal.

### Filters — String
| # | Filter | Example | Recommended |
|---|--------|---------|-------------|
| 38 | `upper` | `{{ name \| upper }}` → `JOHN` | Y |
| 39 | `lower` | `{{ name \| lower }}` → `john` | Y |
| 40 | `capitalize` | `{{ name \| capitalize }}` → `John` | Y |
| 41 | `title` | `{{ text \| title }}` → `Title Case` | Y |
| 42 | `trim` | Strip whitespace | Y |
| 43 | `truncate(n)` | Truncate to n chars with `...` | Y |
| 44 | `truncatewords(n)` | Truncate to n words | Y |
| 45 | `replace(a, b)` | String replace | Y |
| 46 | `split(sep)` | Split to array | Y |
| 47 | `slug` | URL-safe slug | Y |
| 48 | `nl2br` | Newlines to `<br>` | Y |
| 49 | `striptags` | Remove HTML tags | Y |
| 50 | `wordwrap(n)` | Wrap text at n chars | Y |
| 51 | `pad(n, char)` | Pad string to length | N — rarely used in templates |
| 52 | `center(n)` | Center in field of n | N — text formatting, not HTML |
| 53 | `indent(n)` | Indent lines | N — rarely needed |
| 54 | `urlize` | Auto-link URLs in text | Y — very useful for user content |

### Filters — Array/Collection
| # | Filter | Example | Recommended |
|---|--------|---------|-------------|
| 55 | `join(sep)` | Join array with separator | Y |
| 56 | `first` | First element | Y |
| 57 | `last` | Last element | Y |
| 58 | `length` | Count items | Y |
| 59 | `reverse` | Reverse array | Y |
| 60 | `sort` | Sort array | Y |
| 61 | `sort_natural` | Case-insensitive sort | Y |
| 62 | `unique` | Remove duplicates | Y |
| 63 | `keys` | Get dict/map keys | Y |
| 64 | `values` | Get dict/map values | Y |
| 65 | `merge(other)` | Merge arrays/dicts | Y |
| 66 | `slice(start, length)` | Sub-array | Y |
| 67 | `where(key, value)` | Filter by property | Y — very useful |
| 68 | `find(key, value)` | Find first match | Y |
| 69 | `groupby(key)` | Group by property | Y |
| 70 | `map(key)` | Extract property from each | Y — replaces verbose loops |
| 71 | `compact` | Remove null/empty values | Y |
| 72 | `flatten` | Flatten nested arrays | Y |
| 73 | `batch(n)` | Split into groups of n | Y — useful for grid layouts |
| 74 | `sum` | Sum of numbers | Y |
| 75 | `shuffle` | Randomize order | Y — note: disables caching for this template |

### Filters — Number
| # | Filter | Example | Recommended |
|---|--------|---------|-------------|
| 76 | `number_format(decimals)` | Format number | Y |
| 77 | `abs` | Absolute value | Y |
| 78 | `round(precision)` | Round number | Y |
| 79 | `ceil` | Round up | Y |
| 80 | `floor` | Round down | Y |
| 81 | `at_least(n)` | Clamp to minimum | Y — useful for pagination |
| 82 | `at_most(n)` | Clamp to maximum | Y |
| 83 | `filesizeformat` | Bytes → "1.2 MB" | Y — very useful |

### Filters — Date
| # | Filter | Example | Recommended |
|---|--------|---------|-------------|
| 84 | `date(format)` | Format date/datetime | Y |
| 85 | `date_modify("+1 day")` | Date arithmetic | Y |
| 86 | `timeago` | "3 hours ago" relative time | Y — modern UX |

### Filters — Encoding/Security
| # | Filter | Example | Recommended |
|---|--------|---------|-------------|
| 87 | `escape` / `e` | HTML entity escape (default) | Y — core |
| 88 | `escape("js")` | JavaScript escape | Y — security |
| 89 | `escape("url")` | URL encode | Y |
| 90 | `escape("css")` | CSS escape | Y — security |
| 91 | `raw` | Disable auto-escaping | Y — needed for trusted HTML |
| 92 | `url_encode` | URL encode value | Y |
| 93 | `url_decode` | URL decode value | Y |
| 94 | `json_encode` | To JSON string | Y |
| 94b | `json_decode` | Parse JSON string to object/array | Y |
| 95 | `base64_encode` | Base64 encode | Y |
| 96 | `base64_decode` | Base64 decode | Y |
| 97 | `md5` | MD5 hash | N — security anti-pattern in templates |
| 98 | `escape_once` | Prevent double-escaping | Y — common issue |

### Filters — Utility
| # | Filter | Example | Recommended |
|---|--------|---------|-------------|
| 99 | `default(val)` | Default if empty/null | Y — core |
| 100 | `type` | Returns type name ("string", "number", etc.) | Y — debugging |
| 100b | `dump` | Pretty-print for debugging (dev mode only) | Y — filter form of dump() function |

### Tests (used with `is`)
| # | Test | Example | Recommended |
|---|------|---------|-------------|
| 101 | `defined` | `{% if var is defined %}` | Y |
| 102 | `empty` | `{% if var is empty %}` | Y |
| 103 | `null` / `none` | `{% if var is null %}` | Y |
| 104 | `even` | `{% if num is even %}` | Y |
| 105 | `odd` | `{% if num is odd %}` | Y |
| 106 | `iterable` | `{% if var is iterable %}` | Y |
| 107 | `string` | `{% if var is string %}` | Y |
| 108 | `number` | `{% if var is number %}` | Y |
| 109 | `boolean` | `{% if var is boolean %}` | Y |
| 110 | `divisible by(n)` | `{% if x is divisible by(3) %}` | Y |

### Operators
| # | Operator | Description | Recommended |
|---|----------|-------------|-------------|
| 111 | `+`, `-`, `*`, `/`, `%` | Math | Y |
| 112 | `//` | Integer division | Y |
| 113 | `**` | Power | Y |
| 114 | `==`, `!=`, `<`, `>`, `<=`, `>=` | Comparison | Y |
| 115 | `and`, `or`, `not` | Logic | Y |
| 116 | `in` | Containment | Y |
| 117 | `is` | Test operator | Y |
| 118 | `..` | Range (1..5) | Y |
| 119 | `~` | String concatenation | Y |
| 120 | `starts with`, `ends with` | String tests | Y |
| 121 | `matches` | Regex test | Y |

### Built-in Functions
| # | Function | Description | Recommended |
|---|----------|-------------|-------------|
| 122 | `range(start, end, step)` | Generate number sequence | Y |
| 123 | `cycle(a, b, c)` | Cycle through values | Y |
| 124 | `min(a, b)` / `max(a, b)` | Min/max | Y |
| 125 | `random(min, max)` | Random number | Y |
| 126 | `dump(var)` | Debug output (dev mode only) | Y — essential for DX |
| 127 | `source(template)` | Return raw template source | N — rarely needed |
| 128 | `joiner(sep)` | Smart separator (skips first) | Y — elegant lists |

### Security Features
| # | Feature | Description | Recommended |
|---|---------|-------------|-------------|
| 129 | Auto-escaping | All output escaped by default | Y — mandatory |
| 130 | Multiple escape strategies | html, js, css, url | Y — security |
| 131 | Sandboxing | Restrict accessible variables/filters | Y — for user-facing templates |
| 132 | No code execution | Cannot run arbitrary code from templates | Y — by design |

### Extensibility API
| # | Feature | Description | Recommended |
|---|---------|-------------|-------------|
| 133 | `addFilter(name, fn)` | Register custom filter | Y — core |
| 134 | `addFunction(name, fn)` | Register custom function | Y — core |
| 135 | `addGlobal(name, value)` | Register global variable | Y — core |
| 136 | `addTest(name, fn)` | Register custom test | Y — core |
| 137 | `addTag(name, parser, renderer)` | Register custom tag | Y — advanced extensibility |

### Whitespace Control
| # | Feature | Description | Recommended |
|---|---------|-------------|-------------|
| 138 | `{%- -%}` | Strip whitespace around tags | Y |
| 139 | `{{- -}}` | Strip whitespace around output | Y |
| 140 | `{% autoescape false %}` | Disable auto-escape for block | Y |
| 141 | `{% verbatim %}` | Raw output (no parsing) | Y — for JS frameworks |

### Performance
| # | Feature | Description | Recommended |
|---|---------|-------------|-------------|
| 142 | Compiled templates | Parse once, execute many | Y — core |
| 143 | In-memory cache | Cache compiled templates | Y — core |
| 144 | Dev mode invalidation | Detect file changes, recompile | Y — DX |
| 145 | `{% cache key, ttl %}` | Fragment caching | Y — performance |

### Frond Quirks — What Makes Frond *Frond*
These features don't exist in Twig, Jinja2, Blade, or any other template engine. They're what make Frond worth choosing.

#### 1. Inline SQL Queries
```html
{% query "SELECT * FROM users WHERE active = 1 LIMIT 10" as users %}
{% for user in users %}
  <li>{{ user.name }} — {{ user.email }}</li>
{% endfor %}

{# With parameters (safe, parameterized) #}
{% query "SELECT * FROM products WHERE category = ? AND price < ?" with [category, max_price] as products %}

{# Paginated #}
{% query "SELECT * FROM orders" paginate 20 as orders %}
{{ orders.pagination }}
```
**Why:** Tina4 is SQL-first. Templates that need data shouldn't require a round-trip through the controller for simple reads. The query is parameterized (no SQL injection), read-only (no INSERT/UPDATE/DELETE allowed from templates), and uses the connected database automatically.

#### 2. Live Reload Blocks
```html
{% live "chat-messages" poll 5 %}
  {% for msg in messages %}
    <div class="message">{{ msg.text }}</div>
  {% endfor %}
{% endlive %}

{# With a specific endpoint #}
{% live "notifications" from "/api/notifications" poll 10 %}
  <span class="badge">{{ notifications | length }}</span>
{% endlive %}

{# WebSocket instead of polling #}
{% live "ticker" ws "/ws/prices" %}
  <span>{{ price }}</span>
{% endlive %}
```
**Why:** Real-time UI without writing JavaScript. Frond renders the block server-side, wraps it in a container with a `data-frond-live` attribute, and `frond.js` handles the polling/WebSocket connection automatically. The block re-renders server-side and the DOM is updated. Like HTMX, but built into the template engine.

#### 3. Conditional CSS Classes
```html
<button class="{{ classes('btn', active: 'btn-active', disabled: 'btn-disabled', loading: 'btn-loading') }}">
  Submit
</button>
{# Output: <button class="btn btn-active">Submit</button> #}
{# (only includes classes whose condition is truthy) #}

{# Works with arrays too #}
<div class="{{ classes('card', ['shadow', 'rounded'], featured: 'card-featured') }}">
```
**Why:** Every framework has this problem — building CSS class strings from conditions. This is a built-in function, not a filter. Blade has `@class()`, but Frond's version is cleaner.

#### 4. Inline Markdown
```html
{% markdown %}
## Welcome to {{ app_name }}

This is **bold** and this is *italic*.

- Item one
- Item two
- Item three

> A blockquote with {{ user.name }}'s thoughts
{% endmarkdown %}
```
**Why:** Content-heavy pages mix HTML and prose. Writing HTML for every paragraph/heading/list is tedious. Markdown inside templates lets you write content naturally. Frond variables work inside markdown blocks — they're processed first, then the markdown is rendered to HTML.

#### 5. Data Attributes for JS
```html
{# Serialize data to HTML data attributes for JavaScript consumption #}
{% data users as "users" %}
<div data-frond-users='[{"id":1,"name":"John"},...]'>

{# frond.js reads it easily #}
<script>
  const users = Frond.data("users");  // Parsed from data attribute
</script>

{# Multiple data bindings #}
{% data config as "config" %}
{% data user as "current-user" %}
```
**Why:** The cleanest way to pass server data to client JavaScript without inline `<script>` blocks or AJAX calls. Auto-serializes to JSON, HTML-escapes for safety, and `frond.js` provides `Frond.data("name")` to read it back.

#### 6. Inline SVG Icons
```html
{{ icon("chevron-right") }}
{{ icon("user", size=24, class="text-muted") }}
{{ icon("check", color="#22c55e") }}
```
**Why:** SVG icons are ubiquitous but embedding them is ugly. The `icon()` function loads SVGs from `src/public/icons/` and inlines them with configurable size, class, and color. Simple, no icon font dependency.

### EXCLUDED (with reasons)
| Feature | Reason for exclusion |
|---------|---------------------|
| `{% php %}` / arbitrary code blocks | Security — no code execution in templates |
| `@auth` / `@guest` directives | Framework-specific — use `{% if user %}` instead |
| `@env` / `@production` directives | Use globals: `{% if env == "production" %}` |
| `@csrf` / `@method` form helpers | Framework handles this via globals/functions |
| Full component system (`<x-component>`) | Over-engineering — `embed` + `macro` covers this |
| `md5` / `sha1` filters | Security anti-pattern in templates |
| `shuffle` filter | Non-deterministic, breaks caching |
| Arrow functions in filters | Complexity vs value — custom filters solve this |
| Destructuring assignment | Language feature, not template feature |
| Async iteration | Only relevant for Node.js, breaks cross-language parity |
| Custom delimiters | Adds parser complexity for minimal gain |
| `@inject` (service container) | Templates should receive data, not fetch it |

---

## FEATURE COUNT SUMMARY (APPROVED)
- **Output/Variables:** 11 (incl. math expressions)
- **Control Structures:** 7
- **Loop Variables:** 8 (all included)
- **Template Composition:** 13 (incl. redirect tag)
- **Filters:** ~59 (incl. json_decode, dump, shuffle)
- **Tests:** 10
- **Operators:** 11
- **Functions:** 6 (source excluded)
- **Security:** 4
- **Extensibility:** 5
- **Whitespace/Performance:** 8

**Total: ~142 features** — comprehensive, no bloat, all approved
