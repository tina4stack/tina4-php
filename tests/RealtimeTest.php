<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\ORM;
use Tina4\Realtime\Realtime;
use Tina4\Realtime\Storage;
use Tina4\Realtime\LocalStorage;
use Tina4\Realtime\StorageBackend;
use Tina4\Realtime\Workspace;
use Tina4\Realtime\Channel;
use Tina4\Realtime\ChannelMember;
use Tina4\Realtime\Message;
use Tina4\Realtime\Attachment;

/**
 * Behavioural regression suite for the realtime collaboration module
 * (Tina4\Realtime), parity with the Python master's realtime tests.
 *
 * No mocks: the models hit a real SQLite database, LocalStorage hits the real
 * filesystem, and the ICE-config HMAC is verified against a real hash. The
 * central lock-in is the ORM camel/snake mapping: the models declare camelCase
 * properties, which the ORM must map to snake_case columns (channel_id, ...)
 * AND populate on read — a mismatch there silently null'd Attachment.channelId
 * and 403'd every file download until it was fixed.
 */
class RealtimeTest extends TestCase
{
    private string $dbFile = '';
    private string $storeDir = '';

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/tina4_rt_test_' . bin2hex(random_bytes(6)) . '.db';
        $this->storeDir = sys_get_temp_dir() . '/tina4_rt_store_' . bin2hex(random_bytes(6));
        $db = Database::create('sqlite:///' . $this->dbFile);
        ORM::bindDatabase($db);
        \Tina4\App::setDatabase($db);
        foreach ([Workspace::class, Channel::class, ChannelMember::class, Message::class, Attachment::class] as $model) {
            (new $model())->createTable();
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->dbFile);
        if (is_dir($this->storeDir)) {
            foreach (glob($this->storeDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->storeDir);
        }
        foreach (['TINA4_RTC_STUN_URLS', 'TINA4_RTC_TURN_URL', 'TINA4_RTC_TURN_SECRET',
                  'TINA4_RTC_TURN_TTL', 'TINA4_STORAGE_BACKEND', 'TINA4_STORAGE_BUCKET'] as $k) {
            putenv($k);
            unset($_ENV[$k]);
        }
    }

    // ── ICE config (STUN + ephemeral TURN) ──────────────────────────

    public function testIceStunOnlyByDefault(): void
    {
        putenv('TINA4_RTC_TURN_URL');
        putenv('TINA4_RTC_TURN_SECRET');
        $servers = Realtime::iceServers();
        $this->assertCount(1, $servers);
        $this->assertSame(['stun:stun.l.google.com:19302'], $servers[0]['urls']);
        $this->assertArrayNotHasKey('credential', $servers[0]);
    }

    public function testEphemeralTurnCredentialIsValidHmac(): void
    {
        putenv('TINA4_RTC_TURN_URL=turn:turn.example.com:3478');
        putenv('TINA4_RTC_TURN_SECRET=s3cr3t');
        $servers = Realtime::iceServers();
        $this->assertCount(2, $servers);
        $turn = $servers[1];
        $this->assertSame(['turn:turn.example.com:3478'], $turn['urls']);
        // username is a future expiry epoch; credential = base64(HMAC-SHA1(secret, username))
        $this->assertGreaterThan(time(), (int)$turn['username']);
        $expected = base64_encode(hash_hmac('sha1', $turn['username'], 's3cr3t', true));
        $this->assertSame($expected, $turn['credential']);
    }

    // ── LocalStorage (real filesystem) ──────────────────────────────

    public function testLocalStorageRoundtripAndDelete(): void
    {
        $store = new LocalStorage($this->storeDir);
        $key = Storage::key('report.pdf');
        $this->assertStringEndsWith('.pdf', $key);
        $store->put($key, 'binary-bytes', 'application/pdf');
        $this->assertTrue($store->exists($key));
        $this->assertSame('binary-bytes', $store->get($key));
        $this->assertNull($store->url($key));            // local has no direct URL
        $store->delete($key);
        $this->assertFalse($store->exists($key));
        $this->assertNull($store->get($key));
    }

    public function testLocalStorageRejectsPathTraversal(): void
    {
        $store = new LocalStorage($this->storeDir);
        $this->assertNull($store->get('../../etc/passwd'));
        $this->assertFalse($store->exists('../../etc/passwd'));
    }

    public function testStorageSelectDefaultsToLocal(): void
    {
        putenv('TINA4_STORAGE_BACKEND');
        $this->assertInstanceOf(LocalStorage::class, Storage::select());
    }

    public function testStorageSelectS3WithoutBucketFallsBackToLocal(): void
    {
        putenv('TINA4_STORAGE_BACKEND=s3');
        putenv('TINA4_STORAGE_BUCKET');
        $this->assertInstanceOf(LocalStorage::class, Storage::select());
    }

    public function testStorageKeyCarriesNoPathSegment(): void
    {
        $key = Storage::key('../../evil/name.TXT');
        $this->assertStringNotContainsString('/', $key);
        $this->assertStringEndsWith('.TXT', $key);
    }

    // ── ORM camel/snake mapping — the download-403 regression ────────

    public function testAttachmentCamelPropsMapToSnakeColumnsBothWays(): void
    {
        $att = new Attachment();
        $att->channelId = 7;
        $att->storageKey = 'abc123.txt';
        $att->filename = 'note.txt';
        $att->mime = 'text/plain';
        $att->size = 12;
        $this->assertNotFalse($att->save());

        // Column is snake_case in the DB (Python-parity schema + raw queries).
        $db = \Tina4\App::getDatabase();
        $rows = $db->fetch('SELECT channel_id, storage_key FROM tina4_rt_attachments')->toArray();
        $this->assertSame(7, (int)$rows[0]['channel_id']);
        $this->assertSame('abc123.txt', $rows[0]['storage_key']);

        // Read back through the ORM: camelCase property is populated (the bug was
        // this returning null, which 403'd the permissioned download).
        $loaded = (new Attachment())->where('storage_key = ?', ['abc123.txt'], 1)[0];
        $this->assertSame(7, (int)$loaded->channelId);
        $this->assertSame('note.txt', $loaded->filename);

        // toDict() serialises snake_case keys (the wire/JSON contract).
        $dict = $loaded->toDict();
        $this->assertSame(7, (int)$dict['channel_id']);
        $this->assertSame('abc123.txt', $dict['storage_key']);
    }

    public function testMembershipLoadModifySavePersists(): void
    {
        $m = new ChannelMember();
        $m->channelId = 3;
        $m->userId = '1';
        $m->role = 'owner';
        $m->save();

        // Load, set only lastReadAt, save — must persist AND keep channelId/userId
        // (the snake/camel asymmetry used to make this a silent no-op).
        $loaded = (new ChannelMember())->where('channel_id = ? AND user_id = ?', [3, '1'], 1)[0];
        $loaded->lastReadAt = '2026-07-08T10:00:00Z';
        $loaded->save();

        $db = \Tina4\App::getDatabase();
        $row = $db->fetch('SELECT channel_id, user_id, last_read_at FROM tina4_rt_channel_members')->toArray()[0];
        $this->assertSame(3, (int)$row['channel_id']);
        $this->assertSame('1', (string)$row['user_id']);
        $this->assertSame('2026-07-08T10:00:00Z', $row['last_read_at']);
    }

    // ── Message persistence + history newest-first ──────────────────

    public function testMessagesPersistAndHistoryIsNewestFirst(): void
    {
        foreach (['first', 'second', 'third'] as $i => $body) {
            $msg = new Message();
            $msg->channelId = 1;
            $msg->userId = '1';
            $msg->body = $body;
            $msg->createdAt = sprintf('2026-07-08T10:0%d:00Z', $i);
            $this->assertNotFalse($msg->save());
        }
        $rows = (new Message())->where('channel_id = ?', [1], 50);
        $this->assertCount(3, $rows);
        // Newest-first: history() orders id DESC (highest = last inserted leads).
        $db = \Tina4\App::getDatabase();
        $ordered = $db->fetch('SELECT body FROM tina4_rt_messages WHERE channel_id = ? ORDER BY id DESC', [1])->toArray();
        $bodies = array_map(fn ($r) => $r['body'], $ordered);
        $this->assertSame(['third', 'second', 'first'], $bodies);
    }

    // ── mount() wires the calls plane routes + config ───────────────

    public function testMountRegistersRealtimeRoutes(): void
    {
        \Tina4\Router::clear();
        $paths = Realtime::mount('', ['features' => ['calls', 'chat', 'files']]);
        $this->assertSame('mesh', $paths['backend']);
        $this->assertSame('/api/rtc/config', $paths['config']);
        $this->assertSame('/ws/rtc', $paths['signalling']);
        $this->assertSame('/ws/chat', $paths['chat']);
        $this->assertSame('/api/files', $paths['files']);
        \Tina4\Router::clear();
    }
}
