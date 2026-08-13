<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Frond fragment cache: {% cache "key" ttl %} ... {% endcache %}.
 *
 * PHP disagreed with Python, Ruby and Node on BOTH ends of the TTL contract:
 * a missing TTL defaulted to 0 (they default to 60), and 0 meant "cache
 * forever" (they treat now+0 as already expired, so 0 means not cached). A
 * {% cache "k" %} block therefore never re-rendered for the life of a PHP
 * process. Test names match the other three one-for-one.
 *
 * 3.13.100 (ADR-0004): the runtime store ($cache) was ALSO unbounded AND
 * never swept a TTL-expired entry -- a key that expired and was never read
 * again sat in memory for the life of the worker, since expiry was only
 * ever checked lazily on a re-read of that SAME key. Now bounded at
 * TEMPLATE_CACHE_MAX (capMemoCache) and swept of every TTL-expired entry on
 * every {% cache %} render (sweepExpiredCache). Reproduced for real below:
 * real renders through the real engine, and the real private cache read by
 * reflection. No mocks: nothing here stands in for the engine or the clock.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Frond;

class FrondFragmentCacheTest extends TestCase
{
    public function testFrondFragmentCacheDefaultsToSixtySecondsWithoutTtl(): void
    {
        $frond = new Frond(sys_get_temp_dir());
        $source = '{% cache "k1" %}{{ n }}{% endcache %}';
        $first = $frond->renderString($source, ['n' => 'first']);
        $second = $frond->renderString($source, ['n' => 'second']);

        $this->assertSame('first', $first);
        $this->assertSame('first', $second, 'a TTL-less fragment caches for the 60s default');
    }

    public function testFrondFragmentCacheTtlZeroIsNotCached(): void
    {
        $frond = new Frond(sys_get_temp_dir());
        $source = '{% cache "k2" 0 %}{{ n }}{% endcache %}';
        $first = $frond->renderString($source, ['n' => 'first']);
        $second = $frond->renderString($source, ['n' => 'second']);

        $this->assertSame('first', $first);
        $this->assertSame('second', $second, 'ttl 0 means NOT cached, not cached forever');
    }

    public function testFrondFragmentCacheHonoursAnExplicitTtl(): void
    {
        // Negative control: an explicit positive TTL still caches.
        $frond = new Frond(sys_get_temp_dir());
        $source = '{% cache "k3" 300 %}{{ n }}{% endcache %}';
        $this->assertSame('first', $frond->renderString($source, ['n' => 'first']));
        $this->assertSame('first', $frond->renderString($source, ['n' => 'second']));
    }

    /** Read the TEMPLATE_CACHE_MAX cap straight off the class. */
    private function templateCacheMax(): int
    {
        return (new ReflectionClass(Frond::class))->getConstant('TEMPLATE_CACHE_MAX');
    }

    /** @return array<string, array{content: string, time: int, ttl: int}> */
    private function readFragmentCache(Frond $frond): array
    {
        // No setAccessible() call: private-property reads via Reflection need no
        // unlocking since PHP 8.1, and the method is deprecated in 8.5.
        return (new ReflectionClass(Frond::class))->getProperty('cache')->getValue($frond);
    }

    public function testFragmentCacheDoesNotGrowWithoutLimitForManyDistinctKeys(): void
    {
        $cap = $this->templateCacheMax();
        $frond = new Frond(sys_get_temp_dir());
        $distinct = $cap * 2 + 13;

        for ($i = 0; $i < $distinct; $i++) {
            $result = $frond->renderString('{% cache "frag' . $i . '" 300 %}{{ n }}{% endcache %}', ['n' => $i]);
            $this->assertSame((string)$i, $result, "render #{$i}");
        }

        $size = count($this->readFragmentCache($frond));
        $this->assertLessThanOrEqual(
            $cap,
            $size,
            "fragment cache grew to {$size} entries for {$distinct} distinct keys; cap is {$cap}"
        );
    }

    /** Negative control: the cap must not fire EARLY. */
    public function testFragmentCacheEvictsNothingWhileUnderTheCap(): void
    {
        $cap = $this->templateCacheMax();
        $frond = new Frond(sys_get_temp_dir());
        $belowCap = $cap - 1;

        for ($i = 0; $i < $belowCap; $i++) {
            $frond->renderString('{% cache "under' . $i . '" 300 %}{{ n }}{% endcache %}', ['n' => $i]);
        }

        $this->assertCount($belowCap, $this->readFragmentCache($frond));
    }

    /**
     * The bound must not cost correctness: a key evicted while still
     * unexpired simply recomputes on next use instead of erroring or
     * serving another key's content.
     */
    public function testFragmentCacheRecomputesAnEvictedFragmentAndStaysCorrect(): void
    {
        $cap = $this->templateCacheMax();
        $frond = new Frond(sys_get_temp_dir());

        $first = $frond->renderString('{% cache "first_evictable" 300 %}{{ n }}{% endcache %}', ['n' => 'one']);
        $this->assertSame('one', $first);

        for ($i = 0; $i < $cap * 2; $i++) {
            $frond->renderString('{% cache "filler' . $i . '" 300 %}{{ n }}{% endcache %}', ['n' => $i]);
        }

        $keys = array_keys($this->readFragmentCache($frond));
        $this->assertNotContains(
            'first_evictable',
            $keys,
            'the first fragment should have been evicted by the size cap'
        );

        // Recomputes from cold with fresh data -- never reads stale content.
        $recomputed = $frond->renderString('{% cache "first_evictable" 300 %}{{ n }}{% endcache %}', ['n' => 'two']);
        $this->assertSame('two', $recomputed);
    }

    /**
     * ADR-0004: a TTL-expired entry is SWEPT (removed from the array), not
     * merely left stale for the next read of that same key to overwrite.
     */
    public function testFragmentCacheSweepsATtlExpiredEntryInsteadOfLeavingItStaleForever(): void
    {
        $frond = new Frond(sys_get_temp_dir());

        $shortLived = $frond->renderString('{% cache "short_lived" 1 %}{{ n }}{% endcache %}', ['n' => 'first']);
        $this->assertSame('first', $shortLived);
        $frond->renderString('{% cache "control" 300 %}{{ n }}{% endcache %}', ['n' => 'control']);

        sleep(2);

        // Touch a DIFFERENT cache key -- proving the sweep runs as a side
        // effect of any fragment-cache render, not only on a re-read of the
        // SAME key (which the old code already handled by silent overwrite).
        $frond->renderString('{% cache "trigger" 300 %}{{ n }}{% endcache %}', ['n' => 'trigger']);

        $keys = array_keys($this->readFragmentCache($frond));
        $this->assertNotContains(
            'short_lived',
            $keys,
            'the expired entry should have been swept, not merely left stale'
        );
        $this->assertContains('control', $keys, 'a still-live entry must not be swept early');

        // A fresh render recomputes rather than ever reading stale content.
        $refreshed = $frond->renderString('{% cache "short_lived" 1 %}{{ n }}{% endcache %}', ['n' => 'second']);
        $this->assertSame('second', $refreshed);
    }
}
