<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Session;

class SessionV3Test extends TestCase
{
    private string $testSessionPath;

    protected function setUp(): void
    {
        $this->testSessionPath = sys_get_temp_dir() . '/tina4_test_sessions_' . bin2hex(random_bytes(4));
        mkdir($this->testSessionPath, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up session files
        if (is_dir($this->testSessionPath)) {
            $files = glob($this->testSessionPath . '/*.json');
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($this->testSessionPath);
        }
    }

    private function createSession(array $config = []): Session
    {
        $defaults = [
            'path' => $this->testSessionPath,
            'ttl' => 3600,
        ];
        return new Session('file', array_merge($defaults, $config));
    }

    // ── Session Start ─────────────────────────────────────────────

    public function testStartGeneratesSessionId(): void
    {
        $session = $this->createSession();
        $id = $session->start();

        $this->assertNotEmpty($id);
        $this->assertEquals(32, strlen($id)); // 16 bytes = 32 hex chars
        $this->assertTrue(ctype_xdigit($id));
    }

    public function testStartReturnsSessionId(): void
    {
        $session = $this->createSession();
        $id = $session->start();
        $this->assertEquals($id, $session->getSessionId());
    }

    public function testStartWithExistingId(): void
    {
        $session = $this->createSession();
        $id = $session->start('my-custom-session-id');
        $this->assertEquals('my-custom-session-id', $id);
    }

    public function testIsStarted(): void
    {
        $session = $this->createSession();
        $this->assertFalse($session->isStarted());

        $session->start();
        $this->assertTrue($session->isStarted());
    }

    // ── Set / Get / Has / Delete ──────────────────────────────────

    public function testSetAndGet(): void
    {
        $session = $this->createSession();
        $session->start();

        $session->set('username', 'alice');
        $this->assertEquals('alice', $session->get('username'));
    }

    public function testGetDefault(): void
    {
        $session = $this->createSession();
        $session->start();

        $this->assertNull($session->get('nonexistent'));
        $this->assertEquals('fallback', $session->get('nonexistent', 'fallback'));
    }

    public function testHas(): void
    {
        $session = $this->createSession();
        $session->start();

        $this->assertFalse($session->has('key'));
        $session->set('key', 'value');
        $this->assertTrue($session->has('key'));
    }

    public function testDelete(): void
    {
        $session = $this->createSession();
        $session->start();

        $session->set('key', 'value');
        $this->assertTrue($session->has('key'));

        $session->delete('key');
        $this->assertFalse($session->has('key'));
        $this->assertNull($session->get('key'));
    }

    public function testSetMultipleValues(): void
    {
        $session = $this->createSession();
        $session->start();

        $session->set('name', 'Alice');
        $session->set('role', 'admin');
        $session->set('count', 42);

        $this->assertEquals('Alice', $session->get('name'));
        $this->assertEquals('admin', $session->get('role'));
        $this->assertEquals(42, $session->get('count'));
    }

    public function testSetOverwritesExistingValue(): void
    {
        $session = $this->createSession();
        $session->start();

        $session->set('key', 'old');
        $session->set('key', 'new');
        $this->assertEquals('new', $session->get('key'));
    }

    public function testSetComplexValues(): void
    {
        $session = $this->createSession();
        $session->start();

        $session->set('user', ['name' => 'Alice', 'roles' => ['admin', 'editor']]);
        $value = $session->get('user');

        $this->assertIsArray($value);
        $this->assertEquals('Alice', $value['name']);
        $this->assertContains('admin', $value['roles']);
    }

    // ── All ───────────────────────────────────────────────────────

    public function testAllReturnsDataWithoutMeta(): void
    {
        $session = $this->createSession();
        $session->start();

        $session->set('a', 1);
        $session->set('b', 2);

        $all = $session->all();
        $this->assertEquals(['a' => 1, 'b' => 2], $all);
        $this->assertArrayNotHasKey('_meta', $all);
    }

    public function testAllExcludesFlashData(): void
    {
        $session = $this->createSession();
        $session->start();

        $session->set('regular', 'data');
        $session->flash('notice', 'hello');

        $all = $session->all();
        $this->assertArrayHasKey('regular', $all);
        $this->assertArrayNotHasKey('_flash_notice', $all);
    }

    // ── Destroy ───────────────────────────────────────────────────

    public function testDestroy(): void
    {
        $session = $this->createSession();
        $id = $session->start();

        $session->set('key', 'value');
        $session->destroy();

        $this->assertFalse($session->isStarted());
        $this->assertNull($session->get('key'));

        // File should be removed
        $filePath = $this->testSessionPath . '/' . $id . '.json';
        $this->assertFileDoesNotExist($filePath);
    }

    // ── Regenerate ────────────────────────────────────────────────

    public function testRegenerate(): void
    {
        $session = $this->createSession();
        $oldId = $session->start();

        $session->set('keep', 'this');
        $newId = $session->regenerate();

        $this->assertNotEquals($oldId, $newId);
        $this->assertEquals(32, strlen($newId));
        $this->assertEquals('this', $session->get('keep'));

        // Old file should be removed
        $oldFile = $this->testSessionPath . '/' . $oldId . '.json';
        $this->assertFileDoesNotExist($oldFile);

        // New file should exist
        $newFile = $this->testSessionPath . '/' . $newId . '.json';
        $this->assertFileExists($newFile);
    }

    // ── Flash Data ────────────────────────────────────────────────

    public function testFlashSetAndGet(): void
    {
        $session = $this->createSession();
        $session->start();

        $session->flash('message', 'Success!');
        $value = $session->getFlash('message');

        $this->assertEquals('Success!', $value);
    }

    public function testFlashAutoDelete(): void
    {
        $session = $this->createSession();
        $session->start();

        $session->flash('notice', 'One-time message');

        // First read returns the value
        $this->assertEquals('One-time message', $session->getFlash('notice'));

        // Second read returns the default (deleted after first read)
        $this->assertNull($session->getFlash('notice'));
    }

    public function testFlashDefault(): void
    {
        $session = $this->createSession();
        $session->start();

        $this->assertEquals('default', $session->getFlash('missing', 'default'));
    }

    // ── File Backend Persistence ──────────────────────────────────

    public function testFileBackendPersistence(): void
    {
        $session1 = $this->createSession();
        $id = $session1->start();
        $session1->set('persistent', 'data');

        // Create a new session instance and resume with the same ID
        $session2 = $this->createSession();
        $session2->start($id);

        $this->assertEquals('data', $session2->get('persistent'));
    }

    public function testFileBackendCreatesDirectory(): void
    {
        $deepPath = $this->testSessionPath . '/deep/nested/dir';
        $session = new Session('file', ['path' => $deepPath, 'ttl' => 3600]);
        $session->start();
        $session->set('test', 'value');

        $this->assertDirectoryExists($deepPath);

        // Cleanup
        $files = glob($deepPath . '/*.json');
        foreach ($files as $f) {
            unlink($f);
        }
        rmdir($deepPath);
        rmdir($this->testSessionPath . '/deep/nested');
        rmdir($this->testSessionPath . '/deep');
    }

    // ── TTL Expiration ────────────────────────────────────────────

    public function testTTLExpiration(): void
    {
        $session1 = $this->createSession(['ttl' => 1]);
        $id = $session1->start();
        $session1->set('expires', 'soon');

        // Wait for TTL to pass
        sleep(2);

        // Resume should find it expired
        $session2 = $this->createSession(['ttl' => 1]);
        $session2->start($id);

        $this->assertNull($session2->get('expires'));
    }

    public function testTTLNotExpiredYet(): void
    {
        $session1 = $this->createSession(['ttl' => 60]);
        $id = $session1->start();
        $session1->set('alive', 'yes');

        // Resume immediately — should not be expired
        $session2 = $this->createSession(['ttl' => 60]);
        $session2->start($id);

        $this->assertEquals('yes', $session2->get('alive'));
    }

    // ── Flash data — additional tests ───────────────────────────

    public function testFlashMultipleKeys(): void
    {
        $session = $this->createSession();
        $session->start();

        $session->flash('error', 'Something failed');
        $session->flash('success', 'Item saved');

        $this->assertEquals('Something failed', $session->getFlash('error'));
        $this->assertEquals('Item saved', $session->getFlash('success'));

        // Both should be consumed
        $this->assertNull($session->getFlash('error'));
        $this->assertNull($session->getFlash('success'));
    }

    public function testFlashWithArrayValue(): void
    {
        $session = $this->createSession();
        $session->start();

        $session->flash('errors', ['field1' => 'required', 'field2' => 'invalid']);
        $errors = $session->getFlash('errors');

        $this->assertIsArray($errors);
        $this->assertEquals('required', $errors['field1']);
        $this->assertNull($session->getFlash('errors'));
    }

    public function testFlashDoesNotAffectRegularData(): void
    {
        $session = $this->createSession();
        $session->start();

        $session->set('permanent', 'stays');
        $session->flash('temporary', 'goes');

        $session->getFlash('temporary');
        $this->assertEquals('stays', $session->get('permanent'));
    }

    public function testFlashOverwrite(): void
    {
        $session = $this->createSession();
        $session->start();

        $session->flash('msg', 'first');
        $session->flash('msg', 'second');

        $this->assertEquals('second', $session->getFlash('msg'));
    }

    // ── Regenerate — additional tests ───────────────────────────

    public function testRegeneratePreservesMultipleKeys(): void
    {
        $session = $this->createSession();
        $session->start();

        $session->set('a', 1);
        $session->set('b', 'two');
        $session->set('c', [3, 4, 5]);

        $newId = $session->regenerate();

        $this->assertEquals(1, $session->get('a'));
        $this->assertEquals('two', $session->get('b'));
        $this->assertEquals([3, 4, 5], $session->get('c'));
        $this->assertEquals($newId, $session->getSessionId());
    }

    public function testRegenerateMultipleTimes(): void
    {
        $session = $this->createSession();
        $id1 = $session->start();
        $session->set('data', 'persistent');

        $id2 = $session->regenerate();
        $id3 = $session->regenerate();

        $this->assertNotEquals($id1, $id2);
        $this->assertNotEquals($id2, $id3);
        $this->assertEquals('persistent', $session->get('data'));
    }

    // ── Has — additional tests ──────────────────────────────────

    public function testHasAfterDelete(): void
    {
        $session = $this->createSession();
        $session->start();

        $session->set('key', 'value');
        $this->assertTrue($session->has('key'));

        $session->delete('key');
        $this->assertFalse($session->has('key'));
    }

    public function testHasWithNullValue(): void
    {
        $session = $this->createSession();
        $session->start();

        $session->set('nullable', null);
        // array_key_exists should return true even for null value
        $this->assertTrue($session->has('nullable'));
    }

    // ── All — additional tests ──────────────────────────────────

    public function testAllEmpty(): void
    {
        $session = $this->createSession();
        $session->start();

        $all = $session->all();
        $this->assertEmpty($all);
    }

    public function testAllAfterDelete(): void
    {
        $session = $this->createSession();
        $session->start();

        $session->set('a', 1);
        $session->set('b', 2);
        $session->delete('a');

        $all = $session->all();
        $this->assertCount(1, $all);
        $this->assertArrayNotHasKey('a', $all);
        $this->assertArrayHasKey('b', $all);
    }

    // ── Clear ───────────────────────────────────────────────────

    public function testClearRemovesAllDataButKeepsSession(): void
    {
        $session = $this->createSession();
        $session->start();

        $session->set('x', 1);
        $session->set('y', 2);
        $session->clear();

        $this->assertEmpty($session->all());
        $this->assertTrue($session->isStarted());
    }

    public function testClearDoesNotAffectSessionId(): void
    {
        $session = $this->createSession();
        $id = $session->start();

        $session->set('data', 'value');
        $session->clear();

        $this->assertEquals($id, $session->getSessionId());
    }

    // ── Data types ──────────────────────────────────────────────

    public function testStoreInteger(): void
    {
        $session = $this->createSession();
        $session->start();

        $session->set('count', 42);
        $this->assertSame(42, $session->get('count'));
    }

    public function testStoreFloat(): void
    {
        $session = $this->createSession();
        $session->start();

        $session->set('price', 19.99);
        $this->assertSame(19.99, $session->get('price'));
    }

    public function testStoreBoolean(): void
    {
        $session = $this->createSession();
        $session->start();

        $session->set('active', true);
        $session->set('deleted', false);
        $this->assertTrue($session->get('active'));
        $this->assertFalse($session->get('deleted'));
    }

    public function testStoreNestedArray(): void
    {
        $session = $this->createSession();
        $session->start();

        $data = [
            'level1' => [
                'level2' => [
                    'level3' => 'deep',
                ],
            ],
        ];
        $session->set('nested', $data);
        $this->assertEquals('deep', $session->get('nested')['level1']['level2']['level3']);
    }

    public function testStoreEmptyString(): void
    {
        $session = $this->createSession();
        $session->start();

        $session->set('empty', '');
        $this->assertSame('', $session->get('empty'));
        $this->assertTrue($session->has('empty'));
    }

    // ── Multiple keys ───────────────────────────────────────────

    public function testManyKeysAtOnce(): void
    {
        $session = $this->createSession();
        $session->start();

        for ($i = 0; $i < 50; $i++) {
            $session->set("key_{$i}", "value_{$i}");
        }

        $all = $session->all();
        $this->assertCount(50, $all);
        $this->assertEquals('value_0', $session->get('key_0'));
        $this->assertEquals('value_49', $session->get('key_49'));
    }

    // ── Persistence — flash not persisted after read ────────────

    public function testFlashNotPersistedAfterRead(): void
    {
        $session1 = $this->createSession();
        $id = $session1->start();
        $session1->flash('notice', 'Hello');

        // Read flash in same session
        $session1->getFlash('notice');

        // Resume in new instance — flash should be gone
        $session2 = $this->createSession();
        $session2->start($id);
        $this->assertNull($session2->getFlash('notice'));
    }

    public function testFlashPersistedBeforeRead(): void
    {
        $session1 = $this->createSession();
        $id = $session1->start();
        $session1->flash('alert', 'Warning!');

        // Resume without reading flash first
        $session2 = $this->createSession();
        $session2->start($id);
        $this->assertEquals('Warning!', $session2->getFlash('alert'));
    }

    // ── Delete nonexistent key ──────────────────────────────────

    public function testDeleteNonexistentKey(): void
    {
        $session = $this->createSession();
        $session->start();

        // Should not throw
        $session->delete('nonexistent');
        $this->assertFalse($session->has('nonexistent'));
    }

    // ── getSessionId before start ───────────────────────────────

    public function testGetSessionIdBeforeStart(): void
    {
        $session = $this->createSession();
        $this->assertSame('', $session->getSessionId());
    }
}
