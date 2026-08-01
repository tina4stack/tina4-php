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
}
