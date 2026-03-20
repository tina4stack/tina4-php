# Logging

The `Log` class provides structured logging with JSON lines output, log rotation (by size and date), request ID correlation, and color-coded terminal output in development mode. It is zero-dependency and uses only PHP built-in functions.

Logging is configured automatically by the `App` class, but can also be set up manually for standalone use.

## Log Levels

```php
use Tina4\Log;

Log::debug('Variable value', ['count' => 42]);
Log::info('User logged in', ['user_id' => 1]);
Log::warning('Deprecated endpoint called', ['path' => '/old-api']);
Log::error('Database connection failed', ['host' => 'db.example.com']);
```

The four levels, from lowest to highest priority:
- `DEBUG` — detailed development information
- `INFO` — normal operational events
- `WARNING` — unexpected but non-critical issues
- `ERROR` — failures requiring attention

## Configuration

```php
Log::configure(
    logDir: 'logs',           // Directory for log files
    development: true,         // Human-readable format + stdout
    minLevel: Log::LEVEL_INFO, // Suppress DEBUG messages
);
```

In **development mode**, logs are:
- Written to both file and stdout
- Formatted in human-readable form with timestamps
- Color-coded in the terminal (cyan=DEBUG, green=INFO, yellow=WARNING, red=ERROR)

In **production mode**, logs are:
- Written only to file
- Formatted as JSON lines (structured logging)
- Suitable for log aggregation tools (ELK, Datadog, etc.)

## JSON Log Output

Production logs are written as single-line JSON:

```json
{"timestamp":"2026-03-20T10:30:45.123Z","level":"INFO","message":"User logged in","request_id":"a1b2c3d4e5f6a7b8","context":{"user_id":42}}
```

Human-readable (development) format:

```
2026-03-20T10:30:45.123Z [INFO   ] [a1b2c3d4e5f6a7b8] User logged in {"user_id":42}
```

## Request ID Correlation

Every request gets a unique 16-character hex ID for correlating log entries across a single request.

```php
Log::setRequestId('custom-request-id');

// Or let the App class generate one automatically
$requestId = Log::getRequestId();
```

All log entries include the current request ID, making it easy to trace a request through multiple log lines.

## Log Rotation

Logs rotate automatically:
- **By size:** When the log file exceeds 10 MB, it is rotated to `tina4.YYYY-MM-DD_HHmmss.log`
- **By date:** When the log file is from a previous day, it is rotated to `tina4.YYYY-MM-DD.log`

Rotated files are never overwritten — a counter suffix is added if a collision occurs.

## Contextual Logging

Pass an associative array as the second argument for structured context.

```php
Log::info('Order placed', [
    'order_id' => 12345,
    'total' => 99.99,
    'items' => 3,
    'customer_id' => 42,
]);

Log::error('Payment failed', [
    'order_id' => 12345,
    'gateway' => 'stripe',
    'error_code' => 'card_declined',
]);

Log::warning('Slow query detected', [
    'sql' => 'SELECT * FROM large_table',
    'duration_ms' => 2500,
]);
```

## Using in Route Handlers

```php
use Tina4\Router;
use Tina4\Request;
use Tina4\Response;
use Tina4\Log;

Router::post('/api/orders', function (Request $request, Response $response) {
    try {
        Log::info('Creating order', ['body' => $request->body]);

        // Business logic...

        Log::info('Order created successfully', ['order_id' => 123]);
        return $response->json(['order_id' => 123], 201);
    } catch (\Throwable $e) {
        Log::error('Order creation failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return $response->json(['error' => 'Internal server error'], 500);
    }
});
```

## Environment-Driven Configuration

The `App` class reads `TINA4_DEBUG` from `.env` to configure logging:

```bash
# .env
TINA4_DEBUG=true    # Enables development mode logging
```

```php
use Tina4\App;

// Development mode is auto-detected from TINA4_DEBUG env var
$app = new App(basePath: '.');

// Or explicitly
$app = new App(basePath: '.', development: true);
```

## Minimum Log Level

Filter out low-priority messages:

```php
// Only log WARNING and ERROR
Log::configure(
    logDir: 'logs',
    minLevel: Log::LEVEL_WARNING,
);

Log::debug('This is ignored');   // Below minimum level
Log::info('This is ignored');    // Below minimum level
Log::warning('This is logged');  // Meets minimum level
Log::error('This is logged');    // Above minimum level
```

## Testing

```php
// Reset logger state between tests
Log::reset();
```

## Tips

- Use structured context arrays instead of string interpolation — it is easier to parse programmatically.
- Always include relevant identifiers in context: `user_id`, `order_id`, `request_id`.
- Use `Log::error()` with catch blocks — include the exception message and stack trace.
- In production, pipe JSON log output to a log aggregation service.
- The log directory is created automatically if it does not exist.
- Log rotation prevents disk exhaustion — no manual cleanup needed.
