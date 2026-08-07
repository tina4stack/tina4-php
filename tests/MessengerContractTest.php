<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\DevMailbox;
use Tina4\Messenger;
use Tina4\MessengerConnectionError;

/**
 * Messenger contract, mirroring tina4-nodejs#41 and #42.
 *
 * Four contract points, each with a POSITIVE test (the right behaviour is accepted)
 * and a NEGATIVE test (the wrong behaviour is rejected). The same eight run in all
 * four frameworks. Contract and the ADR-0004 ranking:
 * tina4-documentation/plan/v3/messenger-contract.md.
 *
 * PHP was the WORST of the four, by absence rather than by bug:
 * Messenger::createMessenger() was `return new static()` with no interception at
 * all. On a dev box with no SMTP host that opened a socket to localhost:587 and
 * failed. The DevMailbox class existed and was unreachable from the factory, so
 * PHP is the framework that gains the most from this contract.
 *
 * PHP did have one thing right, and the contract keeps it: the factory returns ONE
 * concrete type, so #41 cannot happen here.
 *
 * The gate is availability, not verbosity (owner decision, superseding the plan's
 * original point 3): capture when no SMTP host is configured, send when one is even
 * with TINA4_DEBUG on, and TINA4_MAIL_CAPTURE forces capture. So these tests clear
 * TINA4_MAIL_HOST to select the capture path -- that is the state of any dev box
 * with no mail server.
 *
 * NO MOCKS: DevMailbox writes real JSON. Each test points TINA4_MAILBOX_DIR at a
 * temp directory and reads the file back off disk.
 *
 * ── The 14 shared MESSENGER-CONTRACT invariants (added 3.13.96) ──────────────
 *
 * The block above is the original pilot (send() + the dev-capture branch). The
 * `testMsg*` methods below extend this suite to ONE named test per invariant in
 * the shared fixture:
 *
 *   /Users/andrevanzuydam/IdeaProjects/tina4-documentation/plan/v3/fixtures/messenger_contract.json
 *
 * A cross-framework auditor matches NORMALISED test names (lowercase, strip every
 * non-alphanumeric, strip a leading "test"). Each method below is named so its
 * normalised form CONTAINS the invariant id verbatim -- e.g. testMsgUidIsARealUid
 * normalises to "msguidisarealuid" == the id "msg-uid-is-a-real-uid" -- so the
 * fixture can move that invariant from "owed" to "proven" in PHP.
 *
 * These prove SHIPPED behaviour: the 3.13.96 messenger-parity commits already
 * conformed Tina4/Messenger.php to the fixture (inbox() carries `to`, drops
 * msgno/flagged/size; read() carries attachments + headers under body_text/
 * body_html; read() of a missing UID returns null; inbox()/read() take the folder
 * positionally; uid is UID-addressed and stringified). So no Messenger.php change
 * was required -- the tests LOCK the behaviour in so it cannot regress.
 *
 * NO MOCKS here either. GreenMail-backed invariants build a real Messenger against
 * a live SMTP+IMAP server and skip-guard exactly like MessengerImapGreenMailTest,
 * so they RUN on the lab (TINA4_REQUIRE_SERVICES turns a 'greenmail'/'imap' skip
 * into a hard failure) and skip cleanly on a box with no server. The fail-loud,
 * shape, gate, method-existence and env invariants use a real closed TCP port, the
 * real filesystem, or reflection over the real class -- no server needed, so they
 * run everywhere.
 */
class MessengerContractTest extends TestCase
{
    private string $mailboxDir = '';

    /** @var array<string, string|false> Env values to restore after each test */
    private array $savedEnv = [];

    /**
     * Every mail env var these tests read or set. Saved and cleared in setUp so a
     * value leaking from the real environment (or a previous test) can never make
     * an env-precedence assertion pass or fail for the wrong reason; restored in
     * tearDown. Messenger reads via getenv(), so putenv() is authoritative here.
     *
     * @var string[]
     */
    private const MAIL_ENV_KEYS = [
        'TINA4_MAILBOX_DIR',
        'TINA4_MAIL_HOST',
        'TINA4_MAIL_PORT',
        'TINA4_MAIL_CAPTURE',
        'TINA4_DEBUG',
        'TINA4_MAIL_USERNAME',
        'TINA4_MAIL_PASSWORD',
        'TINA4_MAIL_FROM',
        'TINA4_MAIL_FROM_NAME',
        'TINA4_MAIL_ENCRYPTION',
        'TINA4_MAIL_IMAP_HOST',
        'TINA4_MAIL_IMAP_PORT',
        'TINA4_MAIL_IMAP_USERNAME',
        'TINA4_MAIL_IMAP_PASSWORD',
        'TINA4_MAIL_IMAP_ENCRYPTION',
    ];

    protected function setUp(): void
    {
        foreach (self::MAIL_ENV_KEYS as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key]);
        }

        $this->mailboxDir = sys_get_temp_dir() . '/tina4-messenger-contract-' . bin2hex(random_bytes(6));
        mkdir($this->mailboxDir, 0777, true);

        // A dev box with no mail server: capture is the correct behaviour. This is
        // the baseline for the original pilot tests below and for the capture-gate
        // and send-result invariants; GreenMail tests pass host/imapHost explicitly
        // and so do not depend on it.
        putenv('TINA4_MAILBOX_DIR=' . $this->mailboxDir);
        putenv('TINA4_DEBUG=true');
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv("$key=$value");
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

    /**
     * The captured message, read off disk.
     *
     * Globs recursively rather than assuming a folder name: the four frameworks lay
     * the mailbox out differently and these assertions are about content.
     *
     * @return array<string, mixed> The decoded message
     */
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

    // --- 1. the factory returns ONE type, and it can send --------------------

    public function testPositiveFactoryReturnsASender(): void
    {
        $mail = Messenger::createMessenger();
        $this->assertTrue(
            is_callable([$mail, 'send']),
            'createMessenger() returned ' . get_class($mail) . ' with no callable send()'
        );
    }

    public function testNegativeFactoryNeverReturnsACaptureOnlyObject(): void
    {
        // The #41 failure mode: an object whose only sending verb is capture().
        $mail = Messenger::createMessenger();
        $hasSend = is_callable([$mail, 'send']);
        $hasOnlyCapture = is_callable([$mail, 'capture']) && !$hasSend;
        $this->assertFalse(
            $hasOnlyCapture,
            'createMessenger() returned an object offering capture() but not send(); callers '
                . 'holding the factory result cannot send without branching on the concrete type'
        );
    }

    // --- 2. text is the 5th argument and lands in text -----------------------

    public function testPositiveTextRoundTrips(): void
    {
        $mail = Messenger::createMessenger();
        $mail->send('a@b.com', 'Subj', '<p>body</p>', true, 'the text part');
        $msg = $this->captured();
        $this->assertArrayHasKey(
            'text',
            $msg,
            'the captured message has no text field at all, so it is not what would have been sent'
        );
        $this->assertSame('the text part', $msg['text']);
    }

    public function testNegativeTextIsNeverStoredAsACcRecipient(): void
    {
        // The #42 failure mode, stated as the thing that must NOT happen.
        $mail = Messenger::createMessenger();
        $mail->send('a@b.com', 'Subject', '<p>hi</p>', true, 'plain text alternative');
        $msg = $this->captured();
        $cc = $msg['cc'] ?? [];
        $ccList = is_array($cc) ? $cc : [$cc];
        $this->assertNotContains(
            'plain text alternative',
            $ccList,
            'the plain-text body was filed as a CC recipient: ' . json_encode($cc)
        );
    }

    // --- 3. cc/bcc are normalised at the boundary ---------------------------

    public function testPositiveAProperCcListPassesThroughUnchanged(): void
    {
        $mail = Messenger::createMessenger();
        $mail->send('a@b.com', 'S', '<p>b</p>', true, null, ['x@y.com', 'p@q.com']);
        $msg = $this->captured();
        $this->assertSame(['x@y.com', 'p@q.com'], $msg['cc'] ?? null, 'a valid cc list was altered');
    }

    public function testNegativeABareStringCcIsNotStoredAsABareString(): void
    {
        // Goes through send(), not capture(): the boundary that must normalise is the
        // public one. Normalising in one caller only means the same message is well
        // formed or malformed depending on which door it came through.
        $mail = Messenger::createMessenger();
        $mail->send('a@b.com', 'Subject', '<p>hi</p>', true, null, 'one@cc.com');
        $msg = $this->captured();
        $this->assertIsNotString(
            $msg['cc'] ?? null,
            'cc was stored as a bare string where a list is declared'
        );
        $this->assertSame(['one@cc.com'], $msg['cc']);
    }

    // --- 4. interception is a branch, not a swap ----------------------------

    public function testPositiveSendIsTheClassMethod(): void
    {
        // PHP cannot rebind a method onto an instance the way Python could, so the
        // structural guarantee here is that send() is declared by Messenger itself
        // and the capture path is reached through it, not around it.
        $mail = Messenger::createMessenger();
        $method = new \ReflectionMethod($mail, 'send');
        $this->assertSame(
            Messenger::class,
            $method->getDeclaringClass()->getName(),
            'send() is not declared by Messenger; interception is installed around the method'
        );
    }

    public function testNegativeCaptureAndSendDoNotDisagreeOnTheirFifthArgument(): void
    {
        // The swap's real cost, and why "it works positionally" is not good enough.
        // One name must mean one signature; so must one contract across two methods.
        $sendFifth = (new \ReflectionMethod(Messenger::class, 'send'))->getParameters()[4]->getName();
        $captureFifth = (new \ReflectionMethod(DevMailbox::class, 'capture'))->getParameters()[4]->getName();
        $this->assertSame(
            $sendFifth,
            $captureFifth,
            "send()'s 5th parameter is \$$sendFifth but capture()'s is \$$captureFifth; the same "
                . 'positional call means different things depending on which one you reach'
        );
    }

    // --- the gate: availability, not verbosity ------------------------------

    public function testCapturesWhenNoSmtpHostIsConfigured(): void
    {
        $mail = Messenger::createMessenger();
        $this->assertInstanceOf(
            DevMailbox::class,
            $mail->devMailbox,
            'with no SMTP host there is nowhere to send, so the messenger must capture'
        );
    }

    public function testNegativeDebugAloneDoesNotSuppressSending(): void
    {
        // Debug must NOT swallow mail. A dev box with a real SMTP host is expected to
        // be able to send.
        putenv('TINA4_DEBUG=true');
        putenv('TINA4_MAIL_HOST=smtp.example.com');
        $mail = Messenger::createMessenger();
        $this->assertNull(
            $mail->devMailbox,
            'TINA4_DEBUG forced capture even though SMTP was configured - debug must still '
                . 'be able to send real mail'
        );
    }

    public function testCaptureCanBeForcedWithSmtpConfigured(): void
    {
        putenv('TINA4_MAIL_HOST=smtp.example.com');
        putenv('TINA4_MAIL_CAPTURE=true');
        $mail = Messenger::createMessenger();
        $this->assertInstanceOf(DevMailbox::class, $mail->devMailbox);
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  THE 14 SHARED MESSENGER-CONTRACT INVARIANTS (messenger_contract.json)
    //  One named test per invariant. The normalised method name CONTAINS the id.
    // ═════════════════════════════════════════════════════════════════════════

    // ── 1. msg-uid-is-a-real-uid (GreenMail — proved with a REAL expunge) ────

    public function testMsgUidIsARealUid(): void
    {
        // The ONLY thing that separates the IMAP UID from a sequence number is an
        // expunge: before one they are identical, which is why this survived every
        // existing contract suite. Send three, expunge the oldest, then read the
        // newest by the SAME id -- a sequence number would now address a different
        // (or absent) message; a real UID still resolves to the newest.
        $this->requireGreenMail();
        $to = $this->greenMailRecipient();
        $messenger = $this->greenMailMessenger($to);
        $tag = bin2hex(random_bytes(4));
        [$subjectOne, $subjectTwo, $subjectThree] = ["UidReal-1-$tag", "UidReal-2-$tag", "UidReal-3-$tag"];

        $this->deliverAndWait($messenger, $to, $subjectOne, 'p1');
        $this->deliverAndWait($messenger, $to, $subjectTwo, 'p2');
        $this->deliverAndWait($messenger, $to, $subjectThree, 'p3');

        // Newest first: [P3, P2, P1].
        $inbox = $messenger->inbox();
        $this->assertCount(3, $inbox);
        $this->assertSame($subjectThree, $inbox[0]['subject']);
        $uidNewest = $inbox[0]['uid'];
        $uidOldest = $inbox[count($inbox) - 1]['uid'];
        $this->assertNotSame($uidOldest, $uidNewest, 'three distinct messages must carry distinct UIDs');

        // Expunge the OLDEST. Sequence numbers renumber; UIDs do not.
        $messenger->delete($uidOldest);
        $this->waitForSubjectGone($messenger, $subjectOne);

        // Read the newest by the id captured BEFORE the expunge. It must still be P3.
        $message = $messenger->read($uidNewest);
        $this->assertNotNull(
            $message,
            "reading the newest message by its pre-expunge uid returned null; the uid was a "
                . "sequence number, not the IMAP UID"
        );
        $this->assertSame($subjectThree, $message['subject']);

        // And the surviving newest still reports the SAME uid -- UIDs are stable.
        $this->assertSame($uidNewest, $messenger->inbox()[0]['uid'], 'the UID changed across an expunge');
    }

    // ── 2. msg-uid-is-a-string ───────────────────────────────────────────────

    public function testMsgUidIsAString(): void
    {
        // The Python master emits str(uid); Ruby returned an Integer, so `uid == "3"`
        // was false there alone. PHP casts (string) in summaryItem() and read().
        $this->requireGreenMail();
        $to = $this->greenMailRecipient();
        $messenger = $this->greenMailMessenger($to);
        $subject = 'UidString ' . bin2hex(random_bytes(4));

        $envelope = $this->deliverAndWait($messenger, $to, $subject, 'uid string body');
        $this->assertIsString($envelope['uid'], 'inbox() uid must be a string');
        $this->assertNotSame('', $envelope['uid']);

        $message = $messenger->read($envelope['uid']);
        $this->assertNotNull($message);
        $this->assertIsString($message['uid'], 'read() uid must be a string too');
    }

    // ── 3. msg-inbox-is-newest-first ─────────────────────────────────────────

    public function testMsgInboxIsNewestFirst(): void
    {
        // page[0] is the newest, and inbox(limit: 1) is the NEWEST message -- the
        // most common inbox call. Ruby returned the OLDEST there (uid_fetch silently
        // re-sorted ascending); PHP keys newest-first at both call sites.
        $this->requireGreenMail();
        $to = $this->greenMailRecipient();
        $messenger = $this->greenMailMessenger($to);
        $tag = bin2hex(random_bytes(4));
        [$subjectOne, $subjectTwo, $subjectThree] = ["Newest-1-$tag", "Newest-2-$tag", "Newest-3-$tag"];

        $this->deliverAndWait($messenger, $to, $subjectOne, 'p1');
        $this->deliverAndWait($messenger, $to, $subjectTwo, 'p2');
        $this->deliverAndWait($messenger, $to, $subjectThree, 'p3');

        $inbox = $messenger->inbox();
        $this->assertSame(
            [$subjectThree, $subjectTwo, $subjectOne],
            array_column($inbox, 'subject'),
            'inbox() must return the page newest-first'
        );

        $firstPage = $messenger->inbox('INBOX', 1);
        $this->assertCount(1, $firstPage);
        $this->assertSame(
            $subjectThree,
            $firstPage[0]['subject'],
            'inbox(limit: 1) must return the NEWEST message, not the oldest'
        );
    }

    // ── 4. msg-folder-is-first-and-positional (reflection + positional call) ─

    public function testMsgFolderIsFirstAndPositional(): void
    {
        // inbox(folder, limit, offset) and read(uid, folder) callable POSITIONALLY,
        // folder first on inbox and second on read. Ruby was keyword-only and raised
        // ArgumentError, so there was no positional folder to be first.
        $inboxParams = array_map(
            static fn (\ReflectionParameter $p) => $p->getName(),
            (new \ReflectionMethod(Messenger::class, 'inbox'))->getParameters()
        );
        $this->assertSame(
            ['folder', 'limit', 'offset'],
            $inboxParams,
            'inbox() must be inbox(folder, limit, offset) -- folder FIRST'
        );

        $readParams = array_map(
            static fn (\ReflectionParameter $p) => $p->getName(),
            (new \ReflectionMethod(Messenger::class, 'read'))->getParameters()
        );
        $this->assertSame('uid', $readParams[0], 'read() first positional is the uid');
        $this->assertSame('folder', $readParams[1], 'read(uid, folder) -- folder is the 2nd positional arg');

        // Behavioural proof of positional callability: called positionally against a
        // refused port, both REACH IMAP and raise MessengerConnectionError. A
        // keyword-only signature would TypeError before ever connecting.
        $messenger = $this->refusedImapMessenger();
        $this->assertRaisesConnectionError(
            static fn () => $messenger->inbox('INBOX', 1, 0),
            'inbox() must accept (folder, limit, offset) positionally'
        );
        $this->assertRaisesConnectionError(
            static fn () => $messenger->read('1', 'INBOX'),
            'read() must accept (uid, folder) positionally'
        );
    }

    // ── 5. msg-missing-uid-is-null-not-empty (GreenMail: valid conn, bogus uid)

    public function testMsgMissingUidIsNullNotEmpty(): void
    {
        // A successful fetch for a non-existent UID returns null and never raises. It
        // is not an error, and it is null (not {} as Python emitted) so an HTTP
        // consumer sees the same shape and `$result === null` gets the right answer.
        $this->requireGreenMail();
        $messenger = $this->greenMailMessenger($this->greenMailRecipient());

        $result = $messenger->read(999999);
        $this->assertNull($result, 'a missing UID on a valid connection must read as null, never raise');
        $this->assertNotSame([], $result, 'null, not an empty array / {}');
    }

    // ── 6. msg-read-methods-fail-loud (real closed port; send never raises) ──

    public function testMsgReadMethodsFailLoud(): void
    {
        // inbox/read/unread/search/folders RAISE MessengerConnectionError on a real
        // connection failure -- an empty result means empty, never failure. send()
        // never raises; it returns a result. Failure here is a genuinely refused TCP
        // connection to 127.0.0.1:1, not a stub.
        $reader = $this->refusedImapMessenger();
        $this->assertRaisesConnectionError(static fn () => $reader->inbox(), 'inbox() must fail loud');
        $this->assertRaisesConnectionError(static fn () => $reader->read(1), 'read() must fail loud');
        $this->assertRaisesConnectionError(static fn () => $reader->unread(), 'unread() must fail loud');
        $this->assertRaisesConnectionError(static fn () => $reader->search(), 'search() must fail loud');
        $this->assertRaisesConnectionError(static fn () => $reader->folders(), 'folders() must fail loud');

        // send() to a refused SMTP port returns a result and does NOT raise.
        $sender = new Messenger(
            host: '127.0.0.1',
            port: 1,
            username: 'u',
            password: 'p',
            fromAddress: 'from@tina4.test',
            encryption: 'none',
        );
        $result = $sender->send('to@tina4.test', 'Subj', 'body');
        $this->assertIsArray($result, 'send() must return a result, never raise');
        $this->assertFalse($result['success'], 'a refused SMTP port must report failure, not success');
    }

    // ── 7. msg-inbox-item-shape (GreenMail — EXACTLY the 7-key shape) ─────────

    public function testMsgInboxItemShape(): void
    {
        // Exactly {uid, subject, from, to, date, snippet, seen}; from/to strings;
        // date ISO-8601. PHP historically had no `to` and added msgno/flagged/size --
        // all conformed in 3.13.96.
        $this->requireGreenMail();
        $to = $this->greenMailRecipient();
        $messenger = $this->greenMailMessenger($to);
        $subject = 'InboxShape ' . bin2hex(random_bytes(4));

        $envelope = $this->deliverAndWait($messenger, $to, $subject, 'inbox shape body');

        $this->assertSame(
            ['uid', 'subject', 'from', 'to', 'date', 'snippet', 'seen'],
            array_keys($envelope),
            'inbox item must carry EXACTLY the settled 7-key shape'
        );
        $this->assertIsString($envelope['from']);
        $this->assertIsString($envelope['to']);
        $this->assertStringContainsString($to, $envelope['to'], 'the To header must carry the recipient');
        $this->assertIsBool($envelope['seen']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $envelope['date'],
            'date must be ISO-8601, got: ' . $envelope['date']
        );
        $this->assertArrayNotHasKey('msgno', $envelope);
        $this->assertArrayNotHasKey('flagged', $envelope);
        $this->assertArrayNotHasKey('size', $envelope);
    }

    // ── 8. msg-read-item-shape (GreenMail — 10 canonical keys, one body naming)

    public function testMsgReadItemShape(): void
    {
        // read() carries the same canonical field set in all four: uid, subject,
        // from, to, cc, date (ISO-8601), body_text, body_html, attachments, headers.
        // body_text/body_html is the ONE body naming convention (never body/html or
        // bodyText/bodyHtml). attachments + headers must be present (Ruby/Node had no
        // attachments; PHP/Ruby had no headers -- all conformed in 3.13.96).
        //
        // NOTE: PHP read() additionally carries msgno/seen/flagged/message_id -- a
        // PHP-only SUPERSET, never a measured defect (the fixture flagged MISSING
        // keys, not extras). This test pins the 10 REQUIRED keys and the single body
        // naming; it deliberately does not forbid the extras. See the report note.
        $this->requireGreenMail();
        $to = $this->greenMailRecipient();
        $messenger = $this->greenMailMessenger($to);
        $subject = 'ReadShape ' . bin2hex(random_bytes(4));

        $envelope = $this->deliverAndWait($messenger, $to, $subject, 'read shape body');
        $message = $messenger->read($envelope['uid']);

        $this->assertNotNull($message);
        foreach (['uid', 'subject', 'from', 'to', 'cc', 'date', 'body_text', 'body_html', 'attachments', 'headers'] as $key) {
            $this->assertArrayHasKey($key, $message, "read() must carry the canonical key '{$key}'");
        }
        $this->assertIsString($message['from']);
        $this->assertIsString($message['to']);
        $this->assertIsString($message['cc']);
        $this->assertIsArray($message['attachments'], 'attachments must be present (an array, empty if none)');
        $this->assertIsArray($message['headers'], 'headers must be present (a name => value map)');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $message['date'], 'date must be ISO-8601');

        // One body-field naming convention: body_text/body_html, never the Ruby
        // (body/html) or Node (bodyText/bodyHtml) spellings.
        foreach (['body', 'html', 'bodyText', 'bodyHtml'] as $foreignBodyKey) {
            $this->assertArrayNotHasKey($foreignBodyKey, $message, "read() must not carry the '{$foreignBodyKey}' body spelling");
        }

        // headers carry the standard fields (parity with Python's dict(msg.items())).
        $headerNames = array_map('strtolower', array_keys($message['headers']));
        $this->assertContains('subject', $headerNames, 'read() headers must carry Subject');
        $this->assertContains('from', $headerNames, 'read() headers must carry From');
    }

    // ── 9. msg-snippet-is-decoded-text (GreenMail) ───────────────────────────

    public function testMsgSnippetIsDecodedText(): void
    {
        // snippet is decoded, transfer-decoded, tag-stripped plain text -- never raw
        // encoded bytes. Python returned raw base64 (BODY.PEEK[TEXT] never decoded);
        // Node returned '' always (header-only fetch). PHP decodes + strips + caps 200.
        $this->requireGreenMail();
        $to = $this->greenMailRecipient();
        $messenger = $this->greenMailMessenger($to);

        // Plain body: the DECODED marker must appear (raw base64 would not contain it).
        $subjectPlain = 'Snippet ' . bin2hex(random_bytes(4));
        $markerPlain = 'snip' . bin2hex(random_bytes(4));
        $envelopePlain = $this->deliverAndWait($messenger, $to, $subjectPlain, "plain preview {$markerPlain} tail");
        $this->assertArrayHasKey('snippet', $envelopePlain);
        $this->assertStringContainsString(
            $markerPlain,
            $envelopePlain['snippet'],
            'snippet must be DECODED body text, not the raw transfer-encoded bytes'
        );

        // HTML body: the marker survives but the tags are stripped.
        $subjectHtml = 'SnippetHtml ' . bin2hex(random_bytes(4));
        $markerHtml = 'htmlsnip' . bin2hex(random_bytes(4));
        $envelopeHtml = $this->deliverAndWait($messenger, $to, $subjectHtml, "<p>hi <b>{$markerHtml}</b></p>", true);
        $this->assertStringContainsString($markerHtml, $envelopeHtml['snippet']);
        $this->assertStringNotContainsString('<b>', $envelopeHtml['snippet'], 'HTML tags must be stripped from the snippet');
        $this->assertStringNotContainsString('<p>', $envelopeHtml['snippet']);
    }

    // ── 10. msg-send-result-shape (capture + closed-port failure, no server) ─

    public function testMsgSendResultShape(): void
    {
        // send() returns the SAME keys on BOTH paths: success, message, id. On
        // success id is the real Message-ID (the capture id here); on failure the
        // keys are present with id null. No path-specific extra keys.
        $captureResult = Messenger::createMessenger()->send('a@b.com', 'Subj', 'body');
        $this->assertSame(
            ['success', 'message', 'id'],
            array_keys($captureResult),
            'the capture path must return EXACTLY {success, message, id}'
        );
        $this->assertTrue($captureResult['success']);
        $this->assertIsString($captureResult['id']);
        $this->assertNotSame('', $captureResult['id'], 'id is the real capture id on success, not empty');

        $failure = new Messenger(
            host: '127.0.0.1',
            port: 1,
            username: 'u',
            password: 'p',
            fromAddress: 'from@tina4.test',
            encryption: 'none',
        );
        $failureResult = $failure->send('to@tina4.test', 'Subj', 'body');
        $this->assertSame(
            ['success', 'message', 'id'],
            array_keys($failureResult),
            'the failure path must return the SAME key set -- no path-specific extras'
        );
        $this->assertFalse($failureResult['success']);
        $this->assertNull($failureResult['id'], 'id is null on a failed send');
        $this->assertIsString($failureResult['message']);

        // Both paths carry the identical key set.
        $this->assertSame(array_keys($captureResult), array_keys($failureResult));
    }

    // ── 11. msg-every-method-exists-everywhere (reflection) ──────────────────

    public function testMsgEveryMethodExistsEverywhere(): void
    {
        // Every public Messenger method exists under ONE idiomatic name. mark_unread
        // and send_template were Python-only; delete existed in two of four under two
        // names (delete / deleteMessage). PHP exposes all ten, delete not deleteMessage.
        $methods = ['inbox', 'read', 'unread', 'search', 'folders', 'send', 'sendTemplate', 'markRead', 'markUnread', 'delete'];
        foreach ($methods as $name) {
            $this->assertTrue(method_exists(Messenger::class, $name), "Messenger::{$name}() must exist");
            $this->assertTrue(
                (new \ReflectionMethod(Messenger::class, $name))->isPublic(),
                "Messenger::{$name}() must be public"
            );
        }

        $this->assertTrue(method_exists(Messenger::class, 'delete'), 'the delete concept must exist as delete()');
        $this->assertFalse(
            method_exists(Messenger::class, 'deleteMessage'),
            'the concept is delete(), not deleteMessage() -- one concept, one name'
        );
    }

    // ── 12. msg-env-vars-are-honoured-everywhere ─────────────────────────────

    public function testMsgEnvVarsAreHonouredEverywhere(): void
    {
        // TINA4_MAIL_IMAP_USERNAME/_PASSWORD are READ, and fall back to
        // TINA4_MAIL_USERNAME/_PASSWORD. Node read the IMAP-specific pair only;
        // python/php/ruby read them AND fall back. Pointed at separate IMAP creds,
        // failing to read them authenticates to the wrong account.
        putenv('TINA4_MAIL_USERNAME=smtp-user');
        putenv('TINA4_MAIL_PASSWORD=smtp-pass');
        putenv('TINA4_MAIL_IMAP_USERNAME=imap-user');
        putenv('TINA4_MAIL_IMAP_PASSWORD=imap-pass');

        $withImapCreds = new Messenger();
        $this->assertSame('imap-user', $this->privateValue($withImapCreds, 'imapUsername'), 'TINA4_MAIL_IMAP_USERNAME must be read');
        $this->assertSame('imap-pass', $this->privateValue($withImapCreds, 'imapPassword'), 'TINA4_MAIL_IMAP_PASSWORD must be read');

        // Fallback: with no IMAP-specific credentials, use the SMTP ones.
        putenv('TINA4_MAIL_IMAP_USERNAME');
        putenv('TINA4_MAIL_IMAP_PASSWORD');
        $fallback = new Messenger();
        $this->assertSame('smtp-user', $this->privateValue($fallback, 'imapUsername'), 'IMAP username must fall back to TINA4_MAIL_USERNAME');
        $this->assertSame('smtp-pass', $this->privateValue($fallback, 'imapPassword'), 'IMAP password must fall back to TINA4_MAIL_PASSWORD');
    }

    // ── 13. msg-explicit-beats-env ───────────────────────────────────────────

    public function testMsgExplicitBeatsEnv(): void
    {
        // Every env-read configurable is constructor-settable, and the constructor
        // wins (ADR-0041: explicit > env > default). imap_encryption is the one the
        // fixture calls out (env-only in Python and PHP historically); it is a
        // constructor param now and beats the env value.
        putenv('TINA4_MAIL_HOST=env-host');
        putenv('TINA4_MAIL_USERNAME=env-user');
        putenv('TINA4_MAIL_IMAP_HOST=env-imap-host');
        putenv('TINA4_MAIL_IMAP_USERNAME=env-imap-user');
        putenv('TINA4_MAIL_IMAP_ENCRYPTION=none');

        $messenger = new Messenger(
            host: 'explicit-host',
            username: 'explicit-user',
            imapHost: 'explicit-imap-host',
            imapUsername: 'explicit-imap-user',
            imapEncryption: 'starttls',
        );

        $this->assertSame('explicit-host', $this->privateValue($messenger, 'host'), 'constructor host beats env');
        $this->assertSame('explicit-user', $this->privateValue($messenger, 'username'), 'constructor username beats env');
        $this->assertSame('explicit-imap-host', $this->privateValue($messenger, 'imapHost'), 'constructor imapHost beats env');
        $this->assertSame('explicit-imap-user', $this->privateValue($messenger, 'imapUsername'), 'constructor imapUsername beats env');
        $this->assertSame('starttls', $messenger->getImapEncryption(), 'constructor imap_encryption beats env (ADR-0041)');
    }

    // ── 14. msg-capture-gate (real filesystem) ───────────────────────────────

    public function testMsgCaptureGate(): void
    {
        // Capture when no SMTP host; send when one is; TINA4_DEBUG does NOT suppress;
        // TINA4_MAIL_CAPTURE=true forces capture; the factory returns ONE concrete
        // type and interception is a branch inside send(), never a method swap.
        // setUp has already cleared TINA4_MAIL_HOST and set a temp mailbox dir.

        // (a) No SMTP host -> capture, and it is ONE concrete Messenger whose send()
        //     is declared by Messenger (a branch, not a swapped-in method).
        $noHost = Messenger::createMessenger();
        $this->assertInstanceOf(DevMailbox::class, $noHost->devMailbox, 'no SMTP host -> capture');
        $this->assertSame(Messenger::class, get_class($noHost), 'the factory returns ONE concrete type');
        $this->assertSame(
            Messenger::class,
            (new \ReflectionMethod($noHost, 'send'))->getDeclaringClass()->getName(),
            'send() is declared by Messenger -- capture is a branch, not a method swap'
        );

        // The filesystem proof: capturing actually WRITES the real message to disk.
        $captureResult = $noHost->send('gate@b.com', 'Gate ' . bin2hex(random_bytes(4)), 'gate body');
        $this->assertTrue($captureResult['success']);
        $this->assertSame('gate body', $this->captured()['body'], 'capture must write the real message to disk');

        // (b) A configured SMTP host sends even with TINA4_DEBUG=true -- debug never
        //     suppresses sending.
        putenv('TINA4_MAIL_HOST=smtp.example.com');
        putenv('TINA4_DEBUG=true');
        $this->assertNull(
            Messenger::createMessenger()->devMailbox,
            'a configured SMTP host must send even under TINA4_DEBUG -- debug never suppresses'
        );

        // (c) TINA4_MAIL_CAPTURE=true forces capture even with a host configured.
        putenv('TINA4_MAIL_CAPTURE=true');
        $this->assertInstanceOf(
            DevMailbox::class,
            Messenger::createMessenger()->devMailbox,
            'TINA4_MAIL_CAPTURE=true must force capture even with an SMTP host configured'
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  Helpers for the invariant tests (real dependencies only, no doubles)
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Read a private property off a real Messenger instance.
     *
     * Reflection over a real object is not a mock -- the object was built by the
     * real constructor from real env. This is how the config-resolution invariants
     * (#12, #13) inspect the values there is no public getter for.
     */
    private function privateValue(object $object, string $property): mixed
    {
        // No setAccessible(): a ReflectionProperty reads a private member directly
        // since PHP 8.1, and setAccessible() is a deprecated no-op from 8.5.
        return (new \ReflectionProperty($object, $property))->getValue($object);
    }

    /**
     * Assert a read call raises MessengerConnectionError (the fail-loud contract).
     *
     * @param callable $call The read call under test
     */
    private function assertRaisesConnectionError(callable $call, string $because): void
    {
        try {
            $call();
            $this->fail("expected MessengerConnectionError: {$because}");
        } catch (MessengerConnectionError $expected) {
            $this->assertInstanceOf(MessengerConnectionError::class, $expected);
        }
    }

    /**
     * A Messenger pointed at a refused IMAP port -- imap_open fails fast.
     *
     * Port 1 is privileged and unused, so connect() is refused immediately rather
     * than hanging until a timeout. Mirrors MessengerImapFailLoudTest.
     */
    private function refusedImapMessenger(): Messenger
    {
        return new Messenger(imapHost: '127.0.0.1', imapPort: 1, username: 'u', password: 'p');
    }

    // ── GreenMail plumbing (mirrors MessengerImapGreenMailTest) ──────────────
    // Per-test guards, not setUpBeforeClass: this class also holds server-free
    // tests that must always run, so a class-wide skip is wrong here.

    /**
     * GreenMail SMTP+IMAP coordinates from the environment (Python master's names),
     * defaulting to GreenMail's plain 3025/3143.
     *
     * @return array{0: string, 1: int, 2: string, 3: int} [smtpHost, smtpPort, imapHost, imapPort]
     */
    private function greenMailConfig(): array
    {
        return [
            getenv('TINA4_TEST_SMTP_HOST') ?: '127.0.0.1',
            (int)(getenv('TINA4_TEST_SMTP_PORT') ?: 3025),
            getenv('TINA4_TEST_IMAP_HOST') ?: '127.0.0.1',
            (int)(getenv('TINA4_TEST_IMAP_PORT') ?: 3143),
        ];
    }

    /**
     * Skip (with the wording the TINA4_REQUIRE_SERVICES gate recognises, so it is a
     * hard failure in CI where GreenMail is provisioned) unless a real GreenMail is
     * reachable and ext-imap is present.
     */
    private function requireGreenMail(): void
    {
        if (!function_exists('imap_open')) {
            $this->markTestSkipped('ext-imap not installed — GreenMail IMAP tests cannot run');
        }

        [$smtpHost, $smtpPort, $imapHost, $imapPort] = $this->greenMailConfig();
        if (!$this->portReachable($smtpHost, $smtpPort) || !$this->portReachable($imapHost, $imapPort)) {
            $this->markTestSkipped(sprintf(
                'greenmail SMTP/IMAP not reachable at %s:%d / %s:%d',
                $smtpHost,
                $smtpPort,
                $imapHost,
                $imapPort,
            ));
        }
    }

    private function portReachable(string $host, int $port, float $timeout = 1.5): bool
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }

    /** A unique recipient per call -> an isolated, first-access-created mailbox. */
    private function greenMailRecipient(): string
    {
        return 'php-contract-' . bin2hex(random_bytes(8)) . '@tina4.test';
    }

    private function greenMailMessenger(string $address): Messenger
    {
        [$smtpHost, $smtpPort, $imapHost, $imapPort] = $this->greenMailConfig();

        return new Messenger(
            host: $smtpHost,
            port: $smtpPort,
            username: $address,
            password: 'secret',
            fromAddress: 'sender@tina4.test',
            fromName: 'Sender',
            encryption: 'none',
            imapHost: $imapHost,
            imapPort: $imapPort,
            imapEncryption: 'none',
        );
    }

    /**
     * Deliver over real SMTP, then poll the real IMAP INBOX until the message with
     * this subject lands. Delivery is asynchronous, so a single immediate read would
     * be a race; a bounded poll keeps the test honest without a blanket sleep.
     *
     * @return array<string, mixed> The envelope from inbox()
     */
    private function deliverAndWait(Messenger $messenger, string $to, string $subject, string $body, bool $html = false): array
    {
        $result = $messenger->send(to: $to, subject: $subject, body: $body, html: $html);
        $this->assertTrue($result['success'], 'SMTP send failed: ' . ($result['message'] ?? 'no message'));

        for ($attempt = 0; $attempt < 40; $attempt++) {
            foreach ($messenger->inbox() as $envelope) {
                if (($envelope['subject'] ?? null) === $subject) {
                    return $envelope;
                }
            }
            usleep(250_000);
        }

        $this->fail("message '{$subject}' never arrived in the real IMAP mailbox");
    }

    /** Poll until a subject is gone from the inbox (after an expunge). */
    private function waitForSubjectGone(Messenger $messenger, string $subject): void
    {
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $present = false;
            foreach ($messenger->inbox() as $envelope) {
                if (($envelope['subject'] ?? null) === $subject) {
                    $present = true;
                    break;
                }
            }
            if (!$present) {
                return;
            }
            usleep(250_000);
        }

        $this->fail("message '{$subject}' was not expunged in time");
    }
}
