<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\DotEnv;

/**
 * Feature 1, step 5: the dotenv SURFACE is the same shape in all four.
 *
 * The parser behaviour was reconciled on 2026-07-30 and is pinned by the shared
 * corpus. The CALL SHAPE was not, and that is what these lock in.
 *
 * PHP took a FILE path, like Python and Node, and pushed the precedence rule
 * (real-env > .env.local > .env) onto the caller - App.php built the .env.local
 * path by hand and loaded the two files in the right order. Ruby encapsulated
 * that in a directory form, and getting the order or the overwrite flag wrong
 * lets a stray gitignored .env.local beat a production variable. The directory
 * form is now canonical here too.
 *
 * NO MOCKS and no doubles: a .env is a file, so the real dependency is a real
 * file in a real temp directory, and the real process environment.
 *
 * Identical case names in all four frameworks:
 *   tina4-python/tests/test_dotenv_surface.py
 *   tina4-ruby/spec/dotenv_surface_spec.rb
 *   tina4-nodejs/test/dotenvSurface.test.ts
 */
class DotEnvSurfaceTest extends TestCase
{
    private const KEYS = ['SURFACE_BASE', 'SURFACE_SHARED', 'SURFACE_LOCAL'];

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/tina4_surface_' . bin2hex(random_bytes(6));
        mkdir($this->root);
        file_put_contents($this->root . '/.env', "SURFACE_BASE=from_env\nSURFACE_SHARED=from_env\n");
        file_put_contents($this->root . '/.env.local', "SURFACE_SHARED=from_local\nSURFACE_LOCAL=only_local\n");
        $this->clearKeys();
        DotEnv::resetEnv();
    }

    protected function tearDown(): void
    {
        $this->clearKeys();
        @unlink($this->root . '/.env');
        @unlink($this->root . '/.env.local');
        @rmdir($this->root);
    }

    private function clearKeys(): void
    {
        foreach (self::KEYS as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    /** POSITIVE: the canonical form. A directory loads BOTH files, in order. */
    public function testLoadEnvAcceptsARootDirectory(): void
    {
        $result = DotEnv::loadEnv($this->root);

        $this->assertEquals('from_env', getenv('SURFACE_BASE'), 'the .env was not read');
        $this->assertEquals('only_local', getenv('SURFACE_LOCAL'), 'the .env.local was not read');
        $this->assertEquals('from_env', $result['SURFACE_BASE']);
    }

    /**
     * The whole reason the directory form exists: .env.local beats .env. A
     * caller doing this by hand in the wrong order gets the opposite, silently.
     */
    public function testLoadEnvDirectoryFormGivesEnvLocalPrecedence(): void
    {
        DotEnv::loadEnv($this->root);
        $this->assertEquals('from_local', getenv('SURFACE_SHARED'));
    }

    /** NEGATIVE: the directory form must not break the file form. */
    public function testLoadEnvStillAcceptsASingleFile(): void
    {
        DotEnv::loadEnv($this->root . '/.env');

        $this->assertEquals('from_env', getenv('SURFACE_BASE'));
        $this->assertFalse(
            getenv('SURFACE_LOCAL'),
            'naming ONE file must read only that file - the caller owns the ordering'
        );
    }

    /** NEGATIVE: the obvious call must not raise. */
    public function testLoadEnvIsReachableFromTheTopLevelNamespace(): void
    {
        foreach (['loadEnv', 'getEnv', 'requireEnv', 'hasEnv', 'allEnv', 'resetEnv', 'isTruthy'] as $name) {
            $this->assertTrue(
                method_exists(DotEnv::class, $name),
                "DotEnv::{$name} is not reachable"
            );
        }
    }

    /** A fresh checkout has no .env.local, and the directory form reads it anyway. */
    public function testAMissingEnvLocalIsNotAnError(): void
    {
        $solo = sys_get_temp_dir() . '/tina4_solo_' . bin2hex(random_bytes(6));
        mkdir($solo);
        file_put_contents($solo . '/.env', "SOLO=1\n");
        putenv('SOLO');
        unset($_ENV['SOLO'], $_SERVER['SOLO']);

        DotEnv::loadEnv($solo);
        $this->assertEquals('1', getenv('SOLO'));

        putenv('SOLO');
        unset($_ENV['SOLO'], $_SERVER['SOLO']);
        @unlink($solo . '/.env');
        @rmdir($solo);
    }
}
