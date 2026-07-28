<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\DevMailbox;
use Tina4\Messenger;

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
 */
class MessengerContractTest extends TestCase
{
    private string $mailboxDir = '';

    /** @var array<string, string|false> Env values to restore after each test */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (['TINA4_MAILBOX_DIR', 'TINA4_MAIL_HOST', 'TINA4_MAIL_CAPTURE', 'TINA4_DEBUG'] as $key) {
            $this->savedEnv[$key] = getenv($key);
        }

        $this->mailboxDir = sys_get_temp_dir() . '/tina4-messenger-contract-' . bin2hex(random_bytes(6));
        mkdir($this->mailboxDir, 0777, true);

        // A dev box with no mail server: capture is the correct behaviour.
        putenv('TINA4_MAILBOX_DIR=' . $this->mailboxDir);
        putenv('TINA4_MAIL_HOST');
        putenv('TINA4_MAIL_CAPTURE');
        putenv('TINA4_DEBUG=true');
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
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
}
