# Tina4 Frond — Template Engine Specification

## Overview
Frond is Tina4's native, zero-dependency, twig-like template engine. It is implemented from scratch in each language (Python, PHP, Ruby, TypeScript) with identical syntax and behavior. No third-party template libraries (twig/twig, Twig.js, etc.) are used.

## Design Goals
1. **Identical syntax** across all four implementations
2. **Zero third-party dependencies** — pure language-native code
3. **Fast** — compiled templates cached in memory
4. **Safe** — auto-escaping by default, explicit raw output
5. **Extensible** — custom filters, functions, and globals

## Syntax Reference

### Variables
```
{{ variable }}
{{ user.name }}
{{ items[0] }}
{{ user.name | upper }}
```

### Filters (pipe syntax)
```
{{ name | upper }}              → UPPERCASE
{{ name | lower }}              → lowercase
{{ name | capitalize }}         → Capitalize first letter
{{ text | truncate(100) }}      → Truncate to 100 chars
{{ html | raw }}                → No auto-escaping
{{ date | date("Y-m-d") }}     → Date formatting
{{ list | join(", ") }}         → Join array
{{ value | default("N/A") }}   → Default if empty/null
{{ text | escape }}             → HTML entity escape (default)
{{ number | number_format(2) }}→ Number formatting
{{ text | trim }}               → Strip whitespace
{{ text | replace("a", "b") }} → String replace
{{ list | length }}             → Array/string length
{{ list | first }}              → First element
{{ list | last }}               → Last element
{{ list | reverse }}            → Reverse array
{{ list | sort }}               → Sort array
{{ json | json_encode }}        → JSON string
{{ text | nl2br }}              → Newlines to <br>
{{ text | striptags }}          → Remove HTML tags
{{ text | slug }}               → URL-safe slug
{{ text | md5 }}                → MD5 hash
```

### Control Structures
```
{% if condition %}
  ...
{% elseif other_condition %}
  ...
{% else %}
  ...
{% endif %}

{% for item in items %}
  {{ loop.index }}      → 1-based index
  {{ loop.index0 }}     → 0-based index
  {{ loop.first }}      → true on first iteration
  {{ loop.last }}       → true on last iteration
  {{ loop.length }}     → total items
  {{ item }}
{% else %}
  No items found.
{% endfor %}

{% for key, value in map %}
  {{ key }}: {{ value }}
{% endfor %}
```

### Template Inheritance
```
{# base.html #}
<!DOCTYPE html>
<html>
<head>
  <title>{% block title %}Default Title{% endblock %}</title>
</head>
<body>
  {% block content %}{% endblock %}
</body>
</html>

{# page.html #}
{% extends "base.html" %}

{% block title %}My Page{% endblock %}

{% block content %}
  <h1>Hello {{ name }}</h1>
{% endblock %}
```

### Includes
```
{% include "partials/header.html" %}
{% include "partials/card.html" with { title: "Hello", body: text } %}
{% include "partials/optional.html" ignore missing %}
```

### Macros (reusable template functions)
```
{% macro input(name, value, type) %}
  <input type="{{ type | default('text') }}" name="{{ name }}" value="{{ value }}">
{% endmacro %}

{{ input("email", user.email) }}
```

### Comments
```
{# This is a comment — not rendered in output #}
```

### Whitespace Control
```
{%- if condition -%}   → strips whitespace before/after tag
{{- variable -}}        → strips whitespace before/after output
```

### Set Variables
```
{% set greeting = "Hello " ~ name %}
{{ greeting }}
```

### String Concatenation
```
{{ "Hello " ~ name ~ "!" }}
```

### Ternary / Conditional Expression
```
{{ condition ? "yes" : "no" }}
{{ value ?? "default" }}
```

### Tests
```
{% if variable is defined %}
{% if variable is empty %}
{% if variable is null %}
{% if number is even %}
{% if number is odd %}
{% if value is iterable %}
```

## Implementation Requirements

### Per Language
Each implementation must:
1. **Lexer** — tokenize template into: TEXT, VAR_START, VAR_END, BLOCK_START, BLOCK_END, COMMENT
2. **Parser** — build AST from tokens
3. **Compiler** — compile AST to executable (cached)
4. **Runtime** — execute compiled template with context data
5. **Cache** — store compiled templates in memory, invalidate on file change (dev mode)

### API Surface (per language)

**Python:**
```python
from tina4_python import Frond

engine = Frond(template_dir="src/templates")
engine.add_filter("custom", lambda val: val.upper())
engine.add_global("app_name", "My App")
output = engine.render("page.html", {"name": "World"})
```

**PHP:**
```php
use Tina4\Frond;

$engine = new Frond("src/templates");
$engine->addFilter("custom", fn($val) => strtoupper($val));
$engine->addGlobal("app_name", "My App");
$output = $engine->render("page.html", ["name" => "World"]);
```

**Ruby:**
```ruby
require 'tina4/frond'

engine = Tina4::Frond.new(template_dir: "src/templates")
engine.add_filter("custom") { |val| val.upcase }
engine.add_global("app_name", "My App")
output = engine.render("page.html", { name: "World" })
```

**TypeScript/Node.js:**
```typescript
import { Frond } from '@tina4/frond';

const engine = new Frond('src/templates');
engine.addFilter('custom', (val: string) => val.toUpperCase());
engine.addGlobal('appName', 'My App');
const output = engine.render('page.html', { name: 'World' });
```

## Testing Requirements
Every implementation must pass the **same test suite** (test names and expected outputs identical):

1. `test_variable_output` — `{{ name }}` renders correctly
2. `test_filter_upper` — `{{ name | upper }}` renders UPPERCASE
3. `test_filter_chain` — `{{ name | upper | truncate(5) }}` chains correctly
4. `test_if_true` / `test_if_false` / `test_if_else` — conditionals
5. `test_for_loop` — iteration with loop variables
6. `test_for_empty` — `{% else %}` in for loop
7. `test_extends` — template inheritance
8. `test_include` — partial inclusion
9. `test_include_with_vars` — include with passed variables
10. `test_macro` — macro definition and invocation
11. `test_set_variable` — variable assignment
12. `test_auto_escape` — HTML entities escaped by default
13. `test_raw_filter` — `| raw` disables escaping
14. `test_whitespace_control` — `{%- -%}` strips whitespace
15. `test_comment` — `{# #}` not rendered
16. `test_nested_access` — `{{ user.address.city }}` dot notation
17. `test_undefined_variable` — renders empty string, no error
18. `test_custom_filter` — registered filter works
19. `test_custom_global` — registered global available in template
20. `test_cache_invalidation` — template changes detected in dev mode

### Negative Tests
1. `test_unclosed_variable` — `{{ name` → clear error with line number
2. `test_unclosed_block` — `{% if true %}` without `{% endif %}` → error
3. `test_unknown_filter` — `{{ x | nonexistent }}` → error with filter name
4. `test_invalid_syntax` — `{% foo %}` → error identifying unknown tag
5. `test_circular_extends` — `a extends b, b extends a` → error, no infinite loop
