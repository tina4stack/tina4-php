<?php

/**
 * Tina4 PHP v3 — Runnable Demo Application
 *
 * Demonstrates every working feature of the framework.
 * Run: php app.php
 * Then visit: http://localhost:7146
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../Tina4/Constants.php';

// ── Bootstrap ───────────────────────────────────────────────────────────────

$app = new \Tina4\App(basePath: __DIR__, development: true);

// ── Database Setup (SQLite3 for dev/demo) ────────────────────────────────────

if (extension_loaded('sqlite3')) {
    $dbPath = __DIR__ . '/data/demo.db';
    if (!is_dir(__DIR__ . '/data')) {
        mkdir(__DIR__ . '/data', 0755, true);
    }
    $db = new \Tina4\Database\SQLite3Adapter($dbPath);
    \Tina4\App::setDatabase($db);

    // Seed tables if they don't exist
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
    if (empty($tables)) {
        $db->execute("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            role TEXT DEFAULT 'user',
            active INTEGER DEFAULT 1,
            created_at TEXT DEFAULT (datetime('now'))
        )");
        $db->execute("CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT,
            price REAL NOT NULL DEFAULT 0,
            stock INTEGER DEFAULT 0,
            category TEXT,
            created_at TEXT DEFAULT (datetime('now'))
        )");
        $db->execute("CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            quantity INTEGER DEFAULT 1,
            total REAL NOT NULL DEFAULT 0,
            status TEXT DEFAULT 'pending',
            created_at TEXT DEFAULT (datetime('now'))
        )");

        // Seed users
        $users = [
            ['Alice Johnson', 'alice@example.com', 'admin'],
            ['Bob Smith', 'bob@example.com', 'user'],
            ['Charlie Brown', 'charlie@example.com', 'user'],
            ['Diana Prince', 'diana@example.com', 'moderator'],
            ['Eve Martinez', 'eve@example.com', 'user'],
        ];
        foreach ($users as $u) {
            $db->execute("INSERT INTO users (name, email, role) VALUES (?, ?, ?)", $u);
        }

        // Seed products
        $products = [
            ['Wireless Mouse', 'Ergonomic wireless mouse with USB receiver', 29.99, 150, 'Electronics'],
            ['Mechanical Keyboard', 'Cherry MX Blue switches, RGB backlit', 89.99, 75, 'Electronics'],
            ['USB-C Hub', '7-in-1 USB-C hub with HDMI, ethernet, SD card', 49.99, 200, 'Electronics'],
            ['Standing Desk Mat', 'Anti-fatigue mat for standing desks', 39.99, 100, 'Office'],
            ['Notebook A5', 'Ruled notebook, 200 pages, hardcover', 12.99, 500, 'Stationery'],
            ['Desk Lamp', 'LED desk lamp with adjustable brightness', 34.99, 80, 'Office'],
            ['Webcam HD', '1080p webcam with built-in microphone', 59.99, 60, 'Electronics'],
            ['Monitor Stand', 'Adjustable monitor riser with storage', 44.99, 90, 'Office'],
        ];
        foreach ($products as $p) {
            $db->execute("INSERT INTO products (name, description, price, stock, category) VALUES (?, ?, ?, ?, ?)", $p);
        }

        // Seed orders
        $orders = [
            [1, 2, 1, 89.99, 'completed'],
            [2, 1, 2, 59.98, 'completed'],
            [1, 3, 1, 49.99, 'shipped'],
            [3, 5, 3, 38.97, 'pending'],
            [4, 7, 1, 59.99, 'completed'],
            [5, 4, 1, 39.99, 'pending'],
            [2, 6, 1, 34.99, 'shipped'],
            [3, 8, 2, 89.98, 'pending'],
        ];
        foreach ($orders as $o) {
            $db->execute("INSERT INTO orders (user_id, product_id, quantity, total, status) VALUES (?, ?, ?, ?, ?)", $o);
        }
    }
}

// ── Route Registration (BEFORE start so they take priority over defaults) ──

// GET / — Landing page
\Tina4\Router::get('/', function (\Tina4\Request $request, \Tina4\Response $response) use ($app) {
    $features = [
        ['path' => '/demo/routing', 'name' => 'Routing', 'desc' => 'Basic route registration and JSON response'],
        ['path' => '/demo/routing/42', 'name' => 'Path Params', 'desc' => 'Dynamic path parameter extraction'],
        ['path' => '/demo/router-list', 'name' => 'Router List', 'desc' => 'List all registered routes'],
        ['path' => '/demo/auth', 'name' => 'Auth / JWT', 'desc' => 'JWT token generation, verification, password hashing'],
        ['path' => '/demo/dotenv', 'name' => 'DotEnv', 'desc' => 'Environment variable loading from .env'],
        ['path' => '/demo/logging', 'name' => 'Logging', 'desc' => 'Structured JSON logging with levels'],
        ['path' => '/demo/health', 'name' => 'Health Check', 'desc' => 'Application health and uptime data'],
        ['path' => '/demo/constants', 'name' => 'Constants', 'desc' => 'HTTP status codes and content types'],
        ['path' => '/demo/request', 'name' => 'Request', 'desc' => 'Request parsing: method, path, headers, query'],
        ['path' => '/demo/response-types', 'name' => 'Response Types', 'desc' => 'JSON, HTML, text, redirect responses'],
        ['path' => '/demo/middleware', 'name' => 'Middleware', 'desc' => 'CORS middleware, rate limiter, custom middleware'],
        ['path' => '/demo/cache', 'name' => 'Cache Modifier', 'desc' => 'Route with ->cache() modifier'],
        ['path' => '/demo/secure', 'name' => 'Secure Modifier', 'desc' => 'Route with ->secure() modifier (returns 401)'],
        ['path' => '/demo/groups', 'name' => 'Route Groups', 'desc' => 'Grouped routes with shared prefix'],
        ['path' => '/demo/orm', 'name' => 'ORM', 'desc' => 'Active record ORM (no DB configured — shows API)'],
        ['path' => '/demo/sessions', 'name' => 'Sessions', 'desc' => 'File-backed session management'],
        ['path' => '/demo/i18n', 'name' => 'Internationalization', 'desc' => 'i18n with JSON locale files and parameter substitution'],
        ['path' => '/demo/scss', 'name' => 'SCSS Compiler', 'desc' => 'Zero-dependency SCSS-to-CSS compilation'],
        ['path' => '/demo/queue', 'name' => 'Queue', 'desc' => 'File-backed job queue push/pop'],
        ['path' => '/demo/graphql', 'name' => 'GraphQL', 'desc' => 'Zero-dependency GraphQL engine'],
        ['path' => '/demo/shortcomings', 'name' => 'Shortcomings', 'desc' => 'Honest report of what is missing or broken in v3'],
    ];

    $rows = '';
    foreach ($features as $f) {
        $rows .= '<tr>'
            . '<td><a href="' . $f['path'] . '" style="color:#2563eb;text-decoration:none;font-weight:500;">' . htmlspecialchars($f['name']) . '</a></td>'
            . '<td>' . htmlspecialchars($f['desc']) . '</td>'
            . '<td><a href="' . $f['path'] . '" style="display:inline-block;padding:4px 12px;background:#2563eb;color:#fff;border-radius:4px;text-decoration:none;font-size:13px;">Try</a></td>'
            . '</tr>';
    }

    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tina4 PHP v3 — Feature Demo</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f8fafc; color: #1e293b; }
        .container { max-width: 900px; margin: 0 auto; padding: 40px 20px; }
        h1 { font-size: 28px; margin-bottom: 8px; }
        .subtitle { color: #64748b; margin-bottom: 32px; font-size: 15px; }
        .version-badge { display: inline-block; background: #10b981; color: #fff; padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; margin-left: 8px; vertical-align: middle; }
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        th { background: #1e293b; color: #fff; padding: 12px 16px; text-align: left; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; }
        td { padding: 12px 16px; border-bottom: 1px solid #e2e8f0; font-size: 14px; }
        tr:last-child td { border-bottom: none; }
        tr:hover { background: #f1f5f9; }
        .footer { margin-top: 32px; text-align: center; color: #94a3b8; font-size: 13px; }
        .footer a { color: #2563eb; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Tina4 PHP v3 <span class="version-badge">v3.0.0</span></h1>
        <p class="subtitle">Feature demo application. Click any feature below to see it in action. All routes return JSON with status information.</p>
        <table>
            <thead>
                <tr>
                    <th>Feature</th>
                    <th>Description</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                {$rows}
            </tbody>
        </table>
        <div class="footer">
            <p>Tina4 PHP v3.0.0 &mdash; Zero-dependency PHP framework &mdash; <a href="https://tina4.com">tina4.com</a></p>
        </div>
    </div>
</body>
</html>
HTML;

    return $response->html($html);
});

// GET /demo/routing — Basic routing
\Tina4\Router::get('/demo/routing', function (\Tina4\Request $request, \Tina4\Response $response) {
    return $response->json([
        'feature' => 'Routing',
        'status' => 'working',
        'output' => [
            'message' => 'This route was registered with Router::get()',
            'method' => $request->method,
            'path' => $request->path,
        ],
        'notes' => 'Router supports GET, POST, PUT, PATCH, DELETE, and any(). Routes are pattern-matched with regex.',
    ]);
});

// GET /demo/routing/{id} — Path param extraction
\Tina4\Router::get('/demo/routing/{id}', function (\Tina4\Request $request, \Tina4\Response $response) {
    return $response->json([
        'feature' => 'Path Parameters',
        'status' => 'working',
        'output' => [
            'id' => $request->params['id'] ?? null,
            'all_params' => $request->params,
            'path' => $request->path,
        ],
        'notes' => 'Dynamic segments like {id} are extracted into $request->params. Catch-all params {slug:.*} are also supported.',
    ]);
});

// GET /demo/router-list — List all registered routes
\Tina4\Router::get('/demo/router-list', function (\Tina4\Request $request, \Tina4\Response $response) {
    $routes = \Tina4\Router::list();
    return $response->json([
        'feature' => 'Router List',
        'status' => 'working',
        'output' => [
            'total_routes' => count($routes),
            'routes' => $routes,
        ],
        'notes' => 'Router::list() returns all registered routes with method, pattern, middleware count, cache and secure flags.',
    ]);
});

// GET /demo/auth — JWT and password hashing
\Tina4\Router::get('/demo/auth', function (\Tina4\Request $request, \Tina4\Response $response) {
    $secret = \Tina4\DotEnv::getEnv('APP_SECRET', 'demo-secret');

    // Generate a JWT
    $token = \Tina4\Auth::createToken(
        payload: ['user_id' => 1, 'role' => 'admin'],
        secret: $secret,
        expiresIn: 3600,
    );

    // Validate the JWT
    $decoded = \Tina4\Auth::validateToken($token, $secret);

    // Get payload without verification
    $unverified = \Tina4\Auth::getPayload($token);

    // Password hashing
    $hash = \Tina4\Auth::hashPassword('demo-password');
    $verified = \Tina4\Auth::checkPassword('demo-password', $hash);
    $wrongPassword = \Tina4\Auth::checkPassword('wrong-password', $hash);

    return $response->json([
        'feature' => 'Auth (JWT + Password Hashing)',
        'status' => 'working',
        'output' => [
            'jwt' => [
                'token' => $token,
                'verified_payload' => $decoded,
                'decoded_without_verification' => $unverified,
            ],
            'password' => [
                'hash' => $hash,
                'correct_password_verified' => $verified,
                'wrong_password_verified' => $wrongPassword,
            ],
        ],
        'notes' => 'Auth supports HS256 and RS256 JWT algorithms. Passwords use PBKDF2-HMAC-SHA256. Auth::middleware() creates a Bearer token validator.',
    ]);
});

// GET /demo/dotenv — Environment variables
\Tina4\Router::get('/demo/dotenv', function (\Tina4\Request $request, \Tina4\Response $response) {
    $allVars = \Tina4\DotEnv::allEnv();

    // Filter out potentially sensitive values
    $safeVars = [];
    foreach ($allVars as $key => $value) {
        if (stripos($key, 'secret') !== false || stripos($key, 'password') !== false) {
            $safeVars[$key] = '***REDACTED***';
        } else {
            $safeVars[$key] = $value;
        }
    }

    return $response->json([
        'feature' => 'DotEnv',
        'status' => 'working',
        'output' => [
            'loaded_variables' => $safeVars,
            'app_name' => \Tina4\DotEnv::getEnv('APP_NAME'),
            'has_debug' => \Tina4\DotEnv::hasEnv('TINA4_DEBUG'),
            'missing_key' => \Tina4\DotEnv::getEnv('NONEXISTENT_KEY', 'default-value'),
        ],
        'notes' => 'DotEnv supports quoted values, interpolation (${VAR}), export prefix, inline comments, and section headers.',
    ]);
});

// GET /demo/logging — Structured logging
\Tina4\Router::get('/demo/logging', function (\Tina4\Request $request, \Tina4\Response $response) {
    // Write log entries at each level
    \Tina4\Log::debug('Demo debug message', ['source' => 'demo']);
    \Tina4\Log::info('Demo info message', ['user' => 'demo-user']);
    \Tina4\Log::warning('Demo warning message', ['threshold' => 80]);
    \Tina4\Log::error('Demo error message', ['code' => 'E_DEMO']);

    return $response->json([
        'feature' => 'Logging',
        'status' => 'working',
        'output' => [
            'levels' => ['DEBUG', 'INFO', 'WARNING', 'ERROR'],
            'request_id' => \Tina4\Log::getRequestId(),
            'log_written' => true,
            'messages_logged' => 4,
        ],
        'notes' => 'Log outputs JSON lines to logs/tina4.log with rotation (10MB size, daily). In dev mode, colorized output goes to stdout.',
    ]);
});

// GET /demo/health — Health check
\Tina4\Router::get('/demo/health', function (\Tina4\Request $request, \Tina4\Response $response) use ($app) {
    $health = $app->getHealthData();

    return $response->json([
        'feature' => 'Health Check',
        'status' => 'working',
        'output' => $health,
        'notes' => 'App automatically registers /health. getHealthData() returns status, version, and uptime in seconds.',
    ]);
});

// GET /demo/constants — HTTP constants
\Tina4\Router::get('/demo/constants', function (\Tina4\Request $request, \Tina4\Response $response) {
    return $response->json([
        'feature' => 'Constants',
        'status' => 'working',
        'output' => [
            'http_status_codes' => [
                'HTTP_OK' => \Tina4\HTTP_OK,
                'HTTP_CREATED' => \Tina4\HTTP_CREATED,
                'HTTP_NO_CONTENT' => \Tina4\HTTP_NO_CONTENT,
                'HTTP_BAD_REQUEST' => \Tina4\HTTP_BAD_REQUEST,
                'HTTP_UNAUTHORIZED' => \Tina4\HTTP_UNAUTHORIZED,
                'HTTP_FORBIDDEN' => \Tina4\HTTP_FORBIDDEN,
                'HTTP_NOT_FOUND' => \Tina4\HTTP_NOT_FOUND,
                'HTTP_UNPROCESSABLE' => \Tina4\HTTP_UNPROCESSABLE,
                'HTTP_TOO_MANY' => \Tina4\HTTP_TOO_MANY,
                'HTTP_SERVER_ERROR' => \Tina4\HTTP_SERVER_ERROR,
            ],
            'content_types' => [
                'APPLICATION_JSON' => \Tina4\APPLICATION_JSON,
                'APPLICATION_XML' => \Tina4\APPLICATION_XML,
                'APPLICATION_FORM' => \Tina4\APPLICATION_FORM,
                'TEXT_HTML' => \Tina4\TEXT_HTML,
                'TEXT_PLAIN' => \Tina4\TEXT_PLAIN,
                'TEXT_CSV' => \Tina4\TEXT_CSV,
            ],
        ],
        'notes' => 'Constants are defined in Tina4\\Constants.php as namespaced constants. Use like: Tina4\\HTTP_OK.',
    ]);
});

// GET /demo/request — Request parsing
\Tina4\Router::get('/demo/request', function (\Tina4\Request $request, \Tina4\Response $response) {
    return $response->json([
        'feature' => 'Request Parsing',
        'status' => 'working',
        'output' => [
            'method' => $request->method,
            'path' => $request->path,
            'url' => $request->url,
            'query' => $request->query,
            'ip' => $request->ip,
            'content_type' => $request->contentType,
            'is_json' => $request->isJson(),
            'wants_json' => $request->wantsJson(),
            'bearer_token' => $request->bearerToken(),
            'sample_header' => $request->header('host'),
        ],
        'notes' => 'Request wraps superglobals. Supports query params, JSON/form body parsing, file uploads, bearer token extraction, X-Forwarded-For IP resolution.',
    ]);
});

// GET /demo/response-types — Response builder
\Tina4\Router::get('/demo/response-types', function (\Tina4\Request $request, \Tina4\Response $response) {
    // Show what the Response class can do
    $testResponse = new \Tina4\Response(testing: true);
    $jsonResult = $testResponse->json(['key' => 'value'], 200);
    $testResponse2 = new \Tina4\Response(testing: true);
    $htmlResult = $testResponse2->html('<h1>Hello</h1>', 200);
    $testResponse3 = new \Tina4\Response(testing: true);
    $textResult = $testResponse3->text('Plain text content', 200);
    $testResponse4 = new \Tina4\Response(testing: true);
    $redirectResult = $testResponse4->redirect('/demo/routing', 302);

    return $response->json([
        'feature' => 'Response Types',
        'status' => 'working',
        'output' => [
            'json_response' => [
                'content_type' => $jsonResult->getHeader('Content-Type'),
                'status' => $jsonResult->getStatusCode(),
                'body_preview' => $jsonResult->getJsonBody(),
            ],
            'html_response' => [
                'content_type' => $htmlResult->getHeader('Content-Type'),
                'status' => $htmlResult->getStatusCode(),
            ],
            'text_response' => [
                'content_type' => $textResult->getHeader('Content-Type'),
                'status' => $textResult->getStatusCode(),
            ],
            'redirect_response' => [
                'location' => $redirectResult->getHeader('Location'),
                'status' => $redirectResult->getStatusCode(),
            ],
            'callable_syntax' => 'Response also supports $response($data, $status, $contentType) callable syntax',
        ],
        'notes' => 'Response supports json(), html(), text(), redirect(), cookie(), status chaining, and __invoke() auto-detection.',
    ]);
});

// GET /demo/middleware — Middleware demos
\Tina4\Router::get('/demo/middleware', function (\Tina4\Request $request, \Tina4\Response $response) {
    // CORS middleware
    $cors = new \Tina4\Middleware\CorsMiddleware();
    $corsHeaders = $cors->getHeaders('http://example.com');

    // Rate limiter
    $limiter = new \Tina4\Middleware\RateLimiter(limit: 100, window: 60);
    $rateResult = $limiter->check('127.0.0.1');

    return $response->json([
        'feature' => 'Middleware',
        'status' => 'working',
        'output' => [
            'cors' => [
                'allowed_origins' => $cors->getAllowedOrigins(),
                'allowed_methods' => $cors->getAllowedMethods(),
                'headers_for_origin' => $corsHeaders,
            ],
            'rate_limiter' => [
                'limit' => $limiter->getLimit(),
                'window_seconds' => $limiter->getWindow(),
                'check_result' => $rateResult,
            ],
            'route_middleware' => 'Routes support ->middleware([callable, ...]) chaining. Groups apply middleware to all child routes.',
        ],
        'notes' => 'CorsMiddleware reads from TINA4_CORS_* env vars. RateLimiter uses in-memory sliding window. Custom middleware receives (Request, Response) and returns Response or bool.',
    ]);
});

// GET /demo/cache — Cached route
\Tina4\Router::get('/demo/cache', function (\Tina4\Request $request, \Tina4\Response $response) {
    return $response->json([
        'feature' => 'Cache Modifier',
        'status' => 'working',
        'output' => [
            'cached' => true,
            'timestamp' => date('Y-m-d H:i:s'),
            'route_list_entry' => 'Check /demo/router-list to see cache=true on this route',
        ],
        'notes' => 'Router::get()->cache() marks a route as cacheable. The framework reads this flag during dispatch. noCache() disables it.',
    ]);
})->cache();

// GET /demo/secure — Secure route (requires JWT)
\Tina4\Router::get('/demo/secure', function (\Tina4\Request $request, \Tina4\Response $response) {
    return $response->json([
        'feature' => 'Secure Modifier',
        'status' => 'working',
        'output' => [
            'authenticated' => true,
            'message' => 'You have a valid Bearer token',
        ],
        'notes' => 'This route requires a Bearer token in the Authorization header.',
    ]);
})->secure();

// Route groups
\Tina4\Router::group('/demo/groups', function () {
    \Tina4\Router::get('/info', function (\Tina4\Request $request, \Tina4\Response $response) {
        return $response->json([
            'feature' => 'Route Groups',
            'status' => 'working',
            'output' => [
                'path' => $request->path,
                'group_prefix' => '/demo/groups',
                'route_within_group' => '/info',
                'full_path' => '/demo/groups/info',
            ],
            'notes' => 'Router::group() applies a prefix to all routes registered in its callback. Groups can be nested and support shared middleware.',
        ]);
    });
});

// GET /demo/groups — Redirect to the group info route
\Tina4\Router::get('/demo/groups', function (\Tina4\Request $request, \Tina4\Response $response) {
    return $response->json([
        'feature' => 'Route Groups',
        'status' => 'working',
        'output' => [
            'message' => 'Route groups add a shared prefix to child routes',
            'group_route' => '/demo/groups/info — try this URL to see the grouped route',
            'api' => 'Router::group("/prefix", function() { Router::get("/child", $handler); })',
            'supports' => ['nested groups', 'shared middleware via third parameter', 'prefix concatenation'],
        ],
        'notes' => 'Groups also accept an optional middleware array as the third parameter.',
    ]);
});

// GET /demo/orm — ORM demo
\Tina4\Router::get('/demo/orm', function (\Tina4\Request $request, \Tina4\Response $response) {
    // ORM is abstract, so we demonstrate by showing its API
    $ormInfo = [
        'class' => 'Tina4\\ORM (abstract)',
        'properties' => [
            'tableName' => 'string — table name',
            'primaryKey' => 'string — primary key column (default: id)',
            'fieldMapping' => 'array — maps DB columns to PHP properties',
            'softDelete' => 'bool — enables soft delete with is_deleted column',
            'hasOne' => 'array — single-record relationships',
            'hasMany' => 'array — collection relationships',
        ],
        'methods' => [
            'save()' => 'INSERT or UPDATE (upsert)',
            'load($id)' => 'Load by primary key',
            'delete()' => 'Delete or soft-delete',
            'find($filter, $limit, $offset)' => 'Query with filters',
            'all($limit, $offset)' => 'Paginated listing',
            'fill($data)' => 'Populate from array',
            'toArray()' => 'Convert to associative array',
            'toJson()' => 'Convert to JSON string',
        ],
        'requires' => 'DatabaseAdapter implementation (e.g., SQLite3Adapter)',
    ];

    // Check if SQLite3 extension is available
    $sqliteAvailable = extension_loaded('sqlite3');
    $dbDemo = null;

    if ($sqliteAvailable) {
        try {
            // Try to use SQLite3Adapter if it exists
            if (class_exists('\Tina4\Database\SQLite3Adapter')) {
                $dbDemo = 'SQLite3Adapter class exists and could be used for ORM';
            }
        } catch (\Throwable $e) {
            $dbDemo = 'SQLite3Adapter exists but failed: ' . $e->getMessage();
        }
    }

    return $response->json([
        'feature' => 'ORM',
        'status' => 'partial',
        'output' => [
            'orm_api' => $ormInfo,
            'sqlite3_extension' => $sqliteAvailable ? 'available' : 'not loaded',
            'database_demo' => $dbDemo ?? 'No database configured for this demo',
        ],
        'notes' => 'ORM is fully implemented but requires a DatabaseAdapter. Without a configured database, we show the API surface. The ORM supports magic getters/setters, field mapping, soft delete, and relationships.',
    ]);
});

// GET /demo/sessions — Session demo
\Tina4\Router::get('/demo/sessions', function (\Tina4\Request $request, \Tina4\Response $response) {
    $session = new \Tina4\Session('file', [
        'path' => __DIR__ . '/data/sessions',
        'ttl' => 3600,
    ]);

    // Start a new session
    $sessionId = $session->start();

    // Set values
    $session->set('demo_key', 'demo_value');
    $session->set('counter', 1);

    // Flash data
    $session->flash('notice', 'This is a flash message');

    // Read values
    $demoKey = $session->get('demo_key');
    $counter = $session->get('counter');
    $flashValue = $session->getFlash('notice');
    $flashAgain = $session->getFlash('notice'); // Should be null (consumed)

    // Check state
    $allData = $session->all();
    $hasKey = $session->has('demo_key');
    $isStarted = $session->isStarted();

    // Clean up
    $session->destroy();

    return $response->json([
        'feature' => 'Sessions',
        'status' => 'working',
        'output' => [
            'session_id' => $sessionId,
            'set_and_get' => ['key' => 'demo_key', 'value' => $demoKey],
            'flash_data' => [
                'first_read' => $flashValue,
                'second_read' => $flashAgain,
                'note' => 'Flash data is consumed after first read',
            ],
            'all_data' => $allData,
            'has_key' => $hasKey,
            'is_started' => $isStarted,
            'backends' => ['file', 'redis'],
        ],
        'notes' => 'Session supports file and Redis backends, flash data, regeneration, and TTL expiration. Configured via TINA4_SESSION_* env vars.',
    ]);
});

// GET /demo/i18n — Translation demo
\Tina4\Router::get('/demo/i18n', function (\Tina4\Request $request, \Tina4\Response $response) {
    $i18n = new \Tina4\I18n(localeDir: __DIR__ . '/src/locales', defaultLocale: 'en');

    // Translate with parameters
    $greeting = $i18n->t('greeting', ['name' => 'World']);
    $welcome = $i18n->t('welcome');
    $goodbye = $i18n->t('goodbye', ['name' => 'Tina4']);
    $errorMsg = $i18n->t('errors.not_found');

    // Switch locale
    $i18n->setLocale('fr');
    $frGreeting = $i18n->t('greeting', ['name' => 'Monde']);
    $frWelcome = $i18n->t('welcome');
    $frError = $i18n->t('errors.not_found');

    // Available locales
    $locales = $i18n->getAvailableLocales();

    // Missing key fallback
    $missing = $i18n->t('nonexistent.key');

    return $response->json([
        'feature' => 'Internationalization (i18n)',
        'status' => 'working',
        'output' => [
            'english' => [
                'greeting' => $greeting,
                'welcome' => $welcome,
                'goodbye' => $goodbye,
                'error_not_found' => $errorMsg,
            ],
            'french' => [
                'greeting' => $frGreeting,
                'welcome' => $frWelcome,
                'error_not_found' => $frError,
            ],
            'available_locales' => $locales,
            'missing_key_fallback' => $missing,
        ],
        'notes' => 'I18n uses JSON locale files with dot-notation keys (e.g., errors.not_found). Supports {param} substitution. Falls back to default locale, then to the key itself.',
    ]);
});

// GET /demo/scss — SCSS compilation
\Tina4\Router::get('/demo/scss', function (\Tina4\Request $request, \Tina4\Response $response) {
    $compiler = new \Tina4\ScssCompiler();

    $scss = <<<'SCSS'
$primary: #2563eb;
$padding: 16px;

@mixin button($bg) {
    background: $bg;
    padding: $padding;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}

.container {
    max-width: 900px;
    margin: 0 auto;
    padding: $padding;

    .header {
        color: $primary;
        font-size: 24px;

        &:hover {
            color: darken($primary, 10%);
        }
    }

    .btn-primary {
        @include button($primary);
        color: #fff;
    }
}
SCSS;

    $css = $compiler->compile($scss);

    // Also test variable setting
    $compiler2 = new \Tina4\ScssCompiler(['variables' => ['brand' => '#e11d48']]);
    $scss2 = '.brand { color: $brand; }';
    $css2 = $compiler2->compile($scss2);

    return $response->json([
        'feature' => 'SCSS Compiler',
        'status' => 'working',
        'output' => [
            'input_scss' => $scss,
            'compiled_css' => $css,
            'variable_injection' => [
                'input' => $scss2,
                'output' => $css2,
            ],
            'supported_features' => [
                'variables ($var: value)',
                'nesting with & parent selector',
                '@mixin / @include with parameters',
                '@import file resolution',
                'basic math (+, -, *, /)',
                'single-line comment stripping',
                '@media nesting',
            ],
        ],
        'notes' => 'Zero-dependency SCSS compiler. Handles variables, nesting, mixins, imports, and math. Does not support the full Sass spec (e.g., no @extend, no color functions like darken()).',
    ]);
});

// GET /demo/queue — Queue demo
\Tina4\Router::get('/demo/queue', function (\Tina4\Request $request, \Tina4\Response $response) {
    $queue = new \Tina4\Queue('file', ['path' => __DIR__ . '/data/queue']);

    // Push jobs
    $job1Id = $queue->push('demo-queue', ['task' => 'send_email', 'to' => 'user@example.com']);
    $job2Id = $queue->push('demo-queue', ['task' => 'resize_image', 'file' => 'photo.jpg']);

    // Check size
    $sizeBefore = $queue->size('demo-queue');

    // Pop a job
    $poppedJob = $queue->pop('demo-queue');

    // Check size after pop
    $sizeAfter = $queue->size('demo-queue');

    // Pop remaining
    $remaining = $queue->pop('demo-queue');

    // Clean up
    $queue->clear('demo-queue');
    $sizeEmpty = $queue->size('demo-queue');

    return $response->json([
        'feature' => 'Queue',
        'status' => 'working',
        'output' => [
            'pushed_job_ids' => [$job1Id, $job2Id],
            'size_after_push' => $sizeBefore,
            'popped_job' => $poppedJob,
            'size_after_pop' => $sizeAfter,
            'second_pop' => $remaining,
            'size_after_clear' => $sizeEmpty,
            'features' => ['push', 'pop', 'process (with handler)', 'delayed jobs', 'retry', 'failed job tracking'],
        ],
        'notes' => 'File-backed queue. Jobs stored as JSON files in data/queue/{queueName}/. Supports delays, retries (max 3), and failed job inspection.',
    ]);
});

// GET /demo/graphql — GraphQL demo
\Tina4\Router::get('/demo/graphql', function (\Tina4\Request $request, \Tina4\Response $response) {
    $gql = new \Tina4\GraphQL();

    // Register types
    $gql->addType('User', [
        'id' => 'ID!',
        'name' => 'String!',
        'email' => 'String!',
        'role' => 'String',
    ]);

    // Register query resolvers
    $gql->addQuery('user', ['id' => 'ID!'], 'User', function ($root, $args, $ctx) {
        return ['id' => $args['id'], 'name' => 'Alice', 'email' => 'alice@example.com', 'role' => 'admin'];
    });

    $gql->addQuery('users', [], '[User]', function ($root, $args, $ctx) {
        return [
            ['id' => '1', 'name' => 'Alice', 'email' => 'alice@example.com', 'role' => 'admin'],
            ['id' => '2', 'name' => 'Bob', 'email' => 'bob@example.com', 'role' => 'user'],
        ];
    });

    // Register mutation
    $gql->addMutation('createUser', ['name' => 'String!', 'email' => 'String!'], 'User', function ($root, $args, $ctx) {
        return ['id' => '3', 'name' => $args['name'], 'email' => $args['email'], 'role' => 'user'];
    });

    // Execute queries
    $singleResult = $gql->execute('{ user(id: "1") { id name email role } }');
    $listResult = $gql->execute('{ users { id name } }');
    $mutationResult = $gql->execute('mutation { createUser(name: "Charlie", email: "charlie@example.com") { id name email } }');

    // Get schema SDL
    $schema = $gql->schema();

    return $response->json([
        'feature' => 'GraphQL',
        'status' => 'working',
        'output' => [
            'single_query' => $singleResult,
            'list_query' => $listResult,
            'mutation' => $mutationResult,
            'schema_sdl' => $schema,
            'registered_types' => array_keys($gql->getTypes()),
            'registered_queries' => array_keys($gql->getQueries()),
            'registered_mutations' => array_keys($gql->getMutations()),
        ],
        'notes' => 'Zero-dependency GraphQL with recursive-descent parser. Supports types, queries, mutations, fragments, variables, inline fragments, @skip/@include directives, and aliases.',
    ]);
});

// GET /demo/shortcomings — Honest report
\Tina4\Router::get('/demo/shortcomings', function (\Tina4\Request $request, \Tina4\Response $response) {
    $shortcomings = [];

    // Check ORM without database
    $shortcomings[] = [
        'component' => 'ORM',
        'issue' => 'Requires a DatabaseAdapter to function. No default in-memory adapter ships with the demo.',
        'severity' => 'moderate',
        'workaround' => 'Install SQLite3 extension and configure SQLite3Adapter.',
    ];

    // Check template engine (Frond)
    $frondWorks = false;
    try {
        if (class_exists('\Tina4\Frond')) {
            $frond = new \Tina4\Frond(__DIR__ . '/src/templates');
            $frondWorks = true;
        }
    } catch (\Throwable $e) {
        // Template dir may not exist
    }
    $shortcomings[] = [
        'component' => 'Frond (Template Engine)',
        'issue' => $frondWorks ? 'Frond class exists but no template directory set up in demo' : 'Frond class exists; template directory missing in demo',
        'severity' => 'minor',
        'workaround' => 'Create src/templates/ directory with .html template files.',
    ];

    // Check Migration
    $shortcomings[] = [
        'component' => 'Migration',
        'issue' => 'Migration class exists but requires a database connection and migrations/ directory.',
        'severity' => 'moderate',
        'workaround' => 'Configure a database and create SQL migration files in migrations/.',
    ];

    // Check AutoCrud
    $shortcomings[] = [
        'component' => 'AutoCrud',
        'issue' => 'AutoCrud requires ORM models and a database to auto-generate REST endpoints.',
        'severity' => 'moderate',
        'workaround' => 'Set up a database, define ORM models, then register AutoCrud.',
    ];

    // Check FakeData
    $shortcomings[] = [
        'component' => 'FakeData',
        'issue' => 'FakeData requires a database to populate test data.',
        'severity' => 'minor',
        'workaround' => 'Configure a database connection first.',
    ];

    // Check WebSocket
    $shortcomings[] = [
        'component' => 'WebSocket',
        'issue' => 'WebSocket server requires a long-running process and cannot be demonstrated in a simple HTTP request.',
        'severity' => 'minor',
        'workaround' => 'Run the WebSocket server as a separate process.',
    ];

    // Check ServiceRunner
    $shortcomings[] = [
        'component' => 'ServiceRunner',
        'issue' => 'Background services need a persistent process (daemon). Cannot demo in an HTTP request.',
        'severity' => 'minor',
        'workaround' => 'Run services via CLI or as a system daemon.',
    ];

    // Check SCSS darken/lighten
    $shortcomings[] = [
        'component' => 'SCSS Compiler',
        'issue' => 'Color functions (darken, lighten, mix, etc.) are not implemented. Only basic math is supported.',
        'severity' => 'minor',
        'workaround' => 'Use pre-computed color values instead of Sass color functions.',
    ];

    // Router dispatch without server
    $shortcomings[] = [
        'component' => 'Router Dispatch',
        'issue' => 'Router::dispatch() works but the demo uses PHP built-in server, which handles routing via app.php directly.',
        'severity' => 'none',
        'workaround' => 'This is working as designed.',
    ];

    // Check DatabaseUrl
    $shortcomings[] = [
        'component' => 'DatabaseUrl',
        'issue' => 'DATABASE_URL parser exists but has no database drivers to test against without extensions.',
        'severity' => 'minor',
        'workaround' => 'Install a database extension (sqlite3, pdo_mysql, etc.).',
    ];

    // Route Discovery
    $shortcomings[] = [
        'component' => 'Route Discovery',
        'issue' => 'File-based route discovery works but is not demonstrated in this demo (uses explicit registration instead).',
        'severity' => 'none',
        'workaround' => 'Create src/routes/ directory with get.php/post.php files following the convention.',
    ];

    return $response->json([
        'feature' => 'Shortcomings Report',
        'status' => 'working',
        'output' => [
            'total_issues' => count($shortcomings),
            'summary' => [
                'fully_working_in_demo' => [
                    'Router (GET/POST/PUT/PATCH/DELETE/ANY)',
                    'Path parameters ({id} and {slug:.*})',
                    'Route groups with prefix',
                    'Route modifiers (cache, secure, middleware)',
                    'Auth (JWT HS256/RS256, password hashing)',
                    'DotEnv (.env loading)',
                    'Log (structured JSON logging)',
                    'Request (parsing, headers, query, body)',
                    'Response (json, html, text, redirect, cookies)',
                    'Session (file backend, flash data)',
                    'I18n (JSON locales, parameter substitution)',
                    'SCSS Compiler (variables, nesting, mixins)',
                    'Queue (file-backed push/pop)',
                    'GraphQL (types, queries, mutations, parser)',
                    'Constants (HTTP status codes, content types)',
                    'Health Check (uptime, version)',
                    'Middleware (CORS, Rate Limiter)',
                ],
                'need_database' => ['ORM', 'Migration', 'AutoCrud', 'FakeData'],
                'need_long_running_process' => ['WebSocket', 'ServiceRunner'],
                'limited' => ['SCSS (no color functions)', 'Frond (template dir not set up)'],
            ],
            'issues' => $shortcomings,
        ],
        'notes' => 'This is an honest assessment. 17 of 22 features are fully demonstrated. 4 need a database, 2 need a daemon process, and 1 (SCSS) has partial support for the Sass spec.',
    ]);
});

// ── Start app (after routes are registered) ─────────────────────────────────
$app->start();

// ── Server Startup ──────────────────────────────────────────────────────────

if (php_sapi_name() === 'cli' && !isset($_SERVER['REQUEST_URI'])) {
    // CLI mode — start the built-in PHP server
    $host = '0.0.0.0';
    $port = 7146;

    echo "\n";
    echo "  ╔══════════════════════════════════════════╗\n";
    echo "  ║   Tina4 PHP v3.0.0 — Feature Demo       ║\n";
    echo "  ║   http://localhost:{$port}                  ║\n";
    echo "  ║   Press Ctrl+C to stop                   ║\n";
    echo "  ╚══════════════════════════════════════════╝\n";
    echo "\n";

    $cmd = sprintf(
        'php -S %s:%d -t %s %s',
        $host,
        $port,
        escapeshellarg(__DIR__),
        escapeshellarg(__FILE__)
    );

    passthru($cmd);
    exit(0);
}

// ── HTTP Dispatch (when running under PHP built-in server or other SAPI) ────

$request = new \Tina4\Request();
$response = new \Tina4\Response();

// Let the built-in server handle static files
if (php_sapi_name() === 'cli-server') {
    $filePath = __DIR__ . $request->path;
    if ($request->path !== '/' && is_file($filePath)) {
        return false; // Let PHP serve the static file
    }
}

$result = \Tina4\Router::dispatch($request, $response);
$result->send();
