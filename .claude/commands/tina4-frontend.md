# Set Up a Frontend Framework

Attach a frontend framework (React, Vue, Svelte, or plain JS) to your Tina4 project.

## Instructions

1. Choose your approach
2. Set up in `frontend/`
3. Build output goes to `src/public/` — Tina4 serves it automatically

## Approach 1: Plain JavaScript

No build step needed. Put files directly in `src/public/`:

```
src/public/
  js/app.js
  css/style.css
  index.html
```

## Approach 2: tina4-js (Reactive Components)

Use the built-in `frond.js` for reactive components without a build step:

```html
<script src="/js/frond.js"></script>
<script>
Frond.component("counter", {
    state: { count: 0 },
    template: `<button onclick="this.count++">{{ count }}</button>`
});
</script>
```

## Approach 3: React / Vue / Svelte

Set up in the `frontend/` directory:

### React
```bash
cd frontend
npx create-react-app . --template typescript
```

### Vue
```bash
cd frontend
npm create vue@latest .
```

### Svelte
```bash
cd frontend
npx sv create .
```

### Configure Build Output

**React** (`frontend/package.json`):
```json
{
    "homepage": "/",
    "scripts": {
        "build": "react-scripts build && cp -r build/* ../src/public/"
    }
}
```

**Vue** (`frontend/vite.config.ts`):
```typescript
export default defineConfig({
    build: { outDir: '../src/public' }
})
```

**Svelte** (`frontend/vite.config.ts`):
```typescript
export default defineConfig({
    build: { outDir: '../src/public' }
})
```

### API Proxy (Dev)

**Vite** (`frontend/vite.config.ts`):
```typescript
export default defineConfig({
    server: {
        proxy: {
            '/api': 'http://localhost:7145'
        }
    }
})
```

## Approach 4: HTMX / Alpine.js

Use CDN links in your base template:

```twig
{# base.twig #}
<script src="https://unpkg.com/htmx.org"></script>
<script src="https://unpkg.com/alpinejs"></script>
```

HTMX example:
```twig
<button hx-get="/api/users" hx-target="#user-list">Load Users</button>
<div id="user-list"></div>
```

## Catch-All Route for SPAs

```php
<?php

use Tina4\Router;

Router::get("/{path:path}", function ($path, $request, $response) {
    return $response->html(file_get_contents("src/public/index.html"));
});
```

## Key Rules

- Frontend source lives in `frontend/`, build output goes to `src/public/`
- Tina4 serves everything in `src/public/` as static files
- API routes use `/api/` prefix to avoid conflicts with frontend routing
- For SPAs, add a catch-all route that serves `index.html`
- Don't commit `node_modules/` — add to `.gitignore`
