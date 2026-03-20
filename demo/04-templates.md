# Templates (Frond Engine)

Frond is Tina4's zero-dependency template engine. It provides Twig-compatible syntax including variables, filters, control structures (if/for/set), template inheritance (extends/block), includes, macros, auto-escaping, caching, sandbox mode, and whitespace control. Templates are loaded from `src/templates/` by default.

Frond compiles templates through a pipeline: tokenize, parse AST, resolve inheritance, then execute. No external dependencies are required.

## Basic Setup

```php
use Tina4\Frond;

$frond = new Frond('src/templates');

// Render a template file
$html = $frond->render('home.html', ['title' => 'Welcome']);

// Render a raw string
$html = $frond->renderString('Hello, {{ name }}!', ['name' => 'Alice']);
// Output: Hello, Alice!
```

## Variables

```html
<!-- src/templates/greeting.html -->
<h1>Hello, {{ user.name }}</h1>
<p>Your email is {{ user.email }}</p>
<p>Items in cart: {{ cart_count }}</p>
```

```php
$html = $frond->render('greeting.html', [
    'user' => ['name' => 'Alice', 'email' => 'alice@example.com'],
    'cart_count' => 3,
]);
```

## Filters

Filters transform values using the pipe (`|`) syntax. Frond includes many built-in filters.

```html
{{ name|upper }}               {# ALICE #}
{{ name|lower }}               {# alice #}
{{ name|title }}               {# Alice Johnson #}
{{ description|truncate(50) }} {# First 50 chars... #}
{{ price|number_format(2) }}   {# 19.99 #}
{{ items|length }}             {# 5 #}
{{ items|first }}              {# First item #}
{{ items|last }}               {# Last item #}
{{ items|join(', ') }}         {# item1, item2, item3 #}
{{ html_content|raw }}         {# Unescaped HTML #}
{{ text|escape }}              {# HTML-escaped text #}
{{ text|nl2br }}               {# Newlines to <br> #}
{{ data|json_encode }}         {# JSON string #}
{{ 'default'|default(value) }} {# Fallback value #}
{{ items|reverse }}            {# Reversed array #}
{{ items|sort }}               {# Sorted array #}
{{ text|trim }}                {# Whitespace trimmed #}
{{ text|replace('old', 'new') }}
```

### Custom Filters

```php
$frond->addFilter('currency', function (mixed $value, string $symbol = '$'): string {
    return $symbol . number_format((float)$value, 2);
});
```

```html
{{ price|currency }}       {# $19.99 #}
{{ price|currency('EUR') }}  {# EUR19.99 #}
```

## Control Structures

### If / Elseif / Else

```html
{% if user.role == 'admin' %}
    <span class="badge">Admin</span>
{% elseif user.role == 'editor' %}
    <span class="badge">Editor</span>
{% else %}
    <span class="badge">User</span>
{% endif %}

{% if items is empty %}
    <p>No items found.</p>
{% endif %}

{% if user is not null %}
    <p>Welcome back!</p>
{% endif %}
```

### For Loops

```html
<ul>
{% for item in items %}
    <li>{{ item.name }} - {{ item.price|currency }}</li>
{% endfor %}
</ul>

{# Loop with index #}
{% for key, value in settings %}
    <p>{{ key }}: {{ value }}</p>
{% endfor %}

{# Empty fallback #}
{% for order in orders %}
    <div>Order #{{ order.id }}</div>
{% else %}
    <p>No orders yet.</p>
{% endfor %}
```

### Set Variables

```html
{% set greeting = 'Hello, ' ~ user.name ~ '!' %}
<h1>{{ greeting }}</h1>

{% set total = price * quantity %}
<p>Total: {{ total|currency }}</p>
```

## Template Inheritance

### Base Template

```html
<!-- src/templates/base.html -->
<!DOCTYPE html>
<html>
<head>
    <title>{% block title %}Tina4 App{% endblock %}</title>
    {% block head %}
    <link rel="stylesheet" href="/css/style.css">
    {% endblock %}
</head>
<body>
    <nav>{% block nav %}{% endblock %}</nav>
    <main>
        {% block content %}{% endblock %}
    </main>
    <footer>{% block footer %}&copy; 2026 Tina4{% endblock %}</footer>
</body>
</html>
```

### Child Template

```html
<!-- src/templates/dashboard.html -->
{% extends 'base.html' %}

{% block title %}Dashboard - Tina4 App{% endblock %}

{% block content %}
    <h1>Dashboard</h1>
    <p>Welcome, {{ user.name }}!</p>
    {% include 'partials/stats.html' %}
{% endblock %}
```

## Includes

```html
{# Include a partial with the current context #}
{% include 'partials/header.html' %}

{# Include with extra variables #}
{% include 'partials/card.html' with { 'title': 'Featured', 'count': 5 } %}
```

## Macros

Macros are reusable template fragments, similar to functions.

```html
{% macro input(name, type, value, label) %}
    <label for="{{ name }}">{{ label }}</label>
    <input type="{{ type }}" name="{{ name }}" value="{{ value }}" id="{{ name }}">
{% endmacro %}

{# Use the macro #}
{{ input('email', 'email', '', 'Email Address') }}
{{ input('password', 'password', '', 'Password') }}
```

## Comments

```html
{# This is a comment — it will not appear in the output #}

{#
    Multi-line comments
    are also supported
#}
```

## Whitespace Control

Add a dash (`-`) to trim whitespace around tags.

```html
{%- if show -%}
    <span>Trimmed</span>
{%- endif -%}
```

## Global Variables

```php
$frond->addGlobal('app_name', 'My Tina4 App');
$frond->addGlobal('year', date('Y'));
```

```html
<footer>{{ app_name }} &copy; {{ year }}</footer>
```

## Custom Tests

```php
$frond->addTest('even', function (mixed $value): bool {
    return is_numeric($value) && (int)$value % 2 === 0;
});
```

```html
{% if count is even %}
    <p>Even number of items.</p>
{% endif %}
```

## Sandbox Mode

Restrict which filters, tags, and variables are available in a template — useful for user-submitted templates.

```php
$frond->sandbox(
    filters: ['upper', 'lower', 'escape'],  // Only these filters allowed
    tags: ['if', 'for'],                      // Only these tags allowed
    vars: ['title', 'items'],                 // Only these variables accessible
);
```

## Auto-Escaping

All variable output is HTML-escaped by default to prevent XSS. Use the `raw` filter to output unescaped HTML.

```html
{{ user_input }}        {# Escaped: &lt;script&gt;... #}
{{ trusted_html|raw }}  {# Unescaped: <div>...</div> #}
```

## Tips

- Templates live in `src/templates/` — use subdirectories for organization (`partials/`, `layouts/`, `pages/`).
- Always extend a base template for consistent layouts.
- Use macros for form elements, buttons, and other reusable UI components.
- Enable sandbox mode when rendering user-supplied templates.
- The `raw` filter should only be used for trusted content.
- Frond auto-escapes by default — you rarely need the `escape` filter explicitly.
