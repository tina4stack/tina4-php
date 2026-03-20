# Tina4 v3.0 — Tina4Press (Documentation Site Generator)

## What Is It
A VitePress-style static documentation site generator built on **tina4-js**. It powers the official tina4.com documentation and is available for any Tina4 project to generate beautiful docs.

Think VitePress, but built with Tina4 — eating our own dog food.

## Why
- **Showcase** — tina4.com itself demonstrates what tina4-js can do
- **Dog-fooding** — we build it with our own tools, proving they work
- **Zero-dep docs** — no VitePress, no Hugo, no Jekyll dependency
- **Unified** — one documentation site for all four frameworks side-by-side

## Features

### Content
- **Markdown-first** — write docs in `.md` files, Tina4Press renders them
- **Frond templates** — layout, navigation, sidebar all use Frond
- **Code tabs** — show Python / PHP / Ruby / Node.js examples side by side
- **Live API playground** — embedded Swagger UI for try-it-now
- **Search** — client-side full-text search (zero backend, JSON index)
- **Versioned docs** — switch between v2 and v3 docs

### Design
- Clean, modern, responsive layout
- Dark mode toggle
- Sidebar navigation (auto-generated from folder structure)
- Table of contents per page (auto-generated from headings)
- Previous / Next page navigation
- Edit on GitHub link per page
- Copy button on code blocks

### Code Tabs (Key Feature)
```markdown
::: code-tabs
```python
@get("/api/users")
async def get_users(request, response):
    return response.json(User.select())
```

```php
Route::get("/api/users", function($request, $response) {
    return $response->json(User::select());
});
```

```ruby
get "/api/users" do |request, response|
  response.json(User.select)
end
```

```typescript
export default async (req, res) => {
  res.json(User.select());
};
```
:::
```

Renders as tabbed code block — click Python/PHP/Ruby/Node.js to switch.

### Build
```bash
# Development — hot reload
tina4press dev

# Build static site
tina4press build                      # Outputs to dist/

# Deploy
tina4press deploy                     # Push to GitHub Pages / Netlify / Vercel
```

## Site Structure (tina4.com)

```
docs/
├── index.md                          # Home page
├── getting-started/
│   ├── installation.md               # Install per language
│   ├── quick-start.md                # Hello World in 5 lines
│   └── project-structure.md          # Folder layout explained
├── routing/
│   ├── basics.md
│   ├── parameters.md
│   ├── middleware.md
│   ├── file-based.md
│   └── caching.md
├── orm/
│   ├── models.md
│   ├── queries.md
│   ├── relationships.md
│   ├── soft-delete.md
│   ├── scopes.md
│   └── pagination.md
├── database/
│   ├── drivers.md
│   ├── migrations.md
│   ├── seeds.md
│   └── transactions.md
├── frond/
│   ├── syntax.md
│   ├── filters.md
│   ├── inheritance.md
│   ├── components.md
│   ├── quirks.md                     # query, live, classes, markdown, data, icon
│   └── extensibility.md
├── frond-js/
│   ├── http-client.md
│   ├── forms.md
│   ├── websocket.md
│   ├── live-blocks.md
│   └── crud-tables.md
├── auth/
│   ├── jwt.md
│   ├── sessions.md
│   └── secure-routes.md
├── queue/
│   ├── basics.md
│   ├── workers.md
│   ├── failover.md
│   ├── requeue.md
│   └── extensions.md                # RabbitMQ, Kafka
├── websocket/
│   ├── basics.md
│   ├── channels.md
│   └── broadcasting.md
├── features/
│   ├── graphql.md
│   ├── swagger.md
│   ├── wsdl.md
│   ├── api-client.md
│   ├── localization.md
│   ├── email.md
│   ├── scss.md
│   └── logging.md
├── production/
│   ├── deployment.md
│   ├── docker.md
│   ├── kubernetes.md
│   ├── health-checks.md
│   ├── broken-files.md
│   ├── console.md
│   └── benchmarks.md
├── workflow/
│   ├── dev-staging-production.md
│   ├── cli-reference.md
│   └── testing.md
├── api-reference/
│   ├── python.md                    # Full API reference
│   ├── php.md
│   ├── ruby.md
│   └── nodejs.md
└── examples/
    ├── todo-app.md
    ├── blog.md
    ├── chat.md
    ├── api-gateway.md
    └── multidb.md
```

## Tina4Press Project Structure

```
tina4press/
├── package.json                     # tina4-js project
├── src/
│   ├── routes/
│   │   └── api/
│   │       └── search/get.ts        # Search API endpoint
│   ├── models/
│   ├── templates/
│   │   ├── layouts/
│   │   │   └── docs.html            # Main docs layout (Frond)
│   │   ├── components/
│   │   │   ├── sidebar.html
│   │   │   ├── toc.html             # Table of contents
│   │   │   ├── code-tabs.html
│   │   │   ├── nav.html
│   │   │   └── search.html
│   │   └── pages/
│   │       ├── home.html
│   │       └── doc.html             # Markdown doc page template
│   ├── public/
│   │   ├── js/
│   │   │   ├── frond.js
│   │   │   ├── search.js            # Client-side search
│   │   │   └── code-tabs.js         # Tab switching
│   │   └── css/
│   │       ├── docs.css
│   │       └── prism.css            # Code highlighting
│   └── content/                     # Markdown docs go here
│       └── ... (mirrors site structure above)
├── tina4press.config.js             # Site config
└── build/
    └── Dockerfile
```

### tina4press.config.js
```javascript
export default {
  title: "Tina4 Documentation",
  description: "The lightweight, zero-dependency web framework",
  base: "/",
  themeConfig: {
    logo: "/images/tina4-logo.svg",
    nav: [
      { text: "Guide", link: "/getting-started/installation" },
      { text: "API Reference", link: "/api-reference/python" },
      { text: "Examples", link: "/examples/todo-app" },
      { text: "GitHub", link: "https://github.com/tina4stack" }
    ],
    sidebar: "auto",                  // Auto-generate from folder structure
    editLink: {
      pattern: "https://github.com/tina4stack/tina4-docs/edit/main/docs/:path"
    },
    search: true,
    carbonah: true                    // Show carbon badge
  },
  languages: ["python", "php", "ruby", "typescript"]  // Code tab languages
};
```

## Static Site Generation

`tina4press build` converts markdown + Frond templates into a fully static site:

1. Parse all `.md` files in `content/`
2. Extract frontmatter (title, description, order)
3. Convert markdown to HTML (using Frond's `{% markdown %}` engine)
4. Process code tabs into tabbed components
5. Generate sidebar navigation from folder structure
6. Generate table of contents from headings
7. Build search index (JSON)
8. Render through Frond templates
9. Output static HTML/CSS/JS to `dist/`
10. Ready for any static host (GitHub Pages, Netlify, Vercel, S3)

## Search

Client-side search with zero backend:

1. At build time, extract all headings + first paragraph from every page
2. Write `search-index.json`
3. `search.js` loads the index and provides instant fuzzy search
4. Results link directly to the heading anchor

## Timeline

- **v3.0**: Tina4Press built, tina4.com launched with full docs
- **Post v3.0**: Tina4Press available as a standalone tool for any project's docs
