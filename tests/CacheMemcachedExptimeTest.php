<?php

/**
 * Tina4 - The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * CACHE CONTRACT - a memcached TTL beyond 30 days keeps its full lifetime.
 *
 * memcached reads the `set` exptime field as RELATIVE seconds at or below
 * 2592000 (30 days), and as an ABSOLUTE UNIX TIMESTAMP above it.
 * MemcachedBackend::set() interpolated the caller's ttl RAW, so any
 * TINA4_CACHE_TTL over 30 days made every cache write vanish the instant it
 * landed: the caller wrote a number of seconds and the server read a date in
 * 1970. memcached still answers STORED, so it presented as a 100% miss rate
 * with nothing logged - a cache that looks like it is working and never returns
 * a hit.
 *
 * MEASURED on the real memcached 1.6.45 used by these tests: exptime 2592000
 * survives, 2592001 and 5184000 vanish instantly despite STORED.
 *
 * WHY SURVIVAL ALONE IS NOT A TEST
 *     A case that only checks "the value is still there" passes under a CLAMP
 *     to 2592000 exactly as it does under a CONVERT, so it cannot tell the
 *     right fix from the wrong one. A clamp silently discards more than half
 *     the lifetime the operator explicitly configured - the same class of
 *     silent-wrong-answer as the bug it would replace. So the load-bearing case
 *     reads the SERVER's own reported remaining lifetime via
 *     `mg <key> t` -> `HD t<seconds>` (memcached 1.6+).
 *
 *     The 60-day case is the one that discriminates. The boundary case cannot:
 *     |2592000 - 2592001| = 1, inside any sane tolerance.
 *
 * Everything here runs against a REAL memcached over a real socket.
 *
 * SERVICE ADDRESS
 *     TINA4_TEST_CACHE_MEMCACHED_URL  (default memcached://127.0.0.1:11211)
 */

use PHPUnit\Framework\TestCase;
use Tina4\Cache\MemcachedBackend;

class CacheMemcachedExptimeTest extends TestCase
{
    private const THIRTY_DAYS = 2592000;
    private const SIXTY_DAYS = 5184000;

    private function memcachedUrl(): string
    {
        return getenv('TINA4_TEST_CACHE_MEMCACHED_URL') ?: 'memcached://127.0.0.1:11211';
    }

    /** @return array{0: string, 1: int} */
    private function endpoint(): array
    {
        $url = $this->memcachedUrl();
        $parts = parse_url(str_contains($url, '://') ? $url : '//' . $url);
        return [$parts['host'] ?? '127.0.0.1', (int)($parts['port'] ?? 11211)];
    }

    protected function setUp(): void
    {
        [$host, $port] = $this->endpoint();
        $sock = @fsockopen($host, $port, $errno, $errstr, 2);
        if (!$sock) {
            $this->markTestSkipped("memcached service not reachable at {$host}:{$port}");
        }
        fclose($sock);
    }

    private function backend(): MemcachedBackend
    {
        return new MemcachedBackend($this->memcachedUrl());
    }

    private function key(string $prefix): string
    {
        return $prefix . '-' . bin2hex(random_bytes(16));
    }

    /**
     * Speak the memcached text protocol directly - an oracle this test owns,
     * not the code under test.
     */
    private function memcachedTalk(string $payload, string $terminator): string
    {
        [$host, $port] = $this->endpoint();
        $sock = fsockopen($host, $port, $errno, $errstr, 5);
        $this->assertNotFalse($sock, "could not open a memcached socket to {$host}:{$port}");
        stream_set_timeout($sock, 5);
        fwrite($sock, $payload);
        $buffer = '';
        while (!str_contains($buffer, $terminator)) {
            $chunk = fread($sock, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buffer .= $chunk;
            $meta = stream_get_meta_data($sock);
            if (!empty($meta['timed_out'])) {
                break;
            }
        }
        fclose($sock);
        return $buffer;
    }

    /** The real server key for a logical key (prefix + generation + hash). */
    private function serverKey(MemcachedBackend $backend, string $key): string
    {
        return (new ReflectionObject($backend))->getMethod('mcKey')->invoke($backend, $key);
    }

    /**
     * How many more seconds the SERVER intends to keep this entry.
     *
     * `mg <key> t` returns `HD t<seconds>` on memcached 1.6+, or `EN` on a
     * miss. This is the observable that separates CONVERT from CLAMP - the
     * backend cannot be asked, only the server knows what deadline it stored.
     */
    private function serverRemainingTtl(MemcachedBackend $backend, string $key): ?int
    {
        $response = $this->memcachedTalk(
            'mg ' . $this->serverKey($backend, $key) . " t\r\n",
            "\r\n"
        );
        if (preg_match('/\bt(-?\d+)\b/', $response, $matches) === 1) {
            return (int)$matches[1];
        }
        return null;
    }

    /** The exptime the backend WOULD send for this ttl (a pure function). */
    private function computedExptime(int $ttl): int
    {
        return (new ReflectionClass(MemcachedBackend::class))
            ->getMethod('exptime')
            ->invoke(null, $ttl);
    }

    // -- the rule ------------------------------------------------------------

    /**
     * A TTL past the 30-day cliff must not vanish on write.
     *
     * The shipped defect: the caller wrote seconds, memcached read a date in
     * 1970, and the entry expired instantly while still answering STORED.
     */
    public function testATtlBeyondTheThirtyDayCliffSurvives(): void
    {
        $backend = $this->backend();
        $key = $this->key('cliff');

        $backend->set($key, ['v' => 'sixty days'], self::SIXTY_DAYS);

        $this->assertSame(
            ['v' => 'sixty days'],
            $backend->get($key),
            'a TTL beyond 30 days vanished the instant it was written - memcached '
            . 'read the seconds as an absolute timestamp in 1970, and still '
            . 'answered STORED, so this presents as a 100% miss rate with nothing '
            . 'logged'
        );
    }

    /**
     * CONVERT, not CLAMP - and only the server can settle which happened.
     *
     * This is the case that makes the suite a real gate. A clamp to 2592000
     * also survives, so it passes the case above; only the server's own
     * reported remaining lifetime shows that the requested 60 days was honoured.
     */
    public function testATtlBeyondTheCliffKeepsItsFullLifetime(): void
    {
        $backend = $this->backend();
        $key = $this->key('cliff');

        $backend->set($key, ['v' => 'sixty days'], self::SIXTY_DAYS);

        $remaining = $this->serverRemainingTtl($backend, $key);
        $this->assertNotNull(
            $remaining,
            'memcached did not report a remaining TTL (needs 1.6+)'
        );
        $this->assertLessThanOrEqual(
            60,
            abs($remaining - self::SIXTY_DAYS),
            "the server intends to keep this entry {$remaining}s, not the "
            . self::SIXTY_DAYS . 's that was asked for. A CLAMP to '
            . self::THIRTY_DAYS . ' looks like a working cache and silently '
            . 'discards more than half the lifetime the operator configured.'
        );
    }

    /**
     * Boundary control: 2592000 is still RELATIVE, so it must not convert.
     *
     * MEASURED, and worth stating because it is counter-intuitive: the server
     * observable CANNOT catch an off-by-one here. Sending relative 2592000 and
     * sending absolute time()+2592000 both make memcached report `HD t2592000`,
     * because both describe the same deadline. So this case asserts the
     * COMPUTED exptime as well - a pure function over its input, no service and
     * no stand-in - which is the only thing that distinguishes `> MAX` from
     * `>= MAX`. The live round trip stays as the regression guard that the
     * ordinary 30-day case still works.
     */
    public function testTheThirtyDayBoundaryItselfStaysRelative(): void
    {
        $this->assertSame(
            self::THIRTY_DAYS,
            $this->computedExptime(self::THIRTY_DAYS),
            'at EXACTLY the 30-day boundary the ttl was converted to an absolute '
            . 'stamp - the comparison is `>= MAX` where it must be `> MAX`'
        );

        $backend = $this->backend();
        $key = $this->key('boundary');

        $backend->set($key, ['v' => 'exactly thirty days'], self::THIRTY_DAYS);

        $this->assertSame(['v' => 'exactly thirty days'], $backend->get($key));
        $remaining = $this->serverRemainingTtl($backend, $key);
        $this->assertNotNull($remaining, 'memcached did not report a remaining TTL');
        $this->assertLessThanOrEqual(
            60,
            abs($remaining - self::THIRTY_DAYS),
            "at exactly " . self::THIRTY_DAYS . "s the server reports {$remaining}s remaining"
        );
    }

    /**
     * NEGATIVE: the conversion must not turn every TTL into forever.
     *
     * A fix that sent an absolute stamp for EVERY ttl, or dropped expiry
     * altogether, passes every case above. This one waits on a real wall clock
     * and requires the entry to actually be gone.
     */
    public function testAShortTtlStillExpires(): void
    {
        $backend = $this->backend();
        $key = $this->key('short');

        $backend->set($key, ['v' => 'brief'], 1);
        $this->assertSame(
            ['v' => 'brief'],
            $backend->get($key),
            'precondition: a short TTL is readable'
        );

        sleep(2);

        $this->assertNull(
            $backend->get($key),
            'a 1-second entry was still readable after 2 seconds - the exptime '
            . 'conversion turned a short TTL into a long one, or into forever'
        );
    }

    /**
     * The trap sitting on the line AFTER the fix.
     *
     * The backend keeps a local map of the keys it wrote and the moment each
     * expires, and it computed that deadline from the SAME variable sent to
     * memcached. Convert that variable to an absolute stamp and leave this line
     * alone, and the map's deadline becomes now + <a unix timestamp> - a date
     * about 166 years out, so stats() reports expired entries as live forever.
     *
     * The map must be built from the RAW ttl.
     */
    public function testTheLocalWriteLogUsesTheRawTtl(): void
    {
        $backend = $this->backend();
        $key = $this->key('shadow');

        $before = microtime(true);
        $backend->set($key, ['v' => 'sixty days'], self::SIXTY_DAYS);

        $own = (new ReflectionObject($backend))->getProperty('own')->getValue($backend);
        $deadlines = array_filter($own, static fn(float $expires): bool => $expires > 0.0);
        $this->assertNotEmpty($deadlines, 'the write log recorded no deadline at all');

        $recorded = max($deadlines);
        $expected = $before + self::SIXTY_DAYS;
        $this->assertLessThanOrEqual(
            60,
            abs($recorded - $expected),
            'the local write log expires this entry about '
            . (int)(($recorded - $expected) / 86400)
            . ' days later than it should - it was built from the CONVERTED '
            . 'exptime (already an absolute stamp) instead of the raw ttl, so it '
            . 'never expires anything and stats() reports expired entries as live'
        );
    }
}
