# Set Up Tina4 SCSS Stylesheets

Create SCSS stylesheets that auto-compile to CSS.

## Instructions

1. Create `.scss` files in `src/scss/`
2. They auto-compile to `src/public/css/` on save (in dev mode)
3. Reference compiled CSS in templates

## Main Stylesheet (`src/scss/default.scss`)

```scss
// Variables
$primary: var(--primary, #3b82f6);
$secondary: var(--secondary, #64748b);
$success: var(--success, #22c55e);
$danger: var(--danger, #ef4444);
$text: var(--text, #1e293b);
$border: var(--border, #e2e8f0);
$bg: var(--bg, #ffffff);
$radius: 0.375rem;
$shadow: 0 1px 3px rgba(0, 0, 0, 0.1);

// Reset
*, *::before, *::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

// Typography
body {
    font-family: system-ui, -apple-system, sans-serif;
    color: $text;
    background: $bg;
    line-height: 1.6;
}

// Layout
.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 1rem;
}

.row {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
}

// Components
.card {
    border: 1px solid $border;
    border-radius: $radius;
    padding: 1.5rem;
    box-shadow: $shadow;

    &-body { padding: 1rem; }
    &-title { font-size: 1.25rem; font-weight: 600; }
}

.btn {
    display: inline-flex;
    align-items: center;
    padding: 0.5rem 1rem;
    border: none;
    border-radius: $radius;
    cursor: pointer;
    font-size: 0.875rem;

    &-primary { background: $primary; color: white; }
    &-secondary { background: $secondary; color: white; }
    &-danger { background: $danger; color: white; }
}

// Forms
.form-input, .form-control {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid $border;
    border-radius: $radius;
    font-size: 1rem;

    &:focus {
        outline: none;
        border-color: $primary;
    }
}

// Tables
.table {
    width: 100%;
    border-collapse: collapse;

    th, td {
        padding: 0.75rem;
        text-align: left;
        border-bottom: 1px solid $border;
    }

    th { font-weight: 600; }
}

// Utilities
.mt-4 { margin-top: 1rem; }
.mb-4 { margin-bottom: 1rem; }
.text-center { text-align: center; }
```

## File Structure

```
src/scss/
  default.scss          # Main stylesheet
  _variables.scss       # Shared variables (import with @use)
  _components.scss      # Reusable component styles
  pages/
    _dashboard.scss     # Page-specific styles
```

## Using Partials

```scss
// src/scss/_variables.scss
$primary: var(--primary, #3b82f6);
$border: var(--border, #e2e8f0);

// src/scss/default.scss
@use 'variables' as *;

body { color: $primary; }
```

## In Templates

```twig
{# base.twig #}
<link rel="stylesheet" href="/css/default.css">
```

## Dev Mode

With `TINA4_DEBUG=true` in `.env`:
- SCSS files auto-compile on save
- CSS hot-reloads in the browser (no full page refresh)

## Key Rules

- No inline styles — all styles in SCSS files
- No hardcoded hex colors — use CSS variables or SCSS variables
- No local `<style>` blocks — shared styles only
- Partials start with `_` (e.g., `_variables.scss`)
- Output goes to `src/public/css/`
- Use CSS variables (`var(--name)`) for theming support
