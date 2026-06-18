<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Dev-secret bootstrap (Auth::ensureDevSecret) — fail-safe defaults.
 * Mirrors tina4_python tests/test_dev_secret.py exactly. No DB needed.
 *
 * Behaviour under test:
 *   - DEV (TINA4_DEBUG=true, CI unset, not production, blank secret):
 *       mints a 64-hex secret, sets it in process env, writes ONLY .env.local
 *       (never .env); appends to an existing .env.local without corrupting the
 *       last line; an already-set secret is left untouched (no write).
 *   - CI (CI=true) / production (TINA4_ENV=production) / non-dev
 *       (TINA4_DEBUG=false): NEVER generates, NEVER writes — the actionable
 *       warning path. Process env untouched.
 *   - Write failure (cwd is a regular file so <cwd>/.env.local open fails):
 *       no crash; the in-memory secret is still set for this run.
 *
 * Auth::ensureDevSecret resolves the secret from getenv()/$_ENV, so every test
 * clears TINA4_SECRET/CI/TINA4_ENV/TINA4_DEBUG from BOTH layers first, then sets
 * only what it needs, and passes cwd=$tmpDir to target an isolated directory.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Auth;

class DevSecretTest extends TestCase
{
    private string $tmpDir;

    /** Set an env var across every layer Auth::ensureDevSecret reads. */
    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    /** Clear an env var from every layer. */
    private function clearEnv(string $key): void
    {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }

    protected function setUp(): void
    {
        foreach (['TINA4_SECRET', 'CI', 'TINA4_ENV', 'TINA4_DEBUG'] as $k) {
            $this->clearEnv($k);
        }
        $this->tmpDir = sys_get_temp_dir() . '/tina4_devsecret_' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['TINA4_SECRET', 'CI', 'TINA4_ENV', 'TINA4_DEBUG'] as $k) {
            $this->clearEnv($k);
        }
        // Best-effort cleanup of the temp dir.
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpDir);
        } elseif (is_file($this->tmpDir)) {
            @unlink($this->tmpDir);
        }
    }

    // ── DEV generates ─────────────────────────────────────────────

    public function testDevGeneratesSecretAndWritesEnvLocal(): void
    {
        $this->setEnv('TINA4_DEBUG', 'true'); // dev, CI unset, not production

        $secret = Auth::ensureDevSecret($this->tmpDir);

        // 64 hex chars (32 bytes).
        $this->assertNotNull($secret);
        $this->assertSame(64, strlen($secret));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);

        // Set in the process env for this run.
        $this->assertSame($secret, getenv('TINA4_SECRET'));
        $this->assertSame($secret, $_ENV['TINA4_SECRET']);

        // Wrote ONLY .env.local — never .env.
        $local = $this->tmpDir . '/.env.local';
        $this->assertFileExists($local);
        $this->assertFileDoesNotExist($this->tmpDir . '/.env');
        $this->assertStringContainsString("TINA4_SECRET={$secret}", file_get_contents($local));
    }

    public function testDevAppendsToExistingEnvLocalWithoutCorruptingLastLine(): void
    {
        $this->setEnv('TINA4_DEBUG', 'true');

        $local = $this->tmpDir . '/.env.local';
        // Existing content with NO trailing newline — the new key must land on
        // its own line, not glued onto FOO=bar.
        file_put_contents($local, 'FOO=bar');

        $secret = Auth::ensureDevSecret($this->tmpDir);
        $this->assertNotNull($secret);

        $content = file_get_contents($local);
        $this->assertStringContainsString('FOO=bar', $content);
        $this->assertStringContainsString("TINA4_SECRET={$secret}", $content);
        // The original line is intact (not merged with the appended key).
        $this->assertStringNotContainsString("FOO=barTINA4_SECRET", $content);
        $this->assertStringContainsString("bar\nTINA4_SECRET={$secret}", $content);
    }

    public function testExistingSecretIsLeftUntouchedNoWrite(): void
    {
        $this->setEnv('TINA4_DEBUG', 'true');
        $this->setEnv('TINA4_SECRET', 'already-set-secret');

        $result = Auth::ensureDevSecret($this->tmpDir);

        $this->assertNull($result);                                  // no-op
        $this->assertSame('already-set-secret', getenv('TINA4_SECRET')); // unchanged
        $this->assertFileDoesNotExist($this->tmpDir . '/.env.local'); // no write
    }

    // ── CI / prod / non-dev DO NOT generate ───────────────────────

    public function testCiDoesNotGenerate(): void
    {
        $this->setEnv('TINA4_DEBUG', 'true'); // dev…
        $this->setEnv('CI', 'true');          // …but CI → must NOT generate

        $result = Auth::ensureDevSecret($this->tmpDir);

        $this->assertNull($result);
        $this->assertFalse(getenv('TINA4_SECRET'));                 // env untouched
        $this->assertFileDoesNotExist($this->tmpDir . '/.env.local'); // no file
    }

    public function testProductionDoesNotGenerate(): void
    {
        $this->setEnv('TINA4_DEBUG', 'true');
        $this->setEnv('TINA4_ENV', 'production');

        $result = Auth::ensureDevSecret($this->tmpDir);

        $this->assertNull($result);
        $this->assertFalse(getenv('TINA4_SECRET'));
        $this->assertFileDoesNotExist($this->tmpDir . '/.env.local');
    }

    public function testNonDevDoesNotGenerate(): void
    {
        // Debug off → not dev → must NOT generate.
        $this->setEnv('TINA4_DEBUG', 'false');

        $result = Auth::ensureDevSecret($this->tmpDir);

        $this->assertNull($result);
        $this->assertFalse(getenv('TINA4_SECRET'));
        $this->assertFileDoesNotExist($this->tmpDir . '/.env.local');
    }

    // ── Never crashes on write failure ────────────────────────────

    public function testNeverCrashesWhenEnvLocalCannotBeWritten(): void
    {
        $this->setEnv('TINA4_DEBUG', 'true');

        // Point cwd at a regular FILE — opening <file>/.env.local must fail,
        // but the bootstrap must not crash: it keeps the in-memory secret.
        $filePath = $this->tmpDir . '/not-a-dir';
        file_put_contents($filePath, 'i am a file');

        $secret = Auth::ensureDevSecret($filePath);

        $this->assertNotNull($secret);
        $this->assertSame(64, strlen($secret));
        // In-memory secret is set for this run despite the write failure.
        $this->assertSame($secret, getenv('TINA4_SECRET'));
    }
}
