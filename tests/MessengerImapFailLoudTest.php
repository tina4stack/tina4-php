<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Lock-in tests for the IMAP fail-loud contract (parity with the Python master):
 *
 *   inbox()/read()/unread()/search()/folders() must LOG and RAISE
 *   (MessengerConnectionError) on a connection/auth/protocol failure — they may
 *   NOT swallow it into an empty result. A SUCCESSFUL fetch that simply has no
 *   messages still returns empty ([] / null / 0) normally — that is NOT an
 *   error.
 *
 * Every failure here is a REAL refused TCP connection to 127.0.0.1:1. Nothing
 * on this box listens on port 1, so imap_open genuinely fails and the raise is
 * the framework's own, not a scripted one.
 *
 * This file previously declared namespaced imap_open/imap_errors/imap_close/
 * imap_check/imap_fetch_overview/imap_msgno/imap_headerinfo/imap_setflag_full
 * stubs, diverted by $GLOBALS['__tina4_imap_stub'], to drive the empty-mailbox
 * and "server said no" paths without a server. Those were test doubles for a
 * real dependency, which the no-mock rule forbids outright, and they cost real
 * coverage: not one of them ever proved a message could be SENT and read back,
 * because a stub returns whatever the test told it to. The empty-mailbox and
 * protocol-failure paths now run against a real GreenMail in
 * MessengerImapGreenMailTest. The stubs also leaked: being declared in the
 * Tina4 namespace, they shadowed imap_* for EVERY test in the same process.
 *
 * @see MessengerImapGreenMailTest for the real-server round-trip coverage.
 */

namespace Tina4;

use PHPUnit\Framework\TestCase;

class MessengerImapFailLoudTest extends TestCase
{
    /**
     * A Messenger pointed at a refused port — imap_open fails fast.
     *
     * Port 1 (tcpmux) is privileged and unused; a connect() to it is refused
     * immediately rather than hanging until a timeout, which keeps these tests
     * fast without a stub.
     */
    private function refusedMessenger(): Messenger
    {
        return new Messenger(
            imapHost: '127.0.0.1',
            imapPort: 1,
            username: 'u',
            password: 'p',
        );
    }

    // ── Connection failure must RAISE (not return empty) ─────────────────

    public function testInboxRaisesOnConnectionFailure(): void
    {
        $this->expectException(MessengerConnectionError::class);
        $this->refusedMessenger()->inbox();
    }

    public function testReadRaisesOnConnectionFailure(): void
    {
        $this->expectException(MessengerConnectionError::class);
        $this->refusedMessenger()->read(1);
    }

    public function testUnreadRaisesOnConnectionFailure(): void
    {
        $this->expectException(MessengerConnectionError::class);
        $this->refusedMessenger()->unread();
    }

    public function testSearchRaisesOnConnectionFailure(): void
    {
        $this->expectException(MessengerConnectionError::class);
        $this->refusedMessenger()->search();
    }

    public function testFoldersRaisesOnConnectionFailure(): void
    {
        $this->expectException(MessengerConnectionError::class);
        $this->refusedMessenger()->folders();
    }

    public function testConnectionErrorIsRuntimeException(): void
    {
        // Subclasses \RuntimeException so existing catch (\Throwable)/
        // catch (\RuntimeException) handlers keep catching it.
        try {
            $this->refusedMessenger()->inbox();
            $this->fail('expected MessengerConnectionError');
        } catch (\RuntimeException $e) {
            $this->assertInstanceOf(MessengerConnectionError::class, $e);
        }
    }

    /**
     * The raise must NOT be a silent empty result.
     *
     * The negative half of the contract: it is not enough that inbox() throws
     * SOMETHING — the whole point of the fail-loud change was that it used to
     * return [] and callers could not tell "no mail" from "server down". Assert
     * the message names the cause so a future refactor cannot regress to an
     * empty-result swallow that happens to also throw somewhere else.
     */
    public function testConnectionFailureMessageNamesTheCause(): void
    {
        try {
            $this->refusedMessenger()->inbox();
            $this->fail('expected MessengerConnectionError');
        } catch (MessengerConnectionError $e) {
            $this->assertStringContainsStringIgnoringCase(
                'imap',
                $e->getMessage(),
                'the error should name the failing subsystem, got: ' . $e->getMessage(),
            );
        }
    }

    public function testMissingImapHostRaises(): void
    {
        // No IMAP host configured at all — also a connection failure, fail loud.
        $m = new Messenger(host: 'smtp.example.com', port: 587, imapHost: null);

        $this->expectException(MessengerConnectionError::class);
        $m->inbox();
    }
}
