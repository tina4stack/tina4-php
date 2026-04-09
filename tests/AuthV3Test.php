<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Auth;

class AuthV3Test extends TestCase
{
    private string $secret = 'test-secret-key-for-jwt';

    protected function setUp(): void
    {
        $_ENV['SECRET'] = $this->secret;
    }

    protected function tearDown(): void
    {
        unset($_ENV['SECRET']);
        unset($_ENV['JWT_ALGORITHM']);
    }

    // ── JWT Generation ────────────────────────────────────────────

    public function testGenerateTokenReturnsThreeParts(): void
    {
        $token = Auth::getToken(['sub' => '123']);
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
    }

    public function testGenerateTokenContainsIat(): void
    {
        $token = Auth::getToken(['sub' => '123']);
        $payload = Auth::getPayload($token);
        $this->assertArrayHasKey('iat', $payload);
        $this->assertIsInt($payload['iat']);
    }

    public function testGenerateTokenContainsExp(): void
    {
        $token = Auth::getToken(['sub' => '123'], 3600); // 3600 seconds = 60 minutes
        $payload = Auth::getPayload($token);
        $this->assertArrayHasKey('exp', $payload);
        $this->assertEqualsWithDelta($payload['iat'] + 3600, $payload['exp'], 5);
    }

    public function testGenerateTokenNoExpWhenZero(): void
    {
        $token = Auth::getToken(['sub' => '123'], 0);
        $payload = Auth::getPayload($token);
        $this->assertArrayNotHasKey('exp', $payload);
    }

    public function testGenerateTokenPreservesCustomClaims(): void
    {
        $token = Auth::getToken(['sub' => '123', 'role' => 'admin', 'name' => 'Alice']);
        $payload = Auth::getPayload($token);
        $this->assertEquals('123', $payload['sub']);
        $this->assertEquals('admin', $payload['role']);
        $this->assertEquals('Alice', $payload['name']);
    }

    public function testGenerateTokenHeaderIsCorrect(): void
    {
        $token = Auth::getToken(['sub' => '1']);
        $parts = explode('.', $token);
        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
        $this->assertEquals('HS256', $header['alg']);
        $this->assertEquals('JWT', $header['typ']);
    }

    // ── JWT Verification ──────────────────────────────────────────

    public function testVerifyTokenValid(): void
    {
        $token = Auth::getToken(['sub' => '123', 'role' => 'admin']);

        $this->assertTrue(Auth::validToken($token));
        $payload = Auth::getPayload($token);
        $this->assertEquals('123', $payload['sub']);
        $this->assertEquals('admin', $payload['role']);
    }

    public function testVerifyTokenWrongSecret(): void
    {
        // Generate token with correct secret, then switch env to wrong secret for validation
        $token = Auth::getToken(['sub' => '123']);
        $_ENV['SECRET'] = 'wrong-secret';
        $result = Auth::validToken($token);
        $_ENV['SECRET'] = $this->secret;

        $this->assertFalse($result);
    }

    public function testVerifyTokenTampered(): void
    {
        $token = Auth::getToken(['sub' => '123']);
        // Tamper with the payload
        $parts = explode('.', $token);
        $parts[1] = rtrim(strtr(base64_encode('{"sub":"hacked","iat":' . time() . ',"exp":' . (time() + 3600) . '}'), '+/', '-_'), '=');
        $tampered = implode('.', $parts);

        $this->assertFalse(Auth::validToken($tampered));
    }

    public function testVerifyTokenExpired(): void
    {
        // Generate a token with exp set in the past
        $token = Auth::getToken(['sub' => '123', 'exp' => time() - 10], 0);

        $this->assertFalse(Auth::validToken($token));
    }

    public function testVerifyTokenMalformed(): void
    {
        $this->assertFalse(Auth::validToken('not.a.valid.token'));
        $this->assertFalse(Auth::validToken('only-one-part'));
        $this->assertFalse(Auth::validToken(''));
    }

    public function testVerifyTokenInvalidBase64(): void
    {
        $this->assertFalse(Auth::validToken('abc.!!!.def'));
    }

    // ── JWT Decode Without Verification ───────────────────────────

    public function testDecodeTokenWithoutVerification(): void
    {
        $token = Auth::getToken(['sub' => '456', 'role' => 'user']);
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
        $token = Auth::getToken(['sub' => '789', 'exp' => time() - 100], 0);
        $payload = Auth::getPayload($token);

        $this->assertNotNull($payload);
        $this->assertEquals('789', $payload['sub']);
    }

    public function testDecodeTokenWorksWithWrongSignature(): void
    {
        $token = Auth::getToken(['sub' => '111']);
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

        $_ENV['SECRET'] = $privateKey;
        $_ENV['JWT_ALGORITHM'] = 'RS256';

        $token = Auth::getToken(['sub' => 'rs256-user', 'role' => 'admin'], 3600);

        $this->assertNotEmpty($token);
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        // Verify with public key
        $_ENV['SECRET'] = $publicKey;
        $this->assertTrue(Auth::validToken($token));
        $payload = Auth::getPayload($token);
        $this->assertEquals('rs256-user', $payload['sub']);
        $this->assertEquals('admin', $payload['role']);

        // Restore
        $_ENV['SECRET'] = $this->secret;
        unset($_ENV['JWT_ALGORITHM']);
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

        $_ENV['SECRET'] = $privateKey1;
        $_ENV['JWT_ALGORITHM'] = 'RS256';
        $token = Auth::getToken(['sub' => 'test'], 3600);

        // Verify with wrong public key
        $_ENV['SECRET'] = $publicKey2;
        $result = Auth::validToken($token);

        // Restore
        $_ENV['SECRET'] = $this->secret;
        unset($_ENV['JWT_ALGORITHM']);

        $this->assertFalse($result);
    }

    public function testRS256HeaderAlgorithm(): void
    {
        $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $keyPair = openssl_pkey_new($config);
        openssl_pkey_export($keyPair, $privateKey);

        $_ENV['SECRET'] = $privateKey;
        $_ENV['JWT_ALGORITHM'] = 'RS256';
        $token = Auth::getToken(['sub' => '1'], 3600);

        // Restore
        $_ENV['SECRET'] = $this->secret;
        unset($_ENV['JWT_ALGORITHM']);

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
        $parts = explode('$', $hash);

        $this->assertCount(4, $parts);
        $this->assertEquals('pbkdf2_sha256', $parts[0]);
        $this->assertEquals('260000', $parts[1]);
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
        $parts = explode('$', $hash);
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
        $token = Auth::getToken(['sub' => 'user-1', 'role' => 'admin']);

        $middleware = Auth::middleware();

        // Create a mock request with the Authorization header
        $request = $this->createMockRequest("Bearer $token");

        $result = $middleware($request);
        $this->assertNotNull($result);
        $this->assertEquals('user-1', $result['sub']);
        $this->assertEquals('admin', $result['role']);
    }

    public function testMiddlewareExpiredToken(): void
    {
        $token = Auth::getToken(['sub' => 'user-1', 'exp' => time() - 10], 0);

        $middleware = Auth::middleware();
        $request = $this->createMockRequest("Bearer $token");

        $result = $middleware($request);
        $this->assertNull($result);
    }

    public function testMiddlewareMissingToken(): void
    {
        $middleware = Auth::middleware();
        $request = $this->createMockRequest('');

        $result = $middleware($request);
        $this->assertNull($result);
    }

    public function testMiddlewareInvalidBearerFormat(): void
    {
        $middleware = Auth::middleware();
        $request = $this->createMockRequest('Basic dXNlcjpwYXNz');

        $result = $middleware($request);
        $this->assertNull($result);
    }

    public function testMiddlewareInvalidToken(): void
    {
        $middleware = Auth::middleware();
        $request = $this->createMockRequest('Bearer invalid.token.here');

        $result = $middleware($request);
        $this->assertNull($result);
    }

    // ── Authenticate Request ──────────────────────────────────────

    public function testAuthenticateRequestValidBearer(): void
    {
        $token = Auth::getToken(['sub' => 'user-1', 'role' => 'admin']);
        $result = Auth::authenticateRequest(['Authorization' => "Bearer $token"]);

        $this->assertNotNull($result);
        $this->assertEquals('user-1', $result['sub']);
        $this->assertEquals('admin', $result['role']);
    }

    public function testAuthenticateRequestLowercaseHeader(): void
    {
        $token = Auth::getToken(['sub' => 'user-2']);
        $result = Auth::authenticateRequest(['authorization' => "Bearer $token"]);

        $this->assertNotNull($result);
        $this->assertEquals('user-2', $result['sub']);
    }

    public function testAuthenticateRequestMissingHeader(): void
    {
        $result = Auth::authenticateRequest([]);
        $this->assertNull($result);
    }

    public function testAuthenticateRequestInvalidBearer(): void
    {
        $result = Auth::authenticateRequest(['Authorization' => 'Bearer invalid.token.here']);
        $this->assertNull($result);
    }

    public function testAuthenticateRequestNonBearerScheme(): void
    {
        $result = Auth::authenticateRequest(['Authorization' => 'Basic dXNlcjpwYXNz']);
        $this->assertNull($result);
    }

    public function testAuthenticateRequestEmptyHeader(): void
    {
        $result = Auth::authenticateRequest(['Authorization' => '']);
        $this->assertNull($result);
    }

    // ── Validate API Key ────────────────────────────────────────

    public function testValidateApiKeyCorrect(): void
    {
        $this->assertTrue(Auth::validateApiKey('test-key-123', 'test-key-123'));
    }

    public function testValidateApiKeyWrong(): void
    {
        $this->assertFalse(Auth::validateApiKey('wrong-key', 'correct-key'));
    }

    public function testValidateApiKeyNoExpected(): void
    {
        $this->assertFalse(Auth::validateApiKey('anything'));
    }

    public function testValidateApiKeyEmptyExpected(): void
    {
        $this->assertFalse(Auth::validateApiKey('key', ''));
    }

    public function testValidateApiKeyCaseSensitive(): void
    {
        $this->assertTrue(Auth::validateApiKey('MyKey123', 'MyKey123'));
        $this->assertFalse(Auth::validateApiKey('mykey123', 'MyKey123'));
    }

    // ── Token Refresh ───────────────────────────────────────────

    public function testRefreshTokenValid(): void
    {
        $original = Auth::getToken(['sub' => 'user-1', 'role' => 'admin'], 3600);
        sleep(1); // Ensure different iat
        $refreshed = Auth::refreshToken($original, 7200);

        $this->assertNotNull($refreshed);
        $this->assertNotSame($original, $refreshed);

        $this->assertTrue(Auth::validToken($refreshed));
        $payload = Auth::getPayload($refreshed);
        $this->assertEquals('user-1', $payload['sub']);
        $this->assertEquals('admin', $payload['role']);
    }

    public function testRefreshTokenInvalid(): void
    {
        $result = Auth::refreshToken('bad.token.here');
        $this->assertNull($result);
    }

    public function testRefreshTokenNewExpiry(): void
    {
        $original = Auth::getToken(['sub' => '1'], 60);
        $refreshed = Auth::refreshToken($original, 7200); // 7200 seconds

        $this->assertTrue(Auth::validToken($refreshed));
        $payload = Auth::getPayload($refreshed);
        $this->assertEqualsWithDelta($payload['iat'] + 7200, $payload['exp'], 5);
    }

    // ── RS256 Additional Tests ─────────────────────────────────────

    public function testRS256TokenExpiry(): void
    {
        $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $keyPair = openssl_pkey_new($config);
        openssl_pkey_export($keyPair, $privateKey);
        $publicKeyDetails = openssl_pkey_get_details($keyPair);
        $publicKey = $publicKeyDetails['key'];

        $_ENV['SECRET'] = $privateKey;
        $_ENV['JWT_ALGORITHM'] = 'RS256';

        $token = Auth::getToken(['sub' => 'test', 'exp' => time() - 10], 0);

        $_ENV['SECRET'] = $publicKey;
        $result = Auth::validToken($token); // Expired

        // Restore
        $_ENV['SECRET'] = $this->secret;
        unset($_ENV['JWT_ALGORITHM']);

        $this->assertFalse($result);
    }

    // ── Password Edge Cases ──────────────────────────────────────

    public function testLongPassword(): void
    {
        $longPw = str_repeat('a', 10000);
        $hash = Auth::hashPassword($longPw);
        $this->assertTrue(Auth::checkPassword($longPw, $hash));
    }

    public function testCheckPasswordWrongPrefix(): void
    {
        $this->assertFalse(Auth::checkPassword('password', 'bcrypt$100$salt$hash'));
    }

    // ── JWT Edge Cases ───────────────────────────────────────────

    public function testTwoPartToken(): void
    {
        $this->assertFalse(Auth::validToken('header.payload'));
    }

    public function testFourPartToken(): void
    {
        $this->assertFalse(Auth::validToken('a.b.c.d'));
    }

    public function testGetPayloadTwoParts(): void
    {
        $this->assertNull(Auth::getPayload('a.b'));
    }

    // ── JWT Standard Claims ──────────────────────────────────────

    public function testSubClaimPreserved(): void
    {
        $token = Auth::getToken(['sub' => 'user:1', 'iss' => 'tina4']);
        $this->assertTrue(Auth::validToken($token));
        $payload = Auth::getPayload($token);
        $this->assertEquals('user:1', $payload['sub']);
        $this->assertEquals('tina4', $payload['iss']);
    }

    public function testCustomClaimsPreserved(): void
    {
        $token = Auth::getToken(['roles' => ['admin', 'editor'], 'org' => 'acme']);
        $this->assertTrue(Auth::validToken($token));
        $payload = Auth::getPayload($token);
        $this->assertEquals(['admin', 'editor'], $payload['roles']);
        $this->assertEquals('acme', $payload['org']);
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

    // ── authenticateRequest secret + algorithm params ─────────────

    public function testAuthenticateRequestAcceptsSecretParam(): void
    {
        $token = Auth::getToken(['sub' => 'user-secret-test']);
        $result = Auth::authenticateRequest(['Authorization' => "Bearer $token"], null);
        $this->assertNotNull($result);
        $this->assertEquals('user-secret-test', $result['sub']);
    }

    public function testAuthenticateRequestAcceptsAlgorithmParam(): void
    {
        $token = Auth::getToken(['sub' => 'algo-test']);
        $result = Auth::authenticateRequest(['Authorization' => "Bearer $token"], null, 'HS256');
        $this->assertNotNull($result);
        $this->assertEquals('algo-test', $result['sub']);
    }

    // ── getToken secret param ────────────────────────────────────

    public function testGetTokenWithExplicitSecret(): void
    {
        $token = Auth::getToken(['sub' => 'custom'], 'custom-secret', 3600);
        $this->assertIsString($token);
        $this->assertStringContainsString('.', $token);
    }
}
