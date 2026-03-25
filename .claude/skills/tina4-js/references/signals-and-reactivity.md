# Signals & Reactivity — Complete Reference

## signal<T>(initial: T, label?: string): Signal<T>

```ts
interface Signal<T> {
  value: T;           // getter tracks deps, setter notifies
  peek(): T;          // read WITHOUT tracking
}
```

**Equality:** Uses `Object.is()`. Implications:
- `NaN` to `NaN` → no trigger (correct)
- Same object/array reference → no trigger (you MUST spread to new reference)
- `+0` to `-0` → triggers (edge case, rarely matters)

**Writing inside effects:** Legal but dangerous. Guard against infinite loops:
```ts
// DANGEROUS — infinite loop
effect(() => { count.value = count.value + 1; });

// SAFE — guarded
effect(() => { if (count.value < 10) count.value = count.value + 1; });
```

## computed<T>(fn: () => T): ReadonlySignal<T>

```ts
interface ReadonlySignal<T> {
  readonly value: T;  // writing throws Error('[tina4] computed signals are read-only')
  peek(): T;
}
```

- **NOT lazy** — eagerly recomputes on every dependency change
- No memoization of the function result — recalculates even if output is same
- Chaining works: `computed A → computed B → computed C` (each adds an effect)
- Writing to `.value` throws an error

## effect(fn: () => void): () => void

Runs `fn` immediately, re-runs when any signal read inside changes. Returns dispose function.

**Dependency re-tracking:** On every re-run, old subscriptions are cleared, new ones established.
Conditional branches work correctly:
```ts
effect(() => {
    if (toggle.value) { a.value; }   // tracks a when toggle=true
    else              { b.value; }   // tracks b when toggle=false, unsubscribes a
});
```

**Nested effects:** Supported. Inner effects have their own subscription lifecycle.

**Disposal:** `dispose()` is safe to call multiple times. After disposal, the effect never
runs again and all subscriptions are removed.

## batch(fn: () => void): void

Groups multiple signal writes — subscribers notified once after batch completes.

```ts
batch(() => {
    firstName.value = 'Alice';  // no notification yet
    lastName.value = 'Smith';   // no notification yet
    age.value = 30;             // no notification yet
});
// ALL subscribers run once here
```

- **Nestable** — only outermost batch flushes
- **Error-safe** — flush happens in `finally` block, even if `fn` throws
- **Deduplication** — same subscriber only runs once per batch (uses Set)

## Diamond Dependency

```
    A
   / \
  B   C
   \ /
    D (depends on B and C)
```

- **With batch:** D's effect fires once (correct)
- **Without batch:** D's effect may fire 2-3 times (B updates, D runs, C updates, D runs again)
- **Recommendation:** Always use `batch()` when updating multiple signals that feed into the same computed/effect

## isSignal(value: unknown): boolean

Checks if a value is a tina4-js signal. Useful for building generic utilities:
```ts
import { isSignal, signal } from 'tina4js';

const count = signal(0);
isSignal(count);    // true
isSignal(42);       // false
isSignal(null);     // false
```

## Global State (The "Store")

There is no store module. Signals ARE the store:

```ts
// src/store.ts
export const user = signal<User | null>(null);
export const theme = signal<'light' | 'dark'>('light');
export const cart = signal<CartItem[]>([]);
export const isLoggedIn = computed(() => user.value !== null);
export const cartTotal = computed(() =>
    cart.value.reduce((sum, item) => sum + item.price * item.qty, 0)
);
```

Import and use anywhere. No providers, no context, no wrappers.

## Common Pitfalls

### 1. Forgetting to create new references
```ts
// BUG: push mutates in place, Object.is sees same reference → no update
cart.value.push(item);
// FIX:
cart.value = [...cart.value, item];
```

### 2. Reading .value outside reactive context
```ts
// This logs once, never again (no effect wrapping it)
console.log(count.value);
// This logs every time count changes
effect(() => console.log(count.value));
```

### 3. Infinite effect loops
```ts
// INFINITE LOOP — effect reads AND writes the same signal
effect(() => { total.value = items.value.length; });
// Only infinite if total is also read elsewhere in the same effect.
// If it's write-only to total, it's fine (no circular dependency).
```

### 4. Computed can't be written to
```ts
const doubled = computed(() => count.value * 2);
doubled.value = 10; // THROWS: '[tina4] computed signals are read-only'
```

### 5. peek() for non-tracking reads
```ts
// Use peek() when you need the value but don't want to subscribe
effect(() => {
    const threshold = config.peek(); // doesn't re-run when config changes
    if (count.value > threshold) alert('Over threshold');
});
```
