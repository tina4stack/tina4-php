# Tina4 v3.0 — Backend Console & Error Handling

## 1. Backend Console (`/tina4/console`)

A built-in admin dashboard served at `/tina4/console` when `TINA4_DEBUG=true` or when authenticated with an admin token. Identical UI across all four frameworks (shared HTML/CSS/JS like the debug overlay).

### Access Control
```env
TINA4_CONSOLE=true                         # Enable console (default: false)
TINA4_CONSOLE_TOKEN=your-secret-token      # Required — no default, no console without it
TINA4_CONSOLE_PATH=/tina4/console          # Customizable path (default: /tina4/console)
```

Login: `GET /tina4/console` → prompts for token → sets session cookie.

### Console Panels

#### Queue Manager
```
┌─────────────────────────────────────────────────────────────┐
│ QUEUES                                                       │
├──────────┬─────────┬──────────┬────────┬──────────┬─────────┤
│ Queue    │ Pending │ Reserved │ Failed │ Dead     │ Actions │
├──────────┼─────────┼──────────┼────────┼──────────┼─────────┤
│ emails   │ 142     │ 3        │ 7      │ 2        │ [View]  │
│ orders   │ 0       │ 0        │ 0      │ 0        │ [View]  │
│ reports  │ 23      │ 1        │ 0      │ 0        │ [View]  │
└──────────┴─────────┴──────────┴────────┴──────────┴─────────┘
```

**Per-queue view:**
- Live message list (filterable by status)
- View message payload by ID
- Retry failed/dead-letter messages (single or bulk)
- Requeue messages to different queue
- Purge queue
- View worker status (last pop time, processing rate)
- Failover chain visualization
- Circuit breaker state (closed/open/half-open)

#### WebSocket Monitor
```
┌─────────────────────────────────────────────────────────────┐
│ WEBSOCKET CONNECTIONS                                        │
├──────────┬────────────┬──────────┬───────────┬──────────────┤
│ ID       │ Client IP  │ Channel  │ Connected │ Messages     │
├──────────┼────────────┼──────────┼───────────┼──────────────┤
│ ws-a1b2  │ 10.0.0.15  │ /ws/chat │ 4m 23s    │ 47 ↑ 123 ↓  │
│ ws-c3d4  │ 10.0.0.22  │ /ws/chat │ 12m 01s   │ 12 ↑ 89 ↓   │
│ ws-e5f6  │ 10.0.0.8   │ /ws/live │ 1m 45s    │ 0 ↑ 15 ↓    │
└──────────┴────────────┴──────────┴───────────┴──────────────┘
```

**Features:**
- Active connection list with metadata
- Messages sent/received counts
- Send test message to specific connection or broadcast
- Disconnect specific client
- Channel/room overview
- Connection rate graph (last hour)

#### Debug / Request Inspector
```
┌─────────────────────────────────────────────────────────────┐
│ RECENT REQUESTS                                              │
├────────┬────────┬─────────────────┬────────┬────────┬───────┤
│ Time   │ Method │ Path            │ Status │ Duration│ ReqID │
├────────┼────────┼─────────────────┼────────┼────────┼───────┤
│ 10:31  │ GET    │ /api/users      │ 200    │ 12ms   │ a1b2  │
│ 10:31  │ POST   │ /api/orders     │ 201    │ 45ms   │ c3d4  │
│ 10:30  │ GET    │ /api/products/7 │ 404    │ 3ms    │ e5f6  │
│ 10:30  │ POST   │ /api/login      │ 500    │ 120ms  │ g7h8  │ ← RED
└────────┴────────┴─────────────────┴────────┴────────┴───────┘
```

**Click on any request to see:**
- Full request headers, body, params
- Full response headers, body
- Database queries executed (with timing)
- Template renders (with timing)
- Middleware chain
- Session data
- Log entries for this request ID
- Stack trace (if error)

#### Error Log
```
┌─────────────────────────────────────────────────────────────┐
│ ERRORS (last 24h)                                            │
├────────┬──────────────────────────────────────┬──────┬───────┤
│ Time   │ Error                                │ Count│ Status│
├────────┼──────────────────────────────────────┼──────┼───────┤
│ 10:30  │ TypeError: Cannot read property 'id' │ 3    │ .broken│
│ 09:15  │ DatabaseError: Connection refused     │ 12   │ .broken│
│ 08:45  │ ValidationError: email required       │ 1    │ handled│
└────────┴──────────────────────────────────────┴──────┴───────┘
```

**Per-error view:**
- Full stack trace
- Request that caused it
- Environment snapshot
- Occurrence count + timestamps
- .broken file path
- "Mark resolved" button (deletes .broken file)

#### System Overview
- Framework version, language version, OS
- Uptime, memory usage, CPU
- Database connection status (per driver)
- Session backend status
- Queue backend status
- Active routes count
- .broken file count
- Health check status

### Console Implementation
- Served as static HTML + frond.js
- All data fetched via internal API endpoints (`/tina4/console/api/*`)
- Console API endpoints are protected by the console token
- Auto-refreshes via polling (configurable interval)
- Works without WebSocket (polling fallback) so it's available even when WS is down

---

## 2. Global Exception Handler

### Philosophy
Every unhandled exception is caught, logged, and produces a useful response. In dev mode: full stack trace in the browser. In production: clean error page + .broken file for container health checks.

### Behavior by Environment

| Scenario | Dev (`TINA4_DEBUG=true`) | Production |
|----------|------------------------|------------|
| Unhandled exception | Full stack trace in browser + debug overlay | Generic 500 page + .broken file |
| 404 | Helpful "route not found" page listing similar routes | Clean 404 page |
| Validation error (422) | Detailed field errors in JSON | Same |
| Database error | Full query + error in browser | Generic "service unavailable" + .broken file |
| Template error | Line number + template context in browser | Generic 500 + .broken file |

### Dev Mode Error Page
```
┌─────────────────────────────────────────────────────────────┐
│ 🔴 TypeError: Cannot read property 'email' of null          │
│                                                              │
│ src/routes/api/users/get.py  line 23                        │
│                                                              │
│   21 │ @get("/api/users/{id}")                               │
│   22 │ async def get_user(request, response):                │
│ → 23 │     user = User.find(request.params["id"])            │
│   24 │     return response.json(user.email)    ← user is None│
│   25 │                                                       │
│                                                              │
│ Request: GET /api/users/999                                  │
│ Request ID: abc-123                                          │
│ Params: {"id": "999"}                                        │
│                                                              │
│ Stack Trace:                                                 │
│   get_user (src/routes/api/users/get.py:23)                 │
│   Router.dispatch (tina4_python/core/router.py:145)         │
│   Server.handle_request (tina4_python/core/server.py:89)    │
│                                                              │
│ Database Queries:                                            │
│   SELECT * FROM users WHERE id = ? [999] → 0 rows (2ms)    │
└─────────────────────────────────────────────────────────────┘
```

Identical layout across all four frameworks — shared HTML/CSS template.

### Production Error Response

**JSON endpoints** (detected by Accept header or path prefix `/api/`):
```json
{
  "error": "Internal Server Error",
  "request_id": "abc-123",
  "status": 500
}
```

**HTML endpoints:**
Renders a clean, customizable error template from `src/templates/errors/500.html` (or a default if not present).

---

## 3. .broken File System

### How It Works

When an unhandled exception occurs in production:

1. **Catch** the exception in the global handler
2. **Log** it (structured JSON log)
3. **Write** a `.broken` file to the data directory
4. **Respond** with 500 to the client
5. **Health check** reads `.broken` files and reports unhealthy

### .broken File Format
```
data/.broken/
  2026-03-19T103045_abc123_TypeError.broken
  2026-03-19T091500_def456_DatabaseError.broken
```

**File contents (JSON):**
```json
{
  "timestamp": "2026-03-19T10:30:45Z",
  "request_id": "abc-123",
  "error_type": "TypeError",
  "message": "Cannot read property 'email' of null",
  "stack_trace": "get_user (src/routes/api/users/get.py:23)\n...",
  "request": {
    "method": "GET",
    "path": "/api/users/999",
    "params": {"id": "999"},
    "headers": {"User-Agent": "..."},
    "ip": "10.0.0.15"
  },
  "environment": {
    "framework": "tina4py",
    "version": "3.0.0",
    "language": "python 3.12",
    "database": "postgresql",
    "hostname": "web-pod-abc123"
  },
  "resolved": false
}
```

### .broken File Lifecycle

```
Exception occurs
    ↓
.broken file written to data/.broken/
    ↓
Health check returns unhealthy (includes .broken count + latest error)
    ↓
Container orchestrator (K8s, Docker Swarm) detects unhealthy
    ↓
Options:
  a) Auto-restart container (K8s liveness probe)
  b) Stop routing traffic (K8s readiness probe)
  c) Alert on-call (monitoring picks up health check)
    ↓
Developer investigates via:
  - Backend console (Error Log panel)
  - Direct file inspection
  - Log aggregation (structured JSON logs)
    ↓
Developer resolves:
  - Fix code, deploy
  - "Mark resolved" in console (deletes .broken file)
  - Manual: rm data/.broken/*.broken
    ↓
Health check returns healthy again
```

### Health Check Integration

Updated `/health` endpoint:

```json
// Healthy — no .broken files
{
  "status": "ok",
  "database": "connected",
  "uptime_seconds": 3600,
  "version": "3.0.0",
  "framework": "tina4py",
  "errors": 0
}

// Unhealthy — .broken files exist
{
  "status": "error",
  "database": "connected",
  "uptime_seconds": 3600,
  "version": "3.0.0",
  "framework": "tina4py",
  "errors": 2,
  "latest_error": {
    "timestamp": "2026-03-19T10:30:45Z",
    "type": "TypeError",
    "message": "Cannot read property 'email' of null",
    "request_id": "abc-123"
  }
}
```

**HTTP status codes:**
- `200` — healthy (no .broken files)
- `503` — unhealthy (.broken files exist)

### Configuration

```env
TINA4_BROKEN_DIR=data/.broken              # Where .broken files are written (default)
TINA4_BROKEN_THRESHOLD=5                   # Max .broken files before health = 503 (default: 1)
TINA4_BROKEN_AUTO_RESOLVE=3600             # Auto-delete .broken files older than N seconds (0 = never)
TINA4_BROKEN_MAX_FILES=100                 # Max .broken files to keep (oldest pruned, default: 100)
```

### Container-Specific Behavior

**Kubernetes:**
```yaml
livenessProbe:
  httpGet:
    path: /health
    port: 7145
  initialDelaySeconds: 10
  periodSeconds: 30
  failureThreshold: 3      # Restart after 3 consecutive unhealthy

readinessProbe:
  httpGet:
    path: /health
    port: 7145
  periodSeconds: 10
  failureThreshold: 1      # Stop traffic immediately on unhealthy
```

**Docker Compose:**
```yaml
healthcheck:
  test: ["CMD", "curl", "-f", "http://localhost:7145/health"]
  interval: 30s
  timeout: 10s
  retries: 3
  start_period: 10s
```

### What Triggers a .broken File

| Error Type | Creates .broken | Reason |
|-----------|----------------|--------|
| Unhandled exception (500) | Yes | Application bug |
| Database connection lost | Yes | Infrastructure issue |
| Out of memory | Yes (if catchable) | Resource issue |
| Template compilation error | Yes | Code bug |
| Queue worker crash | Yes | Application bug |
| 404 Not Found | No | Normal — missing route |
| 422 Validation Error | No | Normal — bad input |
| 401/403 Auth Error | No | Normal — unauthorized |
| Rate limited (429) | No | Normal — expected |
| Timeout | Yes | Infrastructure/code issue |
| SIGTERM/SIGINT | No | Normal — graceful shutdown |

### Repeated Error Deduplication

Same error type + same location = ONE .broken file (updated with count):

```json
{
  "error_type": "TypeError",
  "message": "Cannot read property 'email' of null",
  "location": "src/routes/api/users/get.py:23",
  "first_seen": "2026-03-19T10:30:45Z",
  "last_seen": "2026-03-19T10:45:12Z",
  "occurrence_count": 15,
  "request_ids": ["abc-123", "def-456", "..."],
  "resolved": false
}
```

This prevents disk flooding from repeated errors.

---

## 4. Testing Requirements

### Positive Tests
1. `test_global_handler_catches_exception` — unhandled exception returns 500, not crash
2. `test_dev_mode_shows_stack_trace` — debug=true shows full trace in HTML
3. `test_prod_mode_hides_stack_trace` — debug=false shows generic error
4. `test_broken_file_created_on_500` — .broken file written on unhandled exception
5. `test_broken_file_format` — .broken file contains valid JSON with all fields
6. `test_health_check_reads_broken` — /health returns 503 when .broken files exist
7. `test_health_check_healthy` — /health returns 200 when no .broken files
8. `test_broken_deduplication` — same error updates count, doesn't create new file
9. `test_broken_auto_resolve` — old .broken files are auto-deleted after timeout
10. `test_broken_max_files` — oldest .broken files pruned when max exceeded
11. `test_console_requires_token` — /tina4/console returns 401 without token
12. `test_console_queue_view` — queue panel shows correct counts
13. `test_console_retry_message` — retry from console moves message back to pending
14. `test_404_no_broken_file` — 404 does NOT create .broken file
15. `test_422_no_broken_file` — validation error does NOT create .broken file
16. `test_json_error_for_api` — API endpoints get JSON error, not HTML

### Negative Tests
1. `test_broken_dir_not_writable` — graceful handling if dir is read-only (log warning, don't crash)
2. `test_console_invalid_token` — wrong token returns 401
3. `test_broken_file_corrupt` — health check handles malformed .broken file
4. `test_exception_in_exception_handler` — handler itself failing doesn't crash the server
