<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Session;

/**
 * Session lifecycle parity tests (file backend, real filesystem, no mocks).
 *
 * Aligns tina4-php to the Python master
 * (tina4_python/tina4_python/session/__init__.py):
 *
 *   1. destroy() ENDS the session — a later set()+save() with NO new start()
 *      must write NO record. The master nulls _session_id in destroy(); PHP kept
 *      it and set() auto-saves, so set()+save() after destroy re-persisted under
 *      the just-destroyed id.
 *   2. flash(key, null) is the GET sentinel — it READS-and-clears, never STORES
 *      null (PHP already behaves this way; this locks it in).
 *
 * NOTE: assertions are kept OUTSIDE any try/catch. PHPUnit's AssertionFailedError
 * extends \RuntimeException, so a failing assertion inside a catch(\Throwable)
 * would be swallowed and the test could never go red.
 */
class SessionLifecycleTest extends TestCase
{
    private string $testSessionPath;

    protected function setUp(): void
    {
        $this->testSessionPath = sys_get_temp_dir() . '/tina4_lifecycle_sessions_' . bin2hex(random_bytes(4));
        mkdir($this->testSessionPath, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testSessionPath)) {
            foreach (glob($this->testSessionPath . '/*.json') as $file) {
                unlink($file);
            }
            rmdir($this->testSessionPath);
        }
    }

    private function createSession(): Session
    {
        return new Session('file', ['path' => $this->testSessionPath, 'ttl' => 3600]);
    }

    /**
     * The .json session files currently on disk.
     *
     * @return string[]
     */
    private function sessionFiles(): array
    {
        return glob($this->testSessionPath . '/*.json') ?: [];
    }

    // ── destroy() must not let a later set()+save() RESURRECT the session ──

    public function testSetAndSaveAfterDestroyCreatesNoRecord(): void
    {
        $session = $this->createSession();
        $oldId = $session->start();
        $session->set('user_id', 42); // set() auto-saves
        $session->save();
        $this->assertCount(1, $this->sessionFiles(), 'a record must exist after the first write');

        // End the session: the record is removed and the id is cleared.
        $session->destroy();
        $this->assertCount(0, $this->sessionFiles(), 'destroy() must remove the stored record');
        $this->assertSame('', $session->getSessionId(), 'destroy() must clear the session id');

        // A set()+save() with NO new start() must write NO record — the exact
        // resurrection the bug caused (set() auto-saves under the retained id).
        $session->set('user_id', 99);
        $session->save();
        $this->assertCount(0, $this->sessionFiles(), 'set()+save() after destroy() must NOT re-create a record');

        // A FRESH session reading the OLD id from the SAME backend finds NO data.
        $fresh = $this->createSession();
        $this->assertNull($fresh->read($oldId), 'the destroyed id must not be readable again — nothing was re-created');
    }

    public function testNewStartAfterDestroyMintsFreshIdAndPersists(): void
    {
        // Negative control: destroy() ends the current session, not the object —
        // a NEW start() mints a fresh id and that session persists normally.
        $session = $this->createSession();
        $oldId = $session->start();
        $session->set('k', 'v');
        $session->save();
        $session->destroy();

        $newId = $session->start();
        $this->assertNotSame($oldId, $newId, 'a fresh start() after destroy() mints a NEW id');
        $this->assertNotEmpty($newId);

        $session->set('k', 'v2');
        $session->save();
        $this->assertCount(1, $this->sessionFiles(), 'the freshly started session persists normally');

        $fresh = $this->createSession();
        $data = $fresh->read($newId);
        $this->assertIsArray($data);
        $this->assertSame('v2', $data['k'] ?? null, 'the fresh session is readable under its NEW id');
    }

    // ── flash(key, null) must READ-and-clear, not STORE null ──

    public function testFlashNullReadsAndClearsAndDoesNotStoreNull(): void
    {
        $session = $this->createSession();
        $session->start();

        $session->flash('message', 'Saved!'); // set (value !== null)
        $this->assertTrue($session->has('_flash_message'), 'flash set must store the value');

        // null is the GET sentinel: read the pending value AND clear it.
        $first = $session->flash('message', null);
        $this->assertSame('Saved!', $first, 'flash(key, null) must READ the pending value');
        $this->assertFalse(
            $session->has('_flash_message'),
            'flash(key, null) must CLEAR the key — it must never STORE null'
        );

        // A second read is empty — the value was consumed, not re-stored as null.
        $second = $session->flash('message', null);
        $this->assertNull($second, 'a second flash read is empty');
    }
}
