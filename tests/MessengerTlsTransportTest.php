<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * ADR-0071: mail encryption means what it says.
 *
 *   ssl                implicit TLS on ANY port (was: TLS only on 465, clear elsewhere)
 *   tls / starttls     STARTTLS REQUIRED; fail before AUTH / MAIL FROM if not offered
 *   none               never upgrades
 *   port 465           always implicit TLS
 *   anything else      InvalidArgumentException at construction
 *   certificates       ALWAYS verified, host name included, SMTP and IMAP
 *
 * NO mocks, doubles or in-test servers. The servers are the lab's real TLS mail
 * servers (tina4-ruby spec/support/mail-infra.sh):
 *
 *   GreenMail  4025 SMTP + AUTH (no STARTTLS), 4465 SMTPS, 4143 IMAP, 4993 IMAPS
 *   Mailpit    4587 SMTP, STARTTLS required + AUTH, 4825 its HTTP API
 *   Dovecot    4144 IMAP with STARTTLS (any user, password "pass")
 *
 * Every TLS case runs in a child PHP process. A TRUSTED child is started with
 * `-d openssl.cafile=<CA>` (SMTP, PHP streams) and SSL_CERT_FILE=<CA> (IMAP,
 * c-client reads OpenSSL's default store): the runtime's own mechanism, which
 * the framework itself never touches. An UNTRUSTED child gets neither, and must
 * fail: a TLS suite passes just as happily with verification switched off, so
 * the refusals are what prove it is on.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Messenger;

final class MessengerTlsTransportTest extends TestCase
{
    private const SMTP_PLAIN = 4025;
    private const SMTPS = 4465;
    private const IMAP_PLAIN = 4143;
    private const IMAPS = 4993;
    private const SMTP_STARTTLS = 4587;
    private const MAILPIT_API = 4825;
    private const IMAP_STARTTLS = 4144;

    private const USERNAME = 'tina4';
    private const PASSWORD = 'mail-secret';
    private const MAILBOX = 'tina4@tina4.test';

    private string $host = '';
    private string $caFile = '';

    protected function setUp(): void
    {
        $this->host = (string)(getenv('TINA4_TEST_MAIL_TLS_HOST') ?: '');
        $this->caFile = (string)(getenv('TINA4_TEST_MAIL_TLS_CA_FILE') ?: '');
        $reason = null;
        if ($this->host === '' || $this->caFile === '' || !is_file($this->caFile)) {
            $reason = 'TLS mail servers not configured: export TINA4_TEST_MAIL_TLS_HOST and TINA4_TEST_MAIL_TLS_CA_FILE';
        } else {
            foreach ([self::SMTP_PLAIN, self::SMTPS, self::IMAP_PLAIN, self::IMAPS, self::SMTP_STARTTLS, self::MAILPIT_API, self::IMAP_STARTTLS] as $port) {
                $socket = @fsockopen($this->host, $port, $errorNumber, $errorText, 2);
                if (!$socket) {
                    $reason = "TLS mail server not reachable at {$this->host}:{$port}";
                    break;
                }
                fclose($socket);
            }
        }
        if ($reason !== null) {
            if (getenv('TINA4_REQUIRE_SERVICES')) {
                self::fail("TINA4_REQUIRE_SERVICES is set but {$reason}");
            }
            self::markTestSkipped($reason);
        }
    }

    /**
     * The certificate names "localhost" and the IP 127.0.0.1. c-client (IMAP)
     * matches DNS names only, so IMAP connects by name.
     */
    private function imapHost(): string
    {
        return $this->host === '127.0.0.1' ? 'localhost' : $this->host;
    }

    /**
     * Run $action with a Messenger built from $options in a child PHP process.
     *
     * @param array<string, mixed> $options Messenger constructor named arguments
     * @return array<string, mixed>
     */
    private function child(string $action, array $options, bool $trustCa, array $input = []): array
    {
        $program = <<<'PHP'
<?php
require getenv('MAIL_TEST_AUTOLOAD');
$input = json_decode(getenv('MAIL_TEST_INPUT'), true);
try {
    $messenger = new \Tina4\Messenger(...$input['options']);
    $result = match ($input['action']) {
        'send' => $messenger->send(to: $input['to'], subject: $input['subject'], body: 'ADR-0071 ' . $input['subject']),
        'unread' => ['unread' => $messenger->unread()],
        'folders' => ['folders' => $messenger->folders()],
        'find' => ['found' => array_values(array_filter(
            $messenger->search('INBOX', subject: $input['subject']),
            fn($item) => $item['subject'] === $input['subject']
        )) !== []],
    };
} catch (\Throwable $error) {
    $result = ['error_class' => get_class($error), 'error' => $error->getMessage()];
}
echo "\n@@RESULT@@" . json_encode($result);
PHP;
        $script = \TempPath::file('tina4_mail_tls_', '.php');
        file_put_contents($script, $program);

        $environment = getenv();
        foreach (array_keys($environment) as $name) {
            if (str_starts_with($name, 'TINA4_MAIL') || $name === 'SSL_CERT_FILE' || $name === 'SSL_CERT_DIR') {
                unset($environment[$name]);
            }
        }
        $environment['TINA4_MAIL_CAPTURE'] = 'false';
        $environment['TINA4_NO_BROWSER'] = 'true';
        $environment['MAIL_TEST_AUTOLOAD'] = dirname(__DIR__) . '/vendor/autoload.php';
        $environment['MAIL_TEST_INPUT'] = json_encode(['action' => $action, 'options' => $options] + $input);
        $command = [PHP_BINARY];
        if ($trustCa) {
            $environment['SSL_CERT_FILE'] = $this->caFile;
            $command[] = '-d';
            $command[] = 'openssl.cafile=' . $this->caFile;
        }
        $command[] = $script;

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $marker = strrpos($stdout, '@@RESULT@@');
        $this->assertNotFalse($marker, "child PHP failed:\n{$stdout}\n{$stderr}");
        return json_decode(substr($stdout, $marker + strlen('@@RESULT@@')), true);
    }

    private function subject(string $label): string
    {
        return 'adr0071-' . $label . '-' . bin2hex(random_bytes(5));
    }

    /** @return array<string, mixed> */
    private function smtp(int $port, string $encryption, ?string $host = null): array
    {
        return [
            'host' => $host ?? $this->host,
            'port' => $port,
            'username' => self::USERNAME,
            'password' => self::PASSWORD,
            'fromAddress' => 'sender@tina4.test',
            'encryption' => $encryption,
        ];
    }

    /**
     * Did GreenMail deliver $subject to the tina4 mailbox? Read back over IMAPS
     * (trusted). SMTP delivery completes at the end of DATA, so a positive
     * check polls briefly and a negative one (the send already failed) looks once.
     */
    private function greenMailHas(string $subject, int $attempts = 20): bool
    {
        $options = [
            'imapHost' => $this->imapHost(),
            'imapPort' => self::IMAPS,
            'imapUsername' => self::USERNAME,
            'imapPassword' => self::PASSWORD,
            'imapEncryption' => 'tls',
        ];
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $result = $this->child('find', $options, true, ['subject' => $subject]);
            $this->assertArrayHasKey('found', $result, 'reading GreenMail back failed: ' . json_encode($result));
            if ($result['found']) {
                return true;
            }
            usleep(250000);
        }
        return false;
    }

    private function mailpitHas(string $subject, int $attempts = 20): bool
    {
        $url = "http://{$this->host}:" . self::MAILPIT_API . '/api/v1/search?query=' . rawurlencode("subject:\"{$subject}\"");
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $answer = json_decode((string)@file_get_contents($url), true);
            $this->assertIsArray($answer, 'Mailpit API did not answer');
            if (($answer['messages_count'] ?? count($answer['messages'] ?? [])) > 0) {
                return true;
            }
            usleep(250000);
        }
        return false;
    }

    // ── SMTP: section 1, the transport table ──────────────────────────────

    public function testSslOnANon465PortIsImplicitTlsAndDelivers(): void
    {
        $subject = $this->subject('ssl-4465');
        $result = $this->child('send', $this->smtp(self::SMTPS, 'ssl'), true, ['to' => self::MAILBOX, 'subject' => $subject]);

        $this->assertTrue($result['success'] ?? false, json_encode($result));
        $this->assertTrue($this->greenMailHas($subject), 'the implicit-TLS send was not delivered');
    }

    public function testSslAgainstAPlaintextListenerFailsInsteadOfSendingInClear(): void
    {
        $subject = $this->subject('ssl-plain');
        $result = $this->child('send', $this->smtp(self::SMTP_PLAIN, 'ssl'), true, ['to' => self::MAILBOX, 'subject' => $subject]);

        $this->assertFalse($result['success'] ?? true, 'ssl on a plaintext-only listener must not send: ' . json_encode($result));
        $this->assertFalse($this->greenMailHas($subject, 1), 'the message was delivered in clear');
    }

    public function testTlsAndStarttlsDeliverOverStartTls(): void
    {
        foreach (['tls', 'starttls', ' STARTTLS '] as $encryption) {
            $subject = $this->subject('starttls');
            $result = $this->child('send', $this->smtp(self::SMTP_STARTTLS, $encryption), true, ['to' => 'rcpt@tina4.test', 'subject' => $subject]);

            $this->assertTrue($result['success'] ?? false, "encryption '{$encryption}': " . json_encode($result));
            $this->assertTrue($this->mailpitHas($subject), "encryption '{$encryption}': not delivered");
        }
    }

    public function testTlsAgainstAServerWithoutStartTlsFailsBeforeAuth(): void
    {
        foreach (['tls', 'starttls'] as $encryption) {
            $subject = $this->subject('tls-no-starttls');
            $result = $this->child('send', $this->smtp(self::SMTP_PLAIN, $encryption), true, ['to' => self::MAILBOX, 'subject' => $subject]);

            $this->assertFalse($result['success'] ?? true, "encryption '{$encryption}' sent without STARTTLS");
            $this->assertSame(
                "SMTP error: STARTTLS was requested but {$this->host}:" . self::SMTP_PLAIN . ' does not offer it',
                $result['message']
            );
            $this->assertFalse($this->greenMailHas($subject, 1), 'the message was delivered in clear');
        }
    }

    public function testNoneNeverUpgradesSoAStartTlsOnlyServerRefuses(): void
    {
        $subject = $this->subject('none');
        $result = $this->child('send', $this->smtp(self::SMTP_STARTTLS, 'none'), true, ['to' => 'rcpt@tina4.test', 'subject' => $subject]);

        $this->assertFalse($result['success'] ?? true, json_encode($result));
        $this->assertFalse($this->mailpitHas($subject, 1));
    }

    // ── SMTP: section 3, certificates always verified ─────────────────────

    public function testStartTlsToAnUntrustedCertificateFails(): void
    {
        $subject = $this->subject('starttls-untrusted');
        $result = $this->child('send', $this->smtp(self::SMTP_STARTTLS, 'starttls'), false, ['to' => 'rcpt@tina4.test', 'subject' => $subject]);

        $this->assertFalse($result['success'] ?? true, 'an untrusted certificate was accepted');
        $this->assertMatchesRegularExpression('/certificate verify failed/i', $result['message']);
        $this->assertFalse($this->mailpitHas($subject, 1));
    }

    public function testImplicitTlsToAnUntrustedCertificateFails(): void
    {
        $subject = $this->subject('ssl-untrusted');
        $result = $this->child('send', $this->smtp(self::SMTPS, 'ssl'), false, ['to' => self::MAILBOX, 'subject' => $subject]);

        $this->assertFalse($result['success'] ?? true, 'an untrusted certificate was accepted');
        $this->assertMatchesRegularExpression('/certificate verify failed/i', $result['message']);
        $this->assertFalse($this->greenMailHas($subject, 1));
    }

    public function testACertificateForAnotherHostNameFailsEvenWhenItsCaIsTrusted(): void
    {
        // "127.1" is 127.0.0.1 to the resolver, so this is the SAME server, but
        // the certificate names localhost and 127.0.0.1, not "127.1".
        $subject = $this->subject('wrong-name');
        $result = $this->child('send', $this->smtp(self::SMTP_STARTTLS, 'starttls', '127.1'), true, ['to' => 'rcpt@tina4.test', 'subject' => $subject]);

        $this->assertFalse($result['success'] ?? true, 'a certificate for another host name was accepted');
        $this->assertMatchesRegularExpression('/did not match expected CN|peer certificate|certificate/i', $result['message']);
        $this->assertFalse($this->mailpitHas($subject, 1));
    }

    // ── IMAP: section 3 ───────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function imap(int $port, string $encryption, string $username, string $password): array
    {
        return [
            'imapHost' => $this->imapHost(),
            'imapPort' => $port,
            'imapUsername' => $username,
            'imapPassword' => $password,
            'imapEncryption' => $encryption,
        ];
    }

    public function testImapsVerifiesTheCertificate(): void
    {
        $options = $this->imap(self::IMAPS, 'tls', self::USERNAME, self::PASSWORD);

        $trusted = $this->child('unread', $options, true);
        $this->assertArrayHasKey('unread', $trusted, json_encode($trusted));

        $untrusted = $this->child('unread', $options, false);
        $this->assertSame(\Tina4\MessengerConnectionError::class, $untrusted['error_class'] ?? null, json_encode($untrusted));
        $this->assertMatchesRegularExpression('/Certificate failure/i', $untrusted['error']);
    }

    public function testImapStartTlsUpgradesAndVerifiesTheCertificate(): void
    {
        $options = $this->imap(self::IMAP_STARTTLS, 'starttls', 'php' . bin2hex(random_bytes(4)), 'pass');

        $trusted = $this->child('folders', $options, true);
        $this->assertContains('INBOX', $trusted['folders'] ?? [], json_encode($trusted));

        $untrusted = $this->child('folders', $options, false);
        $this->assertSame(\Tina4\MessengerConnectionError::class, $untrusted['error_class'] ?? null, json_encode($untrusted));
        $this->assertMatchesRegularExpression('/Certificate failure/i', $untrusted['error']);
    }

    public function testImapStartTlsToAServerThatDoesNotOfferItFails(): void
    {
        $result = $this->child('unread', $this->imap(self::IMAP_PLAIN, 'starttls', self::USERNAME, self::PASSWORD), true);

        $this->assertSame(\Tina4\MessengerConnectionError::class, $result['error_class'] ?? null, json_encode($result));
    }

    public function testImapNoneNeverUpgrades(): void
    {
        // Dovecot offers STARTTLS and refuses LOGIN in clear. 'none' must not
        // upgrade, so the login is refused -- where plain /imap would have
        // negotiated TLS behind the caller's back.
        $result = $this->child('folders', $this->imap(self::IMAP_STARTTLS, 'none', 'php' . bin2hex(random_bytes(4)), 'pass'), true);

        $this->assertSame(\Tina4\MessengerConnectionError::class, $result['error_class'] ?? null, json_encode($result));
        $this->assertMatchesRegularExpression('/LOGIN|authenticat/i', $result['error']);
    }

    // ── Section 2: an unknown value is refused (pure logic, no service) ───

    public function testAnUnknownEncryptionRaisesAtConstruction(): void
    {
        foreach (['tsl', 'ssl3', 'yes', '', '   '] as $value) {
            try {
                new Messenger(host: 'mail.example.invalid', encryption: $value);
                $this->fail("encryption '{$value}' was accepted");
            } catch (\InvalidArgumentException $error) {
                $this->assertSame(
                    "Unknown mail encryption '{$value}'. Valid values: ssl, tls, starttls, none.",
                    $error->getMessage()
                );
            }
        }
    }

    public function testAnUnknownEncryptionFromTheEnvironmentRaises(): void
    {
        putenv('TINA4_MAIL_ENCRYPTION=starttsl');
        try {
            new Messenger(host: 'mail.example.invalid');
            $this->fail('TINA4_MAIL_ENCRYPTION=starttsl was accepted');
        } catch (\InvalidArgumentException $error) {
            $this->assertSame("Unknown mail encryption 'starttsl'. Valid values: ssl, tls, starttls, none.", $error->getMessage());
        } finally {
            putenv('TINA4_MAIL_ENCRYPTION');
        }
    }

    public function testAnUnknownImapEncryptionRaisesAtConstruction(): void
    {
        foreach (['tsl', 'imaps', ''] as $value) {
            try {
                new Messenger(imapHost: 'mail.example.invalid', imapEncryption: $value);
                $this->fail("IMAP encryption '{$value}' was accepted");
            } catch (\InvalidArgumentException $error) {
                $this->assertSame(
                    "Unknown IMAP encryption '{$value}'. Valid values: ssl, tls, starttls, none.",
                    $error->getMessage()
                );
            }
        }
    }

    public function testImapSslIsAcceptedAsImplicitTls(): void
    {
        $this->assertSame('ssl', (new Messenger(imapHost: 'mail.example.invalid', imapEncryption: ' SSL '))->getImapEncryption());
    }

    public function testValidValuesAreTrimmedAndCaseInsensitive(): void
    {
        foreach (['SSL', ' tls ', 'StartTLS', 'NONE'] as $value) {
            $this->assertInstanceOf(Messenger::class, new Messenger(host: 'mail.example.invalid', encryption: $value));
        }
    }
}
