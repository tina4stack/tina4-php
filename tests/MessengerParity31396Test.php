<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * 3.13.96 Messenger parity — the parts that need no live IMAP server (the
 * GreenMail round-trips live in MessengerImapGreenMailTest). Authority:
 * tina4-documentation/plan/v3/parity-3.13.96-decisions.md.
 *
 *   G7  sendTemplate() renders a Frond template and sends it (proved through
 *       the REAL DevMailbox capture path — no mock).
 *   G9  imap_encryption is constructor-settable; explicit > env > port-aware
 *       default (ADR-0041).
 *   G10 send() RETURNS a result and never raises on a connection failure
 *       (the half of the fail-loud rule that is not the read path).
 */

namespace Tina4;

use PHPUnit\Framework\TestCase;

class MessengerParity31396Test extends TestCase
{
    private string $mailboxDir = '';

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    private array $envKeys = [
        'TINA4_MAILBOX_DIR', 'TINA4_MAIL_HOST', 'TINA4_MAIL_CAPTURE', 'TINA4_DEBUG',
        'TINA4_MAIL_IMAP_ENCRYPTION', 'TINA4_MAIL_IMAP_USERNAME', 'TINA4_MAIL_IMAP_PASSWORD',
        'TINA4_MAIL_USERNAME', 'TINA4_MAIL_PASSWORD',
    ];

    protected function setUp(): void
    {
        foreach ($this->envKeys as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key]);
        }
        $this->mailboxDir = sys_get_temp_dir() . '/tina4-messenger-parity-' . bin2hex(random_bytes(6));
        mkdir($this->mailboxDir, 0777, true);
        putenv('TINA4_MAILBOX_DIR=' . $this->mailboxDir);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv("{$key}={$value}");
            }
        }
        $this->removeDir($this->mailboxDir);
    }

    private function removeDir(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = "$dir/$entry";
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** @return array<string, mixed> The single captured message, read off disk. */
    private function captured(): array
    {
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->mailboxDir));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'json') {
                $found[] = $file->getPathname();
            }
        }
        sort($found);
        $this->assertNotEmpty($found, "nothing captured under {$this->mailboxDir}");
        return json_decode((string)file_get_contents($found[0]), true);
    }

    // ── G7: sendTemplate renders a Frond template (real capture) ──

    public function testSendTemplateRendersTheTemplateBeforeSending(): void
    {
        // No SMTP host -> the real DevMailbox capture path. The body written to
        // disk is the RENDERED template, proving sendTemplate ran Frond.
        $mail = Messenger::createMessenger();
        $result = $mail->sendTemplate(
            'a@b.com',
            'Welcome',
            '<p>Hello {{ name }}</p>',
            ['name' => 'Alice'],
        );

        $this->assertTrue($result['success']);
        $msg = $this->captured();
        $this->assertStringContainsString('Alice', $msg['body'], 'the template variable was not rendered');
        $this->assertStringContainsString('Hello', $msg['body']);
        $this->assertTrue($msg['html'], 'sendTemplate sends HTML');
    }

    public function testSendTemplateDoesNotLeaveTheRawPlaceholder(): void
    {
        // The negative: an unrendered template still carries "{{ name }}".
        $mail = Messenger::createMessenger();
        $mail->sendTemplate('a@b.com', 'Welcome', '<p>Hi {{ name }}</p>', ['name' => 'Bob']);
        $msg = $this->captured();
        $this->assertStringNotContainsString('{{ name }}', $msg['body'], 'the placeholder was sent unrendered');
        $this->assertStringContainsString('Bob', $msg['body']);
    }

    // ── G9: imap_encryption constructor param; explicit > env > default ──

    public function testImapEncryptionConstructorParamWins(): void
    {
        $m = new Messenger(imapHost: 'imap.example.com', imapPort: 993, imapEncryption: 'starttls');
        $this->assertSame('starttls', $m->getImapEncryption(), 'the constructor value must win over the port-993 default');
    }

    public function testImapEncryptionConstructorBeatsEnv(): void
    {
        putenv('TINA4_MAIL_IMAP_ENCRYPTION=none');
        $m = new Messenger(imapHost: 'imap.example.com', imapEncryption: 'tls');
        $this->assertSame('tls', $m->getImapEncryption(), 'explicit > env (ADR-0041)');
    }

    public function testImapEncryptionEnvBeatsDefault(): void
    {
        putenv('TINA4_MAIL_IMAP_ENCRYPTION=starttls');
        $m = new Messenger(imapHost: 'imap.example.com', imapPort: 143);
        $this->assertSame('starttls', $m->getImapEncryption(), 'env > default when no constructor value');
    }

    public function testImapEncryptionPortAwareDefaultIsNonBreaking(): void
    {
        // No explicit value: 993 keeps implicit TLS, anything else stays plain —
        // the historical port-based selection, so nothing regresses.
        $tls = new Messenger(imapHost: 'imap.example.com', imapPort: 993);
        $plain = new Messenger(imapHost: 'imap.example.com', imapPort: 3143);
        $this->assertSame('tls', $tls->getImapEncryption());
        $this->assertSame('none', $plain->getImapEncryption());
    }

    public function testInvalidImapEncryptionFallsBackToTls(): void
    {
        $m = new Messenger(imapHost: 'imap.example.com', imapEncryption: 'rot13');
        $this->assertSame('tls', $m->getImapEncryption());
    }

    // ── G10: send() returns a result and never raises on failure ──

    public function testSendReturnsAResultAndDoesNotRaiseOnAClosedSmtpPort(): void
    {
        // Host configured (so it does NOT capture) but the port is refused: send
        // must catch and RETURN {success:false, message, id:null}, never raise.
        // This is the send() half of the fail-loud rule (the read path raises).
        $mail = new Messenger(
            host: '127.0.0.1',
            port: 1,
            username: 'u',
            password: 'p',
            fromAddress: 'from@tina4.test',
            encryption: 'none',
        );

        $result = $mail->send('to@tina4.test', 'Subj', 'body');

        $this->assertIsArray($result);
        $this->assertFalse($result['success'], 'a refused SMTP port must report failure, not success');
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('id', $result);
        $this->assertNull($result['id'], 'id is null on a failed send');
        $this->assertStringContainsStringIgnoringCase('smtp', $result['message']);
    }
}
