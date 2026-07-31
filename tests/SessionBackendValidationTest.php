<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Session;

/**
 * Tests for TINA4_SESSION_BACKEND name validation.
 *
 * An unrecognised session backend name must THROW, not silently become `file`.
 *
 * The bug these lock in: every one of Session's five match() statements ended in
 * `default => ...File()`, so any name PHP did not recognise wrote sessions to
 * local disk. A typo in TINA4_SESSION_BACKEND ("redsi") or a capitalised value
 * from a .env line ("Redis" — PHP did not normalise) produced a running app with
 * sessions on the wrong storage, nothing logged and nothing failed. The symptom
 * arrived much later, as users being logged out whenever a request landed on
 * another instance.
 *
 * NO MOCKS and no dependency: every case here is the pure name -> outcome
 * decision, asserted through the real Session constructor. Nothing is stubbed,
 * and the cases that would need a live backend deliberately assert only that the
 * name is not REJECTED, rather than opening a connection.
 *
 * Identical case names in all four frameworks:
 *   tina4-python/tests/test_session_backend_validation.py
 *   tina4-ruby/spec/session_backend_validation_spec.rb
 *   tina4-nodejs/test/sessionBackendValidation.test.ts
 */
class SessionBackendValidationTest extends TestCase
{
    private ?string $previousBackend = null;
    private ?string $previousHandler = null;

    protected function setUp(): void
    {
        $this->previousBackend = getenv('TINA4_SESSION_BACKEND') ?: null;
        $this->previousHandler = getenv('TINA4_SESSION_HANDLER') ?: null;
        putenv('TINA4_SESSION_BACKEND');
        putenv('TINA4_SESSION_HANDLER');
    }

    protected function tearDown(): void
    {
        putenv('TINA4_SESSION_BACKEND');
        putenv('TINA4_SESSION_HANDLER');
        if ($this->previousBackend !== null) {
            putenv('TINA4_SESSION_BACKEND=' . $this->previousBackend);
        }
        if ($this->previousHandler !== null) {
            putenv('TINA4_SESSION_HANDLER=' . $this->previousHandler);
        }
    }

    /** NEGATIVE: the actual bug. This constructed a file-backed Session before. */
    public function testAnUnknownSessionBackendRaisesInsteadOfSilentlyUsingFile(): void
    {
        putenv('TINA4_SESSION_BACKEND=redsi');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown session backend/');

        new Session();
    }

    public function testTheErrorNamesTheUnknownBackendAndTheValidOnes(): void
    {
        putenv('TINA4_SESSION_BACKEND=postgres');

        try {
            new Session();
            $this->fail('an unknown backend did not throw');
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString(
                'postgres',
                $message,
                'the operator cannot see which value was wrong'
            );
            foreach (Session::CANONICAL_BACKENDS as $canonical) {
                $this->assertStringContainsString(
                    $canonical,
                    $message,
                    "the message does not offer {$canonical}"
                );
            }
        }
    }

    /** POSITIVE: the documented default must survive the new strictness. */
    public function testAnUnsetBackendStillDefaultsToFile(): void
    {
        $session = new Session();
        $this->assertInstanceOf(Session::class, $session);
    }

    /**
     * POSITIVE, and the subtle one. An env var set to '' is a SET variable.
     * Treating blank as an unknown name would break every deployment that clears
     * the var to take the default.
     */
    public function testABlankBackendStillDefaultsToFile(): void
    {
        putenv('TINA4_SESSION_BACKEND=');
        $this->assertInstanceOf(Session::class, new Session());

        putenv('TINA4_SESSION_BACKEND=   ');
        $this->assertInstanceOf(Session::class, new Session());
    }

    /** A .env line easily carries a trailing space or a capital. */
    public function testABackendNameIsCaseAndWhitespaceInsensitive(): void
    {
        foreach (['FILE', ' file ', 'FileSystem', "\tfilesystem\n"] as $spelling) {
            putenv('TINA4_SESSION_BACKEND=' . $spelling);
            $this->assertInstanceOf(
                Session::class,
                new Session(),
                "rejected the spelling: {$spelling}"
            );
        }
    }

    /**
     * POSITIVE: the new rejection must not swallow a name that IS valid.
     *
     * Only the NAME decision is asserted. Constructing redis/mongo/database
     * reaches for a real service, and this case is about validation, so a backend
     * that fails to CONNECT still counts as accepted - what must never happen is
     * the "Unknown session backend" rejection.
     */
    public function testEveryDocumentedBackendNameIsAccepted(): void
    {
        foreach (Session::VALID_BACKENDS as $name) {
            putenv('TINA4_SESSION_BACKEND=' . $name);
            try {
                new Session();
                $this->assertTrue(true);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringNotContainsString(
                    'Unknown session backend',
                    $e->getMessage(),
                    "{$name} is in VALID_BACKENDS but the dispatch rejected it"
                );
            } catch (\Throwable $e) {
                $this->assertTrue(true); // a connection failure is not a NAME failure
            }
        }
    }

    /**
     * The error message offers CANONICAL_BACKENDS. If one of those were not
     * itself accepted, the message would be telling operators to set an invalid
     * value.
     */
    public function testTheCanonicalNamesAreAllThemselvesValid(): void
    {
        foreach (Session::CANONICAL_BACKENDS as $canonical) {
            $this->assertContains($canonical, Session::VALID_BACKENDS);
        }
    }

    /**
     * PHP-ONLY, and recorded rather than changed: TINA4_SESSION_HANDLER is read
     * by PHP and by no other framework. It goes through the same validation, so
     * a typo there is loud too - but the var itself is a parity gap awaiting a
     * decision, not something this fix invents or blesses.
     */
    public function testThePhpOnlyHandlerAliasIsValidatedTheSameWay(): void
    {
        putenv('TINA4_SESSION_HANDLER=redsi');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown session backend/');

        new Session();
    }
}
