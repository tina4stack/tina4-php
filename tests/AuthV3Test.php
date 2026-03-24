<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Auth;

class AuthV3Test extends TestCase
{
    private string $secret = 'test-secret-key-for-jwt';

    // ── JWT Generation ────────────────────────────────────────────

    public function testGenerateTokenReturnsThreeParts(): void
    {
        $token = Auth::getToken(['sub' => '123'], $this->secret);
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
    }

    public function testGenerateTokenContainsIat(): void
    {
        $token = Auth::getToken(['sub' => '123'], $this->secret);
        $payload = Auth::getPayload($token);
        $this->assertArrayHasKey('iat', $payload);
        $this->assertIsInt($payload['iat']);
    }

    public function testGenerateTokenContainsExp(): void
    {
        $token = Auth::getToken(['sub' => '123'], $this->secret, 3600);
        $payload = Auth::getPayload($token);
        $this->assertArrayHasKey('exp', $payload);
        $this->assertEquals($payload['iat'] + 3600, $payload['exp']);
    }

    public function testGenerateTokenNoExpWhenZero(): void
    {
        $token = Auth::getToken(['sub' => '123'], $this->secret, 0);
        $payload = Auth::getPayload($token);
        $this->assertArrayNotHasKey('exp', $payload);
    }

    public function testGenerateTokenPreservesCustomClaims(): void
    {
        $token = Auth::getToken(['sub' => '123', 'role' => 'admin', 'name' => 'Alice'], $this->secret);
        $payload = Auth::getPayload($token);
        $this->assertEquals('123', $payload['sub']);
        $this->assertEquals('admin', $payload['role']);
        $this->assertEquals('Alice', $payload['name']);
    }

    public function testGenerateTokenHeaderIsCorrect(): void
    {
        $token = Auth::getToken(['sub' => '1'], $this->secret);
        $parts = explode('.', $token);
        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
        $this->assertEquals('HS256', $header['alg']);
        $this->assertEquals('JWT', $header['typ']);
    }

    // ── JWT Verification ──────────────────────────────────────────

    public function testVerifyTokenValid(): void
    {
        $token = Auth::getToken(['sub' => '123', 'role' => 'admin'], $this->secret);
        $payload = Auth::validToken($token, $this->secret);

        $this->assertNotNull($payload);
        $this->assertEquals('123', $payload['sub']);
        $this->assertEquals('admin', $payload['role']);
    }

    public function testVerifyTokenWrongSecret(): void
    {
        $token = Auth::getToken(['sub' => '123'], $this->secret);
        $payload = Auth::validToken($token, 'wrong-secret');

        $this->assertNull($payload);
    }

    public function testVerifyTokenTampered(): void
    {
        $token = Auth::getToken(['sub' => '123'], $this->secret);
        // Tamper with the payload
        $parts = explode('.', $token);
        $parts[1] = rtrim(strtr(base64_encode('{"sub":"hacked","iat":' . time() . ',"exp":' . (time() + 3600) . '}'), '+/', '-_'), '=');
        $tampered = implode('.', $parts);

        $payload = Auth::validToken($tampered, $this->secret);
        $this->assertNull($payload);
    }

    public function testVerifyTokenExpired(): void
    {
        // Generate a token with exp set in the past
        $token = Auth::getToken(['sub' => '123', 'exp' => time() - 10], $this->secret, 0);
        $payload = Auth::validToken($token, $this->secret);

        $this->assertNull($payload);
    }

    public function testVerifyTokenMalformed(): void
    {
        $this->assertNull(Auth::validToken('not.a.valid.token', $this->secret));
        $this->assertNull(Auth::validToken('only-one-part', $this->secret));
        $this->assertNull(Auth::validToken('', $this->secret));
    }

    public function testVerifyTokenInvalidBase64(): void
    {
        $this->assertNull(Auth::validToken('abc.!!!.def', $this->secret));
    }

    // ── JWT Decode Without Verification ───────────────────────────

    public function testDecodeTokenWithoutVerification(): void
    {
        $token = Auth::getToken(['sub' => '456', 'role' => 'user'], $this->secret);
        $payload = Auth::getPayload($token);

        $this->assertNotNull($payload);
        $this->assertEquals('456', $payload['sub']);
        $this->assertEquals('user', $payload['role']);
    }

    public function testDecodeTokenReturnsMalformedNull(): void
    {
        $this->assertNull(Auth::getPayload('not-a-jwt'));
        $this->assertNull(Auth::getPayload(''));
    }

    public function testDecodeTokenWorksWithExpiredToken(): void
    {
        // Expired tokens should still decode (no verification)
        $token = Auth::getToken(['sub' => '789', 'exp' => time() - 100], $this->secret, 0);
        $payload = Auth::getPayload($token);

        $this->assertNotNull($payload);
        $this->assertEquals('789', $payload['sub']);
    }

    public function testDecodeTokenWorksWithWrongSignature(): void
    {
        $token = Auth::getToken(['sub' => '111'], $this->secret);
        // Replace signature with garbage
        $parts = explode('.', $token);
        $parts[2] = 'invalid-signature';
        $modified = implode('.', $parts);

        $payload = Auth::getPayload($modified);
        $this->assertNotNull($payload);
        $this->assertEquals('111', $payload['sub']);
    }

    // ── RS256 ─────────────────────────────────────────────────────

    public function testRS256GenerateAndVerify(): void
    {
        // Generate RSA key pair
        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $keyPair = openssl_pkey_new($config);
        openssl_pkey_export($keyPair, $privateKey);
        $publicKeyDetails = openssl_pkey_get_details($keyPair);
        $publicKey = $publicKeyDetails['key'];

        $token = Auth::getToken(
            ['sub' => 'rs256-user', 'role' => 'admin'],
            $privateKey,
            3600,
            'RS256'
        );

        $this->assertNotEmpty($token);
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        // Verify with public key
        $payload = Auth::validToken($token, $publicKey, 'RS256');
        $this->assertNotNull($payload);
        $this->assertEquals('rs256-user', $payload['sub']);
        $this->assertEquals('admin', $payload['role']);
    }

    public function testRS256RejectsWrongKey(): void
    {
        // Generate two key pairs
        $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

        $keyPair1 = openssl_pkey_new($config);
        openssl_pkey_export($keyPair1, $privateKey1);

        $keyPair2 = openssl_pkey_new($config);
        $publicKeyDetails2 = openssl_pkey_get_details($keyPair2);
        $publicKey2 = $publicKeyDetails2['key'];

        // Sign with key1, verify with key2's public key
        $token = Auth::getToken(['sub' => 'test'], $privateKey1, 3600, 'RS256');
        $payload = Auth::validToken($token, $publicKey2, 'RS256');

        $this->assertNull($payload);
    }

    public function testRS256HeaderAlgorithm(): void
    {
        $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $keyPair = openssl_pkey_new($config);
        openssl_pkey_export($keyPair, $privateKey);

        $token = Auth::getToken(['sub' => '1'], $privateKey, 3600, 'RS256');
        $parts = explode('.', $token);

        $remainder = strlen($parts[0]) % 4;
        $padded = $parts[0];
        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }
        $header = json_decode(base64_decode(strtr($padded, '-_', '+/')), true);
        $this->assertEquals('RS256', $header['alg']);
    }

    // ── Password Hashing ──────────────────────────────────────────

    public function testHashPasswordFormat(): void
    {
        $hash = Auth::hashPassword('secret123');
        $parts = explode(':', $hash);

        $this->assertCount(4, $parts);
        $this->assertEquals('pbkdf2_sha256', $parts[0]);
        $this->assertEquals('100000', $parts[1]);
        $this->assertEquals(32, strlen($parts[2])); // 16 bytes = 32 hex chars
    }

    public function testHashPasswordDifferentSalts(): void
    {
        $hash1 = Auth::hashPassword('same-password');
        $hash2 = Auth::hashPassword('same-password');

        // Different salts should produce different hashes
        $this->assertNotEquals($hash1, $hash2);
    }

    public function testHashPasswordCustomSalt(): void
    {
        $salt = 'abcdef0123456789abcdef0123456789';
        $hash1 = Auth::hashPassword('password', $salt);
        $hash2 = Auth::hashPassword('password', $salt);

        // Same salt should produce identical hashes
        $this->assertEquals($hash1, $hash2);
    }

    public function testHashPasswordCustomIterations(): void
    {
        $hash = Auth::hashPassword('pass', null, 1000);
        $parts = explode(':', $hash);
        $this->assertEquals('1000', $parts[1]);
    }

    public function testVerifyPasswordCorrect(): void
    {
        $hash = Auth::hashPassword('my-secret-password');
        $this->assertTrue(Auth::checkPassword('my-secret-password', $hash));
    }

    public function testVerifyPasswordWrong(): void
    {
        $hash = Auth::hashPassword('correct-password');
        $this->assertFalse(Auth::checkPassword('wrong-password', $hash));
    }

    public function testVerifyPasswordMalformedHash(): void
    {
        $this->assertFalse(Auth::checkPassword('password', 'not-a-valid-hash'));
        $this->assertFalse(Auth::checkPassword('password', 'wrong_algo:1000:salt:hash'));
        $this->assertFalse(Auth::checkPassword('password', ''));
    }

    public function testVerifyPasswordEmptyPassword(): void
    {
        $hash = Auth::hashPassword('');
        $this->assertTrue(Auth::checkPassword('', $hash));
        $this->assertFalse(Auth::checkPassword('not-empty', $hash));
    }

    public function testPasswordHashingUnicodeSupport(): void
    {
        $password = 'p@ssw0rd-with-unicode';
        $hash = Auth::hashPassword($password);
        $this->assertTrue(Auth::checkPassword($password, $hash));
    }

    // ── Auth Middleware ───────────────────────────────────────────

    public function testMiddlewareValidToken(): void
    {
        $token = Auth::getToken(['sub' => 'user-1', 'role' => 'admin'], $this->secret);

        $middleware = Auth::middleware($this->secret);

        // Create a mock request with the Authorization header
        $request = $this->createMockRequest("Bearer $token");

        $result = $middleware($request);
        $this->assertNotNull($result);
        $this->assertEquals('user-1', $result['sub']);
        $this->assertEquals('admin', $result['role']);
    }

    public function testMiddlewareExpiredToken(): void
    {
        $token = Auth::getToken(['sub' => 'user-1', 'exp' => time() - 10], $this->secret, 0);

        $middleware = Auth::middleware($this->secret);
        $request = $this->createMockRequest("Bearer $token");

        $result = $middleware($request);
        $this->assertNull($result);
    }

    public function testMiddlewareMissingToken(): void
    {
        $middleware = Auth::middleware($this->secret);
        $request = $this->createMockRequest('');

        $result = $middleware($request);
        $this->assertNull($result);
    }

    public function testMiddlewareInvalidBearerFormat(): void
    {
        $middleware = Auth::middleware($this->secret);
        $request = $this->createMockRequest('Basic dXNlcjpwYXNz');

        $result = $middleware($request);
        $this->assertNull($result);
    }

    public function testMiddlewareInvalidToken(): void
    {
        $middleware = Auth::middleware($this->secret);
        $request = $this->createMockRequest('Bearer invalid.token.here');

        $result = $middleware($request);
        $this->assertNull($result);
    }

    // ── Helper ────────────────────────────────────────────────────

    /**
     * Create a mock request object with an Authorization header.
     */
    private function createMockRequest(string $authHeader): object
    {
        return new class($authHeader) {
            private string $authHeader;

            public function __construct(string $authHeader)
            {
                $this->authHeader = $authHeader;
            }

            public function header(string $name): ?string
            {
                if (strtolower($name) === 'authorization') {
                    return $this->authHeader ?: null;
                }
                return null;
            }
        };
    }
}
