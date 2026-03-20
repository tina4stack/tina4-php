# Request & Response

The `Request` class wraps PHP superglobals into a clean, readonly object with parsed headers, body, query params, and file uploads. The `Response` class is a fluent builder for constructing HTTP responses with JSON, HTML, text, redirects, cookies, and custom headers. Both classes are zero-dependency.

## Request Object

### Accessing Request Data

```php
use Tina4\Request;

// In a route handler, $request is injected automatically
Router::post('/api/users', function (Request $request, Response $response) {

    // HTTP method
    echo $request->method; // "POST"

    // Request path (without query string)
    echo $request->path; // "/api/users"

    // Full URL including query string
    echo $request->url; // "/api/users?page=2"

    // Client IP (supports X-Forwarded-For, X-Real-IP)
    echo $request->ip; // "192.168.1.100"

    // Content type
    echo $request->contentType; // "application/json"

    // Raw body string
    echo $request->rawBody;
});
```

### Query Parameters

```php
Router::get('/search', function (Request $request, Response $response) {
    // All query params as array
    $query = $request->query; // ['q' => 'tina4', 'page' => '2']

    // Get specific param with default
    $searchTerm = $request->queryParam('q', '');
    $page = $request->queryParam('page', '1');

    return $response->json(['query' => $searchTerm, 'page' => (int)$page]);
});
```

### Request Body

The body is auto-parsed based on Content-Type:
- `application/json` - decoded to array
- `application/x-www-form-urlencoded` - parsed to array
- Other types - raw string

```php
Router::post('/api/items', function (Request $request, Response $response) {
    // Parsed body (array for JSON, form data)
    $data = $request->body;

    // Get specific field with default
    $name = $request->input('name', 'Unnamed');
    $price = $request->input('price', 0);

    // Check if JSON
    if ($request->isJson()) {
        // Handle JSON-specific logic
    }

    return $response->json(['received' => $data]);
});
```

### Headers

```php
Router::get('/api/data', function (Request $request, Response $response) {
    // All headers (lowercase keys)
    $headers = $request->headers;

    // Get specific header
    $contentType = $request->header('Content-Type');
    $auth = $request->header('Authorization');

    // Bearer token extraction
    $token = $request->bearerToken(); // Returns token string or null

    // Check if client wants JSON
    if ($request->wantsJson()) {
        return $response->json(['format' => 'json']);
    }

    return $response->html('<p>HTML response</p>');
});
```

### Route Parameters

Dynamic route parameters (`{id}`, `{slug}`) are populated by the Router.

```php
Router::get('/users/{id}/posts/{postId}', function (Request $request, Response $response) {
    $userId = $request->params['id'];
    $postId = $request->params['postId'];

    return $response->json(['user' => $userId, 'post' => $postId]);
});
```

### File Uploads

```php
Router::post('/upload', function (Request $request, Response $response) {
    $files = $request->files;

    if (!empty($files['document'])) {
        $file = $files['document'];
        echo $file['name'];      // Original filename
        echo $file['type'];      // MIME type
        echo $file['tmp_name'];  // Temporary path
        echo $file['size'];      // Size in bytes
    }

    return $response->json(['uploaded' => count($files)]);
});
```

### Method Checking

```php
if ($request->isMethod('POST')) {
    // Handle POST
}

if ($request->isMethod('GET')) {
    // Handle GET
}
```

### Creating Requests for Testing

```php
$request = Request::create(
    method: 'POST',
    path: '/api/users',
    query: ['page' => '1'],
    body: ['name' => 'Alice', 'email' => 'alice@example.com'],
    headers: ['content-type' => 'application/json', 'authorization' => 'Bearer xyz'],
    ip: '10.0.0.1',
);
```

## Response Object

### JSON Response

```php
Router::get('/api/users', function (Request $request, Response $response) {
    return $response->json(['users' => []], 200);
});

// With custom status
Router::post('/api/users', function (Request $request, Response $response) {
    return $response->json(['id' => 42, 'created' => true], 201);
});
```

### HTML Response

```php
Router::get('/page', function (Request $request, Response $response) {
    return $response->html('<h1>Hello World</h1>');
});
```

### Plain Text Response

```php
Router::get('/robots.txt', function (Request $request, Response $response) {
    return $response->text("User-agent: *\nDisallow: /admin/");
});
```

### Smart Callable Pattern

The Response object is callable. It auto-detects the content type from the data.

```php
Router::get('/auto', function (Request $request, Response $response) {
    // Array -> JSON
    return $response(['key' => 'value']);

    // String starting with < -> HTML
    return $response('<h1>Page</h1>');

    // Other string -> Plain text
    return $response('Hello');

    // With explicit status
    return $response(['error' => 'Not Found'], 404);

    // With explicit content type
    return $response($xmlData, 200, 'application/xml');
});
```

### Headers

```php
$response->header('X-Custom-Header', 'value');

$response->withHeaders([
    'X-Request-Id' => '12345',
    'X-Powered-By' => 'Tina4',
    'Cache-Control' => 'no-cache',
]);
```

### Status Codes

```php
$response->status(201);
$response->status(404);
```

### Redirects

```php
Router::get('/old-page', function (Request $request, Response $response) {
    return $response->redirect('/new-page'); // 302 by default
});

Router::get('/moved', function (Request $request, Response $response) {
    return $response->redirect('/permanent-location', 301);
});
```

### Cookies

```php
$response->cookie('session_id', 'abc123', [
    'expires' => time() + 3600,
    'path' => '/',
    'domain' => '.example.com',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
```

### Chaining

All Response methods return `$this` for fluent chaining.

```php
return $response
    ->status(200)
    ->header('X-Version', '3.0')
    ->cookie('visited', 'true')
    ->json(['data' => 'value']);
```

### Inspecting Responses (Testing)

```php
$response = new Response(testing: true);
$response->json(['hello' => 'world'], 200);

echo $response->getStatusCode();   // 200
echo $response->getBody();         // '{"hello":"world"}'
echo $response->getHeader('Content-Type'); // 'application/json'

$decoded = $response->getJsonBody();  // ['hello' => 'world']
$allHeaders = $response->getHeaders();
$cookies = $response->getCookies();

echo $response->isSent(); // false (testing mode)
```

## Tips

- Use `Request::create()` for testing — it bypasses PHP superglobals entirely.
- The `Response` constructor accepts `testing: true` to suppress actual header/output calls.
- Use `$request->bearerToken()` to extract JWT tokens from the Authorization header.
- The `$response(data)` callable pattern is the idiomatic Tina4 way to return responses.
- Always use the constant helpers from `Constants.php` for status codes: `HTTP_OK`, `HTTP_CREATED`, `HTTP_NOT_FOUND`.
