# Route Discovery

The `RouteDiscovery` class scans a directory tree and auto-registers routes with the `Router` based on file system conventions. Directory names map to URL path segments, bracket-wrapped names become dynamic parameters, and the filename determines the HTTP method.

This approach provides file-based routing similar to Next.js or SvelteKit, but for PHP.

## Convention

```
src/routes/
  {method}.php          -> {METHOD} /

  api/
    users/
      get.php           -> GET    /api/users
      post.php          -> POST   /api/users
      [id]/
        get.php         -> GET    /api/users/{id}
        put.php         -> PUT    /api/users/{id}
        delete.php      -> DELETE /api/users/{id}

  docs/
    [...slug]/
      get.php           -> GET    /docs/{slug:.*}

  health/
    any.php             -> ANY    /health
```

## Supported File Names

| Filename | HTTP Method |
|----------|-------------|
| `get.php` | GET |
| `post.php` | POST |
| `put.php` | PUT |
| `delete.php` | DELETE |
| `patch.php` | PATCH |
| `any.php` | All methods (GET, POST, PUT, DELETE, PATCH) |

## Route File Format

Each route file must return a closure that accepts `Request` and `Response`:

```php
// src/routes/api/users/get.php
<?php

use Tina4\Request;
use Tina4\Response;

return function (Request $request, Response $response): Response {
    return $response->json(['users' => []]);
};
```

```php
// src/routes/api/users/post.php
<?php

use Tina4\Request;
use Tina4\Response;

return function (Request $request, Response $response): Response {
    $data = $request->body;
    return $response->json(['created' => $data], 201);
};
```

## Dynamic Parameters

Wrap directory names in square brackets to create dynamic route segments.

```php
// src/routes/api/users/[id]/get.php
<?php

use Tina4\Request;
use Tina4\Response;

return function (Request $request, Response $response): Response {
    $userId = $request->params['id'];
    return $response->json(['user_id' => $userId]);
};
```

```php
// src/routes/api/orgs/[orgId]/teams/[teamId]/get.php
<?php

use Tina4\Request;
use Tina4\Response;

return function (Request $request, Response $response): Response {
    return $response->json([
        'org' => $request->params['orgId'],
        'team' => $request->params['teamId'],
    ]);
};
```

## Catch-All Parameters

Use `[...name]` for catch-all (wildcard) segments that match the remaining path.

```php
// src/routes/docs/[...slug]/get.php
<?php

use Tina4\Request;
use Tina4\Response;

return function (Request $request, Response $response): Response {
    // slug matches: "guides/getting-started", "api/reference/auth", etc.
    $slug = $request->params['slug'];
    return $response->html("<h1>Docs: {$slug}</h1>");
};
```

## Scanning and Registering

```php
use Tina4\RouteDiscovery;

$discovered = RouteDiscovery::scan('src/routes');

// Returns:
// [
//   ['method' => 'GET',    'path' => '/api/users',       'file' => 'src/routes/api/users/get.php'],
//   ['method' => 'POST',   'path' => '/api/users',       'file' => 'src/routes/api/users/post.php'],
//   ['method' => 'GET',    'path' => '/api/users/{id}',   'file' => 'src/routes/api/users/[id]/get.php'],
//   ['method' => 'GET',    'path' => '/docs/{slug:.*}',   'file' => 'src/routes/docs/[...slug]/get.php'],
// ]

echo count($discovered) . " routes discovered\n";
```

## Path Conversion Rules

| Directory Path | URL Path |
|---------------|----------|
| `/api/users` | `/api/users` |
| `/api/users/[id]` | `/api/users/{id}` |
| `/api/orgs/[orgId]/teams` | `/api/orgs/{orgId}/teams` |
| `/docs/[...slug]` | `/docs/{slug:.*}` |
| `/` | `/` |

Use the static method directly for testing:

```php
echo RouteDiscovery::directoryToUrlPath('/api/users/[id]');
// "/api/users/{id}"

echo RouteDiscovery::directoryToUrlPath('/docs/[...slug]');
// "/docs/{slug:.*}"
```

## Complete Example

```
src/routes/
  get.php                           -> GET /
  api/
    get.php                         -> GET /api
    users/
      get.php                       -> GET /api/users
      post.php                      -> POST /api/users
      [id]/
        get.php                     -> GET /api/users/{id}
        put.php                     -> PUT /api/users/{id}
        delete.php                  -> DELETE /api/users/{id}
        posts/
          get.php                   -> GET /api/users/{id}/posts
    products/
      get.php                       -> GET /api/products
      [productId]/
        get.php                     -> GET /api/products/{productId}
  health/
    any.php                         -> ANY /health
  docs/
    [...slug]/
      get.php                       -> GET /docs/{slug:.*}
```

```php
// Bootstrap
$routes = RouteDiscovery::scan('src/routes');
echo count($routes) . " routes auto-registered\n";

// Routes are now available via Router::dispatch()
```

## Tips

- Route files that do not return a callable are skipped with a warning logged via `Log::warning()`.
- The scanner is recursive — nested directories of any depth are supported.
- File-based routing and programmatic routing (`Router::get()`) can coexist — use both together.
- Keep route files small — delegate complex logic to service classes.
- Use `any.php` sparingly — specific methods are preferred for clarity and REST compliance.
- The `[...slug]` catch-all must be the last segment in the path.
