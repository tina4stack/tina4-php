# Create a Tina4 Template Page

Create a server-rendered page using the Twig template engine.

## Instructions

1. Ensure `src/templates/base.twig` exists
2. Create the page template in `src/templates/pages/`
3. Create a route that renders it
4. Extract reusable UI into `src/templates/partials/`

## Base Template (`src/templates/base.twig`)

```twig
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{% block title %}My App{% endblock %}</title>
    <link rel="stylesheet" href="/css/default.css">
    {% block stylesheets %}{% endblock %}
</head>
<body>
    {% block nav %}{% include "partials/nav.twig" ignore missing %}{% endblock %}
    {% block content %}{% endblock %}
    {% block javascripts %}
    <script src="/js/frond.js"></script>
    {% endblock %}
</body>
</html>
```

## Page Template (`src/templates/pages/dashboard.twig`)

```twig
{% extends "base.twig" %}
{% block title %}Dashboard{% endblock %}
{% block content %}
<div class="container mt-4">
    <h1>{{ title }}</h1>

    {% if stats %}
    <div class="row">
        {% for stat in stats %}
        <div class="col-md-3">
            <div class="card">
                <div class="card-body">
                    <h5>{{ stat.label }}</h5>
                    <p class="display-4">{{ stat.value }}</p>
                </div>
            </div>
        </div>
        {% endfor %}
    </div>
    {% endif %}

    {% if items is not empty %}
    <table class="table mt-4">
        <thead>
            <tr>
                <th>Name</th>
                <th>Status</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
        {% for item in items %}
            <tr>
                <td>{{ item.name }}</td>
                <td>{{ item.active ? "Active" : "Inactive" }}</td>
                <td>{{ item.createdAt }}</td>
            </tr>
        {% endfor %}
        </tbody>
    </table>
    {% else %}
    <p>No items found.</p>
    {% endif %}
</div>
{% endblock %}
```

## Route

```php
<?php

use Tina4\Router;

Router::get("/dashboard", function ($request, $response) {
    return $response->render("pages/dashboard.twig", [
        "title" => "Dashboard",
        "stats" => [
            ["label" => "Users", "value" => 42],
            ["label" => "Orders", "value" => 128],
        ],
        "items" => getRecentItems(),
    ]);
});
```

## Key Twig Syntax

```twig
{{ variable }}                          {# output #}
{{ name | upper }}                      {# filter #}
{{ price | number_format(2) }}          {# format number #}
{{ html_content | raw }}                {# unescaped output #}
{{ "hello " ~ name }}                   {# string concatenation (use ~) #}
{{ items | length }}                    {# count items #}
{{ var | default("fallback") }}         {# default value #}

{% if condition %}...{% endif %}         {# conditional #}
{% if x %}...{% elseif y %}...{% else %} {# else-if #}

{% for item in items %}                 {# loop #}
    {{ loop.index }}                    {# 1-based counter #}
    {{ loop.first }}                    {# boolean #}
    {{ loop.last }}                     {# boolean #}
{% endfor %}

{% extends "base.twig" %}              {# inheritance #}
{% block name %}...{% endblock %}       {# block override #}
{% include "partial.twig" %}            {# include partial #}
```

## Rules

- Every page extends `base.twig`
- No inline styles — use SCSS in `src/scss/`
- No hardcoded colors — use CSS variables
- Reusable UI goes in `src/templates/partials/`
- Use `{{ var | raw }}` for unescaped output
- Use `{% elseif %}` for else-if conditions
