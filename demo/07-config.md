# Configuration

Tina4 v3 uses the `App` class as the main application entry point and the `DotEnv` class for environment variable management. The App class wires together configuration, routing, middleware, logging, and lifecycle management. All configuration is driven by environment variables in a `.env` file.

## App Class

The `App` class bootstraps the application, loads the `.env` file, configures logging, and registers signal handlers for graceful shutdown.

```php
use Tina4\App;

// Development mode — enables verbose logging and stdout output
$app = new App(basePath: '.', development: true);

// Production mode
$app = new App(basePath: '/var/www/myapp');

// The app automatically:
// 1. Loads .env from basePath
// 2. Configures the Log class (dev mode = human-readable + stdout)
// 3. Generates a unique request ID
// 4. Registers a /health endpoint
// 5. Sets up SIGTERM/SIGINT handlers for graceful shutdown
```

### Health Check

A `/health` endpoint is registered automatically and returns application status.

```php
$data = $app->getHealthData();
// [
//   'status' => 'ok',
//   'version' => '3.0.0',
//   'uptime' => 42.15,  // seconds
// ]
```

### Lifecycle Management

```php
$app->start(); // Mark as running

$app->onShutdown(function () {
    // Clean up database connections, flush caches, etc.
    echo "Shutting down...\n";
});

$app->shutdown(); // Triggers all shutdown callbacks

echo $app->isRunning(); // false after shutdown
```

## DotEnv — Environment Variables

The `DotEnv` class loads `.env` files into `$_ENV`, `$_SERVER`, and `putenv()`.

### .env File Format

```bash
# Database
DATABASE_URL=sqlite:///data/app.db
TINA4_AUTOCOMMIT=false

# Authentication
JWT_SECRET=my-super-secret-key-change-in-production
JWT_EXPIRES=3600

# Debug
TINA4_DEBUG=true

# CORS
TINA4_CORS_ORIGINS=https://myapp.com
TINA4_CORS_METHODS=GET,POST,PUT,DELETE,OPTIONS
TINA4_CORS_HEADERS=Content-Type,Authorization

# Rate Limiting
TINA4_RATE_LIMIT=60
TINA4_RATE_WINDOW=60

# Sessions
TINA4_SESSION_BACKEND=file
TINA4_SESSION_PATH=data/sessions
TINA4_SESSION_TTL=3600

# Queue
TINA4_QUEUE_BACKEND=file
TINA4_QUEUE_PATH=data/queue

# Services
TINA4_SERVICE_DIR=src/services
TINA4_SERVICE_SLEEP=5

# Internationalization
TINA4_LOCALE=en
TINA4_LOCALE_DIR=src/locales

# WebSocket
TINA4_WS_PORT=8080
```

### Supported Syntax

```bash
# Comments
KEY=value                          # Simple values
KEY="double quoted"                # Double-quoted (supports \n, \t, \\, \")
KEY='single quoted'                # Single-quoted (literal, no escaping)
export KEY=value                   # Export prefix (stripped automatically)
KEY=value # inline comment         # Inline comments with space-hash
KEY="${OTHER_VAR}/path"            # Variable interpolation in double quotes
KEY=${OTHER_VAR}/path              # Variable interpolation in unquoted values
[Section Headers]                  # Section headers (ignored)
```

### Loading and Accessing

```php
use Tina4\DotEnv;

// Load .env file (called automatically by App)
DotEnv::loadEnv('.env');

// Load with overwrite (replaces existing env vars)
DotEnv::loadEnv('.env.local', overwrite: true);

// Get a value with default
$debug = DotEnv::getEnv('TINA4_DEBUG', 'false');
$port = DotEnv::getEnv('APP_PORT', '8000');

// Require a value (throws RuntimeException if missing)
$secret = DotEnv::requireEnv('JWT_SECRET');

// Check existence
if (DotEnv::hasEnv('DATABASE_URL')) {
    $dbUrl = DotEnv::getEnv('DATABASE_URL');
}

// Get all loaded variables
$allVars = DotEnv::allEnv();
```

## Key Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `TINA4_DEBUG` | `false` | Enable debug mode (verbose logging, no template caching) |
| `TINA4_AUTOCOMMIT` | `false` | Auto-commit database transactions |
| `DATABASE_URL` | — | Database connection string |
| `JWT_SECRET` | — | Secret key for JWT token signing |
| `TINA4_CORS_ORIGINS` | `*` | Allowed CORS origins (comma-separated) |
| `TINA4_CORS_METHODS` | `GET,POST,...` | Allowed CORS methods |
| `TINA4_RATE_LIMIT` | `60` | Max requests per window |
| `TINA4_RATE_WINDOW` | `60` | Rate limit window in seconds |
| `TINA4_SESSION_BACKEND` | `file` | Session backend: `file` or `redis` |
| `TINA4_SESSION_TTL` | `3600` | Session lifetime in seconds |
| `TINA4_SESSION_REDIS_URL` | `tcp://127.0.0.1:6379` | Redis connection URL |
| `TINA4_LOCALE` | `en` | Default locale code |

## Bootstrap Pattern (index.php)

The standard entry point for a Tina4 v3 application:

```php
<?php
require_once "./vendor/autoload.php";

use Tina4\App;
use Tina4\Database\SQLite3Adapter;
use Tina4\DatabaseUrl;

// Load environment
$app = new App(basePath: '.', development: true);

// Set up database
$dbUrl = DatabaseUrl::fromEnv('DATABASE_URL');
if ($dbUrl !== null) {
    $db = new SQLite3Adapter($dbUrl->database);
} else {
    $db = new SQLite3Adapter('app.db');
}

// Discover and register routes
\Tina4\RouteDiscovery::scan('src/routes');

// Run migrations
$migration = new \Tina4\Migration($db, 'src/migrations');
$migration->migrate();

// Start the app
$app->start();
```

## Tips

- Never commit `.env` to version control. Add it to `.gitignore` and provide a `.env.example`.
- Use `DotEnv::requireEnv()` for values that must be set (secrets, database URLs) — it fails fast with a clear error.
- The `App` class detects debug mode from both the constructor flag and `TINA4_DEBUG` env var.
- Signal handlers (SIGTERM/SIGINT) are only registered if `pcntl_signal` is available.
- Use `.env.local` with `overwrite: true` for developer-specific overrides.
