<?php

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Session\MemcachedSessionHandler;

/**
 * SESSION CONTRACT: a TTL is expressed in ONE unit by the caller, whatever the provider.
 *
 * ADR-0024: the developer writes against the CONTRACT, never the PROVIDER. A ttl
 * is a number of SECONDS. Each backend converts that to whatever its own wire
 * protocol needs. Providers differ; the contract must not.
 *
 * WHY THIS FILE EXISTS - the memcached 30-day cliff, MEASURED, not read.
 *
 * memcached's `set` command takes an `exptime` field with a documented dual
 * meaning: a value up to 2592000 (30 days) is RELATIVE seconds, and ANY LARGER
 * VALUE IS AN ABSOLUTE UNIX TIMESTAMP. Tina4 interpolated the caller's ttl into
 * that field raw, in all four frameworks, with no conversion anywhere - a grep
 * for 2592000 across python, php, ruby and nodejs returned ZERO hits on
 * 2026-08-04.
 *
 * So TINA4_SESSION_TTL=2592001 - "about a month", an entirely ordinary
 * remember-me setting - was sent as the absolute timestamp 2592001, which is
 * 1970-01-31. The item was already expired at the moment it was stored.
 * memcached still answers STORED, so the write looks successful and the very
 * next read is a miss. Measured against real memcached 1.6.45:
 *
 *     ttl=60        read -> the payload   SURVIVES
 *     ttl=2592000   read -> the payload   SURVIVES
 *     ttl=2592001   read -> []            VANISHED INSTANTLY
 *     ttl=4000000   read -> []            VANISHED INSTANTLY
 *
 * That is a silent logout on every request, from a config value that looks
 * perfectly reasonable, and nothing anywhere reports it.
 *
 * The fix CONVERTS a ttl past the boundary into the absolute stamp the protocol
 * is asking for, rather than CLAMPING to 2592000 - clamping would silently
 * shorten a session the operator explicitly asked to be longer, which is the
 * same class of lie in the other direction.
 *
 * NO MOCKS. Every assertion runs against a real memcached, and the out-of-band
 * checks open their own socket rather than asking the code under test.
 */
class SessionTtlUnitsTest extends TestCase
{
    /** The protocol boundary. At or below: relative seconds. Above: absolute stamp. */
    private const MAX_RELATIVE_EXPTIME = 2592000;

    private string $host;
    private int $port;
    private MemcachedSessionHandler $handler;

    /** @var string[] session ids created by the running test, cleaned up in tearDown */
    private array $created = [];

    protected function setUp(): void
    {
        $this->host = getenv('TINA4_TEST_MEMCACHED_HOST') ?: '127.0.0.1';
        $this->port = (int)(getenv('TINA4_TEST_MEMCACHED_PORT') ?: 11211);

        $probe = @fsockopen($this->host, $this->port, $errNo, $errStr, 2);
        if ($probe === false) {
            $message = sprintf(
                'memcached is not reachable at %s:%d (%s)',
                $this->host,
                $this->port,
                $errStr ?: 'no error reported'
            );
            if (getenv('TINA4_REQUIRE_SERVICES')) {
                $this->fail('TINA4_REQUIRE_SERVICES is set but ' . $message);
            }
            $this->markTestSkipped($message);
        }
        fclose($probe);

        $this->handler = new MemcachedSessionHandler(['host' => $this->host, 'port' => $this->port]);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $sessionId) {
            try {
                $this->handler->destroy($sessionId);
            } catch (\Throwable) {
                // Cleaning up a record we are throwing away must never fail a test.
            }
        }
        $this->created = [];
    }

    private function newSessionId(string $tag): string
    {
        $sessionId = sprintf('units-%s-%s', $tag, bin2hex(random_bytes(4)));
        $this->created[] = $sessionId;
        return $sessionId;
    }

    /**
     * The prefixed key the handler really stores under, so the out-of-band
     * reads address the same key the handler does.
     */
    private function storedKey(string $sessionId): string
    {
        $method = new \ReflectionMethod(MemcachedSessionHandler::class, 'key');
        $method->setAccessible(true);
        return $method->invoke($this->handler, $sessionId);
    }

    /** Raw `get` over a socket THIS TEST owns - never the handler's. */
    private function rawGet(string $key): string
    {
        $sock = fsockopen($this->host, $this->port, $errNo, $errStr, 3);
        $this->assertNotFalse($sock, 'could not open our own socket to memcached');
        fwrite($sock, "get {$key}\r\n");
        $buf = '';
        while (!str_contains($buf, "END\r\n")) {
            $chunk = fread($sock, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buf .= $chunk;
        }
        fclose($sock);
        return $buf;
    }

    /**
     * Ask the SERVER how long it thinks the key has left, over our own socket.
     *
     * memcached 1.6's meta-get reports the remaining ttl: `mg <key> t` answers
     * `VA <size> t<seconds>`. This is what makes the difference between
     * CONVERTING a long ttl and CLAMPING it visible - both survive a round-trip,
     * but only one still has the lifetime the caller asked for.
     */
    private function rawRemainingTtl(string $key): int
    {
        $sock = fsockopen($this->host, $this->port, $errNo, $errStr, 3);
        $this->assertNotFalse($sock, 'could not open our own socket to memcached');
        fwrite($sock, "mg {$key} t v\r\n");
        usleep(150000);
        $reply = fread($sock, 4096);
        fclose($sock);

        foreach (preg_split('/\s+/', (string)$reply) as $token) {
            if (preg_match('/^t(-?\d+)$/', $token, $m) === 1) {
                return (int)$m[1];
            }
        }
        $this->fail("no ttl in meta-get reply for {$key}: " . var_export($reply, true));
    }

    /**
     * A ttl past 30 days must still mean "that many SECONDS from now".
     *
     * This is the headline gate. Before the fix the record vanished the instant
     * it was written, because 2592001 was read as a moment in 1970.
     *
     * The 60-day value is what stops a CLAMP being mistaken for a fix. Clamping
     * to 2592000 also survives the round-trip, so a survival-only assertion
     * cannot tell the two apart - but a clamp silently turns the 60-day session
     * the operator asked for into a 30-day one. Asking the server for the
     * remaining ttl makes that visible.
     */
    public function testSessionTtlAboveTheMemcachedThirtyDayBoundarySurvives(): void
    {
        foreach ([self::MAX_RELATIVE_EXPTIME + 1, 5184000] as $overTheBoundary) {
            $sessionId = $this->newSessionId('over');
            $this->handler->write($sessionId, ['seeded' => true], $overTheBoundary);

            $this->assertSame(
                ['seeded' => true],
                $this->handler->read($sessionId),
                "a ttl of {$overTheBoundary}s expired the session instantly - "
                . 'memcached read it as an absolute timestamp in 1970'
            );

            $this->assertStringStartsWith(
                'VALUE',
                $this->rawGet($this->storedKey($sessionId)),
                'the handler claimed the session was stored but the server does not have it'
            );

            $remaining = $this->rawRemainingTtl($this->storedKey($sessionId));
            $this->assertLessThan(
                60,
                abs($remaining - $overTheBoundary),
                "asked for a {$overTheBoundary}s session; the server says {$remaining}s remain. "
                . 'A clamp to the 30-day ceiling silently shortens a lifetime the operator '
                . 'explicitly asked to be longer.'
            );
        }
    }

    /**
     * BOUNDARY CONTROL: exactly 2592000 is still legal relative seconds.
     *
     * Pins which side of the cliff the conversion starts on. A fix that
     * converted at or below the boundary would still be wrong, just less
     * obviously so.
     */
    public function testSessionTtlAtTheMemcachedThirtyDayBoundarySurvives(): void
    {
        $sessionId = $this->newSessionId('at');
        $this->handler->write($sessionId, ['seeded' => true], self::MAX_RELATIVE_EXPTIME);

        $this->assertSame(
            ['seeded' => true],
            $this->handler->read($sessionId),
            'a ttl of exactly ' . self::MAX_RELATIVE_EXPTIME . 's is the largest legal '
            . 'RELATIVE exptime and must round-trip untouched'
        );
    }

    /**
     * NEGATIVE CONTROL: a short ttl must still expire, for real.
     *
     * Without this, "never send an expiry at all" passes both cases above and
     * ships a session store where nothing ever expires - which on a session
     * store is a security defect, not a convenience.
     */
    public function testSessionTtlBelowTheBoundaryStillReallyExpires(): void
    {
        $sessionId = $this->newSessionId('short');
        $this->handler->write($sessionId, ['seeded' => true], 1);
        $this->assertSame(['seeded' => true], $this->handler->read($sessionId), 'the record was never stored');

        sleep(3); // REAL wall clock, past the 1s ttl. No clock mocking.

        $this->assertSame(
            [],
            $this->handler->read($sessionId),
            "a 1-second ttl did not expire - the conversion turned every ttl into 'never expires'"
        );
        $this->assertSame(
            "END\r\n",
            $this->rawGet($this->storedKey($sessionId)),
            'the server still holds the key after it should have expired'
        );
    }
}
