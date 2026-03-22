<?php

/**
 * Tina4 - This is not a 4ramework.
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
     * Primary method name: createToken(). Alias: getToken().
     * Maps to Python: create_token(payload, expiry_minutes)
     *
     * @param array  $payload    Claims to include in the token
     * @param string $secret     Signing secret (HS256) or PEM private key (RS256)
     * @param int    $expiresIn  Token lifetime in seconds (0 = no expiry)
     * @param string $algorithm  'HS256' or 'RS256'
     * @return string Encoded JWT (header.payload.signature)
     */
    public static function createToken(array $payload, string $secret, int $expiresIn = 3600, string $algorithm = 'HS256'): string
    {
        $header = ['alg' => $algorithm, 'typ' => 'JWT'];

        $now = time();
        $payload['iat'] = $now;
        if ($expiresIn > 0) {
            $payload['exp'] = $now + $expiresIn;
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
     * Validate a JWT token and return the decoded payload.
     * Primary method name: validToken(). Alias: validateToken().
     * Maps to Python: validate_token(token)
     *
     * @param string $token     The JWT string
     * @param string $secret    Signing secret (HS256) or PEM public key (RS256)
     * @param string $algorithm 'HS256' or 'RS256'
     * @return array|null Decoded payload on success, null on failure
     */
    public static function validToken(string $token, string $secret, string $algorithm = 'HS256'): ?array
    {
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
     * @return array|null Decoded payload, or null if malformed
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
     * @return string Format: "pbkdf2_sha256:iterations:salt:hash"
     */
    public static function hashPassword(string $password, ?string $salt = null, int $iterations = 100000): string
    {
        if ($salt === null) {
            $salt = bin2hex(random_bytes(16));
        }

        $hash = hash_pbkdf2('sha256', $password, $salt, $iterations, 64, false);

        return "pbkdf2_sha256:$iterations:$salt:$hash";
    }

    /**
     * Check a password against a PBKDF2 hash string.
     * Maps to Python: check_password(hashed, password)
     *
     * @param string $password The plaintext password to verify
     * @param string $hash     The stored hash string (pbkdf2_sha256:iterations:salt:hash)
     * @return bool True if the password matches
     */
    public static function checkPassword(string $password, string $hash): bool
    {
        $parts = explode(':', $hash);
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
     *
     * @param string $secret    Signing secret (HS256) or PEM public key (RS256)
     * @param string $algorithm 'HS256' or 'RS256'
     * @return callable Middleware function (Request $request) => ?array
     */
    public static function middleware(string $secret, string $algorithm = 'HS256'): callable
    {
        return function (object $request) use ($secret, $algorithm): ?array {
            $authHeader = $request->header('Authorization') ?? '';

            if (!str_starts_with($authHeader, 'Bearer ')) {
                return null; // No token — signal 401
            }

            $token = substr($authHeader, 7);
            $payload = self::validToken($token, $secret, $algorithm);

            return $payload; // null if invalid/expired, array if valid
        };
    }

    // ── Token Refresh ─────────────────────────────────────────────

    /**
     * Refresh a JWT token — validate then re-sign with fresh expiry.
     * Maps to Python: refresh_token(token, expiry_minutes)
     *
     * @param string $token     The existing JWT
     * @param string $secret    Signing secret
     * @param int    $expiresIn New lifetime in seconds (default 3600)
     * @param string $algorithm 'HS256' or 'RS256'
     * @return string|null New JWT on success, null if original token is invalid
     */
    public static function refreshToken(string $token, string $secret, int $expiresIn = 3600, string $algorithm = 'HS256'): ?string
    {
        $payload = self::validToken($token, $secret, $algorithm);
        if ($payload === null) {
            return null;
        }

        // Remove old timing claims — createToken sets fresh ones
        unset($payload['iat'], $payload['exp']);

        return self::createToken($payload, $secret, $expiresIn, $algorithm);
    }

    /**
     * Authenticate a request by extracting and validating the Bearer token.
     * Maps to Python: authenticate_request(headers)
     *
     * @param array<string, string> $headers Request headers (key => value)
     * @param string $secret Signing secret
     * @param string $algorithm 'HS256' or 'RS256'
     * @return array|null Decoded payload on success, null on failure
     */
    public static function authenticateRequest(array $headers, string $secret, string $algorithm = 'HS256'): ?array
    {
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (!str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        $token = substr($authHeader, 7);
        return self::validToken($token, $secret, $algorithm);
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

    // ── Backward-Compatible Aliases ──────────────────────────────

    /**
     * Alias for createToken() — kept for backward compatibility.
     */
    public static function getToken(array $payload, string $secret, int $expiresIn = 3600, string $algorithm = 'HS256'): string
    {
        return self::createToken($payload, $secret, $expiresIn, $algorithm);
    }

    /**
     * Alias for validToken() — kept for backward compatibility.
     */
    public static function validateToken(string $token, string $secret, string $algorithm = 'HS256'): ?array
    {
        return self::validToken($token, $secret, $algorithm);
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
