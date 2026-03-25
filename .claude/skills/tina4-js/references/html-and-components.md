# HTML Templates, Components, Routing, API & WebSocket

## html Tagged Template Literal

```ts
import { html } from 'tina4js';
```

Returns a `DocumentFragment` with real DOM nodes. Templates are cached by string identity.

### Binding Types

| Syntax | Type | Behavior |
|--------|------|----------|
| `${signal}` | Reactive text | Creates effect, updates text node |
| `${() => expr}` | Reactive block | Creates effect, can return html/null/array |
| `${value}` | Static | Evaluated once, never updates |
| `@click=${fn}` | Event | `addEventListener`, attribute removed |
| `?disabled=${sig}` | Boolean attr | Adds/removes attribute based on truthiness |
| `.value=${sig}` | Property | Sets DOM property (not attribute) |
| `class=${sig}` | Attribute | `setAttribute` reactively |

### Content Rendering Rules

| Value | Renders As |
|-------|-----------|
| Signal | Reactive text node |
| Function | Reactive block (re-evaluated on dep change) |
| DocumentFragment | Static DOM (one-time) |
| Node | Static DOM (one-time) |
| Array | Each item converted to nodes |
| String/Number | Text node (XSS safe, no HTML parsing) |
| `null`/`undefined` | Empty |
| `false` | The text "false" (NOT empty!) |

**Critical:** `${false}` renders as the literal text "false". For conditional show/hide, use:
```ts
${() => condition ? html`<p>Show</p>` : null}
```

### Raw HTML Injection

All string interpolation is XSS-safe by default. To inject raw HTML:
```ts
html`<div .innerHTML=${rawHtmlString}></div>`
```

### Event Handler Patterns

```ts
// Click
html`<button @click=${() => count.value++}>Add</button>`

// Input with value tracking
html`<input @input=${(e) => { name.value = e.target.value; }}>`

// Keyboard
html`<input @keydown=${(e) => { if (e.key === 'Enter') submit(); }}>`

// Form submit (prevent default!)
html`<form @submit=${(e) => { e.preventDefault(); save(); }}>`

// Multiple events on same element
html`<input @focus=${onFocus} @blur=${onBlur} @input=${onInput}>`
```

### Two-Way Input Binding

Form builder focus issues have been resolved in v1.0.12 — inputs no longer lose focus during
reactive updates.

tina4-js does NOT have v-model or ngModel. Two-way binding is explicit:
```ts
const value = signal('');
html`<input .value=${value} @input=${(e) => { value.value = e.target.value; }}>`;
//          ↑ DOM → signal    ↑ signal → DOM
```

Both parts are needed:
- `.value=${value}` — keeps the DOM input in sync when the signal changes externally
- `@input=${...}` — updates the signal when the user types

### Select Elements
```ts
const selected = signal('option1');
html`<select .value=${selected} @change=${(e) => { selected.value = e.target.value; }}>
    <option value="option1">Option 1</option>
    <option value="option2">Option 2</option>
</select>`;
```

### Checkbox
```ts
const checked = signal(false);
html`<input type="checkbox" ?checked=${checked}
            @change=${(e) => { checked.value = e.target.checked; }}>`;
```

---

## Tina4Element — Web Components

```ts
import { Tina4Element, signal, html } from 'tina4js';

class MyCounter extends Tina4Element {
    static props = { start: Number };
    static styles = `
        :host { display: block; }
        button { padding: 8px 16px; }
    `;

    count = signal(0);

    onMount() {
        this.count.value = this.prop('start').value;
    }

    render() {
        return html`
            <p>Count: ${this.count}</p>
            <button @click=${() => this.count.value++}>Add</button>
            <button @click=${() => this.emit('count-changed', { detail: this.count.value })}>
                Save
            </button>
        `;
    }
}

customElements.define('my-counter', MyCounter);
// Usage: <my-counter start="5"></my-counter>
```

### Lifecycle
1. `constructor()` — Shadow root attached, prop signals created from `static props`
2. `connectedCallback()` → `render()` called ONCE → `onMount()` called
3. `disconnectedCallback()` → `onUnmount()` called

**render() is called ONCE.** Reactivity comes from signals in the template, not re-rendering.

### Props
- Declared in `static props = { name: Type }` where Type is `String`, `Number`, or `Boolean`
- Access via `this.prop('name')` which returns a Signal
- Auto-coerced: Boolean (present=true, absent=false), Number (absent=0), String (absent='')
- `this.prop('undeclared')` throws an error

### Communication
- **Parent → child:** Props (attributes on the element)
- **Child → parent:** `this.emit('event-name', { detail: data })` — bubbles through shadow DOM
- **Shared state:** Import signals from a shared module

### Light DOM (no Shadow)
```ts
class MyWidget extends Tina4Element {
    static shadow = false;  // renders into light DOM
    static styles = '';     // styles go in global CSS instead
    render() { return html`<p>In the light</p>`; }
}
```

---

## Routing

```ts
import { route, navigate, router } from 'tina4js/router';

// Define routes
route('/', () => html`<h1>Home</h1>`);
route('/users', () => html`<user-list></user-list>`);
route('/users/{id}', ({ id }) => html`<user-detail uid=${id}></user-detail>`);
route('*', () => html`<h1>404</h1>`);

// Route with guard
route('/admin', {
    guard: () => isLoggedIn.value || '/login',  // true=allow, string=redirect
    handler: () => html`<admin-panel></admin-panel>`
});

// Start
router.start({ target: '#app', mode: 'hash' });  // or 'history'

// Navigate programmatically
navigate('/users/42');
navigate('/login', { replace: true });

// Listen for changes
router.on('change', ({ path, params, pattern, durationMs }) => {
    console.log('Navigated to', path);
});
```

**Effect cleanup:** When navigating away, ALL effects from the previous route's template are
disposed. Fresh effects are created for the new route. This prevents memory leaks.

**Async routes:** Handlers can return `Promise<DocumentFragment>`. Stale results from slow
async handlers are discarded if navigation happens before they resolve.

---

## API Client

```ts
import { api } from 'tina4js/api';

api.configure({ baseUrl: '/api', auth: true });

// CRUD
const users = await api.get('/users');
const user = await api.get('/users/1');
const created = await api.post('/users', { name: 'Alice' });
await api.put('/users/1', { name: 'Alice Smith' });
await api.patch('/users/1', { active: false });
await api.delete('/users/1');

// Query params
const results = await api.get('/search', { params: { q: 'hello', page: 2 } });
// → GET /api/search?q=hello&page=2

// Error handling — throws ApiResponse, NOT Error
try {
    await api.get('/protected');
} catch (err) {
    // err is { status: 401, data: { message: 'Unauthorized' }, ok: false, headers: ... }
    console.log(err.status, err.data);
}

// Interceptors — type signatures:
// RequestInterceptor:  (config: RequestInit & { headers: Record<string, string> }) => config | void
// ResponseInterceptor: (response: ApiResponse) => response | void

api.intercept('request', (config) => {
    config.headers['X-Request-ID'] = crypto.randomUUID();
    return config;
});
api.intercept('response', (response) => {
    if (response.status === 401) navigate('/login');
    return response;
});
```

**Auth behavior (auth: true):**
- Reads token from `localStorage[tokenKey]` (default: `'tina4_token'`)
- Adds `Authorization: Bearer <token>` to all requests
- Injects `formToken` into POST/PUT/PATCH/DELETE bodies (CSRF compat with tina4 backends)
- Reads `FreshToken` response header for token rotation

**DELETE has no body parameter:** `api.delete(path, options)` — not `api.delete(path, body)`.

---

## WebSocket

```ts
import { ws } from 'tina4js/ws';

const socket = ws.connect('/ws/chat', {
    reconnect: true,
    reconnectDelay: 1000,
    reconnectMaxDelay: 30000,
    reconnectAttempts: Infinity
});

// All state is exposed as SIGNALS — bind directly in templates
html`<span>Status: ${socket.status}</span>`;
html`<div ?hidden=${() => !socket.connected.value}>Online</div>`;

// Send (auto-stringifies objects)
socket.send({ type: 'message', text: 'Hello' });

// Listen for events
const unsub = socket.on('message', (data) => console.log(data));

// Pipe messages into signal state (the key pattern)
const messages = signal<Message[]>([]);
socket.pipe(messages, (msg, current) => [...current, msg as Message]);

// Now messages auto-updates, use in templates:
html`<ul>${() => messages.value.map(m => html`<li>${m.text}</li>`)}</ul>`;

// Close (stops reconnection)
socket.close();
```

**Gotcha:** `socket.send()` throws if not connected. Check `socket.connected.value` first.

**pipe() is the preferred pattern** for WebSocket → UI state. It handles the signal update
and new reference creation automatically through the reducer function.

---

## PWA Support

```ts
import { pwa } from 'tina4js/pwa';

pwa.register({
    name: 'My App',
    shortName: 'App',
    display: 'standalone',
    themeColor: '#000',
    icon: '/icon-512.png',
    cacheStrategy: 'network-first',  // or 'cache-first', 'stale-while-revalidate'
    precache: ['/index.html', '/app.js', '/style.css']
});
```

Generates service worker as Blob URL at runtime — no separate SW file needed.

---

## Debug Overlay

```ts
import 'tina4js/debug';
// Toggle: Ctrl+Shift+D
```

4 panels: Signals (labels, update counts), Components (mounted), Routes (history), API (log).
Tree-shakeable — not included in production if not imported.

---

## Package Imports (Tree-Shaking)

```ts
import { signal, computed, effect, batch, html } from 'tina4js';        // core
import { Tina4Element } from 'tina4js';                                  // components
import { route, navigate, router } from 'tina4js/router';               // routing
import { api } from 'tina4js/api';                                       // HTTP client
import { ws } from 'tina4js/ws';                                         // WebSocket
import { pwa } from 'tina4js/pwa';                                       // PWA
import 'tina4js/debug';                                                   // debug overlay
```

Import only what you need. `sideEffects: false` enables tree-shaking.

### IIFE Bundle (no imports needed)

When using the IIFE bundle (`dist/tina4js.min.js`, 13.6KB), all APIs are exposed globally:
```html
<script src="/js/tina4js.min.js"></script>
<script>
// All APIs available globally — no imports
const count = signal(0);
const app = html`<p>${count}</p>`;
</script>
```
