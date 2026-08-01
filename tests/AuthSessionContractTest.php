<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Auth;
use Tina4\Session;

/**
 * Regression tests for the feature 41/42 auth + session contract (ADR-0021).
 *
 * Each test is named for the behaviour it pins and carries a positive AND a
 * negative case, so reverting a fix reproduces the original bug rather than
 * silently passing. The case names are IDENTICAL in all four frameworks
 * (tina4-python/tests/test_auth_session_contract.py,
 * tina4-ruby/spec/auth_session_contract_spec.rb,
 * tina4-nodejs/test/authSessionContract.test.ts).
 *
 * No doubles anywhere: real Auth against real hash_hmac digests, real Session
 * against a real filesystem in a real temp directory.
 */
class AuthSessionContractTest extends TestCase
{
    private const SECRET = 'auth-session-contract-secret';

    /** @var string Temp root for this test's real session directories. */
    private string $tempRoot;

    private ?string $priorSecret = null;
    private ?string $priorApiKey = null;

    protected function setUp(): void
    {
        $this->tempRoot = sys_get_temp_dir() . '/tina4_auth_session_contract_' . bin2hex(random_bytes(6));
        mkdir($this->tempRoot, 0755, true);

        $this->priorSecret = getenv('TINA4_SECRET') ?: null;
        $this->priorApiKey = getenv('TINA4_API_KEY') ?: null;

        // Set BOTH layers so nothing an earlier test left behind can shadow us:
        // Auth resolves getenv() first, then $_ENV.
        putenv('TINA4_SECRET=' . self::SECRET);
        $_ENV['TINA4_SECRET'] = self::SECRET;
        putenv('TINA4_JWT_ALGORITHM');
        unset($_ENV['TINA4_JWT_ALGORITHM']);
        putenv('TINA4_API_KEY');
        unset($_ENV['TINA4_API_KEY']);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->tempRoot);

        putenv('TINA4_SECRET');
        putenv('TINA4_API_KEY');
        putenv('TINA4_JWT_ALGORITHM');
        unset($_ENV['TINA4_SECRET'], $_ENV['TINA4_API_KEY'], $_ENV['TINA4_JWT_ALGORITHM']);

        if ($this->priorSecret !== null) {
            putenv("TINA4_SECRET={$this->priorSecret}");
            $_ENV['TINA4_SECRET'] = $this->priorSecret;
        }
        if ($this->priorApiKey !== null) {
            putenv("TINA4_API_KEY={$this->priorApiKey}");
            $_ENV['TINA4_API_KEY'] = $this->priorApiKey;
        }
    }

    // ── helpers ───────────────────────────────────────────────────

    /** Recursively delete a real directory tree created by a test. */
    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }

    /** Base64url-encode (RFC 7515) — kept local so the test never leans on Auth's private helper. */
    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Mint a token with ARBITRARY claims, correctly signed with a REAL HMAC.
     *
     * The signature is genuine, so every test below isolates the CLAIM check
     * under test rather than accidentally passing because the signature failed.
     *
     * @param array<string, mixed> $claims
     */
    private static function forge(array $claims): string
    {
        $header = self::b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
        $payload = self::b64url(json_encode($claims, JSON_UNESCAPED_SLASHES));
        $signature = self::b64url(hash_hmac('sha256', "{$header}.{$payload}", self::SECRET, true));

        return "{$header}.{$payload}.{$signature}";
    }

    /** A file-backed Session rooted at a REAL directory under this test's temp root. */
    private function fileSession(string $path): Session
    {
        return new Session('file', ['path' => $path, 'ttl' => 3600]);
    }

    // ── 42: a session id is opaque and can never be a filesystem path ──

    /**
     * A traversal id must not read or write outside the session directory.
     *
     * Negative half: the escape target must NOT exist afterwards. Positive
     * half: a normal session in the same directory still round-trips.
     */
    public function testSessionIdFromCookieCannotEscapeTheSessionDirectory(): void
    {
        $sessions = $this->tempRoot . '/data/sessions';
        $outside = $this->tempRoot . '/outside';
        mkdir($sessions, 0755, true);
        mkdir($outside, 0755, true);

        $session = $this->fileSession($sessions);
        $adopted = $session->start('../../outside/pwned');
        $session->set('owned', 'yes');
        $session->save();

        $this->assertFileDoesNotExist(
            $outside . '/pwned.json',
            'session cookie escaped the session directory - arbitrary file write'
        );
        $this->assertNotSame(
            '../../outside/pwned',
            $adopted,
            'a traversal session id was adopted verbatim'
        );

        // Positive half: a legitimate id in the same directory still works.
        $good = $this->fileSession($sessions);
        $goodId = $good->start();
        $good->set('k', 'v');
        $good->save();

        $resumed = $this->fileSession($sessions);
        $resumed->start($goodId);
        $this->assertSame('v', $resumed->get('k'), 'a legitimate session no longer round-trips');
    }

    /** Any id outside the opaque alphabet is replaced, never adopted. */
    public function testSessionIdWithPathSeparatorIsRejectedAndAFreshIdMinted(): void
    {
        $store = $this->tempRoot . '/store';
        mkdir($store, 0755, true);

        $hostileIds = ['../../etc/passwd', 'a/b', 'a\\b', 'a.b', '..', '', 'short'];

        foreach ($hostileIds as $hostile) {
            $session = $this->fileSession($store);
            $adopted = $session->start($hostile);

            $this->assertNotSame(
                $hostile,
                $adopted,
                "hostile session id adopted verbatim: '{$hostile}'"
            );
            $this->assertTrue(
                Session::isValidSessionId($adopted),
                "replacement id is itself invalid for input '{$hostile}'"
            );
        }
    }

    /**
     * The fix must not break the ids the framework itself mints.
     *
     * Negative half is the previous test; this is the positive half and also
     * the non-breaking guarantee: every framework's own id shape stays valid.
     */
    public function testValidGeneratedSessionIdIsAcceptedUnchanged(): void
    {
        $store = $this->tempRoot . '/minted';
        mkdir($store, 0755, true);

        $session = $this->fileSession($store);
        $minted = $session->start();
        $this->assertTrue(Session::isValidSessionId($minted), 'a self-minted id failed its own validator');

        $resumed = $this->fileSession($store);
        $this->assertSame($minted, $resumed->start($minted), 'a self-minted id was not resumed as-is');

        // The id shapes the other three frameworks mint must also be accepted,
        // so a shared Redis/Mongo session store stays readable across the family.
        $foreignIds = [
            '0123456789abcdef0123456789abcdef',  // PHP/Node hex(16)
            str_repeat('0', 64),                 // Ruby hex(32)
            str_repeat('Ab-_9', 8),              // Python token_urlsafe
        ];
        foreach ($foreignIds as $foreign) {
            $this->assertTrue(
                Session::isValidSessionId($foreign),
                "rejected a sibling framework's id shape: '{$foreign}'"
            );
        }
    }

    // ── 41: RFC 7519 s4.1.4 - the token MUST NOT be accepted at or after exp ──

    /**
     * RFC 7519 s4.1.4: "the current date/time MUST be before the expiration".
     *
     * At now == exp the token is expired. Ruby already used >=; Python, PHP and
     * Node used > and accepted a token for one extra second.
     */
    public function testJwtExpiredExactlyAtExpIsRejected(): void
    {
        $now = time();

        $this->assertNull(
            Auth::validToken(self::forge(['user_id' => 1, 'exp' => $now])),
            'token accepted at exactly exp - RFC 7519 s4.1.4 requires now < exp'
        );
    }

    /** Positive half of the boundary: strictly before exp is still valid. */
    public function testJwtOneSecondBeforeExpIsAccepted(): void
    {
        // exp = now + 2 (not + 1) so a clock tick between forging and validating
        // cannot land us exactly ON the boundary and make this flaky. Same
        // margin as the Python master.
        $now = time();

        $payload = Auth::validToken(self::forge(['user_id' => 1, 'exp' => $now + 2]));

        $this->assertNotNull($payload, 'a token two seconds from expiry was rejected');
        $this->assertSame(1, $payload['user_id']);
    }

    /**
     * A malformed exp must never read as "this token never expires".
     *
     * RFC 7519 s2 defines exp as a NumericDate. PHP skipped the check when exp
     * was not a number, turning a malformed claim into an eternal token; and
     * `isset()` is false for null, so `exp: null` slipped through the same way.
     */
    public function testJwtNonNumericExpIsRejectedNotTreatedAsNoExpiry(): void
    {
        $badExps = [
            'string' => 'not-a-number',
            'null' => null,
            'array' => [],
            'object' => new \stdClass(),
            'bool' => true,
        ];

        foreach ($badExps as $label => $badExp) {
            $this->assertNull(
                Auth::validToken(self::forge(['user_id' => 1, 'exp' => $badExp])),
                "token with a {$label} exp was accepted as non-expiring"
            );
        }

        // Positive half: a well-formed numeric exp in the future still works.
        $this->assertNotNull(
            Auth::validToken(self::forge(['user_id' => 1, 'exp' => time() + 600])),
            'a valid future exp was rejected'
        );
    }

    /** Same rule for nbf: malformed is rejected, absent is unconstrained. */
    public function testJwtNonNumericNbfIsRejectedNotTreatedAsUnconstrained(): void
    {
        $now = time();
        $badNbfs = [
            'string' => 'not-a-number',
            'null' => null,
            'array' => [],
            'object' => new \stdClass(),
            'bool' => true,
        ];

        foreach ($badNbfs as $label => $badNbf) {
            $claims = ['user_id' => 1, 'exp' => $now + 600, 'nbf' => $badNbf];
            $this->assertNull(
                Auth::validToken(self::forge($claims)),
                "token with a {$label} nbf was accepted as unconstrained"
            );
        }

        // Positive half: NO nbf at all stays unconstrained (non-breaking).
        $this->assertNotNull(
            Auth::validToken(self::forge(['user_id' => 1, 'exp' => $now + 600])),
            'a token with no nbf claim must stay unconstrained'
        );
    }

    // ── 41: authenticateRequest authenticates, or returns null ──

    /**
     * Basic credentials are not verified against anything, so they are not auth.
     *
     * Python returned a TRUTHY array for any Basic header, so an app following
     * the documented `if (auth === null) return 401;` idiom authenticated every
     * caller. PHP, Ruby and Node all returned null here — this pins PHP's
     * already-correct behaviour so it cannot drift toward the Python bug.
     */
    public function testBasicAuthorizationHeaderIsNotAnAuthenticatedRequest(): void
    {
        $credentials = base64_encode('admin:whatever-i-like');

        $this->assertNull(
            Auth::authenticateRequest(['Authorization' => "Basic {$credentials}"]),
            'an unverified Basic header authenticated the request'
        );

        // Positive half: a real Bearer JWT still authenticates.
        $token = Auth::getToken(['user_id' => 7], self::SECRET);
        $payload = Auth::authenticateRequest(['Authorization' => "Bearer {$token}"]);

        $this->assertNotNull($payload, 'a valid Bearer JWT failed to authenticate');
        $this->assertSame(7, $payload['user_id']);
    }

    /** The API-key result carries the same key in all four frameworks. */
    public function testAuthenticateRequestApiKeyPayloadShapeIsUniform(): void
    {
        $apiKey = 'contract-api-key-value';
        putenv("TINA4_API_KEY={$apiKey}");
        $_ENV['TINA4_API_KEY'] = $apiKey;

        $payload = Auth::authenticateRequest(['Authorization' => "Bearer {$apiKey}"]);

        $this->assertSame(
            ['_auth' => 'api_key'],
            $payload,
            'api_key payload shape drifted from the family-wide {_auth: api_key}'
        );

        // Negative half: a wrong key is not authenticated.
        $this->assertNull(
            Auth::authenticateRequest(['Authorization' => 'Bearer wrong-key']),
            'a wrong API key authenticated the request'
        );
    }
}
