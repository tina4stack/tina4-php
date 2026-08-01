<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tina4\Auth;

/**
 * Regression tests for the JWT algorithm/nbf cluster (php#187 + the algorithm half).
 *
 * Mirrors tina4-python/tests/test_auth_jwt_algorithm_nbf.py. Each test is named for
 * the behaviour it pins and carries a positive AND a negative case, so reverting the
 * fix reproduces the original bug rather than silently passing.
 *
 * No doubles anywhere: these exercise the real Auth against real hash_hmac digests
 * and a real openssl key pair.
 */
class AuthJwtAlgorithmNbfTest extends TestCase
{
    private string $secret = 'jwt-cluster-regression-secret';

    protected function setUp(): void
    {
        // Set BOTH layers so nothing an earlier test left behind can shadow us:
        // Auth resolves getenv() first, then $_ENV.
        putenv("TINA4_SECRET={$this->secret}");
        $_ENV['TINA4_SECRET'] = $this->secret;
        putenv('TINA4_JWT_ALGORITHM');
        unset($_ENV['TINA4_JWT_ALGORITHM']);
    }

    protected function tearDown(): void
    {
        putenv('TINA4_SECRET');
        putenv('TINA4_JWT_ALGORITHM');
        unset($_ENV['TINA4_SECRET'], $_ENV['TINA4_JWT_ALGORITHM']);
    }

    // ── helpers ───────────────────────────────────────────────────

    /** Base64url-decode (RFC 7515) — re-implemented here so the test never leans on Auth's private helper. */
    private static function b64urlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'), true);
    }

    /** Base64url-encode (RFC 7515). */
    private static function b64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** Decode a token into [header array, payload array, raw signature segment]. */
    private static function decodeParts(string $token): array
    {
        [$headerB64, $payloadB64, $signatureB64] = explode('.', $token);
        return [
            json_decode(self::b64urlDecode($headerB64), true),
            json_decode(self::b64urlDecode($payloadB64), true),
            $signatureB64,
        ];
    }

    /** The bytes that were signed: the first two segments joined by a dot. */
    private static function signingInput(string $token): string
    {
        $parts = explode('.', $token);
        return "{$parts[0]}.{$parts[1]}";
    }

    /**
     * Mint a token with an ARBITRARY header but a signature that is genuinely
     * valid for $digest under $secret.
     *
     * This is what makes the alg-pinning tests non-vacuous. Simply rewriting a
     * real token's header proves nothing — that also invalidates the signature
     * (the header is part of the signing input), so the signature check rejects
     * it whether the alg is pinned or not. Only a forgery that survives the
     * signature check can show whether the header's "alg" is being pinned.
     */
    private static function mintWithHeader(array $header, array $claims, string $digest, string $secret): string
    {
        $headerB64 = self::b64urlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $payloadB64 = self::b64urlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES));
        $signature = self::b64urlEncode(hash_hmac($digest, "{$headerB64}.{$payloadB64}", $secret, true));

        return "{$headerB64}.{$payloadB64}.{$signature}";
    }

    /** @return array<string, array{0: string, 1: string, 2: int}> alg => [alg, hash_hmac digest, digest bytes] */
    public static function hmacAlgorithms(): array
    {
        return [
            'HS256' => ['HS256', 'sha256', 32],
            'HS384' => ['HS384', 'sha384', 48],
            'HS512' => ['HS512', 'sha512', 64],
        ];
    }

    // ── the header must name the algorithm that actually signed ───

    /**
     * POSITIVE: for every supported alg the signature is that alg's real HMAC.
     *
     * Before the fix Auth::sign() hardcoded sha256, so an HS512 request emitted a
     * header saying HS512 over an HMAC-SHA256 signature — any RFC-conformant
     * verifier reading the header computed a different digest and rejected it.
     */
    #[DataProvider('hmacAlgorithms')]
    public function testHeaderAlgMatchesTheDigestThatSignedIt(string $alg, string $digest, int $digestBytes): void
    {
        $token = Auth::getToken(['user_id' => 7], $this->secret, 60, $alg);
        [$header, , $signature] = self::decodeParts($token);

        $this->assertSame($alg, $header['alg'], 'header must advertise the requested algorithm');
        $this->assertSame('JWT', $header['typ']);

        $expected = self::b64urlEncode(hash_hmac($digest, self::signingInput($token), $this->secret, true));
        $this->assertSame($expected, $signature, "{$alg} header does not match the signing digest");
    }

    /**
     * NEGATIVE: proves the digest really varies with the alg, independent of the
     * signing input.
     *
     * Comparing an HS256 token's signature to an HS512 token's is NOT a valid
     * probe here — the header is part of the signing input, so the two differ even
     * when both are secretly signed with sha256. The digest LENGTH cannot lie:
     * sha256 is 32 bytes, sha384 48, sha512 64. Under the old hardcoded sha256
     * every token carried 32 bytes regardless of the alg it advertised.
     */
    #[DataProvider('hmacAlgorithms')]
    public function testSignatureLengthIsTheDigestSizeOfTheDeclaredAlg(string $alg, string $digest, int $digestBytes): void
    {
        $token = Auth::getToken(['user_id' => 7], $this->secret, 60, $alg);
        [, , $signature] = self::decodeParts($token);

        $this->assertSame(
            $digestBytes,
            strlen(self::b64urlDecode($signature)),
            "{$alg} signature must be {$digestBytes} raw bytes — a shorter one means the digest was hardcoded"
        );
    }

    /**
     * NEGATIVE: alg pinning, proved with VALIDLY SIGNED forgeries.
     *
     * Each token here carries a header advertising some other algorithm — "none"
     * (the classic substitution downgrade), a different HMAC, or RS256 — but its
     * signature is a genuine HMAC-SHA256 over its own signing input, so it sails
     * through the signature check. Only pinning the header's "alg" to the
     * configured algorithm rejects it. Rewriting a real token's header instead
     * would prove nothing: that breaks the signature, so it is refused with or
     * without the pin.
     *
     * The threat this closes is a shared signing secret across services
     * configured for different algorithms — a token minted for the weaker one
     * must not be accepted by the stronger one.
     */
    public function testTokenIsRejectedWhenHeaderAlgIsNotTheConfiguredOne(): void
    {
        $claims = ['user_id' => 7, 'iat' => time()];

        foreach (['none', 'HS384', 'HS512', 'RS256'] as $forgedAlg) {
            $forged = self::mintWithHeader(
                ['alg' => $forgedAlg, 'typ' => 'JWT'],
                $claims,
                'sha256',
                $this->secret
            );

            // Sanity: the forgery's signature really is valid for HS256, so a
            // rejection can only come from the alg pin.
            [$headerB64, $payloadB64, $signatureB64] = explode('.', $forged);
            $this->assertSame(
                $signatureB64,
                self::b64urlEncode(hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $this->secret, true)),
                'test setup: the forgery must carry a valid HS256 signature'
            );

            $this->assertNull(
                Auth::validToken($forged, $this->secret, 'HS256'),
                "a validly-signed token whose header says alg={$forgedAlg} must be rejected"
            );
        }

        // POSITIVE control: the honest token, header alg included, still validates —
        // so the test rejects the forgery rather than simply everything.
        $honest = self::mintWithHeader(['alg' => 'HS256', 'typ' => 'JWT'], $claims, 'sha256', $this->secret);
        $this->assertNotNull(Auth::validToken($honest, $this->secret, 'HS256'));
    }

    /**
     * NEGATIVE: a validly-signed token whose header has no "alg" at all, or whose
     * header is not JSON, is refused. Both are minted with a correct HS256
     * signature so the signature check cannot be what rejects them.
     */
    public function testTokenWithMissingOrUnparseableHeaderIsRejected(): void
    {
        $claims = ['user_id' => 7, 'iat' => time()];

        $noAlg = self::mintWithHeader(['typ' => 'JWT'], $claims, 'sha256', $this->secret);
        $this->assertNull(Auth::validToken($noAlg, $this->secret, 'HS256'), 'a header with no alg must be rejected');

        // Header segment that decodes to bytes which are not JSON at all.
        $payloadB64 = self::b64urlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES));
        $headerB64 = self::b64urlEncode('this-is-not-json');
        $signature = self::b64urlEncode(hash_hmac('sha256', "{$headerB64}.{$payloadB64}", $this->secret, true));
        $this->assertNull(
            Auth::validToken("{$headerB64}.{$payloadB64}.{$signature}", $this->secret, 'HS256'),
            'an unparseable header must be rejected'
        );
    }

    /**
     * NEGATIVE: a token genuinely signed with one HMAC alg must not validate under
     * another. This is the real-world half of alg pinning — no tampering needed,
     * just a mismatched verifier.
     */
    public function testTokenSignedWithOneAlgIsRejectedByAnother(): void
    {
        $hs512Token = Auth::getToken(['user_id' => 7], $this->secret, 60, 'HS512');

        $this->assertNull(Auth::validToken($hs512Token, $this->secret, 'HS256'));
        $this->assertNull(Auth::validToken($hs512Token, $this->secret, 'HS384'));
        $this->assertNotNull(Auth::validToken($hs512Token, $this->secret, 'HS512'));
    }

    // ── TINA4_JWT_ALGORITHM must actually be read ────────────────

    /**
     * POSITIVE: setting the env var changes the algorithm used to sign AND to
     * verify. It was registered as a known variable and then only half-honoured —
     * anything past HS256 signed with sha256 and failed to verify at all.
     */
    public function testEnvAlgorithmIsHonouredWhenNoExplicitAlgorithmGiven(): void
    {
        putenv('TINA4_JWT_ALGORITHM=HS512');

        $token = Auth::getToken(['user_id' => 1]);
        [$header, , $signature] = self::decodeParts($token);

        $this->assertSame('HS512', $header['alg']);
        $this->assertSame(64, strlen(self::b64urlDecode($signature)), 'env HS512 must sign with sha512');
        $this->assertNotNull(Auth::validToken($token), 'env HS512 must also verify — it used to return null');
    }

    /** NEGATIVE for precedence: an explicit argument must win over the environment. */
    public function testExplicitAlgorithmArgumentBeatsTheEnvVar(): void
    {
        putenv('TINA4_JWT_ALGORITHM=HS512');

        [$header, , $signature] = self::decodeParts(Auth::getToken(['user_id' => 1], $this->secret, 60, 'HS256'));

        $this->assertSame('HS256', $header['alg']);
        $this->assertSame(32, strlen(self::b64urlDecode($signature)));
    }

    /** POSITIVE: with the env var unset the default is HS256. */
    public function testDefaultIsHs256WhenEnvVarIsUnset(): void
    {
        putenv('TINA4_JWT_ALGORITHM');
        unset($_ENV['TINA4_JWT_ALGORITHM']);

        [$header, , $signature] = self::decodeParts(Auth::getToken(['user_id' => 1]));

        $this->assertSame('HS256', $header['alg']);
        $this->assertSame(32, strlen(self::b64urlDecode($signature)));
    }

    /**
     * NEGATIVE: an algorithm we cannot sign raises instead of quietly downgrading
     * to HS256 — a silent downgrade hands back a weaker token than was asked for.
     */
    public function testUnsupportedAlgorithmFailsLoudlyNamingTheSupportedSet(): void
    {
        try {
            Auth::getToken(['user_id' => 1], $this->secret, 60, 'HS1');
            $this->fail('an unsupported algorithm must throw, not silently downgrade');
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('HS1', $message, 'the message must name the bad value');
            foreach (['HS256', 'HS384', 'HS512', 'RS256'] as $supported) {
                $this->assertStringContainsString($supported, $message);
            }
            $this->assertStringContainsString('TINA4_JWT_ALGORITHM', $message);
        }
    }

    /** NEGATIVE: an unsupported value in the env var raises on both sign and verify. */
    public function testUnsupportedEnvAlgorithmAlsoRaises(): void
    {
        putenv('TINA4_JWT_ALGORITHM=banana');

        $this->expectException(InvalidArgumentException::class);
        Auth::getToken(['user_id' => 1]);
    }

    /** NEGATIVE: validToken must not swallow a bad env algorithm into a plain null. */
    public function testUnsupportedEnvAlgorithmRaisesOnValidation(): void
    {
        $token = Auth::getToken(['user_id' => 1], $this->secret, 60, 'HS256');
        putenv('TINA4_JWT_ALGORITHM=banana');

        $this->expectException(InvalidArgumentException::class);
        Auth::validToken($token);
    }

    // ── php#187: nbf (not-before) must be validated ──────────────

    /**
     * NEGATIVE: a token the issuer marked not-yet-valid must not authenticate.
     * PHP had zero occurrences of "nbf", so a deliberately post-dated credential
     * worked immediately — the whole of php#187.
     */
    public function testPostDatedTokenIsRejectedUntilItsNbfPasses(): void
    {
        $future = time() + Auth::JWT_LEEWAY_SECONDS + 600;
        $token = Auth::getToken(['user_id' => 1, 'nbf' => $future], $this->secret);

        $this->assertNull(Auth::validToken($token, $this->secret), 'a post-dated token must not validate');
    }

    /** POSITIVE: an nbf in the past does not block an otherwise valid token. */
    public function testTokenWhoseNbfHasPassedIsAccepted(): void
    {
        $token = Auth::getToken(['user_id' => 1, 'nbf' => time() - 600], $this->secret);

        $payload = Auth::validToken($token, $this->secret);
        $this->assertNotNull($payload);
        $this->assertSame(1, $payload['user_id']);
    }

    /**
     * POSITIVE: clock-skew tolerance. An nbf a few seconds ahead — the normal case
     * for a token minted on another host — must not be rejected.
     */
    public function testNbfWithinTheLeewayStillValidates(): void
    {
        $slightlyAhead = time() + max(1, intdiv(Auth::JWT_LEEWAY_SECONDS, 2));
        $token = Auth::getToken(['user_id' => 1, 'nbf' => $slightlyAhead], $this->secret);

        $this->assertNotNull(Auth::validToken($token, $this->secret), 'nbf inside the leeway must be tolerated');
    }

    /**
     * POSITIVE: absent nbf means no not-before constraint, so every token already
     * in circulation keeps working — this is what makes the change non-breaking.
     */
    public function testTokenWithoutNbfIsUnaffected(): void
    {
        $token = Auth::getToken(['user_id' => 1], $this->secret);

        [, $payload] = self::decodeParts($token);
        $this->assertArrayNotHasKey('nbf', $payload);
        $this->assertNotNull(Auth::validToken($token, $this->secret));
    }

    /**
     * getToken must NOT auto-stamp an nbf — parity with the Python and Node
     * masters (Ruby's auto-stamp is the parity break being removed). Auto-stamping
     * would make every freshly issued token depend on the verifier's clock.
     */
    public function testGetTokenEmitsNoNbfClaim(): void
    {
        foreach ([0, 60] as $expiresIn) {
            [, $payload] = self::decodeParts(Auth::getToken(['user_id' => 1], $this->secret, $expiresIn));
            $this->assertArrayNotHasKey('nbf', $payload, 'getToken must never stamp an nbf claim');
            $this->assertArrayHasKey('iat', $payload);
        }
    }

    /**
     * NEGATIVE: adding the nbf check must not have displaced the exp check.
     * expiresIn = 0 means "do not stamp an exp", which lets the payload's own
     * (already past) exp survive.
     */
    public function testExpiryIsStillEnforcedAlongsideNbf(): void
    {
        $token = Auth::getToken(['user_id' => 1, 'exp' => time() - 600], $this->secret, 0);

        $this->assertNull(Auth::validToken($token, $this->secret));
    }

    /** NEGATIVE: an expired token is refused even when its nbf has long passed. */
    public function testExpiredTokenWithPastNbfIsStillRejected(): void
    {
        $token = Auth::getToken(
            ['user_id' => 1, 'nbf' => time() - 900, 'exp' => time() - 600],
            $this->secret,
            0
        );

        $this->assertNull(Auth::validToken($token, $this->secret));
    }

    // ── round-trip across every supported algorithm ──────────────

    /** POSITIVE: sign then validate returns the original claims, for every HMAC alg. */
    #[DataProvider('hmacAlgorithms')]
    public function testSignThenValidateRoundTrip(string $alg, string $digest, int $digestBytes): void
    {
        $token = Auth::getToken(['user_id' => 3, 'role' => 'admin'], $this->secret, 60, $alg);

        $payload = Auth::validToken($token, $this->secret, $alg);
        $this->assertNotNull($payload);
        $this->assertSame(3, $payload['user_id']);
        $this->assertSame('admin', $payload['role']);
    }

    /** NEGATIVE: the wrong secret never validates, on any HMAC alg. */
    #[DataProvider('hmacAlgorithms')]
    public function testADifferentSecretNeverValidates(string $alg, string $digest, int $digestBytes): void
    {
        $token = Auth::getToken(['user_id' => 3], $this->secret, 60, $alg);

        $this->assertNull(Auth::validToken($token, 'not-the-secret', $alg));
    }

    // ── RS256 must survive the HMAC work (opt-in extra) ──────────

    /**
     * POSITIVE + NEGATIVE: RS256 still signs and verifies against a real openssl
     * key pair, and the wrong public key still fails. RS256 is the OPT-IN extra
     * in all four frameworks (PHP: suggested ext-openssl; Ruby: stdlib
     * OpenSSL::PKey::RSA; Node: builtin node:crypto; Python: the `cryptography`
     * package the app installs) — it must not be collateral damage of adding the
     * HMAC family, which is the zero-dependency standard everywhere.
     */
    public function testRs256StillSignsAndVerifiesAfterTheHmacWork(): void
    {
        $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($keyPair, $privateKey);
        $publicKey = openssl_pkey_get_details($keyPair)['key'];

        $otherPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $otherPublicKey = openssl_pkey_get_details($otherPair)['key'];

        $token = Auth::getToken(['user_id' => 9, 'role' => 'admin'], $privateKey, 60, 'RS256');
        [$header, , $signature] = self::decodeParts($token);

        $this->assertSame('RS256', $header['alg']);
        // 2048-bit RSA signature = 256 bytes; an HMAC digest could never be that long.
        $this->assertSame(256, strlen(self::b64urlDecode($signature)));

        $payload = Auth::validToken($token, $publicKey, 'RS256');
        $this->assertNotNull($payload, 'RS256 must still verify with the matching public key');
        $this->assertSame(9, $payload['user_id']);

        $this->assertNull(Auth::validToken($token, $otherPublicKey, 'RS256'), 'a foreign public key must not verify');
    }

    /** NEGATIVE: an RS256 token is refused by an HMAC verifier, and vice versa. */
    public function testRs256AndHmacDoNotCrossVerify(): void
    {
        $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($keyPair, $privateKey);
        $publicKey = openssl_pkey_get_details($keyPair)['key'];

        $rs256Token = Auth::getToken(['user_id' => 9], $privateKey, 60, 'RS256');
        $this->assertNull(Auth::validToken($rs256Token, $publicKey, 'HS256'));

        $hs256Token = Auth::getToken(['user_id' => 9], $this->secret, 60, 'HS256');
        $this->assertNull(Auth::validToken($hs256Token, $publicKey, 'RS256'));
    }

    /** POSITIVE: RS256 honours nbf too — the claim check is algorithm-independent. */
    public function testRs256AlsoEnforcesNbf(): void
    {
        $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($keyPair, $privateKey);
        $publicKey = openssl_pkey_get_details($keyPair)['key'];

        $future = time() + Auth::JWT_LEEWAY_SECONDS + 600;
        $postDated = Auth::getToken(['user_id' => 9, 'nbf' => $future], $privateKey, 60, 'RS256');
        $this->assertNull(Auth::validToken($postDated, $publicKey, 'RS256'));

        $usable = Auth::getToken(['user_id' => 9, 'nbf' => time() - 600], $privateKey, 60, 'RS256');
        $this->assertNotNull(Auth::validToken($usable, $publicKey, 'RS256'));
    }

    // ── authenticateRequest forwards both overrides ──────────────

    /**
     * NEGATIVE: passing $algorithm used to be accepted and dropped on the floor,
     * so a caller asking for HS512 silently validated as HS256.
     */
    public function testAuthenticateRequestHonoursTheAlgorithmOverride(): void
    {
        $token = Auth::getToken(['user_id' => 42], $this->secret, 60, 'HS512');
        $headers = ['Authorization' => "Bearer {$token}"];

        $this->assertNull(Auth::authenticateRequest($headers, $this->secret, 'HS256'));

        $payload = Auth::authenticateRequest($headers, $this->secret, 'HS512');
        $this->assertNotNull($payload);
        $this->assertSame(42, $payload['user_id']);
    }

    /** NEGATIVE + POSITIVE: the secret override is honoured alongside the algorithm one. */
    public function testAuthenticateRequestHonoursTheSecretOverride(): void
    {
        $token = Auth::getToken(['user_id' => 42], 'the-other-secret', 60, 'HS384');
        $headers = ['Authorization' => "Bearer {$token}"];

        $this->assertNull(Auth::authenticateRequest($headers, $this->secret, 'HS384'));

        $payload = Auth::authenticateRequest($headers, 'the-other-secret', 'HS384');
        $this->assertNotNull($payload);
        $this->assertSame(42, $payload['user_id']);
    }

    /** POSITIVE: with no overrides, authenticateRequest resolves the env algorithm. */
    public function testAuthenticateRequestDefaultsToTheEnvAlgorithm(): void
    {
        putenv('TINA4_JWT_ALGORITHM=HS512');

        $token = Auth::getToken(['user_id' => 5]);
        $payload = Auth::authenticateRequest(['Authorization' => "Bearer {$token}"]);

        $this->assertNotNull($payload, 'the env algorithm must apply when no override is passed');
        $this->assertSame(5, $payload['user_id']);
    }

    /** POSITIVE: refreshToken round-trips under a non-default env algorithm. */
    public function testRefreshTokenWorksUnderANonDefaultEnvAlgorithm(): void
    {
        putenv('TINA4_JWT_ALGORITHM=HS384');

        $original = Auth::getToken(['user_id' => 11]);
        $refreshed = Auth::refreshToken($original, 120);

        $this->assertNotNull($refreshed);
        [$header, , $signature] = self::decodeParts($refreshed);
        $this->assertSame('HS384', $header['alg']);
        $this->assertSame(48, strlen(self::b64urlDecode($signature)));
        $this->assertNotNull(Auth::validToken($refreshed));
    }
}
