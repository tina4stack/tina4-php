# tina4-php Copilot Instructions

Tina4 PHP v3. 54 features, zero external dependencies.

## Route Pattern

```php
use Tina4\Router;

Router::get("/api/users", function($request, $response) {
    return $response->json(["users" => []], 200);
});

Router::post("/api/public", function($request, $response) {
    return $response->json(["ok" => true], 201);
})->noAuth();
```

## Critical Rules

- Static methods: `Router::get()` not `$router->get()`
- POST/PUT/DELETE require auth — chain `->noAuth()` for public
- GET is public — chain `->secure()` to protect
- ORM: `asArray()` not `toArray()`
- Queue: `$job->payload` not `$job->data`
- Namespace: `Tina4\` — `Tina4\Router`, `Tina4\ORM`, `Tina4\Auth`
- Templates: Twig syntax (not Blade)

See llms.txt for full API reference.
