# Persistent Signals — Complete Reference

`tina4js/storage` is an optional sub-package. It wraps a signal so its value
survives a page refresh via `localStorage` or `sessionStorage`. Zero runtime
cost if you do not import it.

## persist<T>(source: Signal<T>, options: PersistOptions<T>): PersistedSignal<T>

```ts
import { signal } from 'tina4js';
import { persist } from 'tina4js/storage';

const theme = persist(signal('light'), { key: 'theme' });
theme.value = 'dark';   // survives a refresh
```

Returns the **same signal you passed in**, with two extra methods attached.
You read and write via `.value` exactly as before. The framework hooks an
effect inside that calls `localStorage.setItem` on every change and reads the
stored value back on creation.

### PersistOptions

| Option | Type | Default | Behaviour |
|---|---|---|---|
| `key` | `string` | required | Storage key. Throws on missing/empty. |
| `storage` | `'local' \| 'session'` | `'local'` | `'session'` clears when the tab closes. |
| `serializer` | `{ read(s): T; write(v): string }` | JSON | Required for Date, Map, Set, or non-JSON shapes. |
| `version` | `number` | `1` | Stored-shape version. Compared to `parsed.v` on read. |
| `migrate` | `(oldValue, oldVersion) => T` | undefined | Called when versions disagree. No-migrate + mismatch = discard. |
| `syncTabs` | `boolean` | `false` | Subscribe to the `storage` event so other tabs see writes. |
| `silenceCredentialWarning` | `boolean` | `false` | Suppress the credential-shape warning. |

### Returned signal: PersistedSignal<T>

Identical to `Signal<T>` plus:

- `.clear(): void` — removes the key from storage. The signal keeps its
  current in-memory value. Subsequent writes will re-create the key.
- `.dispose(): void` — stops the write effect AND removes the storage-event
  listener. The signal still works as an in-memory signal. Used in tests.

## clearPersistedKeys(keys: string[], storage?: 'local' | 'session'): void

```ts
import { clearPersistedKeys } from 'tina4js/storage';

clearPersistedKeys(['cart', 'lastFilter', 'draftReply']);
```

Removes a list of keys at once. Wire this to your logout handler so persisted
state does not leak to the next user on the device. No-op when storage is
unavailable.

## Storage envelope

Values are wrapped in a small JSON envelope so the version travels with the
payload:

```json
{ "v": 1, "value": <user value> }
```

Legacy bare values (no envelope) are tolerated on read — the framework
re-wraps them on the next write.

## Credential-shape warnings

The framework warns loudly on the first persist of:

- **Key names** matching `/(token|password|passwd|secret|api[_-]?key|apikey|auth(?!or)|credential|jwt|bearer|otp|seed|private[_-]?key|session[_-]?id)/i`
- **String values** that look like a JWT (`xxx.yyy.zzz` with each segment ≥ 20 base64 chars)
- **String values** that are 40+ chars of base64
- **Object values** containing a credential-shape field

Warnings fire once per key (via an internal `Set<string>`) so the console
does not flood. `silenceCredentialWarning: true` disables the check for that
signal. Use it only when the match is a coincidence (e.g. `tokenColor` for a
UI palette).

## Safety guarantees the framework enforces

- **SSR-safe**: no `globalThis` access throws. `persist()` returns the signal
  unchanged when there is no `localStorage`. The in-memory value still works.
- **Private-mode safe**: same path as SSR.
- **`QuotaExceededError`**: logged via `console.warn`, then skipped. The app
  continues; the value is not persisted on that write only.
- **No encryption option, ever.** Encrypting with a key in the same bundle is
  theatre, not security. If the value needs real protection, it belongs on
  the server.
- **Cross-tab sync is opt-in** (`syncTabs: true`), not on by default.

## What this is for

The list is the point. Small things the user chose, that the user expects
back, that an attacker gains nothing from reading:

- Theme, language, sidebar collapsed state.
- Last-used filter, sort order, view setting.
- Onboarding flags ("user dismissed the welcome banner").
- Local-only draft text.
- Guest cart contents (server cart overrides on login).

## What this MUST NOT store

`localStorage` is XSS-readable. Any injected script reads every key. Any
browser extension reads every key.

| Do not store | Where it belongs |
|---|---|
| Auth tokens, JWTs, session IDs, API keys | `httpOnly` + `Secure` + `SameSite` cookies |
| Passwords (including "client-side encrypted") | Server only, bcrypt/argon2 |
| Personal data: names, emails, phones, ID numbers | Server, fetched on demand, in memory only |
| Payment data | Tokenised by the payment processor |
| Permission flags, roles, `isAdmin` | Server, re-checked every request |
| Encryption keys, OTP seeds | Never client-side |
| Authoritative server state (orders, balances) | The database — fetch fresh |
| State that must not outlive a logout | Wipe with `clearPersistedKeys()` on logout |

If your code looks like it is using `persist()` for any row above, it is
wrong. The credential-shape warning catches the obvious cases but is not a
substitute for getting it right.

## Patterns

### Theme

```ts
import { signal, effect } from 'tina4js';
import { persist } from 'tina4js/storage';

const theme = persist(signal('light'), { key: 'theme' });

effect(() => {
  document.documentElement.dataset.theme = theme.value;
});
```

### Cross-tab guest cart

```ts
const cart = persist(signal<CartItem[]>([]), {
  key: 'cart',
  syncTabs: true,
});

// New-reference rule still applies — persist does NOT change reactivity
cart.value = [...cart.value, { id: 1, name: 'Widget' }];
```

### Custom serializer for Date

```ts
const lastVisit = persist(signal(new Date()), {
  key: 'lastVisit',
  serializer: {
    write: (d) => d.toISOString(),
    read: (s) => new Date(s),
  },
});
```

### Versioned migration

```ts
// v1 stored:  { name: 'Alice' }
// v2 wants:   { firstName: 'Alice', lastName: '' }
const user = persist(signal({ firstName: '', lastName: '' }), {
  key: 'user',
  version: 2,
  migrate: (old) => ({
    firstName: (old as { name?: string }).name ?? '',
    lastName: '',
  }),
});
```

A v1-stored user reads through `migrate()` once on load. The stored shape
updates to v2 on the next write.

### Wipe on logout

```ts
function logout() {
  api.post('/auth/logout');
  clearPersistedKeys(['cart', 'lastFilter', 'draftReply']);
  window.location.reload();
}
```

### Persist a store-pattern signal

`persist()` composes naturally with the store pattern (one file exporting
shared module-level signals):

```ts
// src/store.ts
import { signal } from 'tina4js';
import { persist } from 'tina4js/storage';

export const theme    = persist(signal('light'),  { key: 'theme' });
export const sidebar  = persist(signal(false),    { key: 'sidebar.collapsed' });
export const language = persist(signal('en'),     { key: 'lang' });
// authToken does NOT belong here — see the dangers list above.
```

Every consumer that imports from `@/store` gets a signal that already knows
how to survive a refresh.

## Common mistakes

1. **Mutating in place.** `cart.value.push(item)` does not trigger an
   update *or* a persist. Spread: `cart.value = [...cart.value, item]`.
2. **Persisting an `isAdmin` flag.** The user can rewrite the localStorage
   value in devtools. Any code that trusts a persisted permission is broken.
3. **Persisting a JWT.** The framework will warn. Listen to the warning.
   The fix is `httpOnly` cookies, not `silenceCredentialWarning: true`.
4. **Forgetting `clearPersistedKeys()` on logout.** Drafts and filters leak
   to the next user on the device.
5. **Assuming `syncTabs: true` always reflects.** The `storage` event fires
   in OTHER tabs, never in the writing tab. The signal in the writing tab
   updates via the normal setter; the signal in other tabs updates via the
   event listener. Both end up in sync, but the path differs.
6. **Storing a Date without a serializer.** JSON turns a Date into a
   string. On read it comes back as a string, not a Date. Pass a serializer.

## Bundle cost

About 1 KB gzipped on top of the core, only when imported. Tree-shaking is
NOT required — the storage entry is a separate package export, so the bytes
never ship if your code does not `import` from `tina4js/storage`.
