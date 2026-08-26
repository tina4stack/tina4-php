# Routes & API Development (PHP)

## Creating Routes

Drop a file in `src/routes/` and it's auto-discovered. No registration needed. Route files load in
the **global namespace**, so reference framework classes with a leading backslash: `\Tina4\Router`.

Routes are registered with the static `\Tina4\Router` methods — `get`, `post`, `put`, `patch`,
`delete` — each taking `(string $path, callable $handler)`. **The handler must be a closure.**

```php
\Tina4\Router::get("/hello", function ($request, $response) {
    return $response("Hello World");
});

\Tina4\Router::get("/users/{id}", function ($request, $response) {
    $user = User::findById($request->params["id"]);
    return $response->json($user);          // ORM model -> array automatically
});

\Tina4\Router::post("/users", function ($request, $response) {
    $user = (new User($request->body))->save();   // body auto-parsed from JSON
    return $response($user, 201);
});

\Tina4\Router::put("/users/{id}", function ($request, $response) {
    $user = User::findById($request->params["id"]);
    $user->name = $request->body["name"];
    $user->save();
    return $response->json($user);
});

\Tina4\Router::delete("/users/{id}", function ($request, $response) {
    User::findById($request->params["id"])->delete();
    return $response("", 204);
});
```

### The handler MUST be a closure — not a class-method array

The framework introspects the handler with `ReflectionFunction`, which only accepts a `Closure` (or
a function-name string). A class-method array like `["ExampleController", "index"]` will fail — it
either fails the `callable` type-hint (if the method isn't static) or blows up inside
`ReflectionFunction` — so **do not** pass one:

```php
// WRONG — fatals: ReflectionFunction cannot introspect a [class, method] array
\Tina4\Router::get("/reports", ["ExampleController", "index"]);

// RIGHT — wrap the call in a closure, keep the route thin
\Tina4\Router::get("/reports", function ($request, $response) {
    return $response->json((new \App\ReportService())->index($request));
});
```

### How handler arguments are injected

The router inspects your closure's parameters and injects by name/type:
- A parameter whose name matches a path placeholder (`{id}` -> `$id`) receives that path value.
- A parameter named `request` or type-hinted `\Tina4\Request` receives the request; anything else
  receives the `\Tina4\Response`.

So both of these work — pick the `($request, $response)` form as the default:
```php
\Tina4\Router::get("/users/{id}", function ($request, $response) {
    return $response->json(["id" => $request->params["id"]]);
});

// path param injected by name:
\Tina4\Router::get("/users/{id}", function (\Tina4\Request $request, \Tina4\Response $response, $id) {
    return $response->json(["id" => $id]);
});
```

## Smart Response Types

A route handler's return value is coerced:
- Return an **array** -> JSON response (`Content-Type: application/json`)
- Return a **string** -> HTML response
- Return a **`Response`** -> used as-is

`$response(...)` (invoke) and `$response->json(...)` both normalise ORM models and collections via
`toDict()`, so you can return them directly:
```php
return $response($user, 201);            // ORM model -> JSON, status 201
return $response->json(User::find([]));  // array<User> -> array<array>
```
Other response helpers:
- `$response->render("template.twig", $data)` -> Frond template rendering
- `$response->redirect("/path")` -> HTTP redirect
- `$response->file("path/to/file")` -> file download
- `$response->html($string)` / `$response->text($string)` -> explicit content type

> A raw ORM object returned **without** `$response->json(...)` / `$response(...)` is NOT
> auto-serialised — the handler falls through to an empty response. Always wrap it.

## Path Parameters

Use `{name}` syntax in route paths; read them from `$request->params`:
```php
\Tina4\Router::get("/users/{id}/posts/{postId}", function ($request, $response) {
    $userId = $request->params["id"];
    $postId = $request->params["postId"];
    // ...
});
```

## Query Parameters

Access via `$request->query`, or `$request->param($key, $default)` which checks path then query:
```php
// GET /search?q=hello&page=2
\Tina4\Router::get("/search", function ($request, $response) {
    $query = $request->query["q"] ?? "";
    $page  = (int)($request->param("page", 1));
    // ...
});
```

## Middleware

Apply authentication, logging, or other cross-cutting concerns with a middleware class. A middleware
class exposes static `before*` / `after*` methods. Each returns `[$request, $response]` to continue,
a `Response` to short-circuit, or `false` for a 403.

```php
// src/app/AuthCheck.php
namespace App;

use Tina4\Request;
use Tina4\Response;

class AuthCheck
{
    public static function beforeAuth(Request $request, Response $response): array|Response
    {
        $token = $request->bearerToken();
        if (!$token || !\Tina4\Auth::validToken($token)) {
            return $response->json(["error" => "Unauthorized"], 401);
        }
        return [$request, $response];
    }
}
```

Attach it per-route via the 3rd argument (an array of middleware), or register it globally:
```php
\Tina4\Router::get("/protected", function ($request, $response) {
    return $response->json(["secret" => "data"]);
}, [\App\AuthCheck::class]);

// Global (runs on every route):
\Tina4\Router::use(\App\AuthCheck::class);
```

For simple "requires a valid JWT" protection on a GET route, you don't even need a middleware class —
chain `->secure()`:
```php
\Tina4\Router::get("/me", function ($request, $response) {
    $auth = \Tina4\Auth::authenticateRequest($request->headers->toArray());
    return $response->json(User::findById($auth["user_id"]));
})->secure();
```

## Swagger / OpenAPI

Auto-generated at `/swagger`. Add metadata by chaining `->swagger([...])` on the route:

```php
\Tina4\Router::get("/users", function ($request, $response) {
    $users = (new User())->where("is_active = ?", [1]);
    return $response->json($users);
})->swagger([
    "summary"     => "List all active users",
    "description" => "Returns every active user account",
    "tags"        => ["Users"],
]);
```

## CSRF / Form Token Protection

All state-changing forms must include a CSRF token. Tina4 provides this built-in.

### In Frond Templates
```twig
<form method="post" action="/contact">
    {{ formToken() }}
    <input type="text" name="name">
    <button type="submit">Send</button>
</form>
```

`{{ formToken() }}` (and its snake_case alias `{{ form_token() }}`) renders a hidden input with a
secure token. The framework validates it automatically on POST/PUT/DELETE requests. If the token is
missing or invalid, the request is rejected. Need just the raw token string (e.g. for a meta tag or
a fetch header)? Use `{{ formTokenValue() }}` / `{{ form_token_value() }}`.

### In frond.js
frond.js's `saveForm(...)` / `sendRequest(...)` pick up the form token automatically. If you POST by
hand, read it from a meta tag you populated with `{{ formTokenValue() }}` and send it as a header.

**Never skip CSRF protection on forms.** It prevents cross-site request forgery attacks.

## CORS

Built-in. Register the CORS middleware globally when you need to customise it:
`\Tina4\Router::use(\Tina4\Middleware\CorsMiddleware::class);`. In development it defaults to
allowing all origins.

## Rate Limiting

Built-in via `\Tina4\Middleware\RateLimiterMiddleware`. Register it globally
(`\Tina4\Router::use(\Tina4\Middleware\RateLimiterMiddleware::class);`) or attach it to specific
routes through the middleware array. Sensible defaults need no configuration; override in `.env`.
