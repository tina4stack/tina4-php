<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use TestServer;
use Tina4\Messenger;

/**
 * What Messenger puts on the wire BEFORE it offers the message.
 *
 * Two decisions were wrong, and neither was visible in send()'s return value,
 * which is why they survived. Both were found against a live Postfix on
 * 26 August 2026 while moving an application off a flaky third-party relay and
 * onto a local queueing MTA — the thing you would reach for precisely because
 * it retries, and the thing Messenger could not talk to.
 *
 *   1. AUTH LOGIN was sent unconditionally. The guard read
 *      `$this->username !== null && $this->password !== null` against two
 *      properties typed `private string` that default to ''. Neither is ever
 *      null, so a Messenger with no credentials at all still sent AUTH LOGIN
 *      with two empty base64 strings. Against a relay that trusts loopback and
 *      wants no credentials, the far end rejected the handshake and the mail
 *      never left. The observed symptom was a bare
 *      "454 4.7.0 Temporary authentication failure", which reads like a
 *      credential problem and sends you looking in the wrong place.
 *
 *   2. STARTTLS was gated on `$this->port === 587`. A caller asking for
 *      encryption on any other port silently got none — credentials in clear,
 *      and send() still returned success. Submission on 25 and 2525 is
 *      ordinary, so this was not a corner case.
 *
 * NO MOCKS. Each test starts a real listener (fixtures/smtp_negotiation_server
 * .php), Messenger speaks real SMTP to it over a real socket, and the
 * assertions are made against the transcript the server recorded. Nothing here
 * inspects Messenger's private state or stubs a socket: a test that asserted
 * "the guard is now `!== ''`" would pass just as happily if the guard were
 * never reached.
 *
 * Every behaviour is pinned from BOTH sides — the command is sent when it
 * should be AND absent when it should not. A test that only checks the absence
 * of AUTH LOGIN passes on a Messenger that has stopped talking altogether.
 */
class MessengerSmtpNegotiationTest extends TestCase
{
    private ?TestServer $server = null;
    private string $transcript = '';

    protected function setUp(): void
    {
        $this->transcript = tempnam(sys_get_temp_dir(), 'tina4-smtp-transcript-');

        // A configured host means send(), not DevMailbox capture. These have to
        // be off explicitly: inherited from the developer's own environment
        // they would divert the message into a folder and every assertion below
        // would be made against a transcript nothing ever wrote to.
        putenv('TINA4_MAIL_CAPTURE=0');
        putenv('TINA4_MAIL_REDIRECT_TO');
        putenv('TINA4_MAIL_HOST');
        putenv('TINA4_MAIL_USERNAME');
        putenv('TINA4_MAIL_PASSWORD');
        putenv('TINA4_MAIL_ENCRYPTION');
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;

        if ($this->transcript !== '' && is_file($this->transcript)) {
            unlink($this->transcript);
        }

        putenv('TINA4_MAIL_CAPTURE');
    }

    /**
     * Start the recording SMTP server advertising exactly $capabilities, and
     * return the port it is on.
     */
    private function startServer(string $capabilities): int
    {
        $this->server = TestServer::startScript(
            __DIR__ . '/fixtures/smtp_negotiation_server.php',
            [$this->transcript, $capabilities]
        );

        $port = parse_url($this->server->base(), PHP_URL_PORT);
        self::assertIsInt($port, 'the test server did not report a port');

        // The port is whatever the OS handed out. If it ever WERE 587 the
        // STARTTLS tests would pass for the old reason rather than the new one,
        // and would stop testing anything.
        self::assertNotSame(587, $port, 'ephemeral port collided with submission');

        return $port;
    }

    /** What the client actually put on the wire. */
    private function transcript(): string
    {
        return is_file($this->transcript) ? (string)file_get_contents($this->transcript) : '';
    }

    private function messenger(int $port, string $username = '', string $encryption = 'none'): Messenger
    {
        return new Messenger(
            host: '127.0.0.1',
            port: $port,
            username: $username,
            password: $username === '' ? '' : 'irrelevant-to-this-test',
            fromAddress: 'sender@tina4.test',
            fromName: 'Tina4 Test',
            encryption: $encryption,
        );
    }

    private function send(Messenger $messenger): array
    {
        return $messenger->send(
            to: 'recipient@tina4.test',
            subject: 'negotiation',
            body: 'body',
        );
    }

    // ---------------------------------------------------------------- AUTH

    public function testNoCredentialsMeansNoAuthCommand(): void
    {
        $port = $this->startServer('AUTH');

        $result = $this->send($this->messenger($port));

        self::assertTrue(
            $result['success'],
            'send failed with no credentials against a server that wants none: ' . $result['message']
        );

        $transcript = $this->transcript();
        self::assertStringNotContainsString('AUTH LOGIN', $transcript);
        self::assertStringContainsString('MAIL FROM', $transcript, 'the message was never offered');
        self::assertStringContainsString('DATA-END', $transcript, 'the body was never delivered');
    }

    public function testCredentialsAreStillSent(): void
    {
        $port = $this->startServer('AUTH');

        $result = $this->send($this->messenger($port, 'someone@tina4.test'));

        self::assertTrue($result['success'], $result['message']);

        $transcript = $this->transcript();
        self::assertStringContainsString('AUTH LOGIN', $transcript);
        self::assertStringContainsString(
            'AUTH-USERNAME ' . base64_encode('someone@tina4.test'),
            $transcript,
            'the username on the wire is not the one that was configured'
        );
    }

    // ------------------------------------------------------------- STARTTLS

    public function testStartTlsIsIssuedOnAPortThatIsNot587(): void
    {
        $port = $this->startServer('STARTTLS,AUTH');

        $result = $this->send($this->messenger($port, '', 'tls'));

        self::assertStringContainsString(
            'STARTTLS',
            $this->transcript(),
            'encryption was asked for and the server offered it, but the client never asked to upgrade'
        );

        // The fixture answers 220 and then drops rather than completing a real
        // handshake, so the send cannot succeed. That is honest: the client is
        // told the channel could not be secured instead of quietly sending in
        // clear, which is the whole point of the change.
        self::assertFalse($result['success']);
        self::assertStringContainsString('STARTTLS', $result['message']);
    }

    public function testStartTlsIsSkippedWhenTheServerDoesNotOfferIt(): void
    {
        $port = $this->startServer('AUTH');

        $result = $this->send($this->messenger($port, '', 'tls'));

        // 'tls' is opportunistic: upgrade where possible, deliver where not.
        // Failing here would break every application already pointed at a plain
        // local MTA, which is the setup this change exists to enable.
        self::assertTrue($result['success'], $result['message']);
        self::assertStringNotContainsString('STARTTLS', $this->transcript());
    }

    public function testExplicitStarttlsFailsLoudlyWhenNotOffered(): void
    {
        $port = $this->startServer('AUTH');

        $result = $this->send($this->messenger($port, '', 'starttls'));

        // A caller who named starttls asked for encryption specifically. Sending
        // their message in clear because the server was not configured for it
        // would be the silent downgrade this change removes.
        self::assertFalse($result['success'], 'mail was sent unencrypted after starttls was demanded');
        self::assertStringContainsString('STARTTLS', $result['message']);
        self::assertStringNotContainsString('MAIL FROM', $this->transcript());
    }
}
