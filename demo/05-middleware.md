# Middleware

Tina4 v3 provides middleware at three levels: route-specific, group-level, and global (via the App class). The framework ships with two built-in middleware classes — `CorsMiddleware` for Cross-Origin Resource Sharing and `RateLimiter` for request throttling. Custom middleware is any callable that receives a `Request` and `Response` and returns either `true` (continue), `false` (403 Forbidden), or a `Response` (short-circuit).

## How Middleware Works

Middleware runs before the route handler. The chain stops if any middleware returns a `Response` object (short-circuit) or `false` (automatic 403).

```php
// Middleware signature
function (Request $request, Response $response): true|false|Response
```

- Return `true` — proceed to next middleware or handler
- Return `false` — Router returns 403 Forbidden
- Return a `Response` — Router returns that response immediately

## Custom Middleware

```php
use Tina4\Request;
use Tina4\Response;

// Logging middleware
$logMiddleware = function (Request $request, Response $response): true {
    \Tina4\Log::info('Request received', [
        'method' => $request->method,
        'path' => $request->path,
        'ip' => $request->ip,
    ]);
    return true;
};

// Auth check middleware
$authMiddleware = function (Request $request, Response $response): true|Response {
    $token = $request->bearerToken();
    if ($token === null) {
        return $response->json(['error' => 'Authentication required'], 401);
    }

    $payload = \Tina4\Auth::validateToken($token, $_ENV['JWT_SECRET']);
    if ($payload === null) {
        return $response->json(['error' => 'Invalid or expired token'], 401);
    }

    return true;
};

// Content-type enforcement
$jsonOnly = function (Request $request, Response $response): true|Response {
    if (!$request->isJson()) {
        return $response->json(['error' => 'Content-Type must be application/json'], 415);
    }
    return true;
};
```

## Route-Specific Middleware

Chain `->middleware()` after any route. Pass an array of callables.

```php
use Tina4\Router;

Router::get('/admin/users', function (Request $request, Response $response) {
    return $response->json(['users' => []]);
})->middleware([$authMiddleware, $logMiddleware]);

Router::post('/api/data', function (Request $request, Response $response) {
    return $response->json(['saved' => true], 201);
})->middleware([$jsonOnly, $authMiddleware]);
```

## Group Middleware

Pass middleware as the third argument to `Router::group()`. All routes in the group inherit it.

```php
Router::group('/api/v1', function () {
    Router::get('/users', function (Request $request, Response $response) {
        return $response->json(['users' => []]);
    });

    Router::post('/users', function (Request $request, Response $response) {
        return $response->json(['created' => true], 201);
    });
}, [$authMiddleware, $logMiddleware]);
```

## Global Middleware (App)

The `App` class has an `addMiddleware()` method for application-wide middleware.

```php
use Tina4\App;

$app = new App(basePath: '.', development: true);

$app->addMiddleware(function (Request $request, Response $response): true {
    \Tina4\Log::info('Global middleware', ['path' => $request->path]);
    return true;
});
```

## CORS Middleware

The built-in `CorsMiddleware` handles Cross-Origin Resource Sharing headers. It reads configuration from environment variables or constructor arguments.

```php
use Tina4\Middleware\CorsMiddleware;

// Using environment variables:
// TINA4_CORS_ORIGINS=https://myapp.com,https://staging.myapp.com
// TINA4_CORS_METHODS=GET,POST,PUT,DELETE,OPTIONS
// TINA4_CORS_HEADERS=Content-Type,Authorization,X-Requested-With
// TINA4_CORS_MAX_AGE=86400

$cors = new CorsMiddleware();

// Or with explicit configuration
$cors = new CorsMiddleware(
    origins: 'https://myapp.com,https://admin.myapp.com',
    methods: 'GET,POST,PUT,DELETE',
    headers: 'Content-Type,Authorization',
    maxAge: 3600,
);

// Use as middleware
$corsHandler = function (Request $request, Response $response) use ($cors): true|Response {
    $result = $cors->handle($request->method, $request->header('Origin'));

    // Apply CORS headers to response
    foreach ($result['headers'] as $name => $value) {
        $response->header($name, $value);
    }

    // Short-circuit preflight requests
    if ($result['preflight']) {
        return $response->status(204);
    }

    return true;
};

Router::group('/api', function () {
    Router::get('/data', function (Request $request, Response $response) {
        return $response->json(['data' => 'value']);
    });
}, [$corsHandler]);
```

## Rate Limiter

The `RateLimiter` uses a sliding window algorithm to throttle requests per IP address. It adds standard rate limit headers to responses.

```php
use Tina4\Middleware\RateLimiter;

// Using environment variables:
// TINA4_RATE_LIMIT=60      # max requests per window
// TINA4_RATE_WINDOW=60     # window duration in seconds

$limiter = new RateLimiter();

// Or with explicit configuration
$limiter = new RateLimiter(limit: 100, window: 60);

// Use as middleware
$rateLimitHandler = function (Request $request, Response $response) use ($limiter): true|Response {
    $result = $limiter->check($request->ip);

    // Always add rate limit headers
    foreach ($result['headers'] as $name => $value) {
        $response->header($name, $value);
    }

    if (!$result['allowed']) {
        return $response->json(
            ['error' => 'Too many requests', 'retry_after' => $result['headers']['Retry-After']],
            429
        );
    }

    return true;
};

Router::group('/api', function () {
    Router::get('/search', function (Request $request, Response $response) {
        return $response->json(['results' => []]);
    });
}, [$rateLimitHandler]);
```

The rate limiter adds these headers to every response:
- `X-RateLimit-Limit` — maximum requests allowed
- `X-RateLimit-Remaining` — requests remaining in the current window
- `Retry-After` — seconds until the window resets (only on 429 responses)

## Combining Multiple Middleware

```php
Router::group('/api/v1', function () {
    Router::get('/public', function (Request $request, Response $response) {
        return $response->json(['public' => true]);
    });

    Router::get('/protected', function (Request $request, Response $response) {
        return $response->json(['secret' => 'data']);
    })->middleware([$authMiddleware]);

}, [$corsHandler, $rateLimitHandler, $logMiddleware]);
```

## Tips

- Middleware executes in array order — put auth checks before business logic.
- Return a `Response` to short-circuit. Return `true` to continue the chain.
- Use `RateLimiter::cleanup()` periodically in long-running servers to prevent memory leaks.
- Configure CORS to specific origins in production — avoid using `*` for APIs that use credentials.
- Rate limiter state is in-memory, so it resets when the process restarts. For distributed rate limiting, implement a Redis-backed limiter.
