<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Messenger;

/**
 * MAIL REDIRECT CONTRACT -- TINA4_MAIL_REDIRECT_TO (MAIL-DEC-01).
 *
 * Pins plan/v3/fixtures/mail_redirect_contract.json. TINA4_MAIL_REDIRECT_TO is a
 * SECOND, independent knob on the real-SMTP path: when a redirect list is
 * configured and send() is actually talking to SMTP (not capturing), every
 * recipient is REPLACED by the redirect list and the original recipients are
 * preserved in an X-Tina4-Original-To header. Capture (TINA4_MAIL_CAPTURE / no
 * SMTP host) always wins -- redirect never applies on the capture path.
 *
 * Real GreenMail SMTP :3025 / IMAP :3143 (TINA4_TEST_SMTP_* / TINA4_TEST_IMAP_*
 * to relocate -- the SAME live server MessengerContractTest and
 * MessengerImapGreenMailTest use). NO MOCKS: every case sends real SMTP and
 * proves delivery/non-delivery with a real IMAP fetch.
 */
class MailRedirectContractTest extends TestCase
{
    private string $mailboxDir = '';

    /** @var array<string, string|false> Env values to restore after each test */
    private array $savedEnv = [];

    /**
     * Every mail env var these tests read or set, saved and cleared in setUp so
     * a value leaking from the real environment (or a previous test) can never
     * decide an assertion for the wrong reason; restored in tearDown.
     *
     * @var string[]
     */
    private const MAIL_ENV_KEYS = [
        'TINA4_MAILBOX_DIR',
        'TINA4_MAIL_HOST',
        'TINA4_MAIL_PORT',
        'TINA4_MAIL_CAPTURE',
        'TINA4_MAIL_REDIRECT_TO',
        'TINA4_DEBUG',
        'TINA4_MAIL_USERNAME',
        'TINA4_MAIL_PASSWORD',
        'TINA4_MAIL_FROM',
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

        $this->mailboxDir = sys_get_temp_dir() . '/tina4-mailredirect-' . bin2hex(random_bytes(6));
        mkdir($this->mailboxDir, 0777, true);
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

    // ── GreenMail plumbing (mirrors MessengerContractTest) ───────────────────

    /**
     * GreenMail SMTP+IMAP coordinates from the environment, defaulting to
     * GreenMail's plain 3025/3143.
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
     * Skip (with the wording the TINA4_REQUIRE_SERVICES gate recognises, so it
     * is a hard failure in CI/lab where GreenMail is provisioned) unless a real
     * GreenMail is reachable and ext-imap is present.
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

    /**
     * A unique address -> an isolated, first-access-created GreenMail mailbox
     * (GreenMail's auth is disabled on the lab, so LOGIN as any never-used
     * address succeeds and SELECT INBOX reports 0 messages -- verified live).
     */
    private function uniqueAddress(string $prefix, string $domain): string
    {
        return 'mailredirect-' . $prefix . '-' . bin2hex(random_bytes(6)) . '@' . $domain;
    }

    /** A messenger wired for a real SMTP send only (no IMAP creds needed). */
    private function senderMessenger(): Messenger
    {
        [$smtpHost, $smtpPort] = $this->greenMailConfig();

        return new Messenger(
            host: $smtpHost,
            port: $smtpPort,
            fromAddress: 'sender@tina4.test',
            fromName: 'Sender',
            encryption: 'none',
        );
    }

    /**
     * A messenger wired to poll ONE mailbox's real IMAP. The address IS the
     * mailbox identity (GreenMail auth disabled -- any password is accepted).
     */
    private function readerMessenger(string $address): Messenger
    {
        [, , $imapHost, $imapPort] = $this->greenMailConfig();

        return new Messenger(
            username: $address,
            password: 'secret',
            imapHost: $imapHost,
            imapPort: $imapPort,
            imapEncryption: 'none',
        );
    }

    /**
     * Poll until $subject is listed in $address's real IMAP inbox.
     *
     * @return array<string, mixed>
     */
    private function waitPresent(string $address, string $subject, int $attempts = 40): array
    {
        $reader = $this->readerMessenger($address);
        for ($i = 0; $i < $attempts; $i++) {
            foreach ($reader->inbox() as $envelope) {
                if (($envelope['subject'] ?? null) === $subject) {
                    return $envelope;
                }
            }
            usleep(250_000);
        }

        $this->fail("'{$subject}' never arrived in {$address}'s real IMAP mailbox");
    }

    /**
     * Poll for $subject to confirm it NEVER arrives within a real bounded
     * window. Mutation-proof: if the recipient-rewrite is disabled the message
     * DOES land here and this returns false well before the budget is spent.
     */
    private function staysAbsent(string $address, string $subject, int $attempts = 40): bool
    {
        $reader = $this->readerMessenger($address);
        for ($i = 0; $i < $attempts; $i++) {
            foreach ($reader->inbox() as $envelope) {
                if (($envelope['subject'] ?? null) === $subject) {
                    return false;
                }
            }
            usleep(250_000);
        }

        return true;
    }

    /**
     * Count of .json messages captured to the real local mailbox on disk.
     * Globs recursively rather than assuming a folder name (mirrors
     * MessengerContractTest::captured()).
     */
    private function outboxCount(): int
    {
        if (!is_dir($this->mailboxDir)) {
            return 0;
        }

        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->mailboxDir));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'json') {
                $found[] = $file->getPathname();
            }
        }

        return count($found);
    }

    // --- redirect_delivers_to_every_address_on_the_list -----------------------

    public function testRedirectDeliversToEveryAddressOnTheList(): void
    {
        $this->requireGreenMail();

        $dev1 = $this->uniqueAddress('dev1', 'x.test');
        $dev2 = $this->uniqueAddress('dev2', 'x.test');
        $real = $this->uniqueAddress('real', 'y.test');
        $subject = 'mailredirect-' . bin2hex(random_bytes(4));

        // A deliberate space after the comma proves the parse rule really trims.
        putenv("TINA4_MAIL_REDIRECT_TO={$dev1}, {$dev2}");

        $sender = $this->senderMessenger();
        $result = $sender->send(to: $real, subject: $subject, body: 'the real message body');
        $this->assertTrue($result['success'], $result['message'] ?? 'send failed');

        // Positive: BOTH dev addresses received it.
        $item1 = $this->waitPresent($dev1, $subject);
        $item2 = $this->waitPresent($dev2, $subject);
        $this->assertSame($subject, $item1['subject']);
        $this->assertSame($subject, $item2['subject']);

        // Negative: the real recipient never did.
        $this->assertTrue(
            $this->staysAbsent($real, $subject),
            "the real recipient {$real} received the redirected mail"
        );

        // The received message carries the original recipient in the header.
        $full = $this->readerMessenger($dev1)->read($item1['uid']);
        $this->assertNotNull($full);
        $this->assertSame($real, $full['headers']['X-Tina4-Original-To'] ?? null);
    }

    // --- redirect_unset_delivers_normally --------------------------------------

    public function testRedirectUnsetDeliversNormally(): void
    {
        $this->requireGreenMail();

        $real = $this->uniqueAddress('realctrl', 'y.test');
        $subject = 'mailredirect-ctrl-' . bin2hex(random_bytes(4));

        // TINA4_MAIL_REDIRECT_TO is already cleared by setUp() — the control case.

        $sender = $this->senderMessenger();
        $result = $sender->send(to: $real, subject: $subject, body: 'normal delivery');
        $this->assertTrue($result['success'], $result['message'] ?? 'send failed');

        $item = $this->waitPresent($real, $subject);
        $this->assertSame($subject, $item['subject']);
    }

    // --- capture_takes_precedence_over_redirect ---------------------------------

    public function testCaptureTakesPrecedenceOverRedirect(): void
    {
        $this->requireGreenMail();

        $dev1 = $this->uniqueAddress('capdev1', 'x.test');
        $dev2 = $this->uniqueAddress('capdev2', 'x.test');
        $real = $this->uniqueAddress('capreal', 'y.test');
        $subject = 'mailredirect-capture-' . bin2hex(random_bytes(4));

        putenv('TINA4_MAIL_CAPTURE=true');
        putenv("TINA4_MAIL_REDIRECT_TO={$dev1},{$dev2}");
        putenv('TINA4_MAILBOX_DIR=' . $this->mailboxDir);

        // A REAL SMTP host is configured too, so capture wins on the MERITS of
        // TINA4_MAIL_CAPTURE, not merely because sending was unavailable.
        [$smtpHost, $smtpPort] = $this->greenMailConfig();
        $messenger = new Messenger(
            host: $smtpHost,
            port: $smtpPort,
            fromAddress: 'sender@tina4.test',
            encryption: 'none',
        );
        $result = $messenger->send(to: $real, subject: $subject, body: 'must never leave this box');
        $this->assertTrue($result['success'], $result['message'] ?? 'capture send failed');

        // Nothing reached GreenMail at all -- dev list AND the real recipient
        // stay empty (a shorter budget suffices: capture short-circuits before
        // any socket is opened, so a mutation would surface fast).
        $this->assertTrue(
            $this->staysAbsent($dev1, $subject, 16),
            "dev1 ({$dev1}) received mail despite TINA4_MAIL_CAPTURE"
        );
        $this->assertTrue(
            $this->staysAbsent($dev2, $subject, 16),
            "dev2 ({$dev2}) received mail despite TINA4_MAIL_CAPTURE"
        );
        $this->assertTrue(
            $this->staysAbsent($real, $subject, 16),
            "the real recipient ({$real}) received mail despite TINA4_MAIL_CAPTURE"
        );

        // Exactly one message landed in the local DevMailbox.
        $this->assertSame(1, $this->outboxCount());
    }
}
