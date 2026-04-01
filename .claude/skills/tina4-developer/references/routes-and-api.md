# Routes & API Development

## Creating Routes

Drop a file in `src/routes/` and it's auto-discovered. No registration needed.

### Python
```python
from tina4 import get, post, put, delete

@get("/hello")
async def hello(request, response):
    return response("Hello World")

@get("/users/{id}")
async def get_user(request, response):
    user_id = request.params["id"]
    user = User().find(user_id)
    return response(user)  # Auto-converts to JSON

@post("/users")
async def create_user(request, response):
    user = User(request.body)  # Auto-parsed from JSON
    user.save()
    return response(user, 201)

@put("/users/{id}")
async def update_user(request, response):
    user = User().find(request.params["id"])
    user.name = request.body["name"]
    user.save()
    return response(user)

@delete("/users/{id}")
async def delete_user(request, response):
    User().find(request.params["id"]).delete()
    return response("", 204)
```

### PHP
```php
use Tina4\Router;
use Tina4\Request;
use Tina4\Response;

Router::get("/hello", function(Request $request, Response $response) {
    return $response("Hello World");
});

Router::get("/users/{id}", function(Request $request, Response $response, string $id) {
    $user = (new User())->findById($id);
    return $response($user->toArray());
});

Router::post("/users", function(Request $request, Response $response) {
    $user = new User();
    $user->fill($request->body);
    $user->save();
    return $response($user->toArray(), 201);
})->secure(false);  // Remove ->secure(false) to require auth
```

### Ruby
```ruby
get "/hello" do |request, response|
  response.text "Hello World"
end

get "/users/{id}" do |request, response|
  user = User.find(request.params[:id])
  response.json user
end

post "/users" do |request, response|
  user = User.new(request.body)
  user.save
  response.json user, 201
end
```

### Node.js (TypeScript)
```typescript
import { get, post } from 'tina4';

export const hello = get('/hello', async (request, response) => {
  return response.text('Hello World');
});

export const getUser = get('/users/{id}', async (request, response) => {
  const user = await User.find(request.params.id);
  return response.json(user);
});
```

## Smart Response Types

The framework infers what you want:
- Return an **object/dict/hash** → JSON response with `Content-Type: application/json`
- Return a **string** → HTML response
- Return a **number** → Status code only
- Call `response.render("template.twig", data)` → Frond template rendering
- Call `response.redirect("/path")` → HTTP redirect
- Call `response.file("path/to/file")` → File download

## Template Fallback Routing

If no explicit route matches, Tina4 automatically looks for a matching template in
`src/templates/`. For example, a request to `/hello` will serve `src/templates/hello.twig`
or `src/templates/hello.html` if either exists. No route handler required.

This is useful for simple static or semi-static pages where a full route handler is overkill.

## Path Parameters

Use `{name}` syntax in route paths:
```python
@get("/users/{id}/posts/{postId}")
async def user_post(request, response):
    user_id = request.params["id"]
    post_id = request.params["postId"]
```

### Typed Route Parameters

Add a type suffix to auto-convert parameters to the correct type in the handler:

```python
@get("/users/{id:int}")
async def get_user(request, response):
    user_id = request.params["id"]   # Already an int, no manual casting

@get("/products/{price:float}")
async def by_price(request, response):
    price = request.params["price"]  # Already a float

@get("/docs/{path:path}")
async def serve_doc(request, response):
    file_path = request.params["path"]  # Matches multi-segment paths like "a/b/c"
```

Supported types: `{name:int}`, `{name:float}`, `{name:path}`. Without a type suffix,
parameters remain strings (existing behavior).

## Query Parameters

Access via `request.params`:
```python
# GET /search?q=hello&page=2
@get("/search")
async def search(request, response):
    query = request.params.get("q", "")
    page = int(request.params.get("page", 1))
```

## Middleware

Apply authentication, logging, or other cross-cutting concerns. Middleware classes use the
naming convention `before_*` / `after_*` for their hook methods.

### Built-in Middleware

Tina4 ships 3 middleware classes ready to use:

| Class | Purpose |
|-------|---------|
| `CorsMiddleware` | Handles CORS headers and preflight requests |
| `RateLimiter` | Rate-limits requests (configurable via `.env`) |
| `RequestLogger` | Logs request method, path, status, and duration |

```python
from tina4 import middleware, CorsMiddleware, RateLimiter, RequestLogger

@middleware(CorsMiddleware, RateLimiter, RequestLogger)
@get("/api/data")
async def get_data(request, response):
    return response({"items": [...]})
```

### Custom Middleware

```python
from tina4 import middleware

class AuthCheck:
    @staticmethod
    async def before_request(request, response):
        token = request.headers.get("Authorization")
        if not token:
            return request, response.json({"error": "Unauthorized"}, 401)
        return request, response

@middleware(AuthCheck)
@get("/protected")
async def protected(request, response):
    return response({"secret": "data"})
```

## Swagger / OpenAPI

Auto-generated at `/swagger`. Add metadata with decorators:

```python
@get("/users")
@description("List all active users")
@tags(["Users"])
@example(200, [{"id": 1, "name": "Alice"}])
async def list_users(request, response):
    users = User().select("*").where("is_active = ?", [True]).fetch()
    return response(users)
```

## CSRF / Form Token Protection

All state-changing forms must include a CSRF token. Tina4 provides this built-in:

### In Frond Templates
```twig
<form method="post" action="/contact">
    {{ formToken() }}
    <input type="text" name="name">
    <button type="submit">Send</button>
</form>
```

The `{{ formToken() }}` tag generates a hidden input with a secure token. The framework
validates it automatically on POST/PUT/DELETE requests. If the token is missing or invalid,
the request is rejected.

### In API Routes (frond.js)
frond.js automatically attaches the form token when configured:
```javascript
Frond.config({ csrfToken: document.querySelector('meta[name="csrf-token"]').content });
```

**Never skip CSRF protection on forms.** It prevents cross-site request forgery attacks.

## CORS

Built-in via `CorsMiddleware`. Configure in `.env` or it defaults to allowing all origins in development.

## Rate Limiting

Built-in via `RateLimiter`. No configuration needed for sensible defaults. Override in `.env` if needed.
