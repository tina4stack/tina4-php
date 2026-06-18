<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Backend-failure policy lock-in (log-loud + degrade) for the Session boundary.
 *
 * The policy lives at the Session boundary (one wrapper, all backends), so an
 * unreachable backend never crashes the request. These tests inject a THROWING
 * handler into the 'database' backend and assert the five invariants mirrored
 * from the Python master (commit cefc738):
 *
 *   1. start() on an unreachable backend does NOT raise — returns an id, empty
 *      data, AND logs an error (never silent).
 *   2. save() on an unreachable backend returns false-y, RETAINS the dirty
 *      flag, logs an error, does not crash.
 *   3. destroy()/gc() on an unreachable backend log + do not crash.
 *   4. A genuinely EMPTY but HEALTHY backend (read returns no data WITHOUT
 *      raising) logs ZERO errors — empty is not a failure.
 *   5. With TINA4_SESSION_STRICT=true, read/write failures RE-RAISE.
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Database\SQLite3Adapter;
use Tina4\Log;
use Tina4\Session;
use Tina4\Session\DatabaseSessionHandler;

/**
 * A session handler whose every operation throws — simulates an unreachable
 * backend. Subclasses the real handler so it satisfies Session's typed
 * `$dbHandler` property. The parent ctor needs a DB, so we hand it an in-memory
 * SQLite adapter; the overridden methods never touch it.
 */
class ThrowingSessionHandler extends DatabaseSessionHandler
{
    public function read(string $sessionId): ?array
    {
        throw new \RuntimeException('backend unreachable (read)');
    }

    public function write(string $sessionId, array $data): void
    {
        throw new \RuntimeException('backend unreachable (write)');
    }

    public function destroy(string $sessionId): void
    {
        throw new \RuntimeException('backend unreachable (destroy)');
    }

    public function gc(int $ttl): void
    {
        throw new \RuntimeException('backend unreachable (gc)');
    }
}

/**
 * A healthy handler that simply has no stored session — read returns null
 * WITHOUT throwing. Must produce ZERO error logs (empty != failure).
 */
class EmptyHealthySessionHandler extends DatabaseSessionHandler
{
    public function read(string $sessionId): ?array
    {
        return null; // genuinely empty, healthy backend
    }

    public function write(string $sessionId, array $data): void
    {
        // succeed silently
    }

    public function destroy(string $sessionId): void
    {
        // succeed silently
    }
}

class SessionBackendFailurePolicyTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/tina4_sess_policy_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        Log::reset();
        Log::configure(logDir: $this->tempDir);
        putenv('TINA4_SESSION_STRICT');
        unset($_ENV['TINA4_SESSION_STRICT']);
    }

    protected function tearDown(): void
    {
        Log::reset();
        putenv('TINA4_SESSION_STRICT');
        unset($_ENV['TINA4_SESSION_STRICT']);
        foreach (glob($this->tempDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tempDir);
    }

    private function logContents(): string
    {
        $file = $this->tempDir . '/tina4.log';
        return is_file($file) ? (string)file_get_contents($file) : '';
    }

    private function errorLogCount(): int
    {
        $file = $this->tempDir . '/error.log';
        if (!is_file($file)) {
            return 0;
        }
        $lines = array_filter(explode("\n", trim((string)file_get_contents($file))));
        return count($lines);
    }

    /**
     * Build a Session on the 'database' backend with the given handler injected
     * into the private $dbHandler property.
     */
    private function sessionWith(DatabaseSessionHandler $handler): Session
    {
        $session = new Session('database');
        $ref = new \ReflectionClass($session);
        $prop = $ref->getProperty('dbHandler');
        $prop->setValue($session, $handler);
        return $session;
    }

    private function throwingHandler(): ThrowingSessionHandler
    {
        $db = Database::create('sqlite::memory:');
        return new ThrowingSessionHandler(['db' => $db, 'ttl' => 3600]);
    }

    private function emptyHealthyHandler(): EmptyHealthySessionHandler
    {
        $db = Database::create('sqlite::memory:');
        return new EmptyHealthySessionHandler(['db' => $db, 'ttl' => 3600]);
    }

    // -- Invariant 1: unreachable backend on start() degrades + logs ----------

    public function testStartOnUnreachableBackendDoesNotRaiseAndLogs(): void
    {
        $session = $this->sessionWith($this->throwingHandler());

        $id = $session->start('existing-session-id'); // resuming -> triggers a read

        // Did NOT raise, returned a session id.
        $this->assertSame('existing-session-id', $id);
        $this->assertTrue($session->isStarted());

        // Data degraded to empty (only internal meta, no user keys).
        $this->assertSame([], $session->all());

        // An error WAS logged (never silent), naming the handler.
        $log = $this->logContents();
        $this->assertStringContainsString('"level":"ERROR"', $log);
        $this->assertStringContainsString('Session read failed', $log);
        $this->assertStringContainsString('DatabaseSessionHandler', $log);
    }

    // -- Invariant 2: save() on unreachable backend -> false, dirty retained --

    public function testSaveOnUnreachableBackendReturnsFalseAndRetainsDirty(): void
    {
        $session = $this->sessionWith($this->throwingHandler());
        $session->start(); // fresh session, no read

        // set() flips dirty + calls save(); save() degrades on the throwing write.
        $session->set('user_id', 42);

        // dirty flag retained for a later retry.
        $ref = new \ReflectionClass($session);
        $dirty = $ref->getProperty('dirty');
        $this->assertTrue($dirty->getValue($session), 'dirty flag must be retained after a failed write');

        // save() itself returns false-y on the degraded write.
        $this->assertFalse($session->save());

        // Did not crash; an error was logged.
        $log = $this->logContents();
        $this->assertStringContainsString('Session write failed', $log);
        $this->assertStringContainsString('"level":"ERROR"', $log);
    }

    // -- Invariant 3: destroy()/gc() on unreachable backend log + don't crash -

    public function testDestroyOnUnreachableBackendLogsAndDoesNotCrash(): void
    {
        $session = $this->sessionWith($this->throwingHandler());
        $session->start('some-id');

        // Must not throw.
        $session->destroy();

        $this->assertFalse($session->isStarted());
        $this->assertStringContainsString('Session destroy failed', $this->logContents());
    }

    public function testGcOnUnreachableBackendLogsAndDoesNotCrash(): void
    {
        $session = $this->sessionWith($this->throwingHandler());

        // Must not throw.
        $session->gc();

        $this->assertStringContainsString('Session gc failed', $this->logContents());
    }

    // -- Invariant 4: empty-but-healthy backend logs ZERO errors --------------

    public function testEmptyHealthyBackendLogsNoErrors(): void
    {
        $session = $this->sessionWith($this->emptyHealthyHandler());

        $session->start('no-such-session'); // healthy read returns null, no throw
        $session->set('k', 'v');            // healthy write, no throw
        $session->save();
        $session->destroy();                // healthy destroy, no throw

        $this->assertSame(0, $this->errorLogCount(), 'empty-but-healthy backend must log zero errors');
        $this->assertStringNotContainsString('"level":"ERROR"', $this->logContents());
    }

    // -- Invariant 5: TINA4_SESSION_STRICT=true re-raises ---------------------

    public function testStrictModeReRaisesReadFailure(): void
    {
        putenv('TINA4_SESSION_STRICT=true');
        $_ENV['TINA4_SESSION_STRICT'] = 'true';

        $session = $this->sessionWith($this->throwingHandler());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('backend unreachable (read)');

        $session->start('existing-id'); // strict -> read failure re-raises
    }

    public function testStrictModeReRaisesWriteFailure(): void
    {
        putenv('TINA4_SESSION_STRICT=true');
        $_ENV['TINA4_SESSION_STRICT'] = 'true';

        $session = $this->sessionWith($this->throwingHandler());
        $session->start(); // fresh, no read

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('backend unreachable (write)');

        $session->set('k', 'v'); // strict -> write failure re-raises
    }
}
