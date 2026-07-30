<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Messenger SMTP + IMAP against a REAL GreenMail server.
 *
 * Parity with the Python master (tests/test_messenger.py) and Ruby
 * (spec/messenger_spec.rb), which have round-tripped real mail for some time.
 * PHP had NO real-mail coverage at all: its IMAP tests drove namespaced imap_*
 * stubs, so nothing ever proved a PHP-composed message could leave over SMTP
 * and be read back over IMAP. A stub returns what the test told it to, so the
 * assertions could not fail even if send() emitted a malformed envelope.
 *
 * Every test here sends over a real SMTP socket and reads back over a real IMAP
 * connection. No doubles.
 *
 * GreenMail runs with -Dgreenmail.auth.disabled, so a mailbox is created on
 * first access. Each test therefore uses a UNIQUE random recipient and gets an
 * isolated, genuinely-empty mailbox — which is what makes the empty-mailbox
 * assertions (inbox() == [], unread() == 0) real rather than incidental.
 *
 * Ports are GreenMail's PLAIN (non-TLS) 3025 / 3143. Override via
 * TINA4_TEST_SMTP_HOST / _PORT and TINA4_TEST_IMAP_HOST / _PORT, matching the
 * Python master's names.
 */

namespace Tina4;

use PHPUnit\Framework\TestCase;

class MessengerImapGreenMailTest extends TestCase
{
    private static string $smtpHost = '127.0.0.1';
    private static int $smtpPort = 3025;
    private static string $imapHost = '127.0.0.1';
    private static int $imapPort = 3143;

    public static function setUpBeforeClass(): void
    {
        self::$smtpHost = getenv('TINA4_TEST_SMTP_HOST') ?: '127.0.0.1';
        self::$smtpPort = (int)(getenv('TINA4_TEST_SMTP_PORT') ?: 3025);
        self::$imapHost = getenv('TINA4_TEST_IMAP_HOST') ?: '127.0.0.1';
        self::$imapPort = (int)(getenv('TINA4_TEST_IMAP_PORT') ?: 3143);

        if (!function_exists('imap_open')) {
            // ext-imap is a hard requirement for the IMAP half. Named so the
            // RequireServicesGate treats it as a provisioned-dependency miss.
            self::markTestSkipped('ext-imap not installed — GreenMail IMAP tests cannot run');
        }

        // The wording matters: "greenmail ... not reachable" is what the
        // TINA4_REQUIRE_SERVICES gate scans for, so in CI (where GreenMail IS
        // provisioned) this skip becomes a hard failure instead of passing green.
        if (!self::reachable(self::$smtpHost, self::$smtpPort)
            || !self::reachable(self::$imapHost, self::$imapPort)) {
            self::markTestSkipped(sprintf(
                'greenmail SMTP/IMAP not reachable at %s:%d / %s:%d',
                self::$smtpHost,
                self::$smtpPort,
                self::$imapHost,
                self::$imapPort,
            ));
        }
    }

    private static function reachable(string $host, int $port, float $timeout = 1.5): bool
    {
        $sock = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($sock === false) {
            return false;
        }
        fclose($sock);

        return true;
    }

    /** A unique recipient per call -> an isolated, first-access-created mailbox. */
    private function recipient(): string
    {
        return 'php-' . bin2hex(random_bytes(8)) . '@tina4.test';
    }

    private function messengerFor(string $address): Messenger
    {
        return new Messenger(
            host: self::$smtpHost,
            port: self::$smtpPort,
            username: $address,
            password: 'secret',
            fromAddress: 'sender@tina4.test',
            fromName: 'Sender',
            encryption: 'none',
            imapHost: self::$imapHost,
            imapPort: self::$imapPort,
        );
    }

    /**
     * Deliver over real SMTP, then poll the real IMAP INBOX until it lands.
     *
     * Delivery is asynchronous from the sender's point of view, so a single
     * immediate read would be a race. Polling with a bounded deadline keeps the
     * test honest without a sleep long enough to slow the suite.
     *
     * @return array The envelope from inbox()
     */
    private function deliverAndWait(
        Messenger $messenger,
        string $recipient,
        string $subject,
        string $body,
        bool $html = false,
        array $cc = [],
    ): array {
        $result = $messenger->send(
            to: $recipient,
            subject: $subject,
            body: $body,
            html: $html,
            cc: $cc,
        );
        $this->assertTrue(
            $result['success'],
            'SMTP send failed: ' . ($result['message'] ?? 'no message'),
        );

        for ($i = 0; $i < 40; $i++) {
            foreach ($messenger->inbox() as $envelope) {
                if (($envelope['subject'] ?? null) === $subject) {
                    return $envelope;
                }
            }
            usleep(250_000);
        }

        $this->fail("message '{$subject}' never arrived in the real IMAP mailbox");
    }

    // ── Real SMTP -> real IMAP round trip ────────────────────────────────

    public function testSendDeliversAndInboxReadsTheEnvelopeBack(): void
    {
        $to = $this->recipient();
        $m = $this->messengerFor($to);
        $subject = 'Inbox RoundTrip ' . bin2hex(random_bytes(4));

        $envelope = $this->deliverAndWait($m, $to, $subject, 'plain text body');

        $this->assertSame($subject, $envelope['subject']);
        // uid is a STRING across all four frameworks (Python master emits
        // str(uid)); an int here changed the JSON shape between languages.
        $this->assertIsString($envelope['uid']);
        $this->assertNotSame('', $envelope['uid']);
    }

    public function testReadReturnsTheFullBodyOverRealImap(): void
    {
        $to = $this->recipient();
        $m = $this->messengerFor($to);
        $subject = 'Read Body ' . bin2hex(random_bytes(4));
        $body = 'Hello over real IMAP ' . bin2hex(random_bytes(4));

        $envelope = $this->deliverAndWait($m, $to, $subject, $body);
        $message = $m->read($envelope['uid']);

        $this->assertNotNull($message, 'read() returned null for a UID inbox() just listed');
        $this->assertSame($subject, $message['subject']);
        // read() splits the MIME parts into body_text / body_html.
        $this->assertStringContainsString(
            $body,
            $message['body_text'],
            'the delivered body did not survive the SMTP -> IMAP round trip',
        );
    }

    public function testHtmlBodyRoundTripsOverRealImap(): void
    {
        $to = $this->recipient();
        $m = $this->messengerFor($to);
        $subject = 'HTML Body ' . bin2hex(random_bytes(4));
        $marker = bin2hex(random_bytes(4));

        $envelope = $this->deliverAndWait(
            $m,
            $to,
            $subject,
            "<p>hello <b>{$marker}</b></p>",
            html: true,
        );
        $message = $m->read($envelope['uid']);

        $this->assertNotNull($message);
        // An html:true send must land in body_html, not be flattened into text —
        // asserting on the union of both would pass even if the HTML part were
        // lost, which is the whole point of the html flag.
        $this->assertStringContainsString($marker, $message['body_html']);
        $this->assertStringContainsString('<b>', $message['body_html'], 'the HTML markup was stripped');
    }

    public function testUnreadCountsThenDropsToZeroAfterMarkRead(): void
    {
        $to = $this->recipient();
        $m = $this->messengerFor($to);
        $subject = 'Unread ' . bin2hex(random_bytes(4));

        $envelope = $this->deliverAndWait($m, $to, $subject, 'unread me');

        // read() marks read by default; count BEFORE touching the message.
        $this->assertSame(1, $m->unread(), 'a freshly delivered message should be unseen');

        $m->markRead($envelope['uid']);
        $this->assertSame(0, $m->unread(), 'unread() should drop to 0 once marked read');
    }

    public function testSearchFindsTheMessageBySubject(): void
    {
        $to = $this->recipient();
        $m = $this->messengerFor($to);
        $subject = 'Findable ' . bin2hex(random_bytes(4));

        $this->deliverAndWait($m, $to, $subject, 'search for me');
        $hits = $m->search(subject: $subject);

        $this->assertCount(1, $hits, 'search(subject:) should match exactly the delivered message');
        $this->assertSame($subject, $hits[0]['subject']);
    }

    public function testFoldersListsInboxOverRealImap(): void
    {
        $to = $this->recipient();
        $m = $this->messengerFor($to);
        // Touch the mailbox so it exists before listing.
        $this->deliverAndWait($m, $to, 'Folders ' . bin2hex(random_bytes(4)), 'x');

        $folders = $m->folders();

        $this->assertNotEmpty($folders, 'folders() returned nothing from a real IMAP server');
        $joined = strtoupper(implode('|', array_map(
            static fn ($f) => is_array($f) ? ($f['name'] ?? '') : (string)$f,
            $folders,
        )));
        $this->assertStringContainsString('INBOX', $joined);
    }

    public function testCcRecipientSurvivesTheRoundTrip(): void
    {
        $to = $this->recipient();
        $cc = $this->recipient();
        $m = $this->messengerFor($to);
        $subject = 'CC ' . bin2hex(random_bytes(4));

        $envelope = $this->deliverAndWait($m, $to, $subject, 'cc body', cc: [$cc]);

        $this->assertSame($subject, $envelope['subject']);
        // The CC mailbox is a separate account; assert the header carried it.
        $message = $m->read($envelope['uid']);
        $this->assertNotNull($message);
        $this->assertStringContainsString(
            $cc,
            strtolower($message['cc']),
            'the Cc header did not survive to the delivered message',
        );
    }

    // ── A genuinely EMPTY mailbox is not an error ────────────────────────
    // These are the cases the deleted stubs used to fake. They are real here:
    // GreenMail creates the mailbox on first access, so it is truly empty and a
    // successful-but-empty read is being exercised, not a scripted return.

    public function testInboxOnGenuinelyEmptyMailboxReturnsEmptyArray(): void
    {
        $m = $this->messengerFor($this->recipient());

        $this->assertSame([], $m->inbox(), 'an empty mailbox must read as [] , not raise');
    }

    public function testUnreadOnGenuinelyEmptyMailboxReturnsZero(): void
    {
        $m = $this->messengerFor($this->recipient());

        $this->assertSame(0, $m->unread());
    }

    public function testSearchWithNoMatchesReturnsEmptyArray(): void
    {
        $m = $this->messengerFor($this->recipient());

        $this->assertSame([], $m->search(subject: 'no-such-subject-' . bin2hex(random_bytes(4))));
    }

    public function testReadOfAMissingUidReturnsNull(): void
    {
        $m = $this->messengerFor($this->recipient());

        $this->assertNull($m->read(999999), 'a missing UID should read as null, not raise');
    }
}
