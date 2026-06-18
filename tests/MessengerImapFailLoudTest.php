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
 *   messages still returns empty ([] / null / 0 / {}) normally — that is NOT an
 *   error.
 *
 * The IMAP layer is exercised two ways:
 *   1. Live: a Messenger pointed at a refused host (127.0.0.1:1) — imap_open
 *      fails fast, so inbox()/read()/unread()/search()/folders() must RAISE.
 *   2. Stubbed: the imap_* globals are overridden inside the Tina4 namespace
 *      (PHP resolves an unqualified imap_* call to the namespaced function
 *      first) so we can drive the empty-mailbox and protocol-failure code paths
 *      deterministically without a real server. The stubs are transparent by
 *      default and only divert when $GLOBALS['__tina4_imap_stub'] is set.
 */

namespace Tina4;

// ── Namespaced imap_* stubs ──────────────────────────────────────────────
// Unqualified imap_* calls inside Tina4\Messenger resolve to these first.
// When the stub flag is unset, they delegate to the real global functions so
// the live refused-connection tests (and any other Messenger test) are
// unaffected.

if (!function_exists(__NAMESPACE__ . '\\imap_open')) {
    function imap_open(...$args)
    {
        if (!empty($GLOBALS['__tina4_imap_stub'])) {
            return $GLOBALS['__tina4_imap']['open'] ?? 'STUB_IMAP';
        }
        return \imap_open(...$args);
    }

    function imap_errors(...$args)
    {
        if (!empty($GLOBALS['__tina4_imap_stub'])) {
            return $GLOBALS['__tina4_imap']['errors'] ?? [];
        }
        return \imap_errors(...$args);
    }

    function imap_close(...$args)
    {
        if (!empty($GLOBALS['__tina4_imap_stub'])) {
            return true;
        }
        return \imap_close(...$args);
    }

    function imap_check(...$args)
    {
        if (!empty($GLOBALS['__tina4_imap_stub'])) {
            return $GLOBALS['__tina4_imap']['check'] ?? false;
        }
        return \imap_check(...$args);
    }

    function imap_fetch_overview(...$args)
    {
        if (!empty($GLOBALS['__tina4_imap_stub'])) {
            return $GLOBALS['__tina4_imap']['overview'] ?? [];
        }
        return \imap_fetch_overview(...$args);
    }

    function imap_msgno(...$args)
    {
        if (!empty($GLOBALS['__tina4_imap_stub'])) {
            return $GLOBALS['__tina4_imap']['msgno'] ?? 0;
        }
        return \imap_msgno(...$args);
    }

    function imap_headerinfo(...$args)
    {
        if (!empty($GLOBALS['__tina4_imap_stub'])) {
            return $GLOBALS['__tina4_imap']['header'] ?? false;
        }
        return \imap_headerinfo(...$args);
    }

    function imap_fetchstructure(...$args)
    {
        if (!empty($GLOBALS['__tina4_imap_stub'])) {
            return $GLOBALS['__tina4_imap']['structure'] ?? false;
        }
        return \imap_fetchstructure(...$args);
    }

    function imap_status(...$args)
    {
        if (!empty($GLOBALS['__tina4_imap_stub'])) {
            return $GLOBALS['__tina4_imap']['status'] ?? false;
        }
        return \imap_status(...$args);
    }

    function imap_search(...$args)
    {
        if (!empty($GLOBALS['__tina4_imap_stub'])) {
            return $GLOBALS['__tina4_imap']['search'] ?? false;
        }
        return \imap_search(...$args);
    }

    function imap_list(...$args)
    {
        if (!empty($GLOBALS['__tina4_imap_stub'])) {
            return $GLOBALS['__tina4_imap']['list'] ?? false;
        }
        return \imap_list(...$args);
    }

    function imap_setflag_full(...$args)
    {
        if (!empty($GLOBALS['__tina4_imap_stub'])) {
            return true;
        }
        return \imap_setflag_full(...$args);
    }
}

use PHPUnit\Framework\TestCase;

class MessengerImapFailLoudTest extends TestCase
{
    protected function tearDown(): void
    {
        // Always disable the stub so other test files keep hitting real imap_*.
        unset($GLOBALS['__tina4_imap_stub'], $GLOBALS['__tina4_imap']);
    }

    /** A Messenger pointed at a refused host — imap_open fails fast. */
    private function refusedMessenger(): Messenger
    {
        return new Messenger(
            imapHost: '127.0.0.1',
            imapPort: 1,
            username: 'u',
            password: 'p',
        );
    }

    /** Enable the imap_* stub with a canned return-value map. */
    private function stub(array $map): void
    {
        $GLOBALS['__tina4_imap_stub'] = true;
        $GLOBALS['__tina4_imap'] = array_merge(['open' => 'STUB_IMAP'], $map);
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

    public function testMissingImapHostRaises(): void
    {
        // No IMAP host configured at all — also a connection failure, fail loud.
        $m = new Messenger(host: 'smtp.example.com', port: 587, imapHost: null);
        $this->expectException(MessengerConnectionError::class);
        $m->inbox();
    }

    // ── Successful-but-empty fetch returns empty (NOT an error) ──────────

    public function testInboxEmptyMailboxReturnsEmpty(): void
    {
        // Connected fine, mailbox genuinely has 0 messages -> [].
        $info = new \stdClass();
        $info->Nmsgs = 0;
        $this->stub(['check' => $info]);

        $m = new Messenger(imapHost: 'imap.example.com', imapPort: 993, username: 'u', password: 'p');
        $this->assertSame([], $m->inbox());
    }

    public function testUnreadZeroReturnsZero(): void
    {
        $status = new \stdClass();
        $status->unseen = 0;
        $this->stub(['status' => $status]);

        $m = new Messenger(imapHost: 'imap.example.com', imapPort: 993, username: 'u', password: 'p');
        $this->assertSame(0, $m->unread());
    }

    public function testSearchNoMatchesReturnsEmpty(): void
    {
        // imap_search returns false when nothing matches — empty, not an error.
        $this->stub(['search' => false]);

        $m = new Messenger(imapHost: 'imap.example.com', imapPort: 993, username: 'u', password: 'p');
        $this->assertSame([], $m->search(subject: 'no-such-subject'));
    }

    public function testReadMissingUidReturnsNull(): void
    {
        // UID not found -> imap_msgno returns 0 -> read() returns null (Python {}).
        $this->stub(['msgno' => 0]);

        $m = new Messenger(imapHost: 'imap.example.com', imapPort: 993, username: 'u', password: 'p');
        $this->assertNull($m->read(99999));
    }

    // ── Non-OK / protocol failure mid-fetch must RAISE ───────────────────

    public function testInboxRaisesWhenCheckFails(): void
    {
        // Connected, but imap_check fails (false) -> protocol failure -> RAISE.
        $this->stub(['check' => false]);

        $m = new Messenger(imapHost: 'imap.example.com', imapPort: 993, username: 'u', password: 'p');
        $this->expectException(MessengerConnectionError::class);
        $m->inbox();
    }

    public function testUnreadRaisesWhenStatusFails(): void
    {
        $this->stub(['status' => false]);

        $m = new Messenger(imapHost: 'imap.example.com', imapPort: 993, username: 'u', password: 'p');
        $this->expectException(MessengerConnectionError::class);
        $m->unread();
    }

    public function testFoldersRaisesWhenListFails(): void
    {
        // A connected server always exposes at least INBOX — false is a failure.
        $this->stub(['list' => false]);

        $m = new Messenger(imapHost: 'imap.example.com', imapPort: 993, username: 'u', password: 'p');
        $this->expectException(MessengerConnectionError::class);
        $m->folders();
    }
}
