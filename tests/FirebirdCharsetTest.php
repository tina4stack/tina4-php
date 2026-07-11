<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * php #160 — the Firebird adapter must honour a charset override.
 *
 * The adapter used to hardcode the connection charset to UTF8 with no override,
 * double-encoding UTF-8 bytes stored under a legacy NONE database. The charset
 * is now resolved from, in precedence order:
 *
 *   1. the connection URL query   firebird://host:port/path?charset=NONE
 *   2. an explicit charset arg passed to the constructor
 *   3. the TINA4_DATABASE_CHARSET environment variable
 *   4. the UTF8 default (unchanged — non-breaking)
 *
 * These exercise the PURE config resolver FirebirdAdapter::resolveCharset()
 * directly. It opens NO connection (it only parses a URL, an arg, and an env
 * var), so this is pure-logic — not a mocked DB. Both the native ext-interbase
 * adapter and PdoFirebirdAdapter thread this resolved value into their connect
 * (ibase_connect charset arg / PDO `charset=` DSN) before opening.
 *
 * Mirrors tina4-python/tests/test_firebird_charset.py (the Python master).
 *
 * LIVE double-encode caveat: a real NONE-vs-UTF8 byte-fidelity assertion needs
 * the dedicated FB5 test container (t4-fb-test on :3052) — it is NOT run here,
 * so the live repro is asserted on a Firebird-provisioned runner. This box has
 * only an unrelated application's Firebird DB, which these tests must not touch.
 * No Firebird connection is faked.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\FirebirdAdapter;

class FirebirdCharsetTest extends TestCase
{
    protected function setUp(): void
    {
        $this->clearCharsetEnv();
    }

    protected function tearDown(): void
    {
        $this->clearCharsetEnv();
    }

    private function clearCharsetEnv(): void
    {
        \Tina4\DotEnv::resetEnv();
        putenv('TINA4_DATABASE_CHARSET');
        unset($_ENV['TINA4_DATABASE_CHARSET']);
    }

    private function setCharsetEnv(string $value): void
    {
        $_ENV['TINA4_DATABASE_CHARSET'] = $value;
    }

    // ── Precedence ──────────────────────────────────────────────────────────

    public function testDefaultIsUtf8(): void
    {
        $this->assertSame(
            'UTF8',
            FirebirdAdapter::resolveCharset('firebird://localhost:3050/employee')
        );
    }

    public function testUrlQueryCharsetWins(): void
    {
        $this->assertSame(
            'NONE',
            FirebirdAdapter::resolveCharset('firebird://localhost:3050/employee?charset=NONE')
        );
    }

    public function testUrlQueryCharsetOverridesEnv(): void
    {
        $this->setCharsetEnv('WIN1252');
        $this->assertSame(
            'NONE',
            FirebirdAdapter::resolveCharset('firebird://localhost:3050/employee?charset=NONE'),
            'URL query param must win over the env var'
        );
    }

    public function testEnvUsedWhenNoUrlParam(): void
    {
        $this->setCharsetEnv('ISO8859_1');
        $this->assertSame(
            'ISO8859_1',
            FirebirdAdapter::resolveCharset('firebird://localhost:3050/employee')
        );
    }

    public function testArgUsedWhenNoUrlParam(): void
    {
        // An explicit charset arg beats env/default but yields to a URL query param.
        $this->assertSame(
            'WIN1251',
            FirebirdAdapter::resolveCharset('firebird://localhost:3050/employee', 'WIN1251')
        );
    }

    public function testArgOverridesEnv(): void
    {
        $this->setCharsetEnv('ISO8859_1');
        $this->assertSame(
            'WIN1251',
            FirebirdAdapter::resolveCharset('firebird://localhost:3050/employee', 'WIN1251'),
            'explicit charset arg must win over the env var'
        );
    }

    public function testUrlParamBeatsArg(): void
    {
        $this->assertSame(
            'NONE',
            FirebirdAdapter::resolveCharset('firebird://localhost:3050/employee?charset=NONE', 'UTF8')
        );
    }

    public function testEmptyArgFallsThroughToEnvThenDefault(): void
    {
        // An empty-string arg is treated as "not provided" — env then default apply.
        $this->assertSame(
            'UTF8',
            FirebirdAdapter::resolveCharset('firebird://localhost:3050/employee', '')
        );
        $this->setCharsetEnv('WIN1252');
        $this->assertSame(
            'WIN1252',
            FirebirdAdapter::resolveCharset('firebird://localhost:3050/employee', '')
        );
    }

    // ── Plain file-path connection string (no ://) — no query to parse ───────

    public function testPlainPathUsesEnvThenDefault(): void
    {
        $this->assertSame('UTF8', FirebirdAdapter::resolveCharset('/data/app.fdb'));
        $this->setCharsetEnv('DOS850');
        $this->assertSame('DOS850', FirebirdAdapter::resolveCharset('/data/app.fdb'));
    }

    // ── The ?charset= query must not pollute the resolved DB identifier ──────

    public function testCharsetQueryDoesNotPolluteDbPath(): void
    {
        $url = 'firebird://localhost:3050//data/app.fdb?charset=NONE';
        $path = parse_url($url, PHP_URL_PATH);
        $this->assertSame('/data/app.fdb', FirebirdAdapter::normalizeDbIdentifier($path));
        $this->assertSame('NONE', FirebirdAdapter::resolveCharset($url));
    }

    // ── Non-breaking: the constructor charset arg now defaults to null so the
    //    resolver (URL/env/default) drives it — a positional charset still wins. ─

    public function testConstructorCharsetArgIsNullableForResolution(): void
    {
        $ctor = (new \ReflectionClass(FirebirdAdapter::class))->getConstructor();
        $charsetParam = null;
        foreach ($ctor->getParameters() as $p) {
            if ($p->getName() === 'charset') {
                $charsetParam = $p;
                break;
            }
        }
        $this->assertNotNull($charsetParam, 'FirebirdAdapter must accept a charset argument');
        $this->assertTrue($charsetParam->allowsNull(), 'charset arg must be nullable so the resolver drives it');
        $this->assertTrue($charsetParam->isDefaultValueAvailable());
        $this->assertNull($charsetParam->getDefaultValue(), 'charset arg default must be null (resolve, not force UTF8)');
    }
}
