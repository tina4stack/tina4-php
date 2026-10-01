<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Session;
use Tina4\Session\RedisSessionHandler;

/**
 * A request's save must not undo what another request did to the same session.
 *
 * Every request loads its session when it starts and saves it when it ends
 * (Router::dispatch: a fresh Session, start() from the cookie, save() after the
 * handler). The save used to write back the WHOLE snapshot loaded at the start,
 * and it ran on every request, changed or not. So any request that was in
 * flight across a logout put the logged-out session back when it ended:
 * destroy(), clear() and regenerate() were all undone, and so was a privilege
 * change made with set(). A copied session cookie outlived the logout that was
 * meant to kill it.
 *
 * The save now writes only this request's own changes onto the record as it is
 * stored NOW, and never re-creates a record removed after this request loaded
 * it. An unchanged session is still written, because that write is what keeps
 * expiry measured from the last request (docs: "Expires after 1 hour of
 * inactivity").
 *
 * Two Session objects over one directory stand in for two concurrent requests.
 * NO MOCKS: a real temp directory, and every outcome is read straight off disk,
 * never through the code under test.
 */
class SessionConcurrentRequestsTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tina4-sess-concurrent-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    /** What the router does at the start of a request: a fresh Session, started from the cookie. */
    private function request(?string $sessionId = null): Session
    {
        $session = new Session('file', ['path' => $this->dir, 'ttl' => 3600]);
        $session->start($sessionId);
        return $session;
    }

    private function loggedIn(array $more = []): string
    {
        $session = $this->request();
        $session->set('user', 'alice');
        foreach ($more as $key => $value) {
            $session->set($key, $value);
        }
        return $session->getSessionId();
    }

    /**
     * The record on disk for $sessionId, _meta aside, or null when there is none.
     * Read straight from the file (sha256(id).json), not through Session.
     */
    private function stored(string $sessionId): ?array
    {
        $file = $this->dir . '/' . hash('sha256', $sessionId) . '.json';
        if (!is_file($file)) {
            return null;
        }
        $record = json_decode((string) file_get_contents($file), true);
        $this->assertIsArray($record, 'a session file on disk must hold a JSON object');
        unset($record['_meta']);
        return $record;
    }

    private function storedExpiry(string $sessionId): int
    {
        $file = $this->dir . '/' . hash('sha256', $sessionId) . '.json';
        $record = json_decode((string) file_get_contents($file), true);
        return (int) ($record['_meta']['expires_at'] ?? 0);
    }

    // ── a request in flight must not undo a logout ─────────────────────────

    public function testDestroyStaysDestroyed(): void
    {
        $sid = $this->loggedIn();
        $slow = $this->request($sid);
        $this->request($sid)->destroy();

        $slow->set('cart', 'one item');

        $this->assertNull($this->stored($sid), 'a request that loaded the session before the logout re-created it');
        $this->assertSame('', $slow->getSessionId(), 'the session must end for the slow request too, so no cookie goes out for it');
    }

    public function testDestroyStaysDestroyedWhenTheSlowRequestOnlyRead(): void
    {
        $sid = $this->loggedIn();
        $slow = $this->request($sid);
        $slow->get('user');
        $this->request($sid)->destroy();

        $this->assertTrue($slow->save(), 'the router saves every request; nothing to write is not a failure');

        $this->assertNull($this->stored($sid), 'a read-only request re-created the logged-out session when it ended');
    }

    public function testClearStaysCleared(): void
    {
        $sid = $this->loggedIn();
        $slow = $this->request($sid);
        $this->request($sid)->clear();

        $slow->set('cart', 'one item');
        $slow->save();

        $this->assertSame(['cart' => 'one item'], $this->stored($sid), 'clear() was undone: the slow request wrote its old user back');
    }

    public function testRegenerateDoesNotBringTheOldIdBack(): void
    {
        $sid = $this->loggedIn();
        $slow = $this->request($sid);
        $newId = $this->request($sid)->regenerate();

        $slow->set('cart', 'one item');
        $slow->save();

        $this->assertNull($this->stored($sid), 'the id regenerate() retired was brought back');
        $this->assertSame(['user' => 'alice'], $this->stored($newId));
    }

    public function testADowngradeMadeWithSetStaysDown(): void
    {
        $sid = $this->loggedIn();
        $slow = $this->request($sid);
        $this->request($sid)->set('user', 'nobody');

        $slow->set('cart', 'one item');
        $slow->save();

        $this->assertSame(['user' => 'nobody', 'cart' => 'one item'], $this->stored($sid));
    }

    public function testADowngradeStaysDownWhenTheSlowRequestOnlyRead(): void
    {
        $sid = $this->loggedIn();
        $slow = $this->request($sid);
        $this->request($sid)->set('user', 'nobody');

        $slow->save();

        $this->assertSame(['user' => 'nobody'], $this->stored($sid));
    }

    public function testTheSessionStaysEndedForTheRestOfTheSlowRequest(): void
    {
        $sid = $this->loggedIn();
        $slow = $this->request($sid);
        $this->request($sid)->destroy();

        $slow->set('cart', 'one item');
        $slow->set('more', 'after');
        $slow->save();

        $this->assertNull($this->stored($sid));
    }

    // ── a regenerate in flight does not carry an ended session ────────────
    //
    // The slow request calls regenerate() at its end (a privilege change, an SSO
    // callback) after another request ended or changed the session. What it
    // loaded must not reach the new id.

    /** The user of every session record on disk, read from the files themselves. */
    private function usersStored(): array
    {
        $users = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $record = json_decode((string) file_get_contents($file), true);
            $users[] = $record['user'] ?? null;
        }
        return $users;
    }

    public function testARegenerateInFlightDoesNotCarryADestroyedSession(): void
    {
        $sid = $this->loggedIn();
        $slow = $this->request($sid);
        $this->request($sid)->destroy();

        $this->assertSame('', $slow->regenerate(), 'the session had ended; regenerate() must not mint an id for it');
        $this->assertSame('', $slow->getSessionId(), 'so no cookie goes out for it');
        $this->assertNotContains('alice', $this->usersStored(), 'the logged-out session was carried to a new id');
    }

    public function testARegenerateInFlightDoesNotCarryAClearedSession(): void
    {
        $sid = $this->loggedIn();
        $slow = $this->request($sid);
        $this->request($sid)->clear();

        $slow->regenerate();

        $this->assertNotContains('alice', $this->usersStored(), 'the cleared session was carried to a new id');
    }

    public function testARegenerateInFlightCarriesADowngradeWithItsOwnChange(): void
    {
        $sid = $this->loggedIn();
        $slow = $this->request($sid);
        $slow->set('cart', 'one item');
        $this->request($sid)->set('user', 'nobody');

        $newId = $slow->regenerate();

        $this->assertSame(['user' => 'nobody', 'cart' => 'one item'], $this->stored($newId));
        $this->assertNull($this->stored($sid));
    }

    public function testADoubleSubmittedLoginKeepsTheLoginThatWon(): void
    {
        // Both requests carry the same pre-login cookie. The first to finish
        // rotates the id; the other must not mint a second, empty session whose
        // cookie would replace it.
        $anon = $this->request();
        $anon->set('pending', 'state');
        $first = $this->request($anon->getSessionId());
        $second = $this->request($anon->getSessionId());

        $second->set('user', 'alice');
        $winner = $second->regenerate();
        $first->set('user', 'alice');
        $first->save();

        $this->assertSame('', $first->regenerate());
        $this->assertSame('', $first->getSessionId(), 'no cookie may go out to replace the winning login');
        $this->assertSame(['pending' => 'state', 'user' => 'alice'], $this->stored($winner));
        $this->assertSame(['alice'], $this->usersStored());
    }

    public function testADoubleSubmittedLoginInTheDocumentedOrderKeepsTheWinner(): void
    {
        // docs/php/09-sessions-cookies.md: regenerate(), then set the user.
        $anon = $this->request();
        $anon->set('pending', 'state');
        $first = $this->request($anon->getSessionId());
        $second = $this->request($anon->getSessionId());

        $winner = $second->regenerate();
        $second->set('user', 'alice');

        $this->assertSame('', $first->regenerate());
        $first->set('user', 'alice');
        $first->save();

        $this->assertSame('', $first->getSessionId(), 'no cookie may go out to replace the winning login');
        $this->assertSame(['pending' => 'state', 'user' => 'alice'], $this->stored($winner));
        $this->assertSame(['alice'], $this->usersStored());
    }

    public function testTheDocumentedLoginStoresTheUserUnderTheNewId(): void
    {
        // docs/php/09-sessions-cookies.md: regenerate(), then set the user.
        $anon = $this->request();
        $anon->set('csrf', 'token');
        $session = $this->request($anon->getSessionId());

        $newId = $session->regenerate();
        $session->set('user_id', 42);
        $session->save();

        $this->assertSame(['csrf' => 'token', 'user_id' => 42], $this->stored($newId));
        $this->assertNull($this->stored($anon->getSessionId()));
    }

    public function testRegenerateAfterThisRequestsOwnDestroyStillStartsAfresh(): void
    {
        $sid = $this->loggedIn();
        $session = $this->request($sid);
        $session->destroy();

        $newId = $session->regenerate();

        $this->assertNotSame('', $newId);
        $this->assertNotSame($sid, $newId);
        $this->assertNull($this->stored($sid));
    }

    // ── concurrent requests keep each other's changes ─────────────────────

    public function testTwoRequestsSettingDifferentKeysBothPersist(): void
    {
        $sid = $this->loggedIn();
        $first = $this->request($sid);
        $second = $this->request($sid);

        $first->set('theme', 'dark');
        $second->set('cart', 'one item');
        $first->save();
        $second->save();

        $this->assertSame(['user' => 'alice', 'theme' => 'dark', 'cart' => 'one item'], $this->stored($sid));
    }

    public function testAKeyAnotherRequestDeletedStaysDeleted(): void
    {
        $sid = $this->loggedIn(['mfa' => 'verified']);
        $slow = $this->request($sid);
        $this->request($sid)->delete('mfa');

        $slow->set('cart', 'one item');

        $this->assertSame(['user' => 'alice', 'cart' => 'one item'], $this->stored($sid));
    }

    public function testClearAlsoRemovesKeysAnotherRequestAdded(): void
    {
        $sid = $this->loggedIn();
        $logout = $this->request($sid);
        $this->request($sid)->set('mfa', 'verified');

        $logout->clear();

        $this->assertSame([], $this->stored($sid));
    }

    // ── a save still writes what the request changed ───────────────────────

    public function testAnUnchangedSessionIsStillWrittenSoExpiryCountsFromTheLastRequest(): void
    {
        $sid = $this->loggedIn();
        $file = $this->dir . '/' . hash('sha256', $sid) . '.json';
        $record = json_decode((string) file_get_contents($file), true);
        $record['_meta']['expires_at'] = time() + 5;   // about to expire
        file_put_contents($file, json_encode($record));

        $this->request($sid)->save();   // a request that touched nothing

        $this->assertGreaterThan(time() + 3000, $this->storedExpiry($sid), 'a read-only request must move the expiry forward');
        $this->assertSame(['user' => 'alice'], $this->stored($sid));
    }

    public function testASecondSaveWritesOnlyWhatChangedSinceTheFirst(): void
    {
        $sid = $this->loggedIn();
        $session = $this->request($sid);
        $session->set('theme', 'dark');
        $other = $this->request($sid);
        $other->set('user', 'nobody');
        $other->set('theme', 'light');

        $session->set('cart', 'one item');

        $this->assertSame(['user' => 'nobody', 'theme' => 'light', 'cart' => 'one item'], $this->stored($sid));
    }

    public function testANewSessionsLaterSaveDoesNotBringItBackOnceDestroyed(): void
    {
        $session = $this->request();
        $session->set('user', 'alice');
        $sid = $session->getSessionId();
        $this->request($sid)->destroy();

        $session->set('cart', 'one item');
        $session->save();

        $this->assertNull($this->stored($sid));
    }

    public function testASaveAfterClearAndASaveMergesAgain(): void
    {
        $sid = $this->loggedIn();
        $session = $this->request($sid);
        $session->clear();
        $session->set('theme', 'dark');
        $this->request($sid)->set('mfa', 'verified');

        $session->set('cart', 'one item');

        $this->assertSame(['theme' => 'dark', 'mfa' => 'verified', 'cart' => 'one item'], $this->stored($sid));
    }

    public function testASetAfterClearAndASaveIsStillStored(): void
    {
        $sid = $this->loggedIn();
        $session = $this->request($sid);
        $session->clear();
        $session->save();

        $session->set('cart', 'one item');
        $session->save();

        $this->assertSame(['cart' => 'one item'], $this->stored($sid));
    }

    public function testASetAfterRegeneratingAnEmptiedSessionIsStillStored(): void
    {
        // The SSO callback: consume the pending state, regenerate, store the identity.
        $anon = $this->request();
        $anon->set('pending', 'state');
        $session = $this->request($anon->getSessionId());
        $session->delete('pending');
        $newId = $session->regenerate();

        $session->set('user', 'alice');
        $session->save();

        $this->assertSame(['user' => 'alice'], $this->stored($newId));
        $this->assertSame($newId, $session->getSessionId());
    }

    public function testClearThenSetInOneRequestWritesOnlyTheNewData(): void
    {
        $sid = $this->loggedIn(['theme' => 'dark']);
        $session = $this->request($sid);
        $session->clear();
        $session->set('user', 'bob');

        $this->assertSame(['user' => 'bob'], $this->stored($sid));
    }

    public function testANewSessionIsWrittenWhole(): void
    {
        $session = $this->request();
        $session->set('user', 'alice');
        $session->set('theme', 'dark');

        $this->assertSame(['user' => 'alice', 'theme' => 'dark'], $this->stored($session->getSessionId()));
    }

    public function testRegenerateCarriesEverythingToTheNewId(): void
    {
        $sid = $this->loggedIn(['theme' => 'dark']);
        $newId = $this->request($sid)->regenerate();

        $this->assertSame(['user' => 'alice', 'theme' => 'dark'], $this->stored($newId));
        $this->assertNull($this->stored($sid));
    }

    public function testARawWriteStillStoresExactlyItsData(): void
    {
        // From a request on a stored session, whose own merge and has-it-gone
        // checks describe a different record from the one being written.
        $session = $this->request($this->loggedIn());
        $session->write('chosen-id', ['user' => 'carol']);

        $this->assertSame(['user' => 'carol'], $this->stored('chosen-id'));
    }

    // ── a store that cannot be read at save time ───────────────────────────

    public function testNothingIsWrittenWhenTheRecordCannotBeReadThoughTheStoreTakesWrites(): void
    {
        $sid = $this->loggedIn();
        $session = $this->request($sid);
        $file = $this->dir . '/' . hash('sha256', $sid) . '.json';
        $good = (string) file_get_contents($file);

        // The record turns unreadable between start() and save() while writes
        // still work: a real file the real loader fails on (a _meta that is not
        // an object makes fileRecordHasExpired() throw).
        $record = json_decode($good, true);
        $record['_meta'] = 'not an object';
        $unreadable = json_encode($record);
        file_put_contents($file, $unreadable);

        $session->set('cart', 'one item');
        $this->assertFalse($session->save(), 'a save that could not read the record must report failure');
        $this->assertSame($unreadable, file_get_contents($file), 'nothing may be written while the record cannot be read');

        file_put_contents($file, $good);
        $this->assertTrue($session->save(), 'the retained change must be written once the record reads again');
        $this->assertSame(['user' => 'alice', 'cart' => 'one item'], $this->stored($sid));
    }

    public function testNothingIsWrittenWhenTheStoreCannotBeReadAndTheChangeIsKeptForARetry(): void
    {
        $sid = $this->loggedIn();
        $session = $this->request($sid);

        // The store becomes unreachable between start() and save(): the REAL
        // redis handler, pointed at a port the kernel really refuses.
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($probe, false);
        fclose($probe);
        $refused = new RedisSessionHandler(['host' => '127.0.0.1', 'port' => (int) substr($name, strrpos($name, ':') + 1), 'ttl' => 60]);
        $reflection = new \ReflectionClass($session);
        $reflection->getProperty('redisHandler')->setValue($session, $refused);
        $reflection->getProperty('backend')->setValue($session, 'redis');

        $session->set('cart', 'one item');
        $this->assertFalse($session->save(), 'a save that could not read the store must report failure');

        $reflection->getProperty('backend')->setValue($session, 'file');
        $this->assertSame(['user' => 'alice'], $this->stored($sid), 'nothing may be written while the store cannot be read');

        $this->assertTrue($session->save(), 'the retained change must be written once the store answers again');
        $this->assertSame(['user' => 'alice', 'cart' => 'one item'], $this->stored($sid));
    }
}
