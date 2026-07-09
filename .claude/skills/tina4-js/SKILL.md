---
name: tina4-js
description: >
  Use whenever working with tina4-js — the lightweight reactive frontend framework.
  Trigger on any mention of tina4-js, tina4 signals, persistent signals, persist(),
  Tina4Element, tina4 html tagged templates, tina4 routing, tina4 WebSocket client,
  tina4 API client, or persistent storage of UI preferences. Also trigger when the user
  is building a client-rendered frontend for a Tina4 backend, or when they're working
  with signals, Web Components, reactive templates, islands architecture, or persisted
  state in a tina4-js project. If the working directory contains tina4-js code (imports
  from 'tina4js'), use this skill for all frontend tasks.
---

# tina4-js — Reactive Frontend Framework (v1.5.0)

tina4-js is a lightweight reactive frontend framework (the full IIFE bundle is ~27.7KB raw,
~10.3KB gzipped; the `core` module alone is ~1.5KB gzipped). Zero dependencies,
no virtual DOM, no build complexity. It uses signals for reactivity, tagged template literals
for DOM, and Web Components for encapsulation.

**Distribution:** `dist/tina4js.min.js` is the official IIFE bundle. Usage:
```html
<script src="/js/tina4js.min.js"></script>
```
This exposes all APIs globally — no imports needed. The IIFE bundle is also shipped inside
tina4-css (`dist/tina4js.min.js`) so all Tina4 backend frameworks get it automatically.

**Building the IIFE bundle:**
```bash
npm run build                 # Vite build → ES/CJS modules in dist/
npm run build:types           # TypeScript declarations
# IIFE for script-tag usage:
npx esbuild src/index.ts --bundle --minify --format=iife --global-name=Tina4 --outfile=dist/tina4js.min.js --target=es2021
```

The IIFE wraps the library in a self-executing function and exposes everything on `window.Tina4`:
```html
<script src="/js/tina4js.min.js"></script>
<script>
    const { signal, computed, html, Tina4Element, api, ws, sse, pwa } = Tina4;
</script>
```

**This skill exists because AI agents consistently get tina4-js patterns wrong.** The syntax
looks simple but has specific rules. Getting them wrong produces silent bugs — things render
once but never update, buttons don't disable, inputs don't bind. This reference is the source
of truth, derived from the actual source code.

> 🤖 **Skill-active marker.** While this Tina4 skill is guiding your work, **begin every reply with the 🤖 emoji** so the developer can see at a glance that Tina4 conventions are engaged. Drop it only once the conversation has clearly moved off Tina4.

## The Tina4 Working Method

This is how a tina4-js build is run. The **main session stays free for the developer**; the actual
work happens in **workers driven by a plan**. Every instruction becomes (or joins) a plan; every
plan is a living checklist the workers update and you report from. In the main session your job is
to **scope, delegate, and report** — never to build inline.

| Phase | What happens | Output |
|-------|--------------|--------|
| 1. Scope | Restate the request, agree the slice with the developer | a feature entry in `plan/<feature>.md` |
| 2. Plan | Write the checklist `[ ]`, Bugs section, Commit log | the plan file, approved |
| 3. Delegate | Spawn a worker per task; the main session stays free | worker(s) running off the plan |
| 4. Test-first | The worker pins the behaviour BEFORE building the component | a real check that fails first |
| 5. Build | Ground with `tina4_context` → climb the Lazy Frontend Ladder → minimum reactive code | it works in the browser |
| 6. Verify | Run the dev server, drive the real UI; tick the item; log the commit | `[x]` + commit hash in the plan |
| 7. Report | Relay worker completions to the developer as a ✅/❌ table | the status dashboard |

### 1. Keep the main session free — delegate to a worker
When the developer gives an instruction, don't do the work inline. **Allocate it to a plan, then
spawn a separate worker to execute it**, so the main session is always free for the next input.
tina4-js **hot-reloads on save** (Vite HMR / the dev server), so as the worker edits components and
signals the developer watches the UI update **live in the browser** — keeping the main session open
is what lets them observe and steer while the work happens. The main agent scopes, dispatches, and
reports; workers build and update the plan. When a worker finishes an item, surface it to the
developer.

### 2. Every instruction is allocated to a plan
No work happens off-plan. A new request that fits an existing feature → **rescope it into that
plan** as new `[ ]` items. A genuinely new feature → **scope it with the developer first**, then
create `plan/<feature>.md`. Additional features are never side-quests — they are just new
checkboxes in a plan.

### 3. The plan folder — a master plan over feature plans
`plan/` holds a **master plan** (`plan/MASTER.md`) that carries the overview — every feature and its
status at a glance — plus one detailed plan per feature. The master plan is the dashboard; each
feature plan owns the detail:

```markdown
# Master Plan — <project>

| Feature | Plan | Status |
|--------------------|-----------------------------------------|----------------|
| Product list       | [product-list.md](product-list.md)      | ✅ Complete    |
| Checkout flow      | [checkout.md](checkout.md)              | 🟡 In Progress |
```

A feature plan has four parts — a Scope checklist, the Tests, a Bugs section, and a Commit log:

```markdown
# Feature: Product List Component

## Scope
- [x] products signal + api load on mount
- [x] <product-list> renders each product as a card
- [ ] search box filters the list reactively

## Tests (written first, real — no smoke tests)
- [x] renders one card per product in the signal   (real DOM, seeded signal)
- [ ] typing in the search box narrows the rendered cards

## Bugs
- [x] list didn't re-render on filter — used ${signal.value} not ${signal} (a1b2c3d)
- [ ] card click navigated before the signal write landed

## Commits
- a1b2c3d  product-list component + reactive-render test
- e4f5g6h  fix frozen binding on filter

## Status: In Progress
```

### 4. Tests first — real behaviour, never smoke tests
Pin the behaviour before you build the component: assert against the **real rendered DOM** (a real
signal, a real `` html`` `` render), or run the dev server and drive the real UI — and make it fail
before the code exists. No mocks, no "it mounted" smoke test: reactivity is the thing under test,
and a frozen `${signal.value}` where you needed `${signal}` is exactly the bug a real render check
catches. The passing real check is the definition of done for a checklist item.

### 5. Build the minimum, grounded
Only once the check exists: ground with `tina4_context`, climb the **Lazy Frontend Ladder** (the
platform + tina4-js primitives cover most of it — never a React/Vue/state/router library), and
write the minimum reactive code that passes. Nothing speculative.

### 6. Verify for real, then log the commit
An item is `[x]` only when the real check passes AND the UI actually behaves in the browser. When
it lands, record the **commit hash + one-line description** in the plan's Commits section, so the
plan is an honest audit trail of what actually shipped.

### 7. Report as a ✅/❌ dashboard
Report to the developer as a table, not prose:

| Item                 | Status |
|----------------------|--------|
| products signal      | ✅     |
| product-list render  | ✅     |
| reactive search      | ❌     |
| Bug: nav race        | ❌     |

The developer should see status at a glance without asking. Update the table as workers complete
items, and surface each completion in the main session.

### Bugs are part of the plan
Bugs aren't tracked elsewhere — each plan has a **Bugs** section. A bug is logged there as `[ ]`,
fixed, proven with a **real** render check, and ticked `[x]` with its commit hash — the same
discipline as a feature.

## Before you write code — the reuse ladder

Climb in order; write new code only at the last rung. tina4-js is **sub-11 KB, zero dependencies** — reach for its primitives, not a framework.

1. **Does it need to exist?** Re-read the request and trace the actual flow. The best change is often none.
2. **Does tina4-js already do it?** Use the built-in primitives: reactivity → `signal`/`computed`/`effect`; DOM → `` html`` `` tagged templates + `Tina4Element`; persistence → `persist()`; HTTP → the `api` client; realtime → `ws`/`sse`/`rtc`; routing → `route`/`navigate`. **Never** reach for React/Vue/a state library/axios/a router lib.
3. **Does the browser/stdlib do it?** (`fetch`, `URL`, `crypto`, `structuredClone`…) Use it before adding anything.
4. **Is it already in THIS app?** Reuse the existing component/signal/store — don't duplicate.
5. **Adding an npm dependency? Stop.** tina4-js is zero-dependency by design — find the primitive.
6. **Can it be one signal / one `${}` hole / one component?** Prefer the smallest reactive form.
7. **Only now**, write the minimum that works — no wrapper components, no speculative props.

## Ground tina4-js Code With `tina4_context` — Then Write It Yourself

Tina4 exposes a `tina4_context(instruction, language)` MCP tool that returns framework-specific
grounding (idioms, current API surface, worked examples) for the thing you are about to build.
Call it to ground yourself before writing tina4-js, then **write the code yourself** using that
context plus the rules in this skill.

- **`tina4_context(instruction, language="tina4-js")`** — returns retrieval-grounded context for
  the requested feature (signals, `html` templates, `Tina4Element` components, routing, and the
  api / ws / sse / rtc clients). Use it as reference material, not as a code generator.

Do **not** use `tina4_code` to generate tina4-js — you are responsible for authoring the code.
The context tool grounds you; the reasoning, the code, and the review are yours. The rules in
this skill are the source of truth — apply them to whatever you write.

## Modules — What Each One Is

tina4-js is tree-shakeable: import only what you use. Ten modules, each with its own entry point.
Per-module gzip sizes below were measured via `npm run test:size` (macOS, v1.5.0); the `core`
bundle is the sub-3KB headline budget.

| Module | Import | Public exports | Gzip | What it does |
|--------|--------|----------------|------|--------------|
| **core** | `tina4js` / `tina4js/core` | `signal`, `computed`, `effect`, `batch`, `isSignal`, `html`, `Tina4Element` | 1.49 KB | Reactive primitives (signals), the `html` tagged-template DOM renderer, and the `Tina4Element` Web Component base. Everything reactive lives here — the headline <3 KB bundle. |
| **router** | `tina4js/router` | `route`, `navigate`, `router` | 0.12 KB | Hash-based client-side routing with `{param}` patterns, guards, and a `change` event. |
| **api** | `tina4js/api` | `api` | 2.27 KB | `fetch` wrapper: Bearer + formToken auth, request/response interceptors, JSON, consistent result shape — talks to Tina4 backends. |
| **ws** | `tina4js/ws` | `ws` | 0.89 KB | Signal-driven WebSocket client with auto-reconnect; `status`/`connected` are signals you bind straight into templates. |
| **sse** | `tina4js/sse` | `sse` | 1.30 KB | Signal-driven Server-Sent-Events / NDJSON streaming client (same reconnect + signal-status shape as `ws`). |
| **rtc** | `tina4js/rtc` | `rtc`, `rtcConfig` | 2.75 KB | Signal-driven realtime-collaboration client for a Tina4 backend's `realtime()` mount: mesh WebRTC calls (`rtc.call`, perfect negotiation), persistent chat (`rtc.chat`), and permissioned file up/download (`rtc.upload`/`rtc.fetchBlob`). Media is peer-to-peer; the server only relays SDP/ICE. |
| **storage** | `tina4js/storage` | `persist`, `clearPersistedKeys` | (folds into app) | Persist a signal to `localStorage` — versioned, cross-tab, migratable. **Never store secrets/tokens/PII** — `localStorage` is XSS-readable (see `STORAGE.md`). |
| **i18n** | `tina4js/i18n` | `createI18n`, `i18n`, `t`, `setLocale`, `getLocale` | 1.2 KB | Reactive translations (the active locale is a signal, so `t()` re-renders on `setLocale()`) + browser `Intl` number/currency/date/relativeTime + RTL `dir()`. Mirrors the backend Tina4 `I18n` API. |
| **pwa** | `tina4js/pwa` | `pwa` | 1.16 KB | Runtime web-manifest injection + service-worker registration for installable/offline apps. The manifest is generated and injected as a blob at runtime; the service worker is NOT — `register()` loads `swUrl` (or `/sw.js`), and `pwa.generateServiceWorker()` emits the SW source to write to disk. |
| **debug** | `import 'tina4js/debug'` | side-effect (auto-enables) | dev-only | Mounts a dev overlay (Ctrl+Shift+D) that tracks signals, components, routes, and API calls. Never ship to production. |

## Backend API Lookups — Use the Live Index

tina4-js talks to a Tina4 backend (Python / PHP / Ruby / Node). When you need a backend route's shape,
an ORM field, or a framework method signature, don't guess from memory — query the running backend's
live API index through its MCP tools (available with `tina4 serve` + `TINA4_DEBUG=true`):
`api_search("…")` to find a class or method, `api_class("User")` for its full surface, and
`api_method("Database", "fetch")` for an exact signature. These reflect the actual installed version,
so the frontend wires up against real endpoints instead of invented ones. (For frontend reactivity
itself — signals, `html`, components — the rules below are the source of truth.)

## The Lazy Frontend Ladder

The frontend is where over-building hurts most: a component library for a button, a state
manager for three variables, an npm dependency for what the platform already does. tina4-js is
~1.5KB precisely because it leans on the browser. Before adding code or a dependency, stop at the
FIRST rung that holds.

1. **Does this need to exist at all?** (YAGNI)
2. **Does the platform already do it?** `<input type="date">` over a date-picker lib, `<dialog>`
   over a modal lib, CSS `:has()` / grid / `position: sticky` over layout JS, native form
   validation attributes over a validation lib, `<details>` over an accordion component. The
   browser is the biggest dependency you already shipped — use it.
3. **Does tina4-js already do it?** `signal` + `computed` for state (no store library), `html`
   bindings + `${() => ...}` for reactivity (no virtual DOM), `?attr` / `.prop` bindings,
   `Tina4Element` for components, and the `router` / `api` / `ws` / `sse` / `persist` modules.
   Do not import React/Vue patterns here.
4. **Is there an installed dependency that covers it?** Use it. Adding a new npm package to a
   1.5KB app is a decision, not a reflex.
5. **Can it be one line?** Make it one line.
6. **Only then:** the minimum code that works.

**Never lazy about:** XSS safety (use `${value}` text binding, never `${htmlString}`; `.innerHTML`
only for trusted/sanitised HTML), accessibility (labels, roles, keyboard), and the bindings that
actually make the UI reactive — a frozen `${signal.value}` where you needed `${signal}` is a bug,
not brevity. Mark a deliberate shortcut with a `tina4:` comment that names its ceiling.

## Naming — Verbose and Descriptive

Spell every signal, variable, function, and component name out in full words. `cartItemCount`
not `cic`/`cnt`; `calculateCartSubtotal()` not `calcSub()`; `parsedApiResponse` not `r`. A name
must read as exactly what it holds or does, with no decoding. The only short names allowed are a
conventional loop index (`i`) and the idiomatic one-line callback argument (`items.map(item => ...)`).
This is naming verbosity (good) and is independent of code volume: keep the code lean (the Lazy
Frontend Ladder above), but give every name its full word — verbose names, lean code.

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

## Footguns That Cost Real Debugging Time

These bite even when you know the Three Rules. They came out of real app work — read them.

### ⚠ THE BIGGEST ONE: one `${...}` per attribute — never mix static text with a dynamic part

An attribute value must be a **single interpolation**. Partial interpolation — static text glued to a `${...}` inside one attribute — is **not** merged: the binder replaces the entire attribute value with just the interpolated result, so the static prefix is dropped and only the dynamic value is applied.

```ts
// ❌ WRONG — the static "card " prefix is DROPPED; class becomes just "active" (or "")
html`<div class="card ${() => active.value ? 'active' : ''}">`
html`<a href="/user/${id}">`               // partial — unreliable

// ✅ RIGHT — the WHOLE attribute value is one expression
html`<div class=${() => 'card ' + (active.value ? 'active' : '')}>`
html`<a href=${() => `/user/${id.value}`}>`    // build the whole string inside the expr
html`<a href=${`/user/${userId}`}>`             // static interpolation, evaluated once
```

**The rule: if an attribute contains any `${}`, the ENTIRE value must be that one `${}`.** Compose the full string inside the expression — don't concatenate static text with `${}` in the template.

### Bind form values with `.value`, never as a reactive child

```ts
// ✅ property binding — two-way, no DOM churn
html`<textarea .value=${() => form.value.note} @input=${e => setNote(e.target.value)}></textarea>`

// ❌ value as a reactive child — leaks a comment marker like <!--t4:12--> into the field
html`<textarea>${() => form.value.note}</textarea>`
```
(Same root cause as "never put inputs inside reactive blocks" above — always drive form elements through `.value` / `@input` / `?disabled`.)

### Reactive `<select>`: mark each option with `?selected`, never `.value` on the select

A `<select>` whose `<option>`s come from a `${() => ...}` block **loses its selection when those options re-render**. `.value` on the select is a separate effect that tracks only the bound signal, not the option list, so it never re-fires when the options rebuild; and setting `select.value` while the matching `<option>` is absent (options render after, or get torn down and recreated) is a silent no-op, then the browser resets the selection when the child list changes. This "worked by luck" when the option timing happened to line up and broke when it did not.

```ts
// ❌ selection drops the moment the options re-render
html`<select .value=${currency} @change=${e => currency.value = e.target.value}>
  ${() => currencies.value.map(c => html`<option value=${c}>${c}</option>`)}
</select>`

// ✅ each option owns its selected state — survives any re-render, in any order
html`<select @change=${e => currency.value = e.target.value}>
  ${() => currencies.value.map(c => html`
    <option value=${c} ?selected=${() => c === currency.value}>${c}</option>`)}
</select>`
```

Keep the `@change` to write the signal back (that half was never the problem). Option values are always strings, so if the bound signal holds a number, compare `String(c) === currency.value` or the match never fires and nothing shows selected.

### Hash-router links use the BARE path — the router adds the `#`

```ts
// ✅ → navigates to #/shop
html`<a href="/shop">Shop</a>`

// ❌ → produces ##/shop → 404
html`<a href="#/shop">Shop</a>`
```

For an in-template control that should **toggle state, not navigate**, don't use `<a href="#">` (it routes). Use a button-role element:

```ts
html`<span role="button" @click=${() => open.value = !open.value}>Toggle</span>`
```

### Defer navigation that depends on a signal you just wrote

A computed or route-guard reads the **old** value if you `navigate()` synchronously right after writing the signal. Defer the navigation one tick so the write settles first:

```ts
setUser(u);
setTimeout(() => navigate('/shop'), 0);   // ✅ the guard now sees isLoggedIn === true
```

Same fix for any write→navigate cascade (e.g. `placeOrder()` → clear cart → go to `/success`) — deferring avoids a 404 race.

### Render the primary list as a top-level reactive block

Render the main list directly as its own `${() => ...}`:

```ts
// ✅ flat, reliable
html`<ul>${() => items.value.map(i => html`<li>${i.name}</li>`)}</ul>`
```

Deeply nesting a list inside a ternary that itself returns `html` is fragile — the inner map may not re-render:

```ts
// ❌ fragile — flatten it instead
html`${() => cond.value ? html`...${() => items.value.map(...)}...` : html`...`}`
```

If one list "won't update" but a sibling list does, this nesting is the usual cause — pull the list up to its own top-level `${() => ...}` block.

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

### File Upload

Use `api.upload()` for multipart file uploads. Do NOT use `api.post()` — it sends JSON.

```ts
import { signal, html } from 'tina4js';
import { api } from 'tina4js/api';

const status = signal('');
const uploading = signal(false);

const handleUpload = async (e: Event) => {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;
    uploading.value = true;
    status.value = '';
    try {
        const form = new FormData();
        form.append('avatar', file);
        form.append('name', 'Alice');  // extra fields work too
        const result = await api.upload('/api/upload', form);
        status.value = 'Uploaded!';
    } catch (err) {
        status.value = 'Upload failed';
    }
    uploading.value = false;
};

html`
    <input type="file" @change=${handleUpload} ?disabled=${uploading} />
    <p>${status}</p>
`;
```

**Key points:**
- `api.upload(path, formData)` — sends FormData with multipart/form-data
- Do NOT set Content-Type header — the browser sets it with the boundary
- Auth uses Bearer token in header (not formToken in body)
- Backend receives files in `request.files` (raw bytes, not base64)

**If you don't use the tina4-js api client**, use native `fetch()`:
```ts
const form = new FormData();
form.append('file', fileInput.files[0]);
const token = localStorage.getItem('tina4_token');
await fetch('/api/upload', {
    method: 'POST',
    headers: token ? { Authorization: `Bearer ${token}` } : {},
    body: form,  // Do NOT set Content-Type
});
```

### GraphQL Queries

Use `api.graphql()` to send GraphQL queries and mutations. It sends a POST with `{ query, variables }`
and returns `{ data, errors }`.

```ts
// Simple query
const { data, errors } = await api.graphql('/api/graphql',
    '{ products(limit: 10) { id name price } }'
);

// Query with variables
const { data } = await api.graphql('/api/graphql',
    'query ($term: String!) { search_products(term: $term) { id name slug price } }',
    { term: searchInput.value }
);

// Mutation
const { data } = await api.graphql('/api/graphql',
    'mutation ($input: CreateProductInput!) { createProduct(input: $input) { id } }',
    { input: { name: 'Widget', price: 29.99 } }
);
```

**Reactive search example — debounced GraphQL search with live results:**
```ts
const term = signal('');
const results = signal([]);

let timer;
effect(() => {
    const q = term.value;
    clearTimeout(timer);
    if (q.length < 2) { results.value = []; return; }
    timer = setTimeout(async () => {
        const { data } = await api.graphql('/api/graphql',
            '{ search_products(term: "' + q.replace(/"/g, '\\"') + '") { id name slug price } }'
        );
        results.value = data?.search_products || [];
    }, 300);
});

html`
<input .value=${term} @input=${(e) => { term.value = e.target.value; }} placeholder="Search...">
<ul>${() => results.value.map(p => html`
    <li><a href="/products/${p.slug}">${p.name} — $${p.price}</a></li>
`)}</ul>`;
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

## Routing — IMPORTANT: {param} not :param

tina4-js uses **curly brace** syntax for route parameters — NOT Express-style colons.

```ts
import { route, navigate, router } from 'tina4js';

// Static route
route('/', () => html`<h1>Home</h1>`);

// Route with parameters — use {name}, NOT :name
route('/users/{id}', ({ id }) => html`<p>User ${id}</p>`);
route('/user/{userId}/post/{postId}', ({ userId, postId }) =>
  html`<p>User ${userId}, Post ${postId}</p>`
);

// Catch-all / 404
route('*', () => html`<h1>404 — Not Found</h1>`);

// Route guards (auth protection)
route('/admin', {
  guard: () => isLoggedIn.value || '/login',  // return true to allow, or redirect path
  handler: () => html`<admin-panel></admin-panel>`,
});

// Async routes (loading states)
route('/data', async () => {
  const data = await fetch('/api/data').then(r => r.json());
  return html`<p>${data.message}</p>`;
});

// Start the router
router.start({ target: '#app', mode: 'hash' });  // or mode: 'history'

// Listen for route changes
router.on('change', ({ path, params, pattern, durationMs }) => {
  console.log(`Navigated to ${path} in ${durationMs}ms`);
});

// Navigate programmatically — navigate() is a standalone export, NOT a method on router.
// (router only has .start() and .on().)
navigate('/users/42');
```

**Common mistake:** Using Express-style `:id` instead of `{id}`. The route will never match.

**Navigation:** Use a BARE `<a href="/path">` in **both** hash and history mode — the router intercepts the click and, in hash mode, prepends the `#` for you. Do NOT write `<a href="#/path">` in hash mode: the interceptor uses the raw `href` as the path, so it becomes `##/path` → 404 (see the hash-router footgun above).

## Persistent Signal Storage (v1.2.5+)

Wrap a signal so its value survives a page refresh, backed by localStorage or sessionStorage.
Opt-in per signal, zero dependencies, tree-shakeable. **Read STORAGE.md before you use it.**

```ts
import { signal } from 'tina4js';
import { persist, clearPersistedKeys } from 'tina4js/storage';

const theme = persist(signal('light'),  { key: 'theme' });
const cart  = persist(signal([]),       { key: 'cart', syncTabs: true });

theme.value = 'dark';   // survives a refresh; second tab sees the cart change

// On logout, wipe persisted user state
clearPersistedKeys(['cart', 'lastFilter']);
```

**What `persist()` returns:** the same signal you passed in, with two extras attached —
`.clear()` removes the key from storage, `.dispose()` stops the write effect.

**Options:** `key` (required), `storage: 'local'|'session'`, custom `serializer` for Date /
Map / Set, `version` + `migrate` for stored-shape changes between deploys, `syncTabs` for
cross-tab updates, `silenceCredentialWarning` to silence false positives like `tokenColor`.

### What this must never store

`localStorage` is XSS-readable. Any script on the origin reads every value. The framework
warns loudly the first time it sees a credential-shaped key or value. Never put any of these
behind `persist()`:

- Auth tokens, JWTs, session IDs, API keys — use `httpOnly` + `Secure` + `SameSite` cookies.
- Passwords, including "encrypted" or "hashed" client-side ones.
- Personal data (names, emails, phone numbers, addresses, IDs) — POPIA/GDPR exposure.
- Payment data (card numbers, CVV, expiry) — not PCI-DSS compliant.
- Permission flags, roles, `isAdmin` booleans — the user can edit them in devtools.
- Encryption keys, OTP seeds, secrets.
- Server-of-record state (orders, balances, ledger entries) — fetch fresh from the database.
- Anything that must not survive a logout — clear it via `clearPersistedKeys()` on logout.

### What it is for

Theme preference, language, sidebar collapsed state, last-used filter, onboarding flags,
local-only draft text, guest cart contents. Small things the user chose, the user expects
back, and an attacker gains nothing from reading.

### Safety guarantees the framework gives you

- **SSR-safe.** No `window`/`localStorage` means `persist()` is a silent no-op. No crash.
- **Quota-safe.** `QuotaExceededError` is logged and skipped; the signal still updates.
- **Credential warnings.** Loud `console.warn` once per key for key names matching
  `token|password|secret|apikey|auth|credential|jwt|bearer|otp|private_key|session_id`,
  for JWT-shaped string values, and for objects with credential-shape fields.
- **No "encrypted" option.** Encryption with a key sitting in the same bundle is theatre,
  not security. Offering it would mislead.
- **Opt-in cross-tab sync.** `syncTabs: true` per signal. Off by default.

Full details, examples (Date round-trip, version migration, logout wipe), and the complete
dangers table live in `STORAGE.md` at the repo root.

## Internationalization — `tina4js/i18n`

Reactive translations plus browser-native `Intl` formatting. The active locale is a signal, so `t()` and every formatter re-render in place on `setLocale()`. Mirrors the backend Tina4 `I18n` API, so the same message JSON works on the server and in the browser.

```ts
import { createI18n } from 'tina4js';
// or the default singleton + shortcuts:
import { i18n, t, setLocale, getLocale } from 'tina4js/i18n';

const i = createI18n({
    locale: 'en-US',
    fallbackLocale: 'en-US',
    messages: {
        'en-US': { greeting: 'Hello', welcome: 'Welcome, {name}!', nav: { home: 'Home' } },
        'fr-FR': { greeting: 'Bonjour', nav: { home: 'Accueil' } },
    },
});

i.t('greeting');                    // "Hello"
i.t('welcome', { name: 'Alice' });  // "Welcome, Alice!"   ({placeholder} interpolation)
i.t('nav.home'); i.t('home');       // dot-path AND leaf-key alias both resolve
i.number(1234.5);                   // "1,234.5"
i.currency(19.99, 'USD');           // "$19.99"
i.date(new Date(), { dateStyle: 'medium' });
i.relativeTime(-1, 'day');          // "yesterday"
i.dir();                            // "ltr" | "rtl"   (RTL-aware)
await i.loadMessages('es-ES', '/i18n/es-ES.json');  // fetch a bundle at runtime
i.setLocale('fr-FR');               // every t()/formatter re-renders
```

### The reactivity rule — use the function form in templates

The locale is a signal, so `t()` and the formatters must be read inside a `${() => ...}` reactive block (Rule 1). A bare `${i.t('greeting')}` evaluates once and freezes at the first locale; it will not update when `setLocale()` runs.

```ts
// RIGHT — re-renders when the locale changes
html`<h1>${() => i.t('greeting')}</h1>`
html`<span>${() => i.currency(cart.value.total, 'USD')}</span>`
html`<div dir=${() => i.dir()}>...</div>`

// WRONG — frozen at the first locale, never updates on setLocale()
html`<h1>${i.t('greeting')}</h1>`
```

Fallback order is current locale -> `fallbackLocale` -> the key itself, so `t()` never throws on a missing key. Formatting delegates to the browser's `Intl` APIs, so no locale data ships in the bundle. Full guide: https://tina4.com/js/09-i18n

## Cloudflare Workers

tina4-js runs on Cloudflare Workers with Durable Objects for WebSocket state. The IIFE bundle
and all client-side code works as-is; the WebSocket client (`ws.connect()`) connects to Worker
endpoints backed by Durable Objects for persistent state across connections.

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

// GraphQL queries and mutations
const { data, errors } = await api.graphql('/api/graphql', '{ users { id name } }');
const { data } = await api.graphql('/api/graphql', 'query($id: Int!) { user(id: $id) { name } }', { id: 42 });

// persist — wrap any signal with localStorage / sessionStorage persistence
import { persist, clearPersistedKeys } from 'tina4js/storage';
const theme = persist(signal('light'), { key: 'theme' });
// theme.clear() removes the stored key; theme.dispose() stops the write effect.
// clearPersistedKeys(['cart', 'lastFilter']) on logout to wipe user state.
// See STORAGE.md for the full "must never store" list. localStorage is XSS-readable.
```

## Reference Files

- **`references/signals-and-reactivity.md`** — Full signal, computed, effect, batch, isSignal,
  and persist API with edge cases and gotchas. Read for any reactive state work, including
  persistence.
- **`references/html-and-components.md`** — html template bindings, Tina4Element Web Components,
  lifecycle, routing, API client, WebSocket. Read for any UI/component work.

## Reporting a stale or incorrect skill

Found guidance in this skill that contradicts how Tina4 actually behaves? Then the
skill has drifted from the code. Report it so it gets fixed for everyone, not just
worked around in this session:

- Open a skill report: https://github.com/tina4stack/tina4-documentation/issues/new?labels=skill&template=skill-report.yml
- Or on the web: https://tina4.com/report-a-skill

Include the skill name (`tina4-developer`, `tina4-js`, or `tina4-maintainer`), the
file and section, what the skill claims, and what the code actually does (a
`file:line` reference or a short repro). The code is the source of truth; a skill
that disagrees with it is the bug.

If you are an AI agent and you hit this drift mid-task, do not file silently: tell
the developer what you found, then file the report only with their go-ahead.
