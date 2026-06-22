<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * JWT authentication, password hashing, and auth middleware — zero dependencies.
 * Uses only PHP built-in functions: hash_hmac, openssl_*, hash_pbkdf2, random_bytes.
 *
 * Mirrors the Python implementation in tina4_python.auth.
 */
class Auth
{
    /**
     * Actionable blank-secret warning. Emitted from the lazy per-call resolver
     * (getToken/validToken) AND from the CI/prod path of ensureDevSecret() —
     * identical text everywhere. It names exactly what to set and how, so an
     * operator who hits it in CI/prod knows the next step.
     */
    public const BLANK_SECRET_WARNING = 'Auth: TINA4_SECRET is not set - JWT signing is insecure. '
        . 'Set TINA4_SECRET to a random value (e.g. `openssl rand -hex 32`) in your '
        . 'environment or .env before serving traffic. '
        . 'For LOCAL DEV, set TINA4_DEBUG=true and a per-machine secret is generated '
        . 'automatically into .env.local (gitignored). Seeing this warning means the '
        . 'run was NOT detected as dev - typically a container or CI without '
        . 'TINA4_DEBUG set, or TINA4_ENV=production.';

    // ── Dev secret bootstrap ───────────────────────────────────────

    /**
     * Bootstrap a per-machine DEV signing secret.
     *
     * Run ONCE at server boot, after env load and before auth is used. Behaviour:
     *   - TINA4_SECRET already set → no-op (return null).
     *   - NOT dev, OR in CI, OR production → emit the actionable warning
     *     (warnBlankSecret) and return null. NEVER generates or persists a
     *     secret in CI/prod/non-dev.
     *   - dev (TINA4_DEBUG truthy) AND not CI AND not production AND blank
     *     secret → generate 32 random bytes (64 hex chars), set it in the
     *     process env IMMEDIATELY (available this run), then try to APPEND
     *     `TINA4_SECRET=<secret>` to <cwd>/.env.local (create if missing,
     *     gitignored). On success log an INFO line. On ANY write failure keep
     *     the in-memory secret and warn — NEVER throw (boot must not crash).
     *
     * SECURITY: only the dev-and-not-CI path generates; we only ever write to
     * .env.local, never .env; the default signing secret stays BLANK (never a
     * guessable built-in). Mirrors tina4_python.auth.ensure_dev_secret().
     *
     * @param string|null $cwd Directory to write .env.local into. Tests pass a
     *   tmp dir; production callers pass null (defaults to getcwd()).
     * @return string|null The generated secret, or null when nothing was generated.
     */
    public static function ensureDevSecret(?string $cwd = null): ?string
    {
        // Already configured — nothing to do.
        $current = self::resolveSecret();
        if ($current !== '') {
            return null;
        }

        // Never generate or persist in CI, production, or non-dev. Emit the
        // actionable warning so operators know exactly what to set.
        if (!self::isDev() || self::isCi() || self::isProduction()) {
            self::warnBlankSecret();
            return null;
        }

        // Dev, not CI, not production, blank secret → mint one.
        $newSecret = bin2hex(random_bytes(32)); // 64 hex chars / 32 bytes

        // Make it available for THIS run immediately (every layer the resolver reads).
        $_ENV['TINA4_SECRET'] = $newSecret;
        $_SERVER['TINA4_SECRET'] = $newSecret;
        putenv("TINA4_SECRET={$newSecret}");

        // Persist to .env.local (gitignored). Guarded — a write failure must
        // never crash boot; we keep the in-memory secret and warn.
        $dir = $cwd ?? getcwd();
        $path = rtrim((string) $dir, '/\\') . DIRECTORY_SEPARATOR . '.env.local';
        try {
            $prefix = '';
            if (is_file($path)) {
                $existing = file_get_contents($path);
                if ($existing === false) {
                    throw new \RuntimeException("could not read {$path}");
                }
                // If the file doesn't end in a newline, prepend one so the new
                // key lands on its own line and we don't corrupt the last line.
                if ($existing !== '' && !str_ends_with($existing, "\n")) {
                    $prefix = "\n";
                }
            }
            // Suppress the PHP warning a failed open emits — we detect the
            // false return and throw our own clean exception (caught below).
            $written = @file_put_contents($path, $prefix . "TINA4_SECRET={$newSecret}\n", FILE_APPEND);
            if ($written === false) {
                throw new \RuntimeException("could not write {$path}");
            }
            self::logInfo('Auth: generated a development secret, saved to .env.local (gitignored)');
        } catch (\Throwable $e) {
            // Keep the in-memory secret for this run; just warn.
            self::logWarning(
                'Auth: generated a development secret but could not persist it to .env.local ('
                . $e->getMessage() . ') — using an in-memory secret for this run.'
            );
        }

        return $newSecret;
    }

    /**
     * Resolve TINA4_SECRET from env (putenv first, then $_ENV), '' if blank.
     * Mirrors the lookup used by getToken()/validToken().
     */
    private static function resolveSecret(): string
    {
        return (getenv('TINA4_SECRET') ?: ($_ENV['TINA4_SECRET'] ?? '')) ?: '';
    }

    /** True when running in dev (TINA4_DEBUG truthy). */
    private static function isDev(): bool
    {
        return DotEnv::isTruthy((getenv('TINA4_DEBUG') ?: ($_ENV['TINA4_DEBUG'] ?? '')) ?: '');
    }

    /** True when a CI env var is truthy (de-facto CI indicator). */
    private static function isCi(): bool
    {
        return DotEnv::isTruthy((getenv('CI') ?: ($_ENV['CI'] ?? '')) ?: '');
    }

    /** True when TINA4_ENV is "production". */
    private static function isProduction(): bool
    {
        $env = (getenv('TINA4_ENV') ?: ($_ENV['TINA4_ENV'] ?? '')) ?: 'development';
        return $env === 'production';
    }

    /** Emit the actionable blank-secret warning (Log if available, else trigger_error). */
    private static function warnBlankSecret(): void
    {
        self::logWarning(self::BLANK_SECRET_WARNING);
    }

    /** Log at INFO via Tina4 Log if present, else print to stderr. */
    private static function logInfo(string $message): void
    {
        if (class_exists(Log::class)) {
            Log::info($message);
            return;
        }
        fwrite(STDERR, $message . PHP_EOL);
    }

    /** Log at WARNING via Tina4 Log if present, else trigger a user warning. */
    private static function logWarning(string $message): void
    {
        if (class_exists(Log::class)) {
            Log::warning($message);
            return;
        }
        trigger_error($message, E_USER_WARNING);
    }

    // ── JWT ────────────────────────────────────────────────────────

    /**
     * Create a signed JWT token.
     *
     * @param array           $payload   Claims to include in the token
     * @param string|int|null $secret    Signing secret, or expiresIn int for back-compat (null = read from SECRET env var)
     * @param int             $expiresIn Token lifetime in MINUTES (0 = no expiry, default 60)
     * @return string Encoded JWT (header.payload.signature)
     *
     * @deprecated since 3.11.22 — $expiresIn was previously seconds; it is now MINUTES
     *             to match Python (tina4_python.auth.get_token) and Ruby. Existing callers
     *             passing seconds will produce tokens that live ~60x longer than intended.
     */
    public static function getToken(array $payload, string|int|null $secret = null, int $expiresIn = 60): string
    {
        // Back-compat: if secret is an int it was passed as expiresIn (old 2-arg form)
        if (is_int($secret)) {
            $expiresIn = $secret;
            $secret = null;
        }
        $secret = $secret ?? (getenv('TINA4_SECRET') ?: ($_ENV['TINA4_SECRET'] ?? '')) ?: '';
        if ($secret === '') {
            self::warnBlankSecret();
        }

        $algorithm = (getenv('TINA4_JWT_ALGORITHM') ?: ($_ENV['TINA4_JWT_ALGORITHM'] ?? '')) ?: 'HS256';
        $header = ['alg' => $algorithm, 'typ' => 'JWT'];

        $now = time();
        $payload['iat'] = $now;
        if ($expiresIn > 0) {
            // expiresIn is MINUTES (parity with Python/Ruby) — convert to seconds for exp claim
            $payload['exp'] = $now + ($expiresIn * 60);
        }

        $segments = [];
        $segments[] = self::base64urlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $segments[] = self::base64urlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));

        $signingInput = implode('.', $segments);
        $signature = self::sign($signingInput, $secret, $algorithm);

        $segments[] = $signature;

        return implode('.', $segments);
    }

    /**
     * Validate a JWT token's signature and expiry.
     *
     * @param string $token The JWT string
     * @return array<string,mixed>|null Decoded payload on success, null if invalid/expired/malformed
     *
     * 3.13.0 — return type changed from `bool` to `array|null`. The decoded
     * payload is returned on success, matching the convention used by
     * firebase/php-jwt and python-jose. Legacy `if (Auth::validToken($t))`
     * patterns keep working because a non-empty array is truthy and null is
     * falsy. Callers that want the payload no longer need a separate
     * `getPayload($t)` call.
     */
    public static function validToken(string $token, ?string $secret = null): ?array
    {
        $secret = $secret ?? (getenv('TINA4_SECRET') ?: ($_ENV['TINA4_SECRET'] ?? '')) ?: '';
        if ($secret === '') {
            self::warnBlankSecret();
        }
        $algorithm = (getenv('TINA4_JWT_ALGORITHM') ?: ($_ENV['TINA4_JWT_ALGORITHM'] ?? '')) ?: 'HS256';
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $signingInput = "$headerB64.$payloadB64";

        // Verify signature
        if ($algorithm === 'HS256') {
            $expected = self::sign($signingInput, $secret, $algorithm);
            if (!hash_equals($expected, $signatureB64)) {
                return null;
            }
        } elseif ($algorithm === 'RS256') {
            $sigBytes = self::base64urlDecode($signatureB64);
            $publicKey = openssl_pkey_get_public($secret);
            if ($publicKey === false) {
                return null;
            }
            $result = openssl_verify($signingInput, $sigBytes, $publicKey, OPENSSL_ALGO_SHA256);
            if ($result !== 1) {
                return null;
            }
        } else {
            return null;
        }

        // Decode payload
        $payloadJson = self::base64urlDecode($payloadB64);
        if ($payloadJson === false) {
            return null;
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return null;
        }

        // Check expiration
        if (isset($payload['exp']) && time() > $payload['exp']) {
            return null;
        }

        return $payload;
    }

    /**
     * Get the payload from a JWT WITHOUT verifying the signature or expiration.
     * Maps to Python: get_payload(token)
     *
     * @param string $token The JWT string
     * @return array<string, mixed>|null Decoded payload, or null if malformed
     */
    public static function getPayload(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $payloadJson = self::base64urlDecode($parts[1]);
        if ($payloadJson === false) {
            return null;
        }

        $payload = json_decode($payloadJson, true);
        return is_array($payload) ? $payload : null;
    }

    // ── Password Hashing ──────────────────────────────────────────

    /**
     * Hash a password using PBKDF2-HMAC-SHA256.
     *
     * @param string      $password   The plaintext password
     * @param string|null $salt       Optional salt (hex-encoded); random if null
     * @param int         $iterations PBKDF2 iteration count
     * @return string Format: "pbkdf2_sha256$iterations$salt$hash"
     */
    public static function hashPassword(string $password, ?string $salt = null, int $iterations = 260000): string
    {
        if ($salt === null) {
            $salt = bin2hex(random_bytes(16));
        }

        $hash = hash_pbkdf2('sha256', $password, $salt, $iterations, 64, false);

        return "pbkdf2_sha256\${$iterations}\${$salt}\${$hash}";
    }

    /**
     * Check a password against a PBKDF2 hash string.
     * Maps to Python: check_password(hashed, password)
     *
     * @param string $password The plaintext password to verify
     * @param string $hash     The stored hash string (pbkdf2_sha256$iterations$salt$hash)
     * @return bool True if the password matches
     */
    public static function checkPassword(string $password, string $hash): bool
    {
        $parts = explode('$', $hash);
        if (count($parts) !== 4 || $parts[0] !== 'pbkdf2_sha256') {
            return false;
        }

        [, $iterations, $salt, $expectedHash] = $parts;
        $iterations = (int)$iterations;

        $computed = hash_pbkdf2('sha256', $password, $salt, $iterations, 64, false);

        return hash_equals($expectedHash, $computed);
    }

    // ── Middleware Helper ──────────────────────────────────────────

    /**
     * Create an auth middleware callable that verifies Bearer JWT tokens.
     *
     * The middleware extracts the Bearer token from the Authorization header,
     * verifies it, and attaches the decoded payload to the request attributes.
     * Returns a 401 response if the token is missing, invalid, or expired.
     * Secret and algorithm are read from env (TINA4_SECRET, TINA4_JWT_ALGORITHM).
     *
     * @return callable Middleware function (Request $request) => ?array
     */
    public static function middleware(): callable
    {
        return function (object $request): ?array {
            $authHeader = $request->header('Authorization') ?? '';

            if (!str_starts_with($authHeader, 'Bearer ')) {
                return null; // No token — signal 401
            }

            $token = substr($authHeader, 7);
            if (!self::validToken($token)) {
                return null; // Invalid/expired token
            }
            return self::getPayload($token);
        };
    }

    // ── Token Refresh ─────────────────────────────────────────────

    /**
     * Refresh a JWT token — validate then re-sign with fresh expiry.
     * Maps to Python: refresh_token(token, expires_in)
     *
     * @param string $token     The existing JWT
     * @param int    $expiresIn New lifetime in MINUTES (default 60)
     * @return string|null New JWT on success, null if original token is invalid
     *
     * @deprecated since 3.11.22 — $expiresIn was previously seconds; it is now MINUTES
     *             to match Python (tina4_python.auth.refresh_token) and Ruby.
     */
    public static function refreshToken(string $token, int $expiresIn = 60): ?string
    {
        if (!self::validToken($token)) {
            return null;
        }

        $payload = self::getPayload($token);
        if ($payload === null) {
            return null;
        }

        // Remove old timing claims — getToken sets fresh ones
        unset($payload['iat'], $payload['exp']);

        return self::getToken($payload, null, $expiresIn);
    }

    /**
     * Authenticate a request by extracting and validating the Bearer token.
     * Maps to Python: authenticate_request(headers)
     * Secret and algorithm are read from env (TINA4_SECRET, TINA4_JWT_ALGORITHM).
     *
     * @param array<string, string> $headers Request headers (key => value)
     * @return array<string, mixed>|null Decoded payload on success, null on failure
     */
    public static function authenticateRequest(array $headers, ?string $secret = null, string $algorithm = 'HS256'): ?array
    {
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);

            // Try JWT first
            if (self::validToken($token, $secret)) {
                return self::getPayload($token);
            }

            // Fallback: treat Bearer value as API key
            if (self::validateApiKey($token)) {
                return ['_auth' => 'api_key'];
            }
        }

        return null;
    }

    /**
     * Validate an API key by comparing against the configured key.
     * Maps to Python: validate_api_key(provided)
     *
     * @param string $provided The API key provided in the request
     * @param string|null $expected The expected API key (defaults to TINA4_API_KEY env var)
     * @return bool True if the API key is valid
     */
    public static function validateApiKey(string $provided, ?string $expected = null): bool
    {
        if ($expected === null) {
            $expected = DotEnv::getEnv('TINA4_API_KEY');
        }

        if ($expected === null || $expected === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    // ── Internal Helpers ──────────────────────────────────────────

    /**
     * Base64url-encode data (RFC 7515).
     */
    private static function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64url-decode data (RFC 7515).
     */
    private static function base64urlDecode(string $data): string|false
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded;
    }

    /**
     * Sign a message using the specified algorithm.
     *
     * @param string $message   The message to sign
     * @param string $secret    Secret key (HS256) or PEM private key (RS256)
     * @param string $algorithm 'HS256' or 'RS256'
     * @return string Base64url-encoded signature
     */
    private static function sign(string $message, string $secret, string $algorithm): string
    {
        if ($algorithm === 'RS256') {
            $privateKey = openssl_pkey_get_private($secret);
            openssl_sign($message, $signature, $privateKey, OPENSSL_ALGO_SHA256);
            return self::base64urlEncode($signature);
        }

        // Default: HS256
        $signature = hash_hmac('sha256', $message, $secret, true);
        return self::base64urlEncode($signature);
    }
}
