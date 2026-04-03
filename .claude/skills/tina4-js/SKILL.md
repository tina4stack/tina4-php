---
name: tina4-js
description: >
  Use whenever working with tina4-js — the lightweight reactive frontend framework (13.6KB bundled).
  Trigger on any mention of tina4-js, tina4 signals, Tina4Element, tina4 html tagged templates,
  tina4 routing, tina4 WebSocket client, or tina4 API client. Also trigger when the user is building
  a client-rendered frontend for a Tina4 backend, or when they're working with signals, Web Components,
  reactive templates, or islands architecture in a tina4-js project. If the working directory contains
  tina4-js code (imports from 'tina4js'), use this skill for all frontend tasks.
---

# tina4-js — Reactive Frontend Framework (v1.0.12)

tina4-js is a lightweight reactive frontend framework (13.6KB bundled IIFE). Zero dependencies,
no virtual DOM, no build complexity. It uses signals for reactivity, tagged template literals
for DOM, and Web Components for encapsulation.

**Distribution:** `dist/tina4js.min.js` is the official IIFE bundle. Usage:
```html
<script src="/js/tina4js.min.js"></script>
```
This exposes all APIs globally — no imports needed. The bundle is also shipped inside all four
Tina4 backend framework repos (PHP, Python, Go, TypeScript).

**This skill exists because AI agents consistently get tina4-js patterns wrong.** The syntax
looks simple but has specific rules. Getting them wrong produces silent bugs — things render
once but never update, buttons don't disable, inputs don't bind. This reference is the source
of truth, derived from the actual source code.

## The Three Rules That Fix 90% of Mistakes

Before writing any tina4-js code, internalize these:

### Rule 1: Static vs Reactive

```ts
// WRONG — evaluates ONCE, never updates
html`<p>${count.value}</p>`

// RIGHT — signal directly, creates reactive text node
html`<p>${count}</p>`

// RIGHT — function wrapper, creates reactive block (for conditionals/lists)
html`<p>${() => count.value > 0 ? 'Has items' : 'Empty'}</p>`
```

The pattern:
- `${signal}` — reactive text node (updates when signal changes)
- `${() => expression}` — reactive block (re-evaluates the function, can return html, null, arrays)
- `${value}` — static, evaluated once, never updates

**If your UI isn't updating, you probably used a static value where you needed a signal or function.**

**WARNING about false/null/undefined:**
```ts
${false}      // Renders the TEXT "false" — NOT empty!
${null}       // Renders empty
${undefined}  // Renders empty
${0}          // Renders "0"
```
Never use `${condition && html`...`}` — if condition is `false`, you get the text "false" in your DOM.
Always use the ternary: `${() => condition ? html`...` : null}`

### CRITICAL: Never Put Inputs Inside Reactive Blocks

**This is the #1 developer mistake.** Putting `<input>`, `<textarea>`, or `<select>` inside `${() => ...}` causes them to lose focus on every keystroke because the reactive block destroys and recreates the entire subtree.

```ts
// WRONG — input inside reactive block, destroyed on every keystroke
html`${() => html`<input .value=${name} @input=${(e) => { name.value = e.target.value; }} />`}`

// RIGHT — input in static template, only computed output is reactive
html`
  <input .value=${name} @input=${(e) => { name.value = e.target.value; }} />
  <p>${() => name.value ? `Hello, ${name.value}!` : 'Type your name'}</p>
`
```

**The rule:** Form elements go in the static template. Use `.value`, `@input`, `?disabled` bindings for reactivity. Only conditional messages, dynamic lists, and computed text go in `${() => ...}` blocks.

### Rule 2: New References for Objects/Arrays

```ts
// WRONG — mutating in place does NOT trigger updates
items.value.push(newItem);

// RIGHT — create a new array reference
items.value = [...items.value, newItem];

// WRONG — mutating object in place
user.value.name = 'Alice';

// RIGHT — spread into new object
user.value = { ...user.value, name: 'Alice' };
```

Signals use `Object.is()` for equality. Same reference = no update. Always create new references.

### Rule 3: Boolean Attributes Use `?` Prefix

```ts
// WRONG — sets the attribute to the string "true"/"false"
html`<button disabled=${isDisabled}>Click</button>`

// RIGHT — toggles the attribute presence
html`<button ?disabled=${isDisabled}>Click</button>`

// RIGHT — with a computed condition
html`<button ?disabled=${() => !isValid.value}>Submit</button>`
```

The `?` prefix adds the attribute when truthy, removes it when falsy. Without `?`, you get
`disabled="false"` which STILL DISABLES the button (any value = disabled in HTML).

**All three forms work reactively (v1.0.11+, boolean bug fixed in v1.0.12):**
```ts
// Signal directly — reactive
html`<button ?disabled=${loading}>Save</button>`

// Function wrapper — reactive, tracks all signals read inside
html`<div ?hidden=${() => !connected.value}>Offline</div>`

// Computed signal — reactive
const isEmpty = computed(() => items.value.length === 0);
html`<p ?hidden=${isEmpty}>Items found</p>`
```

**Common pattern — opposing show/hide pair:**
```ts
const connected = signal(false);
html`
  <div ?hidden=${() => connected.value}>Connecting...</div>
  <div ?hidden=${() => !connected.value}>
    <p>Connected! Send messages below.</p>
  </div>
`;
// Both divs toggle correctly when connected changes
```

**Multi-signal conditions:**
```ts
html`<button ?disabled=${() => loading.value || !isValid.value}>Submit</button>`
```

## Signals — Reactive State

Read `references/signals-and-reactivity.md` for the full API. Quick reference:

```ts
import { signal, computed, effect, batch, isSignal } from 'tina4js';

// Check if a value is a signal
isSignal(count);       // true
isSignal(42);          // false — use this to build generic helpers

// Create
const count = signal(0);
const name = signal('');
const items = signal<string[]>([]);

// Read and write
count.value;          // read (tracks dependency if inside effect)
count.value = 5;      // write (notifies subscribers)
count.peek();         // read WITHOUT tracking

// Computed (read-only, auto-updates)
const doubled = computed(() => count.value * 2);
const isValid = computed(() => name.value.length > 0);

// Effect (runs when dependencies change)
const dispose = effect(() => {
    console.log('Count is now:', count.value);
});
dispose(); // cleanup

// Batch (multiple updates, single notification)
batch(() => {
    count.value = 10;
    name.value = 'Alice';
    // subscribers notified ONCE after batch completes
});
```

## HTML Templates — DOM Creation

Read `references/html-and-components.md` for the full API. Quick reference:

```ts
import { html } from 'tina4js';

// Basic template — returns real DOM nodes (DocumentFragment)
const fragment = html`<h1>Hello ${name}</h1>`;

// Event binding — @event prefix
// All @event handlers are automatically wrapped in batch() — multiple signal
// writes inside one handler produce exactly ONE re-render after the handler returns.
html`<button @click=${() => count.value++}>Add</button>`
html`<input @input=${(e) => { name.value = e.target.value; }}>`
html`<form @submit=${(e) => { e.preventDefault(); save(); }}>`

// Multiple signal writes in one handler — safe, only one re-render fires
html`<button @click=${() => {
    items.value = [...items.value, newItem];
    selected.value = null;
    loading.value = false;
    // ↑ three writes, one DOM update — no mid-event re-renders
}}>Save</button>`

// Property binding — .prop prefix (sets DOM property, not attribute)
html`<input .value=${name}>`          // reactive: updates input when signal changes
html`<div .innerHTML=${rawHtml}>`     // raw HTML (bypasses XSS escaping)

// Boolean attribute — ?attr prefix
html`<button ?disabled=${loading}>Save</button>`
html`<div ?hidden=${() => !visible.value}>Content</div>`
html`<input ?checked=${isChecked}>`

// Regular attribute — no prefix (reactive if signal)
html`<div class=${className}>Styled</div>`
html`<img src=${imageUrl} alt=${altText}>`

// Conditional rendering — MUST use function wrapper
html`${() => loggedIn.value ? html`<p>Welcome</p>` : html`<a>Login</a>`}`

// List rendering — MUST use function wrapper for reactive lists
html`<ul>${() => items.value.map(item => html`<li>${item}</li>`)}</ul>`

// Static list (non-reactive, rendered once)
html`<ul>${['a', 'b', 'c'].map(i => html`<li>${i}</li>`)}</ul>`
```

## Event Handler Batching (v1.0.9+, auto-batch fix in v1.0.12)

All `@event` handlers are **automatically batched**. `@click` handlers now auto-batch properly
(fixed in v1.0.12). You do NOT need to:
- Wrap signal writes in `batch()` inside event handlers
- Use `setTimeout(() => signal.value = x, 0)` to defer updates
- Call `e.stopPropagation()` to prevent mid-render bubble issues

These were workarounds for a bug that is now fixed at the framework level.

```ts
// OLD workaround — no longer needed
@click=${() => setTimeout(() => { items.value = [...items.value, item]; }, 0)}

// CORRECT — just write to signals directly
@click=${() => { items.value = [...items.value, item]; }}
```

`batch()` is still useful outside of event handlers (e.g. in `effect()`, `setTimeout`, WebSocket handlers).

## Things That Don't Exist — Don't Invent Them

AI agents commonly hallucinate these APIs. **None of these exist in tina4-js:**
- `unsafeHTML()` — does NOT exist. Use `.innerHTML=${rawHtml}` property binding
- `t-model`, `t-for`, `t-bind`, `t-text` — these are Vue directives, NOT tina4-js
- `tina4.createApp()` — does NOT exist. There's no app instance
- `ref()` — does NOT exist (that's Vue). Use `signal()`
- `useState()` — does NOT exist (that's React). Use `signal()`
- `observedAttributes` / `attributeChangedCallback` — don't write these manually.
  `Tina4Element` handles them automatically via `static props`. Use `this.prop('name')`

If you find yourself writing something that isn't in this skill, stop and check. The API
is small by design — if it's not here, it probably doesn't exist.

## Common Patterns

### Form with Validation
```ts
const email = signal('');
const password = signal('');
const error = signal('');
const loading = signal(false);
const isValid = computed(() => email.value.includes('@') && password.value.length >= 8);

html`
<form @submit=${async (e) => {
    e.preventDefault();
    loading.value = true;
    error.value = '';
    try {
        await api.post('/login', { email: email.value, password: password.value });
    } catch (err) {
        error.value = err.data?.message || 'Login failed';
    }
    loading.value = false;
}}>
    <input type="email" .value=${email}
           @input=${(e) => { email.value = e.target.value; }}>
    <input type="password" .value=${password}
           @input=${(e) => { password.value = e.target.value; }}>
    ${() => error.value ? html`<p class="error">${error}</p>` : null}
    <button ?disabled=${() => !isValid.value || loading.value}>
        ${() => loading.value ? 'Logging in...' : 'Login'}
    </button>
</form>`;
```

### List with Add/Remove
```ts
const items = signal<{ id: number; text: string }[]>([]);
const input = signal('');
let nextId = 1;

const addItem = () => {
    if (!input.value.trim()) return;
    items.value = [...items.value, { id: nextId++, text: input.value }];
    input.value = '';
};

const removeItem = (id: number) => {
    items.value = items.value.filter(i => i.id !== id);
};

html`
<div>
    <input .value=${input} @input=${(e) => { input.value = e.target.value; }}
           @keydown=${(e) => { if (e.key === 'Enter') addItem(); }}>
    <button @click=${addItem} ?disabled=${() => !input.value.trim()}>Add</button>
    <ul>${() => items.value.map(item => html`
        <li>${item.text} <button @click=${() => removeItem(item.id)}>×</button></li>
    `)}</ul>
    <p>${() => items.value.length} items</p>
</div>`;
```

### API Data Loading
```ts
const users = signal([]);
const loading = signal(true);

effect(() => {
    api.get('/users').then(data => {
        users.value = data;
        loading.value = false;
    });
});

html`
<div>
    ${() => loading.value
        ? html`<p>Loading...</p>`
        : html`<ul>${() => users.value.map(u => html`<li>${u.name}</li>`)}</ul>`
    }
</div>`;
```

### WebSocket with State
```ts
import { ws } from 'tina4js/ws';

const messages = signal<string[]>([]);
const socket = ws.connect('/ws/chat');

// Pipe messages directly into signal state
socket.pipe(messages, (msg, current) => [...current, msg.text]);

html`
<div>
    <span>Status: ${socket.status}</span>
    <div ?hidden=${() => !socket.connected.value}>
        <ul>${() => messages.value.map(m => html`<li>${m}</li>`)}</ul>
        <input @keydown=${(e) => {
            if (e.key === 'Enter') {
                socket.send({ text: e.target.value });
                e.target.value = '';
            }
        }}>
    </div>
</div>`;
```

## Islands Architecture

tina4-js supports an "islands" pattern: use Tina4Element web components as self-contained
interactive widgets within server-rendered pages. Each island auto-registers and hydrates
independently.

```html
<!-- Server-rendered page (e.g. RedwoodSDK RSC, PHP template, Go template) -->
<h1>Product Page</h1>
<p>Server-rendered content here...</p>

<!-- tina4-js island — self-contained, ~2.3KB per island vs 42KB for React -->
<product-rating product-id="42"></product-rating>
<add-to-cart product-id="42" price="29.99"></add-to-cart>

<script src="/js/tina4js.min.js"></script>
<script src="/js/islands/product-rating.js"></script>
<script src="/js/islands/add-to-cart.js"></script>
```

Each island is a standard Tina4Element that calls `customElements.define()` at the bottom of
its file. The IIFE bundle provides the framework globally; island scripts just use it.

## Cloudflare Workers

tina4-js runs on Cloudflare Workers with Durable Objects for WebSocket state. The IIFE bundle
and all client-side code works as-is; the WebSocket client (`ws.connect()`) connects to Worker
endpoints backed by Durable Objects for persistent state across connections.

## Reference Files

## Quick Reference — Commonly Missed APIs

```ts
// isSignal — check if a value is a tina4 signal
import { isSignal } from 'tina4js';
isSignal(myVar);  // true if signal, false otherwise

// router.on — listen for route changes
import { router } from 'tina4js/router';
router.on('change', ({ path, params, pattern, durationMs }) => { /* ... */ });

// PWA cache strategies — exact enum values
import { pwa } from 'tina4js/pwa';
pwa.register({
    cacheStrategy: 'cache-first',             // serve from cache, fallback to network
    // cacheStrategy: 'network-first',         // try network, fallback to cache
    // cacheStrategy: 'stale-while-revalidate' // serve cache, refresh in background
});

// API interceptor signatures
api.intercept('request', (config) => { /* config: RequestInit & { headers: Record<string, string> } */ });
api.intercept('response', (resp) => { /* resp: { status, data, ok, headers } */ });
```

## Reference Files

- **`references/signals-and-reactivity.md`** — Full signal, computed, effect, batch, isSignal API with
  edge cases and gotchas. Read for any reactive state work.
- **`references/html-and-components.md`** — html template bindings, Tina4Element Web Components,
  lifecycle, routing, API client, WebSocket. Read for any UI/component work.
