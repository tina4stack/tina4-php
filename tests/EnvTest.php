<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * EnvTest — typed env-var helpers. Behaviour contract is mirrored in
 * tina4-python (Tina4\Env), tina4-ruby (Tina4::Env), and tina4-nodejs (Env).
 * Feature #43 across all four Tina4 frameworks.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Env;

class EnvTest extends TestCase
{
    /** Env keys mutated by these tests — reset between cases. */
    private const TRACKED = ['FOO'];

    protected function setUp(): void
    {
        foreach (self::TRACKED as $key) {
            unset($_ENV[$key]);
            @putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::TRACKED as $key) {
            unset($_ENV[$key]);
            @putenv($key);
        }
    }

    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        @putenv("$key=$value");
    }

    // ── Env::bool ─────────────────────────────────────────────────────

    public function testBoolReturnsDefaultWhenUnset(): void
    {
        $this->assertTrue(Env::bool('FOO', true));
        $this->assertFalse(Env::bool('FOO', false));
        $this->assertFalse(Env::bool('FOO'));  // default-default
    }

    /**
     * @dataProvider truthyValues
     */
    public function testBoolTruthyValues(string $value): void
    {
        $this->setEnv('FOO', $value);
        $this->assertTrue(Env::bool('FOO'), "value '{$value}' should be truthy");
    }

    public static function truthyValues(): array
    {
        return array_map(fn($v) => [$v], [
            '1', 'true', 'TRUE', 'True', 'on', 'ON',
            'yes', 'YES', 'y', 'Y', 't', 'T',
        ]);
    }

    /**
     * @dataProvider falsyValues
     */
    public function testBoolFalsyValues(string $value): void
    {
        $this->setEnv('FOO', $value);
        $this->assertFalse(Env::bool('FOO', true), "value '{$value}' should be falsy");
    }

    public static function falsyValues(): array
    {
        return array_map(fn($v) => [$v], [
            '0', 'false', 'FALSE', 'False', 'off', 'OFF',
            'no', 'NO', 'n', 'N', 'f', 'F',
        ]);
    }

    public function testBoolEmptyStringUsesFalsyNormalisation(): void
    {
        // Empty string is in the falsy set, so it normalises to false
        // regardless of the default — set-but-empty == explicit false.
        $this->setEnv('FOO', '');
        $this->assertFalse(Env::bool('FOO', true));
    }

    public function testBoolGarbageUsesDefault(): void
    {
        $this->setEnv('FOO', 'maybe');
        $this->assertTrue(Env::bool('FOO', true));
        $this->assertFalse(Env::bool('FOO', false));
    }

    public function testBoolWhitespaceStripped(): void
    {
        $this->setEnv('FOO', '  true  ');
        $this->assertTrue(Env::bool('FOO'));
    }

    // ── Env::int ──────────────────────────────────────────────────────

    public function testIntReturnsDefaultWhenUnset(): void
    {
        $this->assertSame(42, Env::int('FOO', 42));
        $this->assertSame(0, Env::int('FOO'));  // default-default
    }

    public function testIntParsesDecimal(): void
    {
        $this->setEnv('FOO', '123');
        $this->assertSame(123, Env::int('FOO'));
    }

    public function testIntParsesNegative(): void
    {
        $this->setEnv('FOO', '-456');
        $this->assertSame(-456, Env::int('FOO'));
    }

    public function testIntWhitespaceStripped(): void
    {
        $this->setEnv('FOO', '  789  ');
        $this->assertSame(789, Env::int('FOO'));
    }

    public function testIntGarbageReturnsDefault(): void
    {
        $this->setEnv('FOO', 'not-a-number');
        $this->assertSame(99, Env::int('FOO', 99));
        // Must not throw — that's the parity contract.
    }

    public function testIntFloatStringRejected(): void
    {
        // int("3.14") would mangle silently in PHP — we reject it for
        // parity with Python's int() boundary so callers pick Env::float
        // vs Env::int deliberately.
        $this->setEnv('FOO', '3.14');
        $this->assertSame(7, Env::int('FOO', 7));
    }

    // ── Env::float ────────────────────────────────────────────────────

    public function testFloatReturnsDefaultWhenUnset(): void
    {
        $this->assertSame(1.5, Env::float('FOO', 1.5));
        $this->assertSame(0.0, Env::float('FOO'));
    }

    public function testFloatParsesDecimal(): void
    {
        $this->setEnv('FOO', '3.14');
        $this->assertSame(3.14, Env::float('FOO'));
    }

    public function testFloatParsesIntegerAsFloat(): void
    {
        $this->setEnv('FOO', '42');
        $this->assertSame(42.0, Env::float('FOO'));
    }

    public function testFloatParsesScientific(): void
    {
        $this->setEnv('FOO', '1.5e3');
        $this->assertSame(1500.0, Env::float('FOO'));
    }

    public function testFloatWhitespaceStripped(): void
    {
        $this->setEnv('FOO', '  2.5  ');
        $this->assertSame(2.5, Env::float('FOO'));
    }

    public function testFloatGarbageReturnsDefault(): void
    {
        $this->setEnv('FOO', 'not-a-number');
        $this->assertSame(2.5, Env::float('FOO', 2.5));
    }

    // ── Env::str ──────────────────────────────────────────────────────

    public function testStrReturnsDefaultWhenUnset(): void
    {
        $this->assertSame('fallback', Env::str('FOO', 'fallback'));
        $this->assertSame('', Env::str('FOO'));
    }

    public function testStrReturnsValue(): void
    {
        $this->setEnv('FOO', 'hello');
        $this->assertSame('hello', Env::str('FOO'));
    }

    public function testStrPreservesWhitespace(): void
    {
        // str() does NOT trim — that's the caller's choice. This keeps
        // PATH-style values intact when their layout matters.
        $this->setEnv('FOO', '  hello  ');
        $this->assertSame('  hello  ', Env::str('FOO'));
    }

    public function testStrEmptyStringIsEmptyNotDefault(): void
    {
        // Setting "" is different from being unset — preserve that, so
        // callers can detect "explicitly cleared" vs "never configured".
        $this->setEnv('FOO', '');
        $this->assertSame('', Env::str('FOO', 'fallback'));
    }
}
