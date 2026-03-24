# Authentication

The `Auth` class provides JWT token management (HS256 and RS256), PBKDF2 password hashing, and an auth middleware factory. All implementations are zero-dependency, using only PHP built-in functions (`hash_hmac`, `openssl_*`, `hash_pbkdf2`, `random_bytes`).

Combine `Auth` with the Router's `->secure()` modifier for declarative route protection.

## JWT Token Generation (HS256)

```php
use Tina4\Auth;

$secret = 'your-256-bit-secret-key';

// Generate a token with custom payload
$token = Auth::getToken(
    payload: ['user_id' => 42, 'role' => 'admin'],
    secret: $secret,
    expiresIn: 3600, // 1 hour in seconds
);

echo $token;
// eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1c2VyX2lkIjo0Mi...

// Token with no expiration
$permanentToken = Auth::getToken(
    payload: ['api_key' => 'service-account'],
    secret: $secret,
    expiresIn: 0,
);
```

## JWT Token Verification

```php
// Verify and decode
$payload = Auth::validToken($token, $secret);

if ($payload !== null) {
    echo "User ID: " . $payload['user_id'];
    echo "Role: " . $payload['role'];
    echo "Issued at: " . $payload['iat'];
    echo "Expires: " . $payload['exp'];
} else {
    echo "Token is invalid or expired";
}
```

## Decode Without Verification

Useful for inspecting token claims without validating the signature.

```php
$payload = Auth::getPayload($token);

if ($payload !== null) {
    echo "Token claims: " . json_encode($payload);
}
```

## RS256 (RSA) Tokens

For asymmetric signing where the public key is shared and the private key is kept secret.

```php
// Generate RSA keys (done once, store securely)
$config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
$key = openssl_pkey_new($config);
openssl_pkey_export($key, $privateKeyPem);
$publicKeyPem = openssl_pkey_get_details($key)['key'];

// Sign with private key
$token = Auth::getToken(
    payload: ['user_id' => 42],
    secret: $privateKeyPem,
    expiresIn: 3600,
    algorithm: 'RS256',
);

// Verify with public key
$payload = Auth::validToken($token, $publicKeyPem, algorithm: 'RS256');
```

## Password Hashing (PBKDF2)

The `Auth` class uses PBKDF2-HMAC-SHA256 for password hashing with a random 16-byte salt.

```php
// Hash a password
$hash = Auth::hashPassword('my-secure-password');
// Format: "pbkdf2_sha256:100000:random_salt_hex:hash_hex"

// Verify a password
$valid = Auth::checkPassword('my-secure-password', $hash);
// true

$invalid = Auth::checkPassword('wrong-password', $hash);
// false

// Custom iteration count
$strongHash = Auth::hashPassword('password', iterations: 200000);

// With explicit salt (for testing)
$hash = Auth::hashPassword('password', salt: 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4');
```

## Auth Middleware Factory

Create a reusable middleware callable that verifies Bearer JWT tokens on incoming requests.

```php
use Tina4\Router;
use Tina4\Request;
use Tina4\Response;

$secret = $_ENV['JWT_SECRET'] ?? 'dev-secret';

// Create the middleware
$jwtMiddleware = Auth::middleware($secret);

// The middleware extracts the Bearer token, verifies it,
// and returns the payload (array) on success or null on failure.

// Use with route middleware
$authGuard = function (Request $request, Response $response) use ($jwtMiddleware): true|Response {
    $payload = $jwtMiddleware($request);

    if ($payload === null) {
        return $response->json(['error' => 'Invalid or missing authentication token'], 401);
    }

    // Token is valid — payload is available
    // You could attach it to the request for downstream use
    return true;
};

Router::get('/api/profile', function (Request $request, Response $response) {
    return $response->json(['profile' => 'data']);
})->middleware([$authGuard]);
```

## Secure Route Modifier

The `->secure()` modifier on routes performs a basic Bearer token presence check. For full JWT validation, combine it with `Auth::middleware()`.

```php
// Basic secure check (token presence only)
Router::get('/api/data', function (Request $request, Response $response) {
    return $response->json(['data' => 'secret']);
})->secure();

// Full JWT validation via middleware
Router::get('/api/admin', function (Request $request, Response $response) {
    return $response->json(['admin' => true]);
})->secure()->middleware([$authGuard]);
```

## Complete Login Flow

```php
// Registration endpoint
Router::post('/auth/register', function (Request $request, Response $response) {
    $email = $request->input('email');
    $password = $request->input('password');

    // Hash the password
    $hash = Auth::hashPassword($password);

    // Store $email and $hash in the database
    // ...

    return $response->json(['message' => 'Registered'], 201);
});

// Login endpoint
Router::post('/auth/login', function (Request $request, Response $response) {
    $email = $request->input('email');
    $password = $request->input('password');

    // Load user from database
    // $user = ...
    // $storedHash = $user['password_hash'];

    $storedHash = 'pbkdf2_sha256:100000:salt:hash'; // from database

    if (!Auth::checkPassword($password, $storedHash)) {
        return $response->json(['error' => 'Invalid credentials'], 401);
    }

    $secret = $_ENV['JWT_SECRET'];

    // Generate JWT
    $token = Auth::getToken(
        payload: ['user_id' => 1, 'email' => $email, 'role' => 'user'],
        secret: $secret,
        expiresIn: 86400, // 24 hours
    );

    return $response->json(['token' => $token]);
});

// Protected endpoint
Router::get('/auth/me', function (Request $request, Response $response) {
    $token = $request->bearerToken();
    $payload = Auth::validToken($token, $_ENV['JWT_SECRET']);

    return $response->json(['user' => $payload]);
})->secure();
```

## Tips

- Store `JWT_SECRET` in your `.env` file — never hardcode secrets.
- Use RS256 for microservices where the verifier should not have signing capability.
- PBKDF2 with 100,000 iterations is the default — increase for higher security requirements.
- The `Auth::middleware()` factory returns `null` for missing/invalid tokens — your wrapping middleware decides the HTTP response.
- Token `iat` (issued at) and `exp` (expiration) claims are set automatically by `getToken()`.
- `getPayload()` does NOT verify the signature — use it only for debugging or non-security purposes.
