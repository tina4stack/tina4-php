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
            // GreenMail's IMAP port is plain; state it explicitly so these tests
            // do not depend on the port-aware default.
            imapEncryption: 'none',
        );
    }

    /**
     * A Messenger whose SMTP and IMAP accounts differ — the G8 shape.
     */
    private function messengerWithSplitCreds(string $smtpUser, string $imapUser): Messenger
    {
        return new Messenger(
            host: self::$smtpHost,
            port: self::$smtpPort,
            username: $smtpUser,
            password: 'secret',
            fromAddress: 'sender@tina4.test',
            encryption: 'none',
            imapHost: self::$imapHost,
            imapPort: self::$imapPort,
            imapUsername: $imapUser,
            imapPassword: 'secret',
            imapEncryption: 'none',
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

    // ── G4: one inbox() item shape ───────────────────────────────────────

    public function testInboxItemHasExactlyTheSettledKeys(): void
    {
        $to = $this->recipient();
        $m = $this->messengerFor($to);
        $subject = 'Shape ' . bin2hex(random_bytes(4));

        $envelope = $this->deliverAndWait($m, $to, $subject, 'shape body');

        // Exactly {uid, subject, from, to, date, snippet, seen}, in this order.
        $this->assertSame(
            ['uid', 'subject', 'from', 'to', 'date', 'snippet', 'seen'],
            array_keys($envelope),
            'inbox item must carry exactly the settled key set',
        );
        // to was missing before 3.13.96; msgno/flagged/size were dropped.
        $this->assertIsString($envelope['to']);
        $this->assertStringContainsString($to, $envelope['to'], 'the To header must carry the recipient');
        $this->assertIsBool($envelope['seen']);
        $this->assertArrayNotHasKey('msgno', $envelope);
        $this->assertArrayNotHasKey('flagged', $envelope);
        $this->assertArrayNotHasKey('size', $envelope);
    }

    public function testInboxDateIsIso8601(): void
    {
        $to = $this->recipient();
        $m = $this->messengerFor($to);
        $subject = 'IsoDate ' . bin2hex(random_bytes(4));

        $envelope = $this->deliverAndWait($m, $to, $subject, 'date body');

        // ISO-8601 (2026-08-06T12:00:00+00:00), not raw RFC2822.
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $envelope['date'],
            'date must be ISO-8601, got: ' . $envelope['date'],
        );
    }

    // ── G3: snippet is decoded, tag-stripped, truncated ──────────────────

    public function testInboxSnippetIsDecodedBodyText(): void
    {
        $to = $this->recipient();
        $m = $this->messengerFor($to);
        $subject = 'Snippet ' . bin2hex(random_bytes(4));
        $marker = 'snip' . bin2hex(random_bytes(4));

        $envelope = $this->deliverAndWait($m, $to, $subject, "plain preview {$marker} body");

        // The message is sent base64-encoded; the snippet must be the DECODED
        // text (carrying the marker), never the raw base64.
        $this->assertArrayHasKey('snippet', $envelope);
        $this->assertStringContainsString($marker, $envelope['snippet'], 'snippet must be decoded body text, not raw base64');
    }

    public function testInboxSnippetStripsHtmlTags(): void
    {
        $to = $this->recipient();
        $m = $this->messengerFor($to);
        $subject = 'SnippetHtml ' . bin2hex(random_bytes(4));
        $marker = 'htmlsnip' . bin2hex(random_bytes(4));

        $envelope = $this->deliverAndWait($m, $to, $subject, "<p>hi <b>{$marker}</b></p>", html: true);

        $this->assertStringContainsString($marker, $envelope['snippet']);
        $this->assertStringNotContainsString('<b>', $envelope['snippet'], 'HTML tags must be stripped from the snippet');
        $this->assertStringNotContainsString('<p>', $envelope['snippet']);
    }

    public function testInboxSnippetIsTruncatedTo200Chars(): void
    {
        $to = $this->recipient();
        $m = $this->messengerFor($to);
        $subject = 'SnippetLong ' . bin2hex(random_bytes(4));

        $envelope = $this->deliverAndWait($m, $to, $subject, str_repeat('abcdefghij ', 40)); // ~440 chars

        $len = function_exists('mb_strlen') ? mb_strlen($envelope['snippet']) : strlen($envelope['snippet']);
        $this->assertGreaterThan(0, $len);
        $this->assertLessThanOrEqual(200, $len, 'snippet must be truncated to 200 chars');
    }

    // ── G5: read() carries headers + attachments ─────────────────────────

    public function testReadReturnsHeadersAndAttachmentKeys(): void
    {
        $to = $this->recipient();
        $m = $this->messengerFor($to);
        $subject = 'ReadShape ' . bin2hex(random_bytes(4));

        $envelope = $this->deliverAndWait($m, $to, $subject, 'read shape body');
        $message = $m->read($envelope['uid']);

        $this->assertNotNull($message);
        // headers: name => value map carrying the standard headers.
        $this->assertArrayHasKey('headers', $message);
        $this->assertIsArray($message['headers']);
        $headerNames = array_map('strtolower', array_keys($message['headers']));
        $this->assertContains('subject', $headerNames, 'read() headers must carry Subject');
        $this->assertContains('from', $headerNames, 'read() headers must carry From');
        // attachments present (empty array on a no-attachment message).
        $this->assertArrayHasKey('attachments', $message);
        $this->assertIsArray($message['attachments']);
        // idiomatic body fields + ISO date.
        $this->assertArrayHasKey('body_text', $message);
        $this->assertArrayHasKey('body_html', $message);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $message['date']);
    }

    // ── G7: markUnread and delete round-trips ────────────────────────────

    public function testMarkUnreadRestoresTheUnseenState(): void
    {
        $to = $this->recipient();
        $m = $this->messengerFor($to);
        $subject = 'MarkUnread ' . bin2hex(random_bytes(4));

        $envelope = $this->deliverAndWait($m, $to, $subject, 'mark unread body');

        // read() marks it seen -> unread drops to 0.
        $m->read($envelope['uid']);
        $this->assertSame(0, $m->unread(), 'reading marks the message seen');

        // markUnread clears \Seen -> unread back to 1.
        $m->markUnread($envelope['uid']);
        $this->assertSame(1, $m->unread(), 'markUnread must clear \\Seen');
    }

    public function testDeleteRemovesTheMessage(): void
    {
        $to = $this->recipient();
        $m = $this->messengerFor($to);
        $subject = 'Delete ' . bin2hex(random_bytes(4));

        $envelope = $this->deliverAndWait($m, $to, $subject, 'delete me');
        $m->delete($envelope['uid']);

        // delete() expunges immediately; poll until the subject is gone.
        for ($i = 0; $i < 40; $i++) {
            $present = false;
            foreach ($m->inbox() as $item) {
                if (($item['subject'] ?? null) === $subject) {
                    $present = true;
                    break;
                }
            }
            if (!$present) {
                $this->assertFalse($present);
                return;
            }
            usleep(250_000);
        }
        $this->fail("message '{$subject}' was not removed by delete()");
    }

    // ── G8: IMAP credentials select the IMAP account, not the SMTP one ───

    public function testImapCredentialsSelectTheImapAccountNotTheSmtpAccount(): void
    {
        // Deliver a message to account A.
        $acctA = $this->recipient();
        $subject = 'ImapCreds ' . bin2hex(random_bytes(4));
        $this->deliverAndWait($this->messengerFor($acctA), $acctA, $subject, 'imap creds body');

        // A separate, never-written IMAP account.
        $acctB = 'php-imap-empty-' . bin2hex(random_bytes(8)) . '@tina4.test';

        // SMTP username = A (has mail), IMAP points at B (empty). The old bug
        // authenticated IMAP with the SMTP username and read A's mailbox.
        $mB = $this->messengerWithSplitCreds(smtpUser: $acctA, imapUser: $acctB);
        $this->assertSame([], $mB->inbox(), 'IMAP must read account B (empty), not the SMTP account A (which has mail)');
        $this->assertSame(0, $mB->unread());

        // IMAP pointed at A finds the message -> the creds really select the box.
        $mA = $this->messengerWithSplitCreds(smtpUser: 'someone-else@tina4.test', imapUser: $acctA);
        $found = false;
        foreach ($mA->inbox() as $item) {
            if (($item['subject'] ?? null) === $subject) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "IMAP with account A's credentials must read A's mailbox");
    }

    public function testImapUsernameEnvVarSelectsTheImapAccount(): void
    {
        $acctA = $this->recipient();
        $subject = 'ImapCredsEnv ' . bin2hex(random_bytes(4));
        $this->deliverAndWait($this->messengerFor($acctA), $acctA, $subject, 'env creds body');

        $acctB = 'php-imap-env-empty-' . bin2hex(random_bytes(8)) . '@tina4.test';

        $savedU = getenv('TINA4_MAIL_IMAP_USERNAME');
        $savedP = getenv('TINA4_MAIL_IMAP_PASSWORD');
        putenv('TINA4_MAIL_IMAP_USERNAME=' . $acctB);
        putenv('TINA4_MAIL_IMAP_PASSWORD=secret');
        $_ENV['TINA4_MAIL_IMAP_USERNAME'] = $acctB;
        $_ENV['TINA4_MAIL_IMAP_PASSWORD'] = 'secret';
        try {
            // SMTP username = A (has mail), TINA4_MAIL_IMAP_USERNAME = B (empty).
            $m = new Messenger(
                host: self::$smtpHost,
                port: self::$smtpPort,
                username: $acctA,
                password: 'secret',
                fromAddress: 'sender@tina4.test',
                encryption: 'none',
                imapHost: self::$imapHost,
                imapPort: self::$imapPort,
                imapEncryption: 'none',
            );
            $this->assertSame([], $m->inbox(), 'TINA4_MAIL_IMAP_USERNAME must select the IMAP account');
        } finally {
            $savedU === false ? putenv('TINA4_MAIL_IMAP_USERNAME') : putenv('TINA4_MAIL_IMAP_USERNAME=' . $savedU);
            $savedP === false ? putenv('TINA4_MAIL_IMAP_PASSWORD') : putenv('TINA4_MAIL_IMAP_PASSWORD=' . $savedP);
            unset($_ENV['TINA4_MAIL_IMAP_USERNAME'], $_ENV['TINA4_MAIL_IMAP_PASSWORD']);
        }
    }
}
