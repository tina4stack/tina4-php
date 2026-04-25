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
        $secret = $secret ?? $_ENV['SECRET'] ?? getenv('SECRET') ?: '';
        if ($secret === '') {
            trigger_error('Auth: SECRET not set in .env — using blank secret (insecure)', E_USER_WARNING);
        }

        $algorithm = $_ENV['JWT_ALGORITHM'] ?? getenv('JWT_ALGORITHM') ?: 'HS256';
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
     * @return bool True if the token is valid, false otherwise
     */
    public static function validToken(string $token, ?string $secret = null): bool
    {
        $secret = $secret ?? $_ENV['SECRET'] ?? getenv('SECRET') ?: '';
        if ($secret === '') {
            trigger_error('Auth: SECRET not set in .env — using blank secret (insecure)', E_USER_WARNING);
        }
        $algorithm = $_ENV['JWT_ALGORITHM'] ?? getenv('JWT_ALGORITHM') ?: 'HS256';
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $signingInput = "$headerB64.$payloadB64";

        // Verify signature
        if ($algorithm === 'HS256') {
            $expected = self::sign($signingInput, $secret, $algorithm);
            if (!hash_equals($expected, $signatureB64)) {
                return false;
            }
        } elseif ($algorithm === 'RS256') {
            $sigBytes = self::base64urlDecode($signatureB64);
            $publicKey = openssl_pkey_get_public($secret);
            if ($publicKey === false) {
                return false;
            }
            $result = openssl_verify($signingInput, $sigBytes, $publicKey, OPENSSL_ALGO_SHA256);
            if ($result !== 1) {
                return false;
            }
        } else {
            return false;
        }

        // Decode payload
        $payloadJson = self::base64urlDecode($payloadB64);
        if ($payloadJson === false) {
            return false;
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return false;
        }

        // Check expiration
        if (isset($payload['exp']) && time() > $payload['exp']) {
            return false;
        }

        return true;
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
     * Secret and algorithm are read from env (SECRET, JWT_ALGORITHM).
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
     * Secret and algorithm are read from env (SECRET, JWT_ALGORITHM).
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
