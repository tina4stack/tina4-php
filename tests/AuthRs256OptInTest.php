<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Regression tests for the RS256-opt-in ruling (HMAC is the Tina4 standard).
 *
 * The ruling, in three lines:
 *   1. HS256 / HS384 / HS512 is THE algorithm family, zero-dependency in all
 *      four frameworks. PHP gets it from core `hash_hmac` — no extension.
 *   2. RS256 is an OPT-IN EXTRA. `ext-openssl` moved from composer `require` to
 *      `suggest`, so it may genuinely be absent.
 *   3. Where it is unavailable it fails LOUDLY and ACTIONABLY, AT THE POINT OF
 *      USE ONLY — never a silent downgrade, never a boot probe for the HMAC
 *      majority.
 *
 * And the trap this file exists to nail down: dropping the `ext-openssl`
 * REQUIRE also drops PHP's `https://` STREAM WRAPPER, which that extension
 * registers. `Tina4\Api` never calls an `openssl_*` function, so grepping for
 * one does NOT find the dependency — it rides on the URL scheme — and PHP
 * reports the missing wrapper as "No such file or directory", which reads like
 * a bad path. Silent outbound-HTTPS death is the failure mode; `Api::HTTPS_UNAVAILABLE`
 * is the loud check, and it is exercised here for real.
 *
 * NO MOCKS ANYWHERE. Nothing stands in for the absent extension:
 *   - "the openssl functions are gone" is produced by a REAL child interpreter
 *     run with `-d disable_functions=...`, which is exactly what
 *     `Auth::rs256Available()` probes (`function_exists`), and is exactly as
 *     absent at the call site as a build without the extension;
 *   - "the https wrapper is gone" is produced by a REAL
 *     `stream_wrapper_unregister('https')`, which is exactly what
 *     `Api::httpsAvailable()` consults (`stream_get_wrappers()`), and is exactly
 *     what `fopen()` consults on the next request;
 *   - every HTTP assertion is a real socket round trip against a real
 *     `php -S` server (see TestServer).
 * Every isolated child asserts a CONTROL first, so an isolation that silently
 * did nothing FAILS instead of passing for the wrong reason.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Api;
use Tina4\Auth;
use Tina4\Mqtt;
use Tina4\MqttError;

class AuthRs256OptInTest extends TestCase
{
    private const SECRET = 'rs256-optin-regression-secret';

    /** The ini that genuinely removes every openssl function RS256 calls. */
    private const NO_RSA_FUNCTIONS =
        'openssl_sign,openssl_verify,openssl_pkey_get_private,openssl_pkey_get_public';

    /** HMAC algorithm => [core hash_hmac digest, raw signature bytes]. */
    private const HMAC_FAMILY = [
        'HS256' => ['sha256', 32],
        'HS384' => ['sha384', 48],
        'HS512' => ['sha512', 64],
    ];

    private static ?string $privateKey = null;
    private static ?string $publicKey = null;

    /** @var list<string> temp files to remove */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];

        // Any test that unregisters the wrapper restores it, but restore here
        // too: leaking a missing https wrapper would poison every later test in
        // the process, and a restore of an already-registered wrapper is a
        // harmless false.
        if (!in_array('https', stream_get_wrappers(), true)) {
            @stream_wrapper_restore('https');
        }
    }

    /** A real 2048-bit RSA key pair, generated once for the whole class. */
    private function keyPair(): array
    {
        if (self::$privateKey === null) {
            $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($pair, $private);
            self::$privateKey = $private;
            self::$publicKey = openssl_pkey_get_details($pair)['key'];
        }

        return [self::$privateKey, self::$publicKey];
    }

    private function base64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** Header segment advertising an arbitrary `alg`. */
    private function header(string $alg): string
    {
        return $this->base64url((string)json_encode(['alg' => $alg, 'typ' => 'JWT']));
    }

    /** Keep a token's payload + signature, swap only the header's `alg`. */
    private function reHeader(string $token, string $alg): string
    {
        [, $payload, $signature] = explode('.', $token);

        return "{$this->header($alg)}.{$payload}.{$signature}";
    }

    /**
     * Run PHP source in a REAL child interpreter and return [stdout, stderr, exit].
     *
     * @param array<string,string> $ini ini overrides passed as -d key=value
     * @return array{0:string,1:string,2:int}
     */
    private function runChild(string $code, array $ini = []): array
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $script = \TempPath::file('tina4-rs256-', '.php');
        $this->tempFiles[] = $script;
        file_put_contents($script, "<?php\nrequire " . var_export($autoload, true) . ";\n" . $code);

        // zend.assertions is FORCED on. A production-built PHP ships it at -1,
        // where assert() is compiled out entirely — every assert() in a child
        // body would then be a no-op and the child would exit 0 having checked
        // nothing. CHILD_CONTROL proves the setting actually took.
        $command = [PHP_BINARY, '-d', 'zend.assertions=1'];
        foreach ($ini as $key => $value) {
            $command[] = '-d';
            $command[] = "{$key}={$value}";
        }
        $command[] = $script;

        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc = proc_open($command, $descriptors, $pipes);
        $this->assertIsResource($proc, 'could not start a child interpreter');

        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return [$stdout, $stderr, $exit];
    }

    /**
     * The control every openssl-disabled child runs FIRST.
     *
     * `hash_hmac` must still be there (it is PHP core, and the HMAC family is
     * supposed to be unaffected) while every RSA function must be gone. If the
     * `-d disable_functions` never took effect, this dies instead of letting the
     * rest of the child pass for the wrong reason.
     */
    private const CHILD_CONTROL = <<<'PHP'
        // assert() is compiled OUT when zend.assertions is -1 (the production
        // default), which would make every assert() below a silent no-op. Prove
        // it really throws before trusting a single one of them.
        $assertionsLive = false;
        try {
            assert(1 === 2, 'control');
        } catch (\AssertionError $e) {
            $assertionsLive = true;
        }
        if (!$assertionsLive) {
            fwrite(STDERR, "CONTROL FAILED: assert() is a no-op here, so every child assertion proves nothing\n");
            exit(1);
        }
        foreach (['openssl_sign', 'openssl_verify', 'openssl_pkey_get_private', 'openssl_pkey_get_public'] as $fn) {
            if (function_exists($fn)) {
                fwrite(STDERR, "CONTROL FAILED: {$fn}() is still callable, so disable_functions did nothing\n");
                exit(1);
            }
        }
        if (!function_exists('hash_hmac')) {
            fwrite(STDERR, "CONTROL FAILED: hash_hmac() is gone, so this proves nothing about ext-openssl\n");
            exit(1);
        }

        PHP;

    /** Run a child with the RSA functions genuinely removed, asserting it exits clean. */
    private function runWithoutRsaFunctions(string $code): string
    {
        [$stdout, $stderr, $exit] = $this->runChild(
            self::CHILD_CONTROL . $code,
            ['disable_functions' => self::NO_RSA_FUNCTIONS]
        );
        $this->assertSame(0, $exit, "child interpreter failed\n--- stdout ---\n{$stdout}\n--- stderr ---\n{$stderr}");

        return $stdout;
    }

    // ── 1. HMAC is the standard, and it needs no extension ──────────────────

    /**
     * POSITIVE + NEGATIVE: the whole HMAC family signs and verifies, and the
     * signature is byte-identical to one computed straight from core
     * `hash_hmac` — never read back out of the code under test.
     */
    public function testHmacFamilyIsCorePhpAndRoundTrips(): void
    {
        foreach (self::HMAC_FAMILY as $algorithm => [$digest, $bytes]) {
            $token = Auth::getToken(['user_id' => 7], self::SECRET, 60, $algorithm);
            [$head, $payload, $signature] = explode('.', $token);

            $header = json_decode((string)base64_decode(strtr($head, '-_', '+/')), true);
            $this->assertSame($algorithm, $header['alg'], "{$algorithm}: the header must name what signed");

            $expected = $this->base64url(hash_hmac($digest, "{$head}.{$payload}", self::SECRET, true));
            $this->assertSame($expected, $signature, "{$algorithm}: not an independently computed {$digest} HMAC");
            $this->assertSame(
                $bytes,
                strlen((string)base64_decode(strtr($signature, '-_', '+/'))),
                "{$algorithm}: signature is not {$bytes} raw bytes"
            );

            $this->assertSame(7, Auth::validToken($token, self::SECRET, $algorithm)['user_id']);

            // NEGATIVE: a different secret never validates, and a tampered
            // signature never validates.
            $this->assertNull(Auth::validToken($token, 'not-the-secret', $algorithm));
            $flipped = substr($signature, 0, -1) . ($signature[strlen($signature) - 1] === 'a' ? 'b' : 'a');
            $this->assertNull(Auth::validToken("{$head}.{$payload}.{$flipped}", self::SECRET, $algorithm));
        }
    }

    /**
     * POSITIVE, on a build where every RSA function is genuinely gone: the
     * DEFAULT path is completely unaffected. This is the claim that lets
     * ext-openssl move to `suggest` at all.
     */
    public function testHmacRoundTripsOnABuildWithNoOpensslFunctions(): void
    {
        $secret = var_export(self::SECRET, true);
        $out = $this->runWithoutRsaFunctions(<<<PHP
            foreach (['HS256', 'HS384', 'HS512'] as \$alg) {
                \$token = \Tina4\Auth::getToken(['user_id' => 9], {$secret}, 60, \$alg);
                \$payload = \Tina4\Auth::validToken(\$token, {$secret}, \$alg);
                assert(\$payload !== null && \$payload['user_id'] === 9, \$alg);

                // NEGATIVE in the same interpreter: tamper and wrong secret still fail.
                [\$h, \$p, \$s] = explode('.', \$token);
                \$flipped = substr(\$s, 0, -1) . (\$s[strlen(\$s) - 1] === 'a' ? 'b' : 'a');
                assert(\Tina4\Auth::validToken("\$h.\$p.\$flipped", {$secret}, \$alg) === null, \$alg);
                assert(\Tina4\Auth::validToken(\$token, 'not-the-secret', \$alg) === null, \$alg);
            }
            echo "HMAC-NO-OPENSSL-OK";
            PHP);

        $this->assertStringContainsString('HMAC-NO-OPENSSL-OK', $out);
    }

    // ── 2. RS256 works where the runtime provides it ────────────────────────

    /**
     * POSITIVE + NEGATIVE: with ext-openssl present RS256 signs with the private
     * key and verifies with the PUBLIC key alone — the entire reason RS256
     * exists — while a foreign key and a tampered payload are refused.
     */
    public function testRs256SignsAndVerifiesWhenExtOpensslIsAvailable(): void
    {
        $this->assertTrue(Auth::rs256Available(), 'this PHP build has no ext-openssl functions');

        [$private, $public] = $this->keyPair();
        $token = Auth::getToken(['user_id' => 42, 'role' => 'admin'], $private, 60, 'RS256');
        [$head, , $signature] = explode('.', $token);

        $header = json_decode((string)base64_decode(strtr($head, '-_', '+/')), true);
        $this->assertSame('RS256', $header['alg']);
        // 2048-bit RSA makes a 256-byte signature; no HMAC digest is that size,
        // so this cannot pass if the token was quietly signed with HMAC.
        $this->assertSame(256, strlen((string)base64_decode(strtr($signature, '-_', '+/'))));

        $payload = Auth::validToken($token, $public, 'RS256');
        $this->assertNotNull($payload, 'the public key alone must verify an RS256 token');
        $this->assertSame(42, $payload['user_id']);

        // NEGATIVE: an unrelated public key never validates it.
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $otherPublic = openssl_pkey_get_details($other)['key'];
        $this->assertNull(Auth::validToken($token, $otherPublic, 'RS256'));

        // NEGATIVE: keep the real signature, swap the claims.
        $forged = $this->base64url((string)json_encode(['user_id' => 1, 'role' => 'admin']));
        $this->assertNull(Auth::validToken("{$head}.{$forged}.{$signature}", $public, 'RS256'));

        // NEGATIVE: an RSA algorithm handed something that is not a private key
        // names the KEY, instead of dying inside base64urlEncode() on a null.
        try {
            Auth::getToken(['user_id' => 1], 'not-a-pem', 60, 'RS256');
            $this->fail('signing RS256 with a non-PEM secret did not throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('PRIVATE KEY', $e->getMessage());
        }
    }

    // ── 3. RS256 absent: loud, actionable, and only at the point of use ─────

    /**
     * NEGATIVE: on a build without the openssl functions, BOTH signing and
     * verifying with RS256 throw an error that names the missing extension, the
     * install command, and the zero-dependency alternative.
     *
     * The verify half is the one that matters: returning null there would be
     * indistinguishable from "that token is invalid", so a missing deployment
     * dependency would be debugged as an authentication failure.
     */
    public function testRs256FailsLoudlyAndActionablyWithoutTheOpensslFunctions(): void
    {
        $out = $this->runWithoutRsaFunctions(<<<'PHP'
            if (\Tina4\Auth::rs256Available()) {
                fwrite(STDERR, "rs256Available() said true with every RSA function removed\n");
                exit(1);
            }

            $messages = [];
            foreach (['sign', 'verify'] as $operation) {
                try {
                    if ($operation === 'sign') {
                        \Tina4\Auth::getToken(['user_id' => 1], 'pem-goes-here', 60, 'RS256');
                    } else {
                        \Tina4\Auth::validToken('a.b.c', 'pem-goes-here', 'RS256');
                    }
                    fwrite(STDERR, "RS256 {$operation} did NOT throw without a backend\n");
                    exit(1);
                } catch (\RuntimeException $e) {
                    $messages[$operation] = $e->getMessage();
                }
            }
            echo json_encode($messages);
            PHP);

        $messages = json_decode($out, true);
        $this->assertIsArray($messages, "child did not report both messages: {$out}");
        $this->assertArrayHasKey('sign', $messages);
        $this->assertArrayHasKey('verify', $messages);

        foreach ($messages as $operation => $message) {
            $this->assertStringContainsString('RS256', $message, $operation);
            $this->assertStringContainsString('ext-openssl', $message, $operation);
            // Actionable: an operator must be able to act on it without guessing.
            $this->assertStringContainsString('php-openssl', $message, $operation);
            // And it must offer the zero-dependency way out.
            $this->assertStringContainsString('HS256', $message, $operation);
            $this->assertStringContainsString('hash_hmac', $message, $operation);
        }
    }

    /**
     * NEGATIVE: "only if the user is trying to use RS256" is a HARD constraint.
     *
     * Loading the framework, resolving an HMAC algorithm, and minting and
     * verifying HMAC tokens must all stay completely silent on a build with no
     * RSA functions — no exception, no warning, no notice, no log line. An app
     * that never touches RS256 must never learn that RS256 exists.
     */
    public function testRs256IsProbedOnlyAtThePointOfUse(): void
    {
        [$stdout, $stderr, $exit] = $this->runChild(
            self::CHILD_CONTROL . <<<'PHP'
                // Loading the class is not "use". Neither is the HMAC path.
                class_exists(\Tina4\Auth::class) || exit(1);
                for ($i = 0; $i < 3; $i++) {
                    $token = \Tina4\Auth::getToken(['user_id' => $i], 'a-secret', 60, 'HS256');
                    \Tina4\Auth::validToken($token, 'a-secret', 'HS256') ?? exit(1);
                }
                echo 'LAZY-OK';
                PHP,
            ['disable_functions' => self::NO_RSA_FUNCTIONS, 'error_reporting' => (string)E_ALL, 'display_errors' => 'stderr']
        );

        $this->assertSame(0, $exit, "child failed: {$stderr}");
        $this->assertSame('LAZY-OK', $stdout, 'the HMAC path printed something it should not have');
        $this->assertSame('', trim($stderr), "an RS256-free app saw diagnostics it should never see: {$stderr}");
    }

    // ── 4. Algorithm pinning did NOT weaken ────────────────────────────────

    /**
     * NEGATIVE: making RS256 optional must not open algorithm substitution.
     *
     * Under an HMAC configuration every re-labelled header is refused, a
     * genuinely RSA-signed token is refused, a real token of a DIFFERENT HMAC
     * algorithm is refused, and `alg: "none"` is refused in all three of its
     * shapes. And none of them may raise — pinning happens before any signature
     * or capability work, so a stranger's header can never steer an
     * HMAC-configured app into the RS256 path.
     */
    public function testHmacConfiguredVerifierPinsTheAlgorithm(): void
    {
        [$private] = $this->keyPair();
        $rsaToken = Auth::getToken(['user_id' => 5], $private, 60, 'RS256');

        foreach (array_keys(self::HMAC_FAMILY) as $configured) {
            $good = Auth::getToken(['user_id' => 5], self::SECRET, 60, $configured);
            [, $payload, $signature] = explode('.', $good);

            // POSITIVE control FIRST: the pin rejects substitutions, not everything.
            $this->assertSame(5, Auth::validToken($good, self::SECRET, $configured)['user_id']);

            // Header lies about the algorithm, signature is the real HMAC.
            foreach (['RS256', 'none', 'HS512', 'HS384', 'HS256'] as $lie) {
                if ($lie === $configured) {
                    continue;
                }
                $this->assertNull(
                    Auth::validToken($this->reHeader($good, $lie), self::SECRET, $configured),
                    "{$configured}: a header claiming {$lie} over a valid signature was ACCEPTED"
                );
            }

            // A GENUINELY RSA-signed token, offered to an HMAC verifier.
            $this->assertNull(
                Auth::validToken($rsaToken, self::SECRET, $configured),
                "{$configured}: a real RS256 token authenticated under an HMAC configuration"
            );

            // A real token of another HMAC algorithm — correctly signed, wrong family member.
            foreach (array_keys(self::HMAC_FAMILY) as $other) {
                if ($other === $configured) {
                    continue;
                }
                $this->assertNull(
                    Auth::validToken(Auth::getToken(['user_id' => 5], self::SECRET, 60, $other), self::SECRET, $configured),
                    "{$configured}: a real {$other} token was ACCEPTED"
                );
            }

            // alg:"none" in every shape: real signature, empty signature, no signature segment.
            $this->assertNull(Auth::validToken($this->reHeader($good, 'none'), self::SECRET, $configured));
            $this->assertNull(Auth::validToken("{$this->header('none')}.{$payload}.", self::SECRET, $configured));
            $this->assertNull(Auth::validToken("{$this->header('none')}.{$payload}", self::SECRET, $configured));
            $this->assertNotSame('', $signature);
        }
    }

    /**
     * NEGATIVE: a correctly-signed token whose header PARSES as a different
     * algorithm. This is the ONE construction that gates the algorithm-pin line
     * itself — measured, not assumed.
     *
     * Every other substitution case in this file is refused even by an
     * implementation with NO pin at all, because the header is part of the HMAC
     * signing input: relabel it and the recomputed signature simply stops
     * matching. (Deleting the `$header['alg'] !== $algorithm` comparison and
     * re-running this class was still green until this test existed.)
     *
     * Here the header BYTES are identical between signing and verifying, so the
     * signature genuinely verifies — but the header carries `alg` twice, and
     * PHP's `json_decode`, Python's `json.loads`, Ruby's `JSON.parse` and JS's
     * `JSON.parse` all take the LAST duplicate key. So the token parses as the
     * smuggled algorithm while carrying a valid signature, and only a verifier
     * that compares the PARSED alg against its OWN configuration rejects it.
     *
     * It is also a real split-brain shape rather than a synthetic one: a gateway
     * that pre-validates on the first `alg` and a backend that acts on the last
     * are looking at two different tokens.
     */
    public function testACorrectlySignedTokenWhoseHeaderParsesAsAnotherAlgIsRejected(): void
    {
        $payload = $this->base64url((string)json_encode(['user_id' => 42, 'exp' => 4102444800]));

        foreach ([['HS256', 'none'], ['HS256', 'RS256'], ['HS512', 'HS256'], ['HS384', 'HS512']] as [$honest, $smuggled]) {
            [$digest] = self::HMAC_FAMILY[$honest];
            // Header bytes name $honest FIRST and $smuggled LAST. The signature
            // is a real HMAC over exactly these bytes, so it verifies.
            $header = $this->base64url("{\"alg\":\"{$honest}\",\"alg\":\"{$smuggled}\",\"typ\":\"JWT\"}");
            $signingInput = "{$header}.{$payload}";
            $token = $signingInput . '.' . $this->base64url(hash_hmac($digest, $signingInput, self::SECRET, true));

            // CONTROL: the signature really IS valid, and the header really does
            // parse as the smuggled algorithm — otherwise this proves nothing.
            $decoded = json_decode((string)base64_decode(strtr($header, '-_', '+/')), true);
            $this->assertSame($smuggled, $decoded['alg'], 'the duplicate-key header did not parse as expected');
            $this->assertSame(
                $this->base64url(hash_hmac($digest, $signingInput, self::SECRET, true)),
                explode('.', $token)[2]
            );

            $this->assertNull(
                Auth::validToken($token, self::SECRET, $honest),
                "a validly-signed {$honest} token whose header parses as {$smuggled} was ACCEPTED — "
                . 'the algorithm pin is gone'
            );

            // The second gate shape, the one tina4-ruby already had: an honest
            // single-key header that NAMES the smuggled algorithm, with the
            // signature RE-COMPUTED over that lying header so the HMAC genuinely
            // verifies. Only the pin refuses it. (Contrast the re-labelled tokens
            // elsewhere in this class, which keep a signature computed over the
            // honest header and so break on the signature, pin or no pin.)
            $lying = $this->base64url((string)json_encode(['alg' => $smuggled, 'typ' => 'JWT']));
            $lyingInput = "{$lying}.{$payload}";
            $resigned = $lyingInput . '.' . $this->base64url(hash_hmac($digest, $lyingInput, self::SECRET, true));
            $this->assertNull(
                Auth::validToken($resigned, self::SECRET, $honest),
                "a token advertising {$smuggled}, signed correctly with the {$honest} secret, was ACCEPTED"
            );
        }

        // POSITIVE control: the same construction with ONE honest alg validates,
        // so the rejections above are the pin and not the duplicate key itself.
        $header = $this->base64url('{"alg":"HS256","typ":"JWT"}');
        $signingInput = "{$header}.{$payload}";
        $token = $signingInput . '.' . $this->base64url(hash_hmac('sha256', $signingInput, self::SECRET, true));
        $this->assertSame(42, Auth::validToken($token, self::SECRET, 'HS256')['user_id']);
    }

    /**
     * NEGATIVE: the RS256-to-HS256 confusion attack, in full.
     *
     * An RS256 verifier's key is PUBLIC, so an attacker takes that public key,
     * uses it as an HMAC SECRET, and mints a perfectly-signed HS256 token. An
     * implementation that picks its algorithm from the TOKEN'S header instead of
     * its own configuration verifies it happily and authenticates the attacker.
     * Tina4 dispatches on the CONFIGURED algorithm, so the forgery never reaches
     * an HMAC comparison at all.
     */
    public function testRs256AppRejectsAnHmacTokenForgedWithItsOwnPublicKey(): void
    {
        [, $public] = $this->keyPair();

        $forged = Auth::getToken(['user_id' => 1, 'role' => 'admin'], $public, 60, 'HS256');

        // Sanity: the forgery IS a valid HS256 token under that secret, so the
        // attack is real and only the algorithm pin stops it.
        $this->assertNotNull(Auth::validToken($forged, $public, 'HS256'));

        $this->assertNull(
            Auth::validToken($forged, $public, 'RS256'),
            'an HMAC token signed with the RS256 verifier\'s own public key was ACCEPTED — '
            . 'the algorithm came from the token header, not the configuration'
        );

        // The mirror image: an RS256-configured app also refuses alg:"none".
        [$private] = $this->keyPair();
        $real = Auth::getToken(['user_id' => 1], $private, 60, 'RS256');
        [, $payload, $signature] = explode('.', $real);
        $this->assertNull(Auth::validToken("{$this->header('none')}.{$payload}.{$signature}", $public, 'RS256'));
        $this->assertNull(Auth::validToken($this->reHeader($real, 'HS256'), $public, 'RS256'));
        // POSITIVE control: the untampered RS256 token still validates.
        $this->assertNotNull(Auth::validToken($real, $public, 'RS256'));
    }

    /**
     * NEGATIVE: pinning holds even on a build where RS256 CANNOT work.
     *
     * If the header check were ever moved after the capability check, an
     * RS256-labelled token would reach `rs256Available()` and throw — a
     * remote-triggerable 500 on an app that never opted into RS256. It must
     * return null instead.
     */
    public function testAlgPinningHoldsWhereRs256IsImpossible(): void
    {
        $secret = var_export(self::SECRET, true);
        $out = $this->runWithoutRsaFunctions(<<<PHP
            \$token = \Tina4\Auth::getToken(['user_id' => 7], {$secret}, 60, 'HS256');
            [\$h, \$p, \$s] = explode('.', \$token);

            foreach (['RS256', 'none', 'HS512'] as \$lie) {
                \$header = rtrim(strtr(base64_encode(json_encode(['alg' => \$lie, 'typ' => 'JWT'])), '+/', '-_'), '=');
                try {
                    \$result = \Tina4\Auth::validToken("\$header.\$p.\$s", {$secret}, 'HS256');
                } catch (\RuntimeException \$e) {
                    fwrite(STDERR, "alg pinning bypassed: a header claiming {\$lie} reached the RS256 capability check\n");
                    exit(1);
                }
                if (\$result !== null) {
                    fwrite(STDERR, "a header claiming {\$lie} was ACCEPTED\n");
                    exit(1);
                }
            }
            assert(\Tina4\Auth::validToken(\$token, {$secret}, 'HS256') !== null);
            echo 'ALG-PINNING-OK';
            PHP);

        $this->assertStringContainsString('ALG-PINNING-OK', $out);
    }

    // ── 5. The manifest: suggested, not required ───────────────────────────

    /**
     * The ruling in the file that actually decides installability. `require`
     * must not name ext-openssl, `suggest` must, and the suggestion must name
     * the IMPLICIT https-wrapper dependency, because that is the one nothing
     * greps its way to.
     */
    public function testComposerSuggestsExtOpensslRatherThanRequiringIt(): void
    {
        $composer = json_decode((string)file_get_contents(dirname(__DIR__) . '/composer.json'), true);
        $this->assertIsArray($composer, 'composer.json did not parse');

        // POSITIVE control: the manifest really was read and really does require
        // things, so "openssl is absent from require" is a finding, not an empty scan.
        $this->assertArrayHasKey('php', $composer['require'], 'require block looks empty');
        $this->assertArrayHasKey('ext-json', $composer['require']);

        $this->assertArrayNotHasKey('ext-openssl', $composer['require'], 'ext-openssl is still REQUIRED');
        $this->assertArrayHasKey('ext-openssl', $composer['suggest'], 'ext-openssl is not even suggested');

        $suggestion = $composer['suggest']['ext-openssl'];
        $this->assertStringContainsString('RS256', $suggestion);
        $this->assertStringContainsString('https', $suggestion, 'the implicit https-wrapper dependency is not named');
        $this->assertStringContainsString('hash_hmac', $suggestion, 'the zero-dependency alternative is not named');
    }

    /**
     * CONFORMANCE: the wire contract a REAL client of any Tina4 app speaks.
     *
     * tests/fixtures/jwt_cross_framework.json is a byte-identical copy of the
     * file in tina4-nodejs, tina4-python and tina4-ruby (same convention as
     * adapter_contract.json). The tokens were minted by tina4-nodejs with
     * node:crypto; nothing here re-implements JWT, so agreement is real interop
     * rather than two copies of the same bug.
     *
     * BOTH halves are asserted: an accept-only fixture would pass on an
     * implementation that accepts everything.
     */
    public function testTheCrossFrameworkJwtContractFixtureIsHonoured(): void
    {
        $raw = (string)file_get_contents(__DIR__ . '/fixtures/jwt_cross_framework.json');
        $fixture = json_decode($raw, true);
        $this->assertIsArray($fixture, 'the cross-framework fixture did not parse');

        // POSITIVE control: it really was read and really has both halves.
        $this->assertGreaterThanOrEqual(4, count($fixture['accept']), 'the fixture lost its accept entries');
        $this->assertGreaterThanOrEqual(10, count($fixture['reject']), 'the fixture lost its reject entries');
        $this->assertNotSame($fixture['hmacSecret'], $fixture['wrongSecret']);
        $this->assertStringNotContainsString('PRIVATE KEY', $raw, 'a private key leaked into the fixture');

        foreach ($fixture['accept'] as $entry) {
            $payload = Auth::validToken($entry['token'], $fixture[$entry['key']], $entry['algorithm']);
            $this->assertNotNull($payload, "fixture accept rejected: {$entry['name']}");
            $this->assertEquals($fixture['expectedPayload'], $payload, "claims differ: {$entry['name']}");
        }

        foreach ($fixture['reject'] as $entry) {
            $this->assertNull(
                Auth::validToken($entry['token'], $fixture[$entry['key']], $entry['algorithm']),
                "fixture reject ACCEPTED: {$entry['name']}"
            );
        }
    }

    // ── 6. THE TRAP: dropping ext-openssl silently kills outbound HTTPS ────

    /**
     * POSITIVE control, then NEGATIVE: an https request on a runtime with no
     * `https` stream wrapper returns an error that NAMES ext-openssl, instead of
     * PHP's misleading "No such file or directory".
     *
     * The wrapper is unregistered for real. `Api` consults
     * `stream_get_wrappers()`, and so does `fopen()`, so this is the same state
     * a build without ext-openssl is in — not a simulation of it.
     */
    public function testOutboundHttpsFailsWithANamedErrorWhenTheHttpsWrapperIsGone(): void
    {
        // CONTROL: HTTPS works here, so the negative below is a real change of state.
        $this->assertContains('https', stream_get_wrappers(), 'this build has no https wrapper to begin with');
        $this->assertTrue(Api::httpsAvailable());

        $server = TestServer::start(__DIR__ . '/fixtures/https_guard_server.php');
        try {
            $this->assertTrue(stream_wrapper_unregister('https'), 'could not unregister the https wrapper');
            $this->assertNotContains('https', stream_get_wrappers());
            $this->assertFalse(Api::httpsAvailable(), 'httpsAvailable() did not notice the wrapper went away');

            $result = (new Api('https://example.com'))->get('/anything');
            $this->assertNull($result['http_code']);
            $this->assertIsString($result['error']);
            $this->assertStringContainsString('ext-openssl', $result['error']);
            $this->assertStringContainsString('https', $result['error']);
            // Not PHP's misleading wording.
            $this->assertStringNotContainsString('No such file or directory', $result['error']);

            // AND the failure is SCOPED: plain http is completely unaffected, on
            // a real socket round trip to a real server, in this same process.
            $plain = (new Api($server->base()))->get('/ping');
            $this->assertSame(200, $plain['http_code'], 'plain http broke: ' . var_export($plain['error'], true));
            $this->assertSame('pong', $plain['body']['reply']);
        } finally {
            $this->assertTrue(stream_wrapper_restore('https'));
            $server->stop();
        }

        // And the guard goes away again when the wrapper comes back.
        $this->assertTrue(Api::httpsAvailable());
    }

    /**
     * NEGATIVE: the guard is checked PER HOP, not once up front.
     *
     * A plain http:// request is allowed to redirect to https://, so the hop
     * that actually needs TLS is not necessarily the one the caller asked for.
     * A start-of-request-only check would let this reach `fopen()` and come back
     * as "No such file or directory".
     */
    public function testAnHttpRedirectToHttpsIsGuardedAtTheHopThatNeedsIt(): void
    {
        $server = TestServer::start(__DIR__ . '/fixtures/https_guard_server.php');
        try {
            // CONTROL: the redirect really is served, and really does point at https.
            $raw = (string)file_get_contents(
                $server->base() . '/redirect-to-https',
                false,
                stream_context_create(['http' => ['follow_location' => 0, 'ignore_errors' => true]])
            );
            $this->assertStringContainsString('redirecting', $raw);
            $location = '';
            foreach ($http_response_header ?? [] as $line) {
                if (stripos($line, 'location:') === 0) {
                    $location = trim(substr($line, 9));
                }
            }
            $this->assertStringStartsWith('https://', $location, 'the fixture did not redirect to https');

            $this->assertTrue(stream_wrapper_unregister('https'));
            $result = (new Api($server->base()))->get('/redirect-to-https');

            $this->assertNull($result['http_code']);
            $this->assertStringContainsString('ext-openssl', (string)$result['error']);
            $this->assertStringContainsString($location, (string)$result['error'], 'the error does not name the hop');
        } finally {
            @stream_wrapper_restore('https');
            $server->stop();
        }
    }

    /**
     * POSITIVE + NEGATIVE: `bin/doctor.php` no longer calls ext-openssl
     * REQUIRED, and it reports the https wrapper directly — an openssl build
     * with the wrapper disabled would otherwise look perfectly healthy.
     *
     * The negative half runs doctor in a REAL child whose `https` wrapper is
     * genuinely unregistered (via auto_prepend_file), so the MISSING branch is
     * executed rather than read.
     */
    public function testDoctorTreatsOpensslAsOptionalAndReportsTheHttpsWrapper(): void
    {
        $doctor = dirname(__DIR__) . '/bin/doctor.php';

        [$healthy] = $this->runChildScript($doctor);
        $this->assertStringContainsString('https:// stream wrapper: OK', $healthy);
        // Still required, and still reported.
        foreach (['json:', 'mbstring:', 'pdo:'] as $required) {
            $this->assertStringContainsString($required, $healthy);
        }

        $prepend = \TempPath::file('tina4-unwrap-', '.php');
        $this->tempFiles[] = $prepend;
        file_put_contents($prepend, "<?php stream_wrapper_unregister('https');\n");

        [$broken] = $this->runChildScript($doctor, ['auto_prepend_file' => $prepend]);
        $this->assertStringContainsString(
            'https:// stream wrapper: MISSING (outbound HTTPS will fail)',
            $broken,
            'doctor reported a healthy https wrapper on a runtime that has none'
        );
    }

    /**
     * NEGATIVE: `Mqtt`'s single `openssl_error_string()` call must not turn a
     * TLS handshake failure into a "Call to undefined function" fatal.
     *
     * php.ini `disable_functions` can remove that one function while ext-openssl
     * stays loaded, which is exactly the state produced here. The TCP connect
     * SUCCEEDS (a real `php -S` server accepts it) and the TLS handshake then
     * fails, so the error branch really does run.
     */
    public function testMqttTlsErrorReportingSurvivesADisabledOpensslErrorString(): void
    {
        $server = TestServer::start(__DIR__ . '/fixtures/https_guard_server.php');
        $url = 'mqtts://127.0.0.1:' . parse_url($server->base(), PHP_URL_PORT);
        try {
            [$stdout, $stderr, $exit] = $this->runChild(
                <<<PHP
                if (function_exists('openssl_error_string')) {
                    fwrite(STDERR, "CONTROL FAILED: openssl_error_string() is still callable\n");
                    exit(1);
                }
                if (!extension_loaded('openssl')) {
                    fwrite(STDERR, "CONTROL FAILED: ext-openssl is gone, so this tests the wrong branch\n");
                    exit(1);
                }
                // Keep the framework's own logging OFF this pipe so stdout carries
                // only this child's marker. tlsVerify: false legitimately logs a
                // WARNING, and since feature 2 made stdout ALWAYS-ON by default
                // (PHP used to suppress it entirely, which is why this assertion
                // passed before), that warning would otherwise be the first thing
                // on stdout. Silencing the channel keeps assertStringStartsWith at
                // full strength instead of relaxing it to a "contains" check.
                putenv('TINA4_LOG_OUTPUT=file');
                \Tina4\Log::reset();
                try {
                    new \Tina4\Mqtt(url: '{$url}', tlsVerify: false, timeout: 3);
                    echo 'NO-ERROR';
                } catch (\Tina4\MqttError \$e) {
                    echo 'MQTT-ERROR: ' . \$e->getMessage();
                }
                PHP,
                ['disable_functions' => 'openssl_error_string']
            );

            $this->assertSame(0, $exit, "child died instead of raising MqttError: {$stderr}");
            $this->assertStringNotContainsString('undefined function', $stdout . $stderr);
            $this->assertStringStartsWith(
                'MQTT-ERROR:',
                $stdout,
                'a TLS handshake against a non-TLS server did not raise MqttError'
            );
        } finally {
            $server->stop();
        }
    }

    /**
     * Run an existing PHP script in a child interpreter.
     *
     * @param array<string,string> $ini
     * @return array{0:string,1:string,2:int}
     */
    private function runChildScript(string $script, array $ini = []): array
    {
        $command = [PHP_BINARY];
        foreach ($ini as $key => $value) {
            $command[] = '-d';
            $command[] = "{$key}={$value}";
        }
        $command[] = $script;

        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $proc = proc_open($command, $descriptors, $pipes);
        $this->assertIsResource($proc);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [$stdout, $stderr, proc_close($proc)];
    }
}
