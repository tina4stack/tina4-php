<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * DevMailbox — comprehensive unit tests for the development mailbox.
 */

use PHPUnit\Framework\TestCase;
use Tina4\DevMailbox;

class DevMailboxTest extends TestCase
{
    private string $mailboxDir;

    protected function setUp(): void
    {
        $this->mailboxDir = sys_get_temp_dir() . '/tina4-devmailbox-test-' . getmypid() . '-' . uniqid();
        putenv('TINA4_MAILBOX_DIR=' . $this->mailboxDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->mailboxDir)) {
            $this->rmdirRecursive($this->mailboxDir);
        }
        putenv('TINA4_MAILBOX_DIR');
    }

    private function rmdirRecursive(string $dir): void
    {
        $entries = scandir($dir);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->rmdirRecursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    // ── Constructor ────────────────────────────────────────────────

    public function testConstructorCreatesInstance(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $this->assertInstanceOf(DevMailbox::class, $mailbox);
    }

    public function testGetMailboxDir(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $this->assertEquals($this->mailboxDir, $mailbox->getMailboxDir());
    }

    // ── Capture (send) ─────────────────────────────────────────────

    public function testCaptureReturnsSuccess(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $result = $mailbox->capture('test@example.com', 'Test Subject', 'Test body');
        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['id']);
    }

    public function testCaptureStoresMessageInOutbox(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $mailbox->capture('test@example.com', 'Test Subject', 'Test body');

        $messages = $mailbox->inbox(50, 0, 'outbox');
        $this->assertCount(1, $messages);
        $this->assertEquals('Test Subject', $messages[0]['subject']);
    }

    public function testCaptureWithArrayRecipients(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $result = $mailbox->capture(['a@test.com', 'b@test.com'], 'Multi', 'Body');
        $this->assertTrue($result['success']);

        $msg = $mailbox->read($result['id']);
        $this->assertCount(2, $msg['to']);
    }

    public function testCaptureWithHtml(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $result = $mailbox->capture('to@test.com', 'HTML', '<h1>Hello</h1>', true);
        $msg = $mailbox->read($result['id']);
        $this->assertTrue($msg['html']);
    }

    public function testCaptureWithCcAndBcc(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $result = $mailbox->capture(
            'to@test.com',
            'CC Test',
            'Body',
            cc: ['cc@test.com'],
            bcc: ['bcc@test.com'],
        );
        $msg = $mailbox->read($result['id']);
        $this->assertContains('cc@test.com', $msg['cc']);
        $this->assertContains('bcc@test.com', $msg['bcc']);
    }

    public function testCaptureWithReplyTo(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $result = $mailbox->capture(
            'to@test.com',
            'ReplyTo Test',
            'Body',
            replyTo: 'reply@test.com',
        );
        $msg = $mailbox->read($result['id']);
        $this->assertEquals('reply@test.com', $msg['reply_to']);
    }

    public function testCaptureWithHeaders(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $result = $mailbox->capture(
            'to@test.com',
            'Headers',
            'Body',
            headers: ['X-Custom' => 'value'],
        );
        $msg = $mailbox->read($result['id']);
        $this->assertEquals('value', $msg['headers']['X-Custom']);
    }

    public function testCaptureWithAttachmentMetadata(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $result = $mailbox->capture(
            'to@test.com',
            'Attach',
            'Body',
            attachments: [['filename' => 'doc.pdf', 'content' => 'binary', 'mime' => 'application/pdf']],
        );
        $msg = $mailbox->read($result['id']);
        $this->assertCount(1, $msg['attachments']);
        $this->assertEquals('doc.pdf', $msg['attachments'][0]['filename']);
    }

    public function testCaptureMultipleMessages(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        for ($i = 0; $i < 5; $i++) {
            $mailbox->capture("user{$i}@test.com", "Subject {$i}", "Body {$i}");
        }
        $messages = $mailbox->inbox(50, 0, 'outbox');
        $this->assertCount(5, $messages);
    }

    // ── Inbox listing ──────────────────────────────────────────────

    public function testInboxReturnsEmptyWhenNoMessages(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $messages = $mailbox->inbox();
        $this->assertIsArray($messages);
        $this->assertEmpty($messages);
    }

    public function testInboxWithLimit(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        for ($i = 0; $i < 5; $i++) {
            $mailbox->capture("user{$i}@test.com", "Subject {$i}", "Body");
        }
        $messages = $mailbox->inbox(3);
        $this->assertCount(3, $messages);
    }

    public function testInboxWithOffset(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        for ($i = 0; $i < 5; $i++) {
            $mailbox->capture("user{$i}@test.com", "Subject {$i}", "Body");
        }
        $messages = $mailbox->inbox(50, 2);
        $this->assertCount(3, $messages);
    }

    public function testInboxSortedByTimestampDescending(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $mailbox->capture('a@test.com', 'First', 'Body');
        // Sleep 1.1 seconds to ensure timestamps differ (resolution is 1 second)
        sleep(1);
        $mailbox->capture('b@test.com', 'Second', 'Body');

        $messages = $mailbox->inbox(50, 0, 'outbox');
        // Newest first
        $this->assertEquals('Second', $messages[0]['subject']);
        $this->assertEquals('First', $messages[1]['subject']);
    }

    public function testInboxAllFolders(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $mailbox->capture('to@test.com', 'Outbox msg', 'Body');
        $mailbox->seed(2);

        // All folders
        $all = $mailbox->inbox();
        $this->assertGreaterThanOrEqual(3, count($all));
    }

    // ── Read message ───────────────────────────────────────────────

    public function testReadReturnsFullMessage(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $result = $mailbox->capture('to@test.com', 'Read Me', 'Full body');
        $msg = $mailbox->read($result['id']);

        $this->assertNotNull($msg);
        $this->assertEquals('Read Me', $msg['subject']);
        $this->assertEquals('Full body', $msg['body']);
        $this->assertContains('to@test.com', $msg['to']);
    }

    public function testReadMarksAsRead(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $result = $mailbox->capture('to@test.com', 'Unread', 'Body');

        // First read marks it
        $msg = $mailbox->read($result['id']);
        $this->assertTrue($msg['read']);

        // Second read still shows read
        $msg2 = $mailbox->read($result['id']);
        $this->assertTrue($msg2['read']);
    }

    public function testReadNonExistentReturnsNull(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $this->assertNull($mailbox->read('nonexistent-id'));
    }

    // ── Unread count ───────────────────────────────────────────────

    public function testUnreadCountInitially(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $this->assertEquals(0, $mailbox->unreadCount());
    }

    public function testUnreadCountAfterCapture(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $mailbox->capture('to@test.com', 'Msg 1', 'Body');
        $mailbox->capture('to@test.com', 'Msg 2', 'Body');
        $this->assertEquals(2, $mailbox->unreadCount());
    }

    public function testUnreadCountDecreasesAfterRead(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $r1 = $mailbox->capture('to@test.com', 'Msg 1', 'Body');
        $mailbox->capture('to@test.com', 'Msg 2', 'Body');

        $mailbox->read($r1['id']);
        $this->assertEquals(1, $mailbox->unreadCount());
    }

    // ── Delete ─────────────────────────────────────────────────────

    public function testDeleteRemovesMessage(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $result = $mailbox->capture('to@test.com', 'Delete Me', 'Body');

        $this->assertTrue($mailbox->delete($result['id']));
        $this->assertNull($mailbox->read($result['id']));
    }

    public function testDeleteNonExistentReturnsFalse(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $this->assertFalse($mailbox->delete('nonexistent'));
    }

    public function testDeleteDoesNotAffectOtherMessages(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $r1 = $mailbox->capture('to@test.com', 'Keep', 'Body');
        $r2 = $mailbox->capture('to@test.com', 'Remove', 'Body');

        $mailbox->delete($r2['id']);

        $this->assertNotNull($mailbox->read($r1['id']));
    }

    // ── Clear ──────────────────────────────────────────────────────

    public function testClearAllFolders(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $mailbox->capture('to@test.com', 'Outbox', 'Body');
        $mailbox->seed(3);

        $mailbox->clear();

        $this->assertEmpty($mailbox->inbox());
    }

    public function testClearSpecificFolder(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $mailbox->capture('to@test.com', 'Outbox Msg', 'Body');
        $mailbox->seed(2);

        $mailbox->clear('outbox');

        $outbox = $mailbox->inbox(50, 0, 'outbox');
        $this->assertEmpty($outbox);

        // Inbox should still have seeded messages
        $inbox = $mailbox->inbox(50, 0, 'inbox');
        $this->assertCount(2, $inbox);
    }

    // ── Count ──────────────────────────────────────────────────────

    public function testCountReturnsCorrectCounts(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $mailbox->capture('to@test.com', 'Out 1', 'Body');
        $mailbox->capture('to@test.com', 'Out 2', 'Body');

        $counts = $mailbox->count();
        $this->assertEquals(2, $counts['outbox']);
        $this->assertEquals(2, $counts['total']);
    }

    public function testCountEmptyMailbox(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $counts = $mailbox->count();
        $this->assertEquals(0, $counts['total']);
    }

    public function testCountSpecificFolder(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $mailbox->seed(3);

        $counts = $mailbox->count('inbox');
        $this->assertEquals(3, $counts['inbox']);
    }

    // ── Seed ───────────────────────────────────────────────────────

    public function testSeedCreatesMessages(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $mailbox->seed(5);

        $messages = $mailbox->inbox(50, 0, 'inbox');
        $this->assertCount(5, $messages);
    }

    public function testSeedDefaultCount(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $mailbox->seed();

        $messages = $mailbox->inbox(50, 0, 'inbox');
        $this->assertCount(5, $messages);
    }

    public function testSeededMessagesHaveExpectedFields(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $mailbox->seed(1);

        $messages = $mailbox->inbox(1, 0, 'inbox');
        $msg = $mailbox->read($messages[0]['id']);

        $this->assertArrayHasKey('id', $msg);
        $this->assertArrayHasKey('from', $msg);
        $this->assertArrayHasKey('to', $msg);
        $this->assertArrayHasKey('subject', $msg);
        $this->assertArrayHasKey('body', $msg);
        $this->assertArrayHasKey('date', $msg);
        $this->assertArrayHasKey('folder', $msg);
        $this->assertEquals('inbox', $msg['folder']);
    }

    public function testSeedAddsToExistingMessages(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $mailbox->seed(3);
        $mailbox->seed(2);

        $messages = $mailbox->inbox(50, 0, 'inbox');
        $this->assertCount(5, $messages);
    }

    // ── Inbox listing field checks ─────────────────────────────────

    public function testInboxListingHasSummaryFields(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $mailbox->capture('to@test.com', 'Subject', 'Body');

        $messages = $mailbox->inbox(1, 0, 'outbox');
        $msg = $messages[0];

        $this->assertArrayHasKey('id', $msg);
        $this->assertArrayHasKey('to', $msg);
        $this->assertArrayHasKey('subject', $msg);
        $this->assertArrayHasKey('date', $msg);
        $this->assertArrayHasKey('read', $msg);
        $this->assertArrayHasKey('folder', $msg);
        $this->assertArrayHasKey('has_attachments', $msg);
    }

    public function testInboxListingShowsAttachmentFlag(): void
    {
        $mailbox = new DevMailbox($this->mailboxDir);
        $mailbox->capture(
            'to@test.com',
            'With Attach',
            'Body',
            attachments: [['filename' => 'file.txt', 'content' => 'data']],
        );

        $messages = $mailbox->inbox(1, 0, 'outbox');
        $this->assertTrue($messages[0]['has_attachments']);
    }
}
