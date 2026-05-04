<?php

use PHPUnit\Framework\TestCase;
use Tina4\Messenger;
use Tina4\DevMailbox;
use Tina4\MessengerFactory;

class MessengerTest extends TestCase
{
    private string $mailboxDir;

    protected function setUp(): void
    {
        $this->mailboxDir = sys_get_temp_dir() . '/tina4-mailbox-test-' . getmypid();
        // Point the env var to our test dir so DotEnv doesn't override the constructor
        putenv('TINA4_MAILBOX_DIR=' . $this->mailboxDir);
    }

    protected function tearDown(): void
    {
        // Clean up mailbox dir
        if (is_dir($this->mailboxDir)) {
            $this->rmdirRecursive($this->mailboxDir);
        }
        // Unset (not set to empty) to avoid leaking into other tests
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

    // ── Messenger Configuration ─────────────────────────────────

    public function testConstructorAcceptsAllParams(): void
    {
        $m = new Messenger(
            host: 'smtp.example.com',
            port: 587,
            username: 'user@example.com',
            password: 'secret',
            fromAddress: 'noreply@example.com',
            fromName: 'Test',
            useTls: true,
        );
        $this->assertInstanceOf(Messenger::class, $m);
    }

    public function testConstructorWithNullDefaults(): void
    {
        $m = new Messenger();
        $this->assertInstanceOf(Messenger::class, $m);
    }

    public function testSendFailsWithoutHost(): void
    {
        $m = new Messenger(host: null, port: null);
        $result = $m->send('test@example.com', 'Subject', 'Body');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('SMTP', $result['message']);
    }

    public function testSendFailsWithoutFromAddress(): void
    {
        $m = new Messenger(host: 'smtp.example.com', port: 587, fromAddress: null);
        $result = $m->send('test@example.com', 'Subject', 'Body');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('SMTP', $result['message']);
    }

    public function testSendFailsWithNoRecipients(): void
    {
        $m = new Messenger(host: 'smtp.example.com', port: 587, fromAddress: 'from@example.com');
        $result = $m->send([], 'Subject', 'Body');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('recipient', $result['message']);
    }

    public function testTestConnectionFailsWithoutHost(): void
    {
        $m = new Messenger(host: null, port: null);
        $result = $m->testConnection();
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('SMTP', $result['message']);
    }

    public function testSendFailsWithRefusedConnection(): void
    {
        $m = new Messenger(
            host: '127.0.0.1',
            port: 1, // Privileged port, connection refused instantly
            fromAddress: 'from@example.com',
            useTls: false,
        );
        $result = $m->send('to@example.com', 'Test', 'Body');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('SMTP error', $result['message']);
    }

    public function testTestConnectionFailsWithRefusedConnection(): void
    {
        $m = new Messenger(
            host: '127.0.0.1',
            port: 1,
            useTls: false,
        );
        $result = $m->testConnection();
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('failed', $result['message']);
    }

    public function testSendStringRecipient(): void
    {
        // Verifies string-to-array normalization doesn't crash (will fail on connect)
        $m = new Messenger(host: '127.0.0.1', port: 1, fromAddress: 'from@example.com', useTls: false);
        $result = $m->send('single@example.com', 'Test', 'Body');
        $this->assertFalse($result['success']); // Expected: connection fails
    }

    public function testSendArrayRecipients(): void
    {
        $m = new Messenger(host: '127.0.0.1', port: 1, fromAddress: 'from@example.com', useTls: false);
        $result = $m->send(['a@example.com', 'b@example.com'], 'Test', 'Body');
        $this->assertFalse($result['success']);
    }

    // ── DevMailbox ──────────────────────────────────────────────

    public function testDevMailboxCapture(): void
    {
        $mb = new DevMailbox($this->mailboxDir);
        $result = $mb->capture('to@example.com', 'Hello', 'Body text');
        $this->assertTrue($result['success']);
        $this->assertNotNull($result['id']);
        $this->assertStringContainsString('captured', $result['message']);
    }

    public function testDevMailboxCaptureWithArray(): void
    {
        $mb = new DevMailbox($this->mailboxDir);
        $result = $mb->capture(['a@test.com', 'b@test.com'], 'Multi', 'Body');
        $this->assertTrue($result['success']);
    }

    public function testDevMailboxInbox(): void
    {
        $mb = new DevMailbox($this->mailboxDir);
        $mb->capture('to@test.com', 'Subject 1', 'Body 1');
        $mb->capture('to@test.com', 'Subject 2', 'Body 2');
        $messages = $mb->inbox();
        $this->assertCount(2, $messages);
    }

    public function testDevMailboxInboxSortedNewestFirst(): void
    {
        $mb = new DevMailbox($this->mailboxDir);
        $mb->capture('to@test.com', 'First', 'Body');
        usleep(10000); // Ensure different timestamps
        $mb->capture('to@test.com', 'Second', 'Body');
        $messages = $mb->inbox();
        $this->assertGreaterThanOrEqual(
            $messages[1]['timestamp'],
            $messages[0]['timestamp']
        );
    }

    public function testDevMailboxInboxPagination(): void
    {
        $mb = new DevMailbox($this->mailboxDir);
        for ($i = 0; $i < 5; $i++) {
            $mb->capture('to@test.com', "Subject {$i}", 'Body');
        }
        $page = $mb->inbox(2, 0);
        $this->assertCount(2, $page);
    }

    public function testDevMailboxRead(): void
    {
        $mb = new DevMailbox($this->mailboxDir);
        $result = $mb->capture('to@test.com', 'Read Me', '<p>Hello</p>', true);
        $msg = $mb->read($result['id']);
        $this->assertNotNull($msg);
        $this->assertSame('Read Me', $msg['subject']);
        $this->assertSame('<p>Hello</p>', $msg['body']);
        $this->assertTrue($msg['html']);
    }

    public function testDevMailboxReadMarksAsRead(): void
    {
        $mb = new DevMailbox($this->mailboxDir);
        $result = $mb->capture('to@test.com', 'Unread', 'Body');
        $msg = $mb->read($result['id']);
        $this->assertTrue($msg['read']);
    }

    public function testDevMailboxReadNonexistent(): void
    {
        $mb = new DevMailbox($this->mailboxDir);
        $this->assertNull($mb->read('nonexistent-id'));
    }

    public function testDevMailboxDelete(): void
    {
        $mb = new DevMailbox($this->mailboxDir);
        $result = $mb->capture('to@test.com', 'Delete Me', 'Body');
        $this->assertTrue($mb->delete($result['id']));
        $this->assertNull($mb->read($result['id']));
    }

    public function testDevMailboxDeleteNonexistent(): void
    {
        $mb = new DevMailbox($this->mailboxDir);
        $this->assertFalse($mb->delete('fake-id'));
    }

    public function testDevMailboxClearAll(): void
    {
        $mb = new DevMailbox($this->mailboxDir);
        $mb->capture('to@test.com', 'Msg 1', 'Body');
        $mb->capture('to@test.com', 'Msg 2', 'Body');
        $mb->clear();
        $messages = $mb->inbox();
        $this->assertCount(0, $messages);
    }

    public function testDevMailboxClearSpecificFolder(): void
    {
        $mb = new DevMailbox($this->mailboxDir);
        $mb->capture('to@test.com', 'Outbox Msg', 'Body');
        $mb->clear('outbox');
        $messages = $mb->inbox(50, 0, 'outbox');
        $this->assertCount(0, $messages);
    }

    public function testDevMailboxUnreadCount(): void
    {
        $mb = new DevMailbox($this->mailboxDir);
        $mb->capture('to@test.com', 'Unread 1', 'Body');
        $mb->capture('to@test.com', 'Unread 2', 'Body');
        $this->assertSame(2, $mb->unreadCount());
    }

    public function testDevMailboxUnreadCountAfterRead(): void
    {
        $mb = new DevMailbox($this->mailboxDir);
        $r1 = $mb->capture('to@test.com', 'Msg 1', 'Body');
        $mb->capture('to@test.com', 'Msg 2', 'Body');
        $mb->read($r1['id']);
        $this->assertSame(1, $mb->unreadCount());
    }

    public function testDevMailboxCount(): void
    {
        $mb = new DevMailbox($this->mailboxDir);
        $mb->capture('to@test.com', 'Msg', 'Body');
        $counts = $mb->count();
        $this->assertArrayHasKey('total', $counts);
        $this->assertGreaterThanOrEqual(1, $counts['total']);
    }

    public function testDevMailboxCountWithOutbox(): void
    {
        $mb = new DevMailbox($this->mailboxDir);
        $mb->capture('to@test.com', 'Msg', 'Body');
        $counts = $mb->count();
        $this->assertArrayHasKey('outbox', $counts);
        $this->assertSame(1, $counts['outbox']);
    }

    public function testDevMailboxGetMailboxDir(): void
    {
        $mb = new DevMailbox($this->mailboxDir);
        $this->assertSame($this->mailboxDir, $mb->getMailboxDir());
    }

    public function testDevMailboxSeed(): void
    {
        $mb = new DevMailbox($this->mailboxDir);
        $mb->seed(3);
        $messages = $mb->inbox(50, 0, 'inbox');
        $this->assertCount(3, $messages);
    }

    public function testDevMailboxCaptureWithCcBcc(): void
    {
        $mb = new DevMailbox($this->mailboxDir);
        $result = $mb->capture(
            'to@test.com',
            'CC Test',
            'Body',
            false,
            ['cc@test.com'],
            ['bcc@test.com'],
        );
        $msg = $mb->read($result['id']);
        $this->assertSame(['cc@test.com'], $msg['cc']);
        $this->assertSame(['bcc@test.com'], $msg['bcc']);
    }

    public function testDevMailboxCaptureWithReplyTo(): void
    {
        $mb = new DevMailbox($this->mailboxDir);
        $result = $mb->capture(
            'to@test.com', 'Reply Test', 'Body', false,
            [], [], 'reply@test.com',
        );
        $msg = $mb->read($result['id']);
        $this->assertSame('reply@test.com', $msg['reply_to']);
    }

    public function testDevMailboxCaptureWithHeaders(): void
    {
        $mb = new DevMailbox($this->mailboxDir);
        $result = $mb->capture(
            'to@test.com', 'Header Test', 'Body', false,
            [], [], null, [], ['X-Custom' => 'value'],
        );
        $msg = $mb->read($result['id']);
        $this->assertSame('value', $msg['headers']['X-Custom']);
    }

    public function testDevMailboxCaptureWithAttachmentMetadata(): void
    {
        $mb = new DevMailbox($this->mailboxDir);
        $result = $mb->capture(
            'to@test.com', 'Attach Test', 'Body', false,
            [], [], null,
            [['filename' => 'doc.pdf', 'content' => 'fake', 'mime' => 'application/pdf']],
        );
        $msg = $mb->read($result['id']);
        $this->assertCount(1, $msg['attachments']);
        $this->assertSame('doc.pdf', $msg['attachments'][0]['filename']);
    }

    public function testDevMailboxInboxEmpty(): void
    {
        $mb = new DevMailbox($this->mailboxDir);
        $messages = $mb->inbox();
        $this->assertIsArray($messages);
        $this->assertCount(0, $messages);
    }

    public function testDevMailboxInboxFolderFilter(): void
    {
        $mb = new DevMailbox($this->mailboxDir);
        $mb->capture('to@test.com', 'Outbox Msg', 'Body');
        $mb->seed(2);
        $outbox = $mb->inbox(50, 0, 'outbox');
        $inbox = $mb->inbox(50, 0, 'inbox');
        $this->assertCount(1, $outbox);
        $this->assertCount(2, $inbox);
    }

    // ── MessengerFactory ────────────────────────────────────────

    public function testFactoryReturnsDevMailboxWithoutSmtpHost(): void
    {
        putenv('TINA4_MAIL_HOST');
        putenv('TINA4_DEBUG');
        $instance = MessengerFactory::create();
        $this->assertInstanceOf(DevMailbox::class, $instance);
    }

    public function testFactoryReturnsDevMailboxInDebugMode(): void
    {
        putenv('TINA4_DEBUG=true');
        putenv('TINA4_MAIL_HOST=smtp.example.com');
        $instance = MessengerFactory::create();
        $this->assertInstanceOf(DevMailbox::class, $instance);
        putenv('TINA4_DEBUG');
        putenv('TINA4_MAIL_HOST');
    }

    public function testFactoryReturnsMessengerInProduction(): void
    {
        putenv('TINA4_DEBUG=false');
        putenv('TINA4_MAIL_HOST=smtp.example.com');
        $instance = MessengerFactory::create();
        $this->assertInstanceOf(Messenger::class, $instance);
        putenv('TINA4_DEBUG');
        putenv('TINA4_MAIL_HOST');
    }
}
