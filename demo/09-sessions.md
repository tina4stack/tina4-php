# Sessions

The `Session` class provides server-side session management with pluggable backends (file or Redis). Sessions support standard get/set/delete operations, flash data (one-time reads), session regeneration, and automatic TTL expiration.

Configuration is driven by environment variables, making it easy to switch backends between development and production.

## Basic Usage

```php
use Tina4\Session;

// File-based session (default)
$session = new Session();
$sessionId = $session->start();

// Set values
$session->set('user_id', 42);
$session->set('role', 'admin');

// Get values
$userId = $session->get('user_id');          // 42
$role = $session->get('role');               // "admin"
$missing = $session->get('missing', 'default'); // "default"

// Check existence
if ($session->has('user_id')) {
    echo "User is logged in";
}

// Delete a key
$session->delete('role');

// Get all data (excludes metadata and flash data)
$allData = $session->all();

// Get session ID
echo $session->getSessionId();

// Check if started
echo $session->isStarted(); // true
```

## Resuming a Session

```php
// Resume an existing session by ID
$session = new Session();
$session->start('existing-session-id-here');

$userId = $session->get('user_id');
```

## Flash Data

Flash data is available for a single read, then automatically deleted. Useful for success/error messages after redirects.

```php
// Set flash data
$session->flash('success', 'Your changes have been saved.');
$session->flash('errors', ['name' => 'Name is required']);

// Read flash data (one-time — removed after read)
$success = $session->getFlash('success');       // "Your changes have been saved."
$success = $session->getFlash('success');       // null (already consumed)

// With default
$warning = $session->getFlash('warning', 'No warnings'); // "No warnings"
```

## Session Regeneration

Regenerate the session ID while preserving data. Use this after login to prevent session fixation attacks.

```php
$session = new Session();
$session->start();
$session->set('user_id', 42);

$oldId = $session->getSessionId();
$newId = $session->regenerate();

echo $oldId !== $newId; // true
echo $session->get('user_id'); // 42 (data preserved)
```

## Destroying Sessions

```php
$session->destroy(); // Removes all data and storage

echo $session->isStarted(); // false
```

## File Backend

The file backend stores sessions as JSON files in a configurable directory.

```php
// Using environment variables
// TINA4_SESSION_BACKEND=file
// TINA4_SESSION_PATH=data/sessions
// TINA4_SESSION_TTL=3600

$session = new Session('file');

// Or with explicit configuration
$session = new Session('file', [
    'path' => '/tmp/sessions',
    'ttl' => 7200, // 2 hours
]);
```

Sessions are stored as `{session_id}.json` files. Expired sessions are detected on load and automatically discarded.

## Redis Backend

The Redis backend stores sessions in Redis with automatic TTL via `SETEX`.

```php
// Using environment variables
// TINA4_SESSION_BACKEND=redis
// TINA4_SESSION_REDIS_URL=tcp://127.0.0.1:6379
// TINA4_SESSION_TTL=3600

$session = new Session('redis');

// Or with explicit configuration
$session = new Session('redis', [
    'redis_url' => 'tcp://redis:6379',
    'ttl' => 3600,
]);

// Redis with password
$session = new Session('redis', [
    'redis_url' => 'tcp://:password@redis-host:6379',
]);
```

Redis keys are prefixed with `tina4:session:`.

**Requirement:** The Redis backend requires PHP's `redis` extension (`ext-redis`).

## Session in Routes

```php
use Tina4\Router;
use Tina4\Request;
use Tina4\Response;
use Tina4\Session;

$session = new Session();

Router::post('/login', function (Request $request, Response $response) use ($session) {
    $sessionId = $session->start();
    $session->set('user_id', 42);
    $session->set('logged_in_at', date('c'));

    return $response
        ->cookie('session_id', $sessionId, ['httponly' => true, 'secure' => true])
        ->json(['message' => 'Logged in']);
});

Router::get('/dashboard', function (Request $request, Response $response) use ($session) {
    // Extract session ID from cookie (would come from request in real app)
    $sessionId = 'from-cookie';
    $session->start($sessionId);

    if (!$session->has('user_id')) {
        return $response->json(['error' => 'Not authenticated'], 401);
    }

    return $response->json(['user_id' => $session->get('user_id')]);
});

Router::post('/logout', function (Request $request, Response $response) use ($session) {
    $session->destroy();
    return $response->json(['message' => 'Logged out']);
});
```

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `TINA4_SESSION_BACKEND` | `file` | Backend: `file` or `redis` |
| `TINA4_SESSION_PATH` | `data/sessions` | File backend storage path |
| `TINA4_SESSION_TTL` | `3600` | Session lifetime in seconds |
| `TINA4_SESSION_REDIS_URL` | `tcp://127.0.0.1:6379` | Redis connection URL |

## Tips

- Call `regenerate()` after login to prevent session fixation attacks.
- Use flash data for post-redirect-get patterns (form submissions, notifications).
- The file backend is great for single-server deployments. Switch to Redis for multi-server or containerized environments.
- Session metadata (`_meta.created_at`, `_meta.last_accessed`) is tracked internally and excluded from `all()`.
- Always set the `httponly` and `secure` flags on session cookies in production.
