# Constants

Tina4 v3 defines HTTP status codes and content type constants in the `Tina4` namespace. Using these constants instead of magic numbers makes route handlers more readable and maintainable.

## HTTP Status Codes

### Success (2xx)

```php
use const Tina4\HTTP_OK;           // 200
use const Tina4\HTTP_CREATED;      // 201
use const Tina4\HTTP_ACCEPTED;     // 202
use const Tina4\HTTP_NO_CONTENT;   // 204
```

### Redirection (3xx)

```php
use const Tina4\HTTP_MOVED;        // 301
use const Tina4\HTTP_REDIRECT;     // 302
use const Tina4\HTTP_NOT_MODIFIED; // 304
```

### Client Error (4xx)

```php
use const Tina4\HTTP_BAD_REQUEST;        // 400
use const Tina4\HTTP_UNAUTHORIZED;       // 401
use const Tina4\HTTP_FORBIDDEN;          // 403
use const Tina4\HTTP_NOT_FOUND;          // 404
use const Tina4\HTTP_METHOD_NOT_ALLOWED; // 405
use const Tina4\HTTP_CONFLICT;           // 409
use const Tina4\HTTP_GONE;              // 410
use const Tina4\HTTP_UNPROCESSABLE;      // 422
use const Tina4\HTTP_TOO_MANY;           // 429
```

### Server Error (5xx)

```php
use const Tina4\HTTP_SERVER_ERROR;  // 500
use const Tina4\HTTP_BAD_GATEWAY;   // 502
use const Tina4\HTTP_UNAVAILABLE;   // 503
```

## Content Types

```php
use const Tina4\APPLICATION_JSON;   // "application/json"
use const Tina4\APPLICATION_XML;    // "application/xml"
use const Tina4\APPLICATION_FORM;   // "application/x-www-form-urlencoded"
use const Tina4\APPLICATION_OCTET;  // "application/octet-stream"

use const Tina4\TEXT_HTML;          // "text/html; charset=utf-8"
use const Tina4\TEXT_PLAIN;         // "text/plain; charset=utf-8"
use const Tina4\TEXT_CSV;           // "text/csv"
use const Tina4\TEXT_XML;           // "text/xml"
```

## Usage in Route Handlers

```php
use Tina4\Router;
use Tina4\Request;
use Tina4\Response;
use const Tina4\HTTP_OK;
use const Tina4\HTTP_CREATED;
use const Tina4\HTTP_NOT_FOUND;
use const Tina4\HTTP_UNAUTHORIZED;
use const Tina4\APPLICATION_JSON;

Router::get('/api/users/{id}', function (Request $request, Response $response) {
    $userId = $request->params['id'];

    // User not found
    if ($userId === '0') {
        return $response->json(['error' => 'User not found'], HTTP_NOT_FOUND);
    }

    return $response->json(['id' => $userId, 'name' => 'Alice'], HTTP_OK);
});

Router::post('/api/users', function (Request $request, Response $response) {
    $data = $request->body;
    return $response->json(['id' => 1, 'created' => true], HTTP_CREATED);
});

// Using the callable response pattern with explicit content type
Router::get('/api/export', function (Request $request, Response $response) {
    $csvData = "name,email\nAlice,alice@example.com\n";
    return $response($csvData, HTTP_OK, Tina4\TEXT_CSV);
});
```

## Comparison

```php
// Without constants (harder to read)
return $response->json(['error' => 'Not found'], 404);
return $response->json(['data' => $data], 200);
return $response(['error' => 'Forbidden'], 403);

// With constants (self-documenting)
return $response->json(['error' => 'Not found'], HTTP_NOT_FOUND);
return $response->json(['data' => $data], HTTP_OK);
return $response(['error' => 'Forbidden'], HTTP_FORBIDDEN);
```

## Tips

- Import constants with `use const` at the top of your route files for clean access.
- All constants are defined in the `Tina4` namespace — they do not pollute the global namespace.
- Use these consistently across your codebase — they match standard HTTP semantics exactly.
- The content type constants include the `charset=utf-8` declaration where appropriate.
