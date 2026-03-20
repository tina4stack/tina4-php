# Routing

The `Router` class is the HTTP routing engine for Tina4 v3. It supports static and dynamic routes, route groups with shared prefixes, middleware chaining, and modifiers for caching and authentication. All routes are registered statically and matched against incoming requests using compiled regex patterns.

Routes follow a closure-based pattern where every handler receives a `Request` and `Response` object. The router supports catch-all parameters for wildcard paths.

## Basic Route Registration

```php
use Tina4\Router;
use Tina4\Request;
use Tina4\Response;

// GET route
Router::get('/hello', function (Request $request, Response $response) {
    return $response->json(['message' => 'Hello, world!']);
});

// POST route
Router::post('/users', function (Request $request, Response $response) {
    $data = $request->body;
    return $response->json(['created' => $data], 201);
});

// PUT route
Router::put('/users/{id}', function (Request $request, Response $response) {
    $id = $request->params['id'];
    return $response->json(['updated' => $id]);
});

// DELETE route
Router::delete('/users/{id}', function (Request $request, Response $response) {
    $id = $request->params['id'];
    return $response->json(['deleted' => $id]);
});

// PATCH route
Router::patch('/users/{id}', function (Request $request, Response $response) {
    return $response->json(['patched' => $request->params['id']]);
});

// ANY — registers for all HTTP methods at once
Router::any('/ping', function (Request $request, Response $response) {
    return $response->json(['pong' => true, 'method' => $request->method]);
});
```

## Dynamic Path Parameters

Parameters are declared with `{name}` syntax and become available on `$request->params`.

```php
Router::get('/users/{id}', function (Request $request, Response $response) {
    $userId = $request->params['id'];
    return $response->json(['user_id' => $userId]);
});

// Multiple parameters
Router::get('/orgs/{orgId}/teams/{teamId}', function (Request $request, Response $response) {
    return $response->json([
        'org' => $request->params['orgId'],
        'team' => $request->params['teamId'],
    ]);
});
```

## Catch-All Parameters

Use `{name:.*}` to match the remaining path. Useful for wildcard routes like documentation pages.

```php
Router::get('/docs/{slug:.*}', function (Request $request, Response $response) {
    $slug = $request->params['slug']; // e.g. "guides/getting-started"
    return $response->html("<h1>Docs: {$slug}</h1>");
});
```

## Route Groups

Groups apply a shared URL prefix and optional middleware to all enclosed routes. Groups can be nested.

```php
Router::group('/api/v1', function () {
    Router::get('/users', function (Request $request, Response $response) {
        return $response->json(['users' => []]);
    });

    Router::post('/users', function (Request $request, Response $response) {
        return $response->json(['created' => true], 201);
    });
});
// Registers: GET /api/v1/users, POST /api/v1/users

// Nested groups
Router::group('/api', function () {
    Router::group('/v2', function () {
        Router::get('/status', function (Request $request, Response $response) {
            return $response->json(['version' => 2]);
        });
    });
});
// Registers: GET /api/v2/status
```

## Group Middleware

Pass middleware as the third argument to `group()`. Every route in the group inherits it.

```php
$logMiddleware = function (Request $request, Response $response) {
    \Tina4\Log::info('API request', ['path' => $request->path]);
    return true; // continue
};

Router::group('/api', function () {
    Router::get('/data', function (Request $request, Response $response) {
        return $response->json(['data' => 'value']);
    });
}, [$logMiddleware]);
```

## Route-Specific Middleware

Chain `->middleware()` after any route registration. Pass an array of callable middleware.

```php
$authCheck = function (Request $request, Response $response) {
    if ($request->bearerToken() === null) {
        return $response->json(['error' => 'Unauthorized'], 401);
    }
    return true;
};

$adminCheck = function (Request $request, Response $response) {
    // custom admin logic
    return true;
};

Router::get('/admin/dashboard', function (Request $request, Response $response) {
    return $response->json(['dashboard' => 'data']);
})->middleware([$authCheck, $adminCheck]);
```

## Cache Modifier

Mark a route as cacheable with `->cache()`. Remove caching with `->noCache()`.

```php
Router::get('/products', function (Request $request, Response $response) {
    return $response->json(['products' => []]);
})->cache();

Router::get('/live-data', function (Request $request, Response $response) {
    return $response->json(['timestamp' => time()]);
})->noCache();
```

## Secure Modifier

Mark a route as secure with `->secure()`. The router will require a valid Bearer token in the Authorization header, returning 401 if missing.

```php
Router::get('/profile', function (Request $request, Response $response) {
    return $response->json(['user' => 'current']);
})->secure();

Router::put('/settings', function (Request $request, Response $response) {
    return $response->json(['updated' => true]);
})->secure()->noCache();
```

## Chaining Modifiers

All modifiers return `$this` and can be chained in any order.

```php
Router::get('/api/reports', function (Request $request, Response $response) {
    return $response->json(['report' => 'data']);
})->secure()->cache()->middleware([$logMiddleware]);
```

## Dispatching Requests

The `Router::dispatch()` method matches the request, runs middleware, and invokes the handler.

```php
$request = Request::create(method: 'GET', path: '/users/42');
$response = new Response(testing: true);

$result = Router::dispatch($request, $response);

echo $result->getStatusCode(); // 200
echo $result->getBody();       // JSON response
```

## Listing Registered Routes

Use `Router::list()` for debugging or CLI output.

```php
$routes = Router::list();
// Returns: [
//   ['method' => 'GET', 'pattern' => '/users/{id}', 'middleware' => 1, 'cache' => false, 'secure' => true],
//   ...
// ]

echo Router::count(); // total number of registered routes
```

## Tips

- Route files are auto-discovered from `src/routes/` — one file per resource keeps things organized.
- Always return via `$response->json()`, `$response->html()`, or the callable `$response(data)` pattern.
- Use route groups for API versioning: `/api/v1`, `/api/v2`.
- The `->secure()` modifier checks for Bearer token presence. Combine with `Auth::middleware()` for full JWT validation.
- Call `Router::reset()` in tests to clear all registered routes between test cases.
