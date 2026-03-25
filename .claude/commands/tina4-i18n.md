# Set Up Tina4 Internationalization (i18n)

Add multi-language support using the built-in Localization module.

## Instructions

1. Create translation files in `src/locales/`
2. Set up the localization instance
3. Use translations in routes and templates

## Translation Files

Create JSON files per language in `src/locales/`:

**`src/locales/en.json`**
```json
{
    "welcome": "Welcome",
    "greeting": "Hello, {name}!",
    "items_count": "{count} item|{count} items",
    "nav": {
        "home": "Home",
        "about": "About",
        "contact": "Contact"
    }
}
```

**`src/locales/fr.json`**
```json
{
    "welcome": "Bienvenue",
    "greeting": "Bonjour, {name} !",
    "items_count": "{count} article|{count} articles",
    "nav": {
        "home": "Accueil",
        "about": "A propos",
        "contact": "Contact"
    }
}
```

## Setup

```php
<?php

use Tina4\Localization;

$i18n = new Localization("en", "src/locales");
```

## Usage in Routes

```php
<?php

use Tina4\Router;
use Tina4\Localization;

$i18n = new Localization();

Router::get("/welcome", function ($request, $response) use ($i18n) {
    $locale = $request->params["lang"] ?? "en";
    return $response->json([
        "message" => $i18n->t("welcome", $locale),
        "greeting" => $i18n->t("greeting", $locale, ["name" => "Alice"]),
    ]);
});
```

## Usage in Templates

```twig
{# Set locale #}
{% set locale = "fr" %}

{# Simple translation #}
<h1>{{ t("welcome", locale) }}</h1>

{# With parameters #}
<p>{{ t("greeting", locale, {name: user.name}) }}</p>

{# Nested keys #}
<nav>
    <a href="/">{{ t("nav.home", locale) }}</a>
    <a href="/about">{{ t("nav.about", locale) }}</a>
</nav>

{# Pluralization #}
<p>{{ t("items_count", locale, {count: cart.size}) }}</p>
```

## Features

```php
<?php

use Tina4\Localization;

$i18n = new Localization();

// Translate
$i18n->t("welcome");                              // English (default)
$i18n->t("welcome", "fr");                        // French
$i18n->t("greeting", "en", ["name" => "Alice"]);  // With interpolation
$i18n->t("nav.home");                             // Nested key
$i18n->t("items_count", "en", ["count" => 1]);    // Singular
$i18n->t("items_count", "en", ["count" => 5]);    // Plural

// Available locales
$i18n->getLocales();                               // ["en", "fr", "de"]

// Missing key handling
$i18n->t("missing.key");                           // Returns "missing.key"
```

## Key Rules

- Translation files are JSON in `src/locales/`
- File name = locale code (e.g., `en.json`, `fr.json`, `pt-BR.json`)
- Use dot notation for nested keys: `"nav.home"`
- Use `{name}` for interpolation parameters
- Use `|` for singular/plural forms
- Detect locale from: query param, header (`Accept-Language`), or session
