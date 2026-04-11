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
\Tina4\Get::add("/hello", function(\Tina4\Response $response) {
    return $response("Hello World");
});

\Tina4\Get::add("/users/{id}", function(\Tina4\Response $response, $id) {
    $user = (new User())->find($id);
    return $response->json($user);
});

\Tina4\Post::add("/users", function(\Tina4\Request $request, \Tina4\Response $response) {
    $user = new User($request->body);
    $user->save();
    return $response->json($user, 201);
});
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

## Path Parameters

Use `{name}` syntax in route paths:
```python
@get("/users/{id}/posts/{postId}")
async def user_post(request, response):
    user_id = request.params["id"]
    post_id = request.params["postId"]
```

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

Apply authentication, logging, or other cross-cutting concerns:

### Python
```python
from tina4 import middleware

class AuthCheck:
    @staticmethod
    async def before(request, response):
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

Built-in. Configure in `.env` or it defaults to allowing all origins in development.

## Rate Limiting

Built-in. No configuration needed for sensible defaults. Override in `.env` if needed.

### RateLimiterMiddleware (v3.10.92)

A dedicated `RateLimiterMiddleware` class is available for route-level rate limiting. In Python, the `RateLimiter` class lives in `core/rate_limiter.py`.

**Methods:**
- `before_rate_limit(request, response)` / `beforeRateLimit(request, response)` — called before the rate limit check; return modified request/response
- `check(request, response)` — performs the rate limit check and returns a 429 if exceeded
