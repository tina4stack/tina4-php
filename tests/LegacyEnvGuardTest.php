<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Regression tests for App::checkLegacyEnvVars() — the v3.12 boot guard
 * that refuses to start the framework if pre-prefix env vars are still
 * present in the environment. Mirrors the Python implementation in
 * tina4_python.core.server._check_legacy_env_vars.
 *
 * Verifies:
 *   1. Setting any legacy name causes the guard to die.
 *   2. Setting TINA4_ALLOW_LEGACY_ENV=true bypasses the guard.
 *   3. The error message lists every legacy var with its replacement.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tina4\App;

class LegacyEnvGuardTest extends TestCase
{
    /**
     * Every legacy name v3.12 retired and the TINA4_-prefixed name it
     * was renamed to. Kept here independently of App::LEGACY_ENV_VARS
     * so the test catches drift between the constant and what the
     * release was supposed to retire.
     *
     * @var array<string, string>
     */
    private const EXPECTED_LEGACY_MAP = [
        'DATABASE_URL'           => 'TINA4_DATABASE_URL',
        'DATABASE_USERNAME'      => 'TINA4_DATABASE_USERNAME',
        'DATABASE_PASSWORD'      => 'TINA4_DATABASE_PASSWORD',
        'DB_URL'                 => 'TINA4_DATABASE_URL',
        'SECRET'                 => 'TINA4_SECRET',
        'API_KEY'                => 'TINA4_API_KEY',
        'JWT_ALGORITHM'          => 'TINA4_JWT_ALGORITHM',
        'SMTP_HOST'              => 'TINA4_MAIL_HOST',
        'SMTP_PORT'              => 'TINA4_MAIL_PORT',
        'SMTP_USERNAME'          => 'TINA4_MAIL_USERNAME',
        'SMTP_PASSWORD'          => 'TINA4_MAIL_PASSWORD',
        'SMTP_FROM'              => 'TINA4_MAIL_FROM',
        'SMTP_FROM_NAME'         => 'TINA4_MAIL_FROM_NAME',
        'IMAP_HOST'              => 'TINA4_MAIL_IMAP_HOST',
        'IMAP_PORT'              => 'TINA4_MAIL_IMAP_PORT',
        'IMAP_USER'              => 'TINA4_MAIL_IMAP_USERNAME',
        'IMAP_PASS'              => 'TINA4_MAIL_IMAP_PASSWORD',
        'HOST_NAME'              => 'TINA4_HOST_NAME',
        'SWAGGER_TITLE'          => 'TINA4_SWAGGER_TITLE',
        'SWAGGER_DESCRIPTION'    => 'TINA4_SWAGGER_DESCRIPTION',
        'SWAGGER_VERSION'        => 'TINA4_SWAGGER_VERSION',
        'ORM_PLURAL_TABLE_NAMES' => 'TINA4_ORM_PLURAL_TABLE_NAMES',
    ];

    /**
     * Snapshot of all legacy + bypass keys before each test so we can
     * fully restore the environment in tearDown — PHPUnit otherwise
     * leaks setenv() state across the suite.
     *
     * @var array<string, string|false>
     */
    private array $envSnapshot = [];

    protected function setUp(): void
    {
        $keys = array_merge(array_keys(self::EXPECTED_LEGACY_MAP), ['TINA4_ALLOW_LEGACY_ENV']);
        foreach ($keys as $key) {
            $this->envSnapshot[$key] = getenv($key);
            // Wipe each key from every source the guard inspects.
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envSnapshot as $key => $value) {
            if ($value !== false) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            } else {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            }
        }
    }

    // ── 1. Constant matches the spec ─────────────────────────────────

    public function testLegacyMapMatchesSpec(): void
    {
        $this->assertSame(
            self::EXPECTED_LEGACY_MAP,
            App::LEGACY_ENV_VARS,
            'App::LEGACY_ENV_VARS must list exactly the env vars retired in v3.12'
        );
    }

    public function testEveryLegacyValueIsTina4Prefixed(): void
    {
        foreach (App::LEGACY_ENV_VARS as $old => $new) {
            $this->assertStringStartsWith(
                'TINA4_',
                $new,
                "Replacement for {$old} must use TINA4_ prefix, got {$new}"
            );
        }
    }

    // ── 2. Guard passes when no legacy vars are set ──────────────────

    public function testGuardSilentWhenNoLegacyVarsPresent(): void
    {
        // Should be a no-op — explicitly request throw mode so a
        // mistaken implementation wouldn't kill the test process.
        App::checkLegacyEnvVars(exit: false);
        $this->addToAssertionCount(1);
    }

    // ── 3. Setting any legacy name throws ────────────────────────────

    #[DataProvider('legacyNameProvider')]
    public function testGuardThrowsForEachLegacyName(string $legacy): void
    {
        putenv("{$legacy}=any-value");

        try {
            App::checkLegacyEnvVars(exit: false);
            $this->fail("checkLegacyEnvVars() should have thrown for {$legacy}");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($legacy, $e->getMessage());
            $this->assertStringContainsString(
                self::EXPECTED_LEGACY_MAP[$legacy],
                $e->getMessage(),
                "Error message should suggest the new name for {$legacy}"
            );
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function legacyNameProvider(): array
    {
        $cases = [];
        foreach (self::EXPECTED_LEGACY_MAP as $old => $new) {
            $cases[$old] = [$old];
        }
        return $cases;
    }

    public function testGuardDetectsViaServerSuperglobal(): void
    {
        // Some hosts (CLI scripts, FPM) populate $_SERVER, not $_ENV
        // and not getenv. The guard must pick those up too.
        $_SERVER['DATABASE_URL'] = 'sqlite::memory:';

        try {
            App::checkLegacyEnvVars(exit: false);
            $this->fail('Guard ignored a $_SERVER-only legacy var');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('DATABASE_URL', $e->getMessage());
        }
    }

    public function testGuardDetectsViaEnvSuperglobal(): void
    {
        $_ENV['SECRET'] = 'old-secret';

        try {
            App::checkLegacyEnvVars(exit: false);
            $this->fail('Guard ignored a $_ENV-only legacy var');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('SECRET', $e->getMessage());
        }
    }

    // ── 4. TINA4_ALLOW_LEGACY_ENV bypasses the guard ─────────────────

    #[DataProvider('truthyBypassValues')]
    public function testBypassEnvAllowsLegacyVars(string $bypassValue): void
    {
        putenv('SECRET=ignored');
        putenv('DATABASE_URL=sqlite::memory:');
        putenv("TINA4_ALLOW_LEGACY_ENV={$bypassValue}");

        // Should not throw.
        App::checkLegacyEnvVars(exit: false);
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function truthyBypassValues(): array
    {
        return [
            'lowercase true' => ['true'],
            'uppercase TRUE' => ['TRUE'],
            'mixed True'     => ['True'],
            'numeric 1'      => ['1'],
            'yes word'       => ['yes'],
        ];
    }

    public function testBypassRejectsFalsyValues(): void
    {
        putenv('SECRET=ignored');
        putenv('TINA4_ALLOW_LEGACY_ENV=false');

        $this->expectException(\RuntimeException::class);
        App::checkLegacyEnvVars(exit: false);
    }

    // ── 5. Error message lists every offending var ───────────────────

    public function testErrorMessageListsAllLegacyVarsThatAreSet(): void
    {
        $offenders = ['SECRET', 'DATABASE_URL', 'SMTP_HOST', 'HOST_NAME'];
        foreach ($offenders as $name) {
            putenv("{$name}=value");
        }

        try {
            App::checkLegacyEnvVars(exit: false);
            $this->fail('Guard did not throw with multiple legacy vars set');
        } catch (\RuntimeException $e) {
            foreach ($offenders as $name) {
                $this->assertStringContainsString(
                    $name,
                    $e->getMessage(),
                    "Error message should list legacy var {$name}"
                );
                $this->assertStringContainsString(
                    self::EXPECTED_LEGACY_MAP[$name],
                    $e->getMessage(),
                    "Error message should list replacement for {$name}"
                );
            }
        }
    }

    public function testErrorMessageMentionsBypassFlag(): void
    {
        putenv('SECRET=value');

        try {
            App::checkLegacyEnvVars(exit: false);
            $this->fail('Guard did not throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString(
                'TINA4_ALLOW_LEGACY_ENV',
                $e->getMessage(),
                'Error message must mention how to bypass during migration'
            );
        }
    }

    public function testErrorMessageReferencesReleaseDocs(): void
    {
        putenv('SECRET=value');

        try {
            App::checkLegacyEnvVars(exit: false);
            $this->fail('Guard did not throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString(
                '3.12',
                $e->getMessage(),
                'Error message should reference the v3.12 release'
            );
        }
    }
}
