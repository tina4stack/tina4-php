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
        $_ENV['TINA4_SECRET'] = $this->secret;
    }

    protected function tearDown(): void
    {
        unset($_ENV['TINA4_SECRET']);
        unset($_ENV['TINA4_JWT_ALGORITHM']);
        // Clear getenv-side too so cross-test pollution can't shadow $_ENV in
        // the next test (regression guard for the CI failure fixed in 3.11.34)
        putenv('TINA4_SECRET');
        putenv('TINA4_JWT_ALGORITHM');
    }

    // ── Secret resolution (regression guard for 3.11.34 CI fix) ────────
    // Both Auth::getToken and Auth::validToken must resolve TINA4_SECRET in the
    // SAME priority order — getenv() first, $_ENV second. If they differ,
    // a token signed with one source can't be verified with the other,
    // which manifests as a 401 on every secure route whenever an ambient
    // env shadows a runtime putenv() override.

    public function testGetenvOverridesStaleEnvSuperglobal(): void
    {
        // Simulate a CI runner where $_ENV['TINA4_SECRET'] was set by .env load
        // (or a prior test) and a runtime putenv() then overrides it.
        $_ENV['TINA4_SECRET'] = 'stale-env-superglobal-value';
        putenv('TINA4_SECRET=runtime-override-value');

        // Sign with no-arg getToken (resolves from env) and verify the same way
        $token = Auth::getToken(['sub' => 'tester']);
        $this->assertNotNull(Auth::validToken($token), 'Auth must resolve TINA4_SECRET consistently between getToken and validToken');

        putenv('TINA4_SECRET'); // clear getenv side
        // $_ENV is restored by tearDown
    }

    public function testExplicitSecretToGetTokenStillVerifiesViaEnv(): void
    {
        // SmokeTest pattern: getToken receives an explicit secret AND
        // putenv() is set to the same value. validToken (no arg) must
        // resolve to the runtime putenv() value, not a stale $_ENV.
        $_ENV['TINA4_SECRET'] = 'stale-env-superglobal-value';
        putenv('TINA4_SECRET=explicit-runtime-secret');

        $token = Auth::getToken(['sub' => 'tester'], 'explicit-runtime-secret');
        $this->assertNotNull(Auth::validToken($token));

        putenv('TINA4_SECRET');
    }

    public function testValidTokenPrefersGetenvOverEnvSuperglobal(): void
    {
        // Direct probe: getenv and $_ENV disagree. The token is signed with
        // the getenv value; validation must accept it.
        $_ENV['TINA4_SECRET'] = 'env-superglobal-value';
        putenv('TINA4_SECRET=getenv-value');

        $token = Auth::getToken(['sub' => 'tester'], 'getenv-value');

        // No-arg validation must use getenv() — same source getToken used
        $this->assertNotNull(Auth::validToken($token));

        putenv('TINA4_SECRET');
    }

    public function testJwtAlgorithmAlsoPrefersGetenv(): void
    {
        // Same priority rule must apply to TINA4_JWT_ALGORITHM (parity with TINA4_SECRET)
        $_ENV['TINA4_JWT_ALGORITHM'] = 'RS256';            // stale
        putenv('TINA4_JWT_ALGORITHM=HS256');                // runtime override
        $_ENV['TINA4_SECRET'] = 'stale';
        putenv('TINA4_SECRET=test-secret-key-for-jwt');

        $token = Auth::getToken(['sub' => 'tester']);

        // If the algorithm resolution were broken, signing or verification
        // would mismatch. PASS proves both resolve identically.
        $this->assertNotNull(Auth::validToken($token));

        putenv('TINA4_SECRET');
        putenv('TINA4_JWT_ALGORITHM');
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
        // expiresIn is now MINUTES (parity with Python/Ruby) — 60 minutes = 3600 seconds
        $token = Auth::getToken(['sub' => '123'], 60);
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

        $this->assertNotNull(Auth::validToken($token));
        $payload = Auth::getPayload($token);
        $this->assertEquals('123', $payload['sub']);
        $this->assertEquals('admin', $payload['role']);
    }

    public function testVerifyTokenWrongSecret(): void
    {
        // Generate token with correct secret, then switch env to wrong secret for validation
        $token = Auth::getToken(['sub' => '123']);
        $_ENV['TINA4_SECRET'] = 'wrong-secret';
        $result = Auth::validToken($token);
        $_ENV['TINA4_SECRET'] = $this->secret;

        $this->assertNull($result);
    }

    public function testVerifyTokenTampered(): void
    {
        $token = Auth::getToken(['sub' => '123']);
        // Tamper with the payload
        $parts = explode('.', $token);
        $parts[1] = rtrim(strtr(base64_encode('{"sub":"hacked","iat":' . time() . ',"exp":' . (time() + 3600) . '}'), '+/', '-_'), '=');
        $tampered = implode('.', $parts);

        $this->assertNull(Auth::validToken($tampered));
    }

    public function testVerifyTokenExpired(): void
    {
        // Generate a token with exp set in the past
        $token = Auth::getToken(['sub' => '123', 'exp' => time() - 10], 0);

        $this->assertNull(Auth::validToken($token));
    }

    public function testVerifyTokenMalformed(): void
    {
        $this->assertNull(Auth::validToken('not.a.valid.token'));
        $this->assertNull(Auth::validToken('only-one-part'));
        $this->assertNull(Auth::validToken(''));
    }

    public function testVerifyTokenInvalidBase64(): void
    {
        $this->assertNull(Auth::validToken('abc.!!!.def'));
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

        $_ENV['TINA4_SECRET'] = $privateKey;
        $_ENV['TINA4_JWT_ALGORITHM'] = 'RS256';

        $token = Auth::getToken(['sub' => 'rs256-user', 'role' => 'admin'], 3600);

        $this->assertNotEmpty($token);
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);

        // Verify with public key
        $_ENV['TINA4_SECRET'] = $publicKey;
        $this->assertNotNull(Auth::validToken($token));
        $payload = Auth::getPayload($token);
        $this->assertEquals('rs256-user', $payload['sub']);
        $this->assertEquals('admin', $payload['role']);

        // Restore
        $_ENV['TINA4_SECRET'] = $this->secret;
        unset($_ENV['TINA4_JWT_ALGORITHM']);
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

        $_ENV['TINA4_SECRET'] = $privateKey1;
        $_ENV['TINA4_JWT_ALGORITHM'] = 'RS256';
        $token = Auth::getToken(['sub' => 'test'], 3600);

        // Verify with wrong public key
        $_ENV['TINA4_SECRET'] = $publicKey2;
        $result = Auth::validToken($token);

        // Restore
        $_ENV['TINA4_SECRET'] = $this->secret;
        unset($_ENV['TINA4_JWT_ALGORITHM']);

        $this->assertNull($result);
    }

    public function testRS256HeaderAlgorithm(): void
    {
        $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $keyPair = openssl_pkey_new($config);
        openssl_pkey_export($keyPair, $privateKey);

        $_ENV['TINA4_SECRET'] = $privateKey;
        $_ENV['TINA4_JWT_ALGORITHM'] = 'RS256';
        $token = Auth::getToken(['sub' => '1'], 3600);

        // Restore
        $_ENV['TINA4_SECRET'] = $this->secret;
        unset($_ENV['TINA4_JWT_ALGORITHM']);

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

        // A REAL Tina4\Request carrying a real Authorization header
        $request = $this->requestWithAuthHeader("Bearer $token");

        $result = $middleware($request);
        $this->assertNotNull($result);
        $this->assertEquals('user-1', $result['sub']);
        $this->assertEquals('admin', $result['role']);
    }

    public function testMiddlewareExpiredToken(): void
    {
        $token = Auth::getToken(['sub' => 'user-1', 'exp' => time() - 10], 0);

        $middleware = Auth::middleware();
        $request = $this->requestWithAuthHeader("Bearer $token");

        $result = $middleware($request);
        $this->assertNull($result);
    }

    public function testMiddlewareMissingToken(): void
    {
        $middleware = Auth::middleware();
        $request = $this->requestWithAuthHeader('');

        $result = $middleware($request);
        $this->assertNull($result);
    }

    public function testMiddlewareInvalidBearerFormat(): void
    {
        $middleware = Auth::middleware();
        $request = $this->requestWithAuthHeader('Basic dXNlcjpwYXNz');

        $result = $middleware($request);
        $this->assertNull($result);
    }

    public function testMiddlewareInvalidToken(): void
    {
        $middleware = Auth::middleware();
        $request = $this->requestWithAuthHeader('Bearer invalid.token.here');

        $result = $middleware($request);
        $this->assertNull($result);
    }

    /**
     * A NON-Bearer scheme carrying an OTHERWISE-VALID token must be rejected.
     *
     * Auth::middleware() slices the token with substr($header, 7) AFTER checking
     * the 'Bearer ' prefix. Any other 7-character prefix therefore lines a real
     * JWT up at exactly the same offset — 'Token  <jwt>' is the shortest example.
     * Every other middleware test feeds a token that fails validToken() anyway,
     * so deleting the scheme guard entirely left all five of them GREEN while
     * `Authorization: Token  <jwt>` authenticated successfully. This is the one
     * assertion that actually pins the scheme check.
     */
    public function testMiddlewareRejectsNonBearerSchemeCarryingAValidToken(): void
    {
        $token = Auth::getToken(['sub' => 'attacker']);

        $middleware = Auth::middleware();
        // 'Token  ' is 7 chars, so substr($header, 7) is the untouched JWT.
        $request = $this->requestWithAuthHeader("Token  $token");

        $this->assertNull(
            $middleware($request),
            'Only the Bearer scheme may authenticate; a 7-char lookalike prefix must not.'
        );
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

        $this->assertNotNull(Auth::validToken($refreshed));
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
        // expiresIn is in MINUTES (parity with Python/Ruby) — 120 min = 7200 sec
        $original = Auth::getToken(['sub' => '1'], 60);
        $refreshed = Auth::refreshToken($original, 120);

        $this->assertNotNull(Auth::validToken($refreshed));
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

        $_ENV['TINA4_SECRET'] = $privateKey;
        $_ENV['TINA4_JWT_ALGORITHM'] = 'RS256';

        $token = Auth::getToken(['sub' => 'test', 'exp' => time() - 10], 0);

        $_ENV['TINA4_SECRET'] = $publicKey;
        $result = Auth::validToken($token); // Expired

        // Restore
        $_ENV['TINA4_SECRET'] = $this->secret;
        unset($_ENV['TINA4_JWT_ALGORITHM']);

        $this->assertNull($result);
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
        $this->assertNull(Auth::validToken('header.payload'));
    }

    public function testFourPartToken(): void
    {
        $this->assertNull(Auth::validToken('a.b.c.d'));
    }

    public function testGetPayloadTwoParts(): void
    {
        $this->assertNull(Auth::getPayload('a.b'));
    }

    // ── JWT Standard Claims ──────────────────────────────────────

    public function testSubClaimPreserved(): void
    {
        $token = Auth::getToken(['sub' => 'user:1', 'iss' => 'tina4']);
        $this->assertNotNull(Auth::validToken($token));
        $payload = Auth::getPayload($token);
        $this->assertEquals('user:1', $payload['sub']);
        $this->assertEquals('tina4', $payload['iss']);
    }

    public function testCustomClaimsPreserved(): void
    {
        $token = Auth::getToken(['roles' => ['admin', 'editor'], 'org' => 'acme']);
        $this->assertNotNull(Auth::validToken($token));
        $payload = Auth::getPayload($token);
        $this->assertEquals(['admin', 'editor'], $payload['roles']);
        $this->assertEquals('acme', $payload['org']);
    }

    // ── Helper ────────────────────────────────────────────────────

    /**
     * Build a REAL Tina4\Request carrying a real Authorization header.
     *
     * NOT a double. This used to return an anonymous one-method object that
     * re-implemented header() by hand, so all five Auth::middleware() tests
     * asserted against a fabricated request. Auth::middleware() takes
     * `object $request` and calls `$request->header('Authorization')`, so any
     * divergence between the fake's lookup and the real Request's — header
     * case handling, an absent header returning '' rather than null — left
     * these tests green while the middleware failed on real traffic. This is
     * the auth gate, so that gap was an authentication-bypass blind spot.
     *
     * The real Request stores headers in a CaseInsensitiveArray, so passing
     * the canonical 'Authorization' spelling exercises the real normalisation.
     * Passing '' means "no Authorization header at all" — the real absent-header
     * case, rather than a fake mapping '' to null.
     */
    private function requestWithAuthHeader(string $authHeader): \Tina4\Request
    {
        return \Tina4\Request::create(
            method: 'GET',
            path: '/protected',
            headers: $authHeader === '' ? [] : ['Authorization' => $authHeader],
        );
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
