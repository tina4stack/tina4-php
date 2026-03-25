# Set Up Tina4 Authentication

Set up JWT authentication with login, password hashing, and route protection.

## Instructions

1. Ensure `SECRET` is set in `.env`
2. Create a users table with password_hash column (migration)
3. Create login/register routes
4. Protect routes with auth defaults or `->secured()`

## .env

```bash
SECRET=your-secure-random-secret
```

## Migration

```sql
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    role TEXT DEFAULT 'user',
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
```

## Auth Routes (`src/routes/auth.php`)

```php
<?php

use Tina4\Router;
use Tina4\Auth;

Router::post("/api/register", function ($request, $response) {
    $data = $request->body;

    // Check if email already exists
    $existing = new User();
    if ($existing->load("email = ?", [$data["email"] ?? ""])) {
        return $response->json(["error" => "Email already registered"], 409);
    }

    $user = new User([
        "name" => $data["name"],
        "email" => $data["email"],
        "passwordHash" => Auth::hashPassword($data["password"]),
    ]);
    $user->save();
    return $response->json(["id" => $user->id, "name" => $user->name], 201);
})->noAuth()->description("Register a new user")->tags(["auth"]);

Router::post("/api/login", function ($request, $response) {
    $email = $request->body["email"] ?? "";
    $password = $request->body["password"] ?? "";

    $user = new User();
    if (!$user->load("email = ?", [$email])) {
        return $response->json(["error" => "Invalid credentials"], 401);
    }

    if (!Auth::checkPassword($user->passwordHash, $password)) {
        return $response->json(["error" => "Invalid credentials"], 401);
    }

    $token = Auth::getToken(["userId" => $user->id, "email" => $user->email, "role" => $user->role]);
    return $response->json(["token" => $token]);
})->noAuth()->description("Login and get JWT token")->tags(["auth"]);

Router::get("/api/me", function ($request, $response) {
    $token = str_replace("Bearer ", "", $request->headers["authorization"] ?? "");
    $payload = Auth::getPayload($token);
    if (!$payload) {
        return $response->json(["error" => "Invalid token"], 401);
    }
    return $response->json($payload);
})->secured()->description("Get current user profile")->tags(["auth"]);
```

## How Auth Works

- **GET routes** are public by default
- **POST/PUT/PATCH/DELETE routes** require `Authorization: Bearer <token>` by default
- Use `->noAuth()` on write routes that should be public (login, register, webhooks)
- Use `->secured()` on GET routes that need protection (profile, admin pages)

## Auth Functions

```php
<?php

use Tina4\Auth;

// Uses SECRET from .env
$token = Auth::getToken(["userId" => 1]);           // Create JWT
$valid = Auth::validToken($token);                   // Returns true/false
$payload = Auth::getPayload($token);                 // Returns array or null
$newToken = Auth::refreshToken($token);              // Refresh before expiry

$hashed = Auth::hashPassword("my-password");         // PBKDF2-HMAC-SHA256
$valid = Auth::checkPassword($hashed, "my-password"); // Returns true/false
```
