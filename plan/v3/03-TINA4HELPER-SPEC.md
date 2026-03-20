# frond.js — Unified Frontend Helper Specification

## Overview
`frond.js` is a lightweight, framework-agnostic JavaScript library that provides common frontend utilities for any Tina4 backend (Python, PHP, Ruby, Node.js). It ships with every Tina4 project and is served from `src/public/js/frond.js`.

## Design Goals
1. **Zero dependencies** — vanilla JavaScript, no jQuery, no build step
2. **Works with any Tina4 backend** — abstracts backend differences
3. **Small** — under 10KB minified
4. **ES Module + IIFE** — works with `<script>` tag or `import`

## API Surface

### HTTP Client
```javascript
// GET request
const users = await Frond.get("/api/users");
const user = await Frond.get("/api/users/1");

// POST request
const result = await Frond.post("/api/users", { name: "John", email: "john@example.com" });

// PUT request
await Frond.put("/api/users/1", { name: "Jane" });

// PATCH request
await Frond.patch("/api/users/1", { name: "Jane" });

// DELETE request
await Frond.delete("/api/users/1");

// With options
await Frond.get("/api/users", {
  headers: { "Authorization": "Bearer token123" },
  params: { page: 2, limit: 20, sort: "-created_at" }
});
```

### Form Handling
```javascript
// Submit form via AJAX
Frond.submitForm("#myForm", {
  onSuccess: (response) => { /* handle success */ },
  onError: (errors) => { /* handle validation errors */ },
  method: "POST"   // auto-detected from form action/method if omitted
});

// Serialize form to object
const data = Frond.formData("#myForm");

// Populate form from object
Frond.fillForm("#myForm", { name: "John", email: "john@example.com" });

// Reset form
Frond.resetForm("#myForm");
```

### CRUD Table Helper
```javascript
// Render a CRUD table from an API endpoint
Frond.crud("#container", {
  endpoint: "/api/users",
  columns: ["id", "name", "email", "created_at"],
  labels: { id: "ID", name: "Full Name", email: "Email", created_at: "Joined" },
  searchable: true,
  paginate: true,
  pageSize: 20,
  actions: ["edit", "delete"],
  onEdit: (row) => { /* open edit modal */ },
  onDelete: (row) => { /* confirm and delete */ }
});

// Refresh table data
Frond.refreshCrud("#container");
```

### Modal / Dialog
```javascript
// Show a modal with content from a URL
Frond.modal({
  url: "/api/users/1/edit",
  title: "Edit User",
  size: "medium",   // small, medium, large
  onClose: () => { /* cleanup */ }
});

// Show a confirmation dialog
const confirmed = await Frond.confirm("Delete this user?", {
  confirmText: "Delete",
  cancelText: "Cancel"
});
```

### Notifications / Toast
```javascript
Frond.notify("User saved successfully", "success");   // success, error, warning, info
Frond.notify("Something went wrong", "error", { duration: 5000 });
```

### DOM Utilities
```javascript
// Query shorthand
const el = Frond.el("#myElement");          // single element
const els = Frond.els(".my-class");         // multiple elements

// Event binding
Frond.on("#button", "click", (e) => { /* handler */ });
Frond.on(".item", "click", (e) => { /* delegated */ });

// Show/hide
Frond.show("#element");
Frond.hide("#element");
Frond.toggle("#element");

// Load HTML into element
await Frond.load("#container", "/partials/sidebar");
```

### Auth Helper
```javascript
// Set auth token (stored in memory, not localStorage by default)
Frond.setToken("jwt-token-here");

// Get current token
const token = Frond.getToken();

// Clear auth
Frond.clearToken();

// All subsequent requests automatically include Authorization header
```

### Reconnecting WebSocket Engine
Built-in reconnecting WebSocket with exponential backoff — never loses connection permanently.

```javascript
// Connect to WebSocket (auto-reconnect by default)
const ws = Frond.ws("/ws/chat");

ws.on("message", (data) => { /* handle message */ });
ws.on("open", () => { /* connected */ });
ws.on("close", () => { /* disconnected — will auto-reconnect */ });
ws.on("reconnecting", (attempt) => { /* attempt 1, 2, 3... */ });
ws.on("reconnected", () => { /* back online */ });
ws.on("error", (err) => { /* error */ });
ws.on("max_retries", () => { /* gave up after N attempts */ });

ws.send({ type: "chat", message: "Hello" });
ws.close();     // Intentional close — no reconnect
ws.destroy();   // Kill connection + remove all listeners

// Reconnect configuration
const ws = Frond.ws("/ws/chat", {
  reconnect: true,          // Auto-reconnect on disconnect (default: true)
  maxRetries: 10,           // Max reconnect attempts (default: 10, 0 = infinite)
  baseDelay: 1000,          // Initial delay in ms (default: 1000)
  maxDelay: 30000,          // Max delay cap in ms (default: 30000)
  backoffMultiplier: 2,     // Exponential backoff factor (default: 2)
  heartbeat: 30000,         // Send ping every N ms to detect dead connections (default: 30000)
  protocols: []             // WebSocket sub-protocols (optional)
});

// Reconnect sequence: 1s → 2s → 4s → 8s → 16s → 30s → 30s → ...
// Heartbeat pings detect silent disconnects (e.g. network switch, sleep/wake)
```

### Live Block Integration
`frond.js` automatically powers `{% live %}` template blocks:

```javascript
// Automatic — no JS code needed, Frond handles it:
// {% live "notifications" poll 5 %}...{% endlive %}
// frond.js finds [data-frond-live] elements and starts polling/ws

// Manual control if needed
Frond.live.pause("notifications");   // Pause polling
Frond.live.resume("notifications");  // Resume
Frond.live.refresh("notifications"); // Force immediate refresh
```

### Data Attribute Reader
Reads data serialized by `{% data %}` template tags:

```javascript
// {% data users as "users" %} in template creates data-frond-users attribute
const users = Frond.data("users");           // Parsed JSON array/object
const config = Frond.data("config");         // Another data binding
const missing = Frond.data("nonexistent");   // Returns null, no error
```

### Configuration
```javascript
// Global config (call once at app startup)
Frond.config({
  baseUrl: "",                    // API base URL (default: same origin)
  tokenStorage: "memory",        // "memory" | "localStorage" | "sessionStorage"
  defaultHeaders: {},             // headers added to every request
  csrfToken: null,               // CSRF token for form submissions
  notificationPosition: "top-right" // toast position
});
```

## File Structure
```
src/public/js/
  frond.js          ← full source (ES module + IIFE)
  frond.min.js      ← minified production version
```

## Usage in Templates
```html
<!-- As script tag -->
<script src="/js/frond.js"></script>
<script>
  Frond.get("/api/users").then(users => console.log(users));
</script>

<!-- As ES module -->
<script type="module">
  import { Frond } from '/js/frond.js';
  const users = await Frond.get("/api/users");
</script>
```

## Testing Requirements
frond.js must have its own test suite (run in a browser test runner or jsdom):

### Positive Tests
1. `test_get_request` — GET returns parsed JSON
2. `test_post_request` — POST sends body, returns response
3. `test_put_request` — PUT sends body
4. `test_delete_request` — DELETE sends request
5. `test_auth_header` — token automatically included after setToken
6. `test_form_serialize` — formData returns correct object
7. `test_form_fill` — fillForm populates inputs
8. `test_notify_renders` — notification appears in DOM
9. `test_modal_loads` — modal fetches and displays content
10. `test_ws_connect` — WebSocket connects and receives messages
11. `test_ws_reconnect_backoff` — reconnects with exponential backoff (1s, 2s, 4s...)
12. `test_ws_heartbeat` — heartbeat pings detect dead connections
13. `test_ws_reconnected_event` — fires "reconnected" after successful reconnect
14. `test_live_block_poll` — live block auto-refreshes at configured interval
15. `test_live_block_pause_resume` — pause/resume stops/starts polling
16. `test_live_block_manual_refresh` — force refresh updates immediately
17. `test_data_read` — Frond.data() parses data-frond-* attributes
18. `test_data_missing` — Frond.data("nonexistent") returns null
19. `test_crud_renders_table` — crud() renders table with data from endpoint
20. `test_crud_pagination` — table pagination works

### Negative Tests
1. `test_get_404` — GET non-existent endpoint returns error
2. `test_post_validation_error` — POST with invalid data returns 422 with errors
3. `test_ws_max_retries` — fires "max_retries" after exhausting attempts
4. `test_ws_intentional_close` — close() does NOT trigger reconnect
5. `test_no_token` — requests without setToken have no auth header
6. `test_invalid_selector` — Frond.el with bad selector returns null, no throw
7. `test_live_block_invalid_name` — live block with unknown name is silent
