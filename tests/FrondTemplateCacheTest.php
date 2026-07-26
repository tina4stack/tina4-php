<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tina4\Frond;

/**
 * Lock-in tests for the bound on Frond's three TEMPLATE caches (ADR-0004).
 *
 * `compiled` (file templates), `compiledStrings` (renderString) and
 * `compiledFn` (AOT closures) were plain unbounded instance arrays. Each entry
 * is a token list + an AST + an eval'd closure — orders of magnitude bigger
 * than one expression descriptor — and two real workloads grow them for the
 * life of a worker:
 *
 *   1. `renderString()` keys on md5(source), so an app that builds template
 *      strings dynamically adds an entry per distinct string, forever.
 *   2. In dev the compiled-fn key is 'dev:' . md5(source), so every edit to a
 *      template file adds an entry, forever.
 *
 * Both are reproduced below for real: real renders through the real engine,
 * real template files on disk, and the real private caches read by reflection.
 * The bound must never cost correctness — an evicted template recompiles.
 *
 * No mocks: nothing here stands in for the engine or the filesystem.
 */
class FrondTemplateCacheTest extends TestCase
{
    private string $templateDir;
    private Frond $frond;
    private string|false $previousDebug;

    protected function setUp(): void
    {
        $this->previousDebug = getenv('TINA4_DEBUG');
        // Pin the production path (cache read + name-keyed compiled fn) so the
        // bound is measured deterministically regardless of the ambient .env.
        putenv('TINA4_DEBUG=false');

        $this->templateDir = sys_get_temp_dir() . '/frond-template-cache-' . bin2hex(random_bytes(6));
        mkdir($this->templateDir, 0777, true);
        $this->frond = new Frond($this->templateDir);
    }

    protected function tearDown(): void
    {
        if ($this->previousDebug === false) {
            putenv('TINA4_DEBUG');
        } else {
            putenv('TINA4_DEBUG=' . $this->previousDebug);
        }

        foreach (glob($this->templateDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->templateDir)) {
            rmdir($this->templateDir);
        }
    }

    /** Read the TEMPLATE_CACHE_MAX cap straight off the class. */
    private function templateCacheMax(): int
    {
        return (new ReflectionClass(Frond::class))->getConstant('TEMPLATE_CACHE_MAX');
    }

    /**
     * @return array<string, mixed> The named private cache's current contents.
     */
    private function readCache(string $property): array
    {
        // No setAccessible() call: private-property reads via Reflection need no
        // unlocking since PHP 8.1, and the method is deprecated in 8.5.
        return (new ReflectionClass(Frond::class))
            ->getProperty($property)
            ->getValue($this->frond);
    }

    /** Write a real template file and return its name. */
    private function writeTemplate(string $name, string $contents): string
    {
        file_put_contents($this->templateDir . '/' . $name, $contents);
        return $name;
    }

    public function testTemplateCacheMaxIsAPositiveCapBelowTheExpressionCap(): void
    {
        $templateCap = $this->templateCacheMax();
        $expressionCap = (new ReflectionClass(Frond::class))->getConstant('MEMO_CACHE_MAX');

        $this->assertGreaterThan(0, $templateCap, 'TEMPLATE_CACHE_MAX must be a positive cap');
        $this->assertLessThanOrEqual(
            $expressionCap,
            $templateCap,
            'a template entry (tokens + AST + closure) is far bigger than one expression '
            . 'descriptor, so its cap must not exceed MEMO_CACHE_MAX'
        );
    }

    /**
     * ADR-0004: renderString() keys on md5(source), so dynamically-built
     * template strings must not grow compiledStrings/compiledFn without limit.
     */
    public function testDynamicRenderStringDoesNotGrowTemplateCachesWithoutLimit(): void
    {
        $cap = $this->templateCacheMax();
        $distinct = $cap * 2 + 13;

        for ($index = 0; $index < $distinct; $index++) {
            // A DISTINCT source string every iteration — the real footgun.
            $rendered = $this->frond->renderString('t' . $index . '={{ n + ' . $index . ' }}', ['n' => 10]);
            $this->assertSame('t' . $index . '=' . (10 + $index), $rendered, "render #{$index}");
        }

        $stringsSize = count($this->readCache('compiledStrings'));
        $this->assertLessThanOrEqual(
            $cap,
            $stringsSize,
            "compiledStrings grew to {$stringsSize} entries for {$distinct} distinct sources; cap is {$cap}"
        );

        $fnSize = count($this->readCache('compiledFn'));
        $this->assertLessThanOrEqual(
            $cap,
            $fnSize,
            "compiledFn grew to {$fnSize} entries for {$distinct} distinct sources; cap is {$cap}"
        );
    }

    /**
     * The bound must not cost correctness: a source evicted by the sweep has to
     * recompile and render the same bytes, not break and not go stale.
     */
    public function testEvictedStringTemplateRecompilesAndStaysCorrect(): void
    {
        $cap = $this->templateCacheMax();

        $firstSource = 'first={{ n + 1 }}';
        $this->assertSame('first=11', $this->frond->renderString($firstSource, ['n' => 10]));

        // Overflow the cache so the first entry is swept out.
        for ($index = 0; $index < $cap * 2; $index++) {
            $this->frond->renderString('filler' . $index . '={{ n }}', ['n' => $index]);
        }

        $keys = array_keys($this->readCache('compiledStrings'));
        $this->assertNotContains(md5($firstSource), $keys, 'the first entry should have been evicted');

        // Recompiles from cold and is still byte-correct — and still tracks the
        // data rather than replaying a stale cached result.
        $this->assertSame('first=11', $this->frond->renderString($firstSource, ['n' => 10]));
        $this->assertSame('first=101', $this->frond->renderString($firstSource, ['n' => 100]));
    }

    /**
     * ADR-0004: the file-template token/AST cache is bounded too.
     */
    public function testFileTemplateCacheIsBounded(): void
    {
        $cap = $this->templateCacheMax();
        $distinct = $cap + 41;

        for ($index = 0; $index < $distinct; $index++) {
            $this->writeTemplate("tpl{$index}.twig", "T{$index}={{ n + {$index} }}");
        }

        for ($index = 0; $index < $distinct; $index++) {
            $rendered = $this->frond->render("tpl{$index}.twig", ['n' => 5]);
            $this->assertSame("T{$index}=" . (5 + $index), $rendered, "file render #{$index}");
        }

        $compiledSize = count($this->readCache('compiled'));
        $this->assertLessThanOrEqual(
            $cap,
            $compiledSize,
            "compiled grew to {$compiledSize} entries for {$distinct} distinct templates; cap is {$cap}"
        );

        // Every template still renders correctly after the sweep, including the
        // earliest ones, which were evicted and must re-read from disk.
        for ($index = 0; $index < $distinct; $index++) {
            $this->assertSame(
                "T{$index}=" . (7 + $index),
                $this->frond->render("tpl{$index}.twig", ['n' => 7]),
                "post-eviction file render #{$index}"
            );
        }
    }

    /**
     * The dev footgun, reproduced for real: in dev the compiled-fn key is
     * 'dev:' . md5(source), so a long session editing ONE template file used to
     * add a closure per edit forever. The template-name-keyed `compiled` cache
     * stays at one entry throughout, which is exactly why only `compiledFn`
     * grew and why it needed its own bound.
     */
    public function testDevModeEditsDoNotGrowTheCompiledFnCacheWithoutLimit(): void
    {
        putenv('TINA4_DEBUG=true');

        $cap = $this->templateCacheMax();
        $edits = $cap * 2 + 7;
        $name = 'edited.twig';

        for ($edit = 0; $edit < $edits; $edit++) {
            // A real edit: the file content changes on disk every iteration.
            $this->writeTemplate($name, "edit{$edit}={{ n + {$edit} }}");
            $rendered = $this->frond->render($name, ['n' => 1]);
            $this->assertSame("edit{$edit}=" . (1 + $edit), $rendered, "edit #{$edit} must render the NEW source");
        }

        $fnSize = count($this->readCache('compiledFn'));
        $this->assertLessThanOrEqual(
            $cap,
            $fnSize,
            "compiledFn grew to {$fnSize} entries across {$edits} dev edits; cap is {$cap}"
        );

        // One template name, so the token/AST cache never grew in the first place.
        $this->assertCount(1, $this->readCache('compiled'), 'one template name, one compiled entry');
    }

    /**
     * Negative control: the cap must not fire EARLY. Below the cap nothing is
     * evicted, so a warm cache keeps every entry it was given.
     */
    public function testCacheBelowTheCapIsNotEvicted(): void
    {
        $cap = $this->templateCacheMax();
        $belowCap = $cap - 1;

        for ($index = 0; $index < $belowCap; $index++) {
            $this->frond->renderString('u' . $index . '={{ n }}', ['n' => $index]);
        }

        $this->assertCount(
            $belowCap,
            $this->readCache('compiledStrings'),
            'nothing may be evicted while the cache is under the cap'
        );
        $this->assertCount(
            $belowCap,
            $this->readCache('compiledFn'),
            'nothing may be evicted while the cache is under the cap'
        );
    }

    /** clearCache() must still empty all three template caches. */
    public function testClearCacheEmptiesEveryTemplateCache(): void
    {
        $this->writeTemplate('page.twig', 'page={{ n }}');
        $this->assertSame('page=3', $this->frond->render('page.twig', ['n' => 3]));
        $this->assertSame('str=4', $this->frond->renderString('str={{ n }}', ['n' => 4]));

        $this->assertNotEmpty($this->readCache('compiled'), 'compiled should be warm');
        $this->assertNotEmpty($this->readCache('compiledStrings'), 'compiledStrings should be warm');
        $this->assertNotEmpty($this->readCache('compiledFn'), 'compiledFn should be warm');

        $this->frond->clearCache();

        $this->assertSame([], $this->readCache('compiled'), 'compiled not cleared');
        $this->assertSame([], $this->readCache('compiledStrings'), 'compiledStrings not cleared');
        $this->assertSame([], $this->readCache('compiledFn'), 'compiledFn not cleared');

        // Still renders correctly from cold after a clear.
        $this->assertSame('page=3', $this->frond->render('page.twig', ['n' => 3]));
        $this->assertSame('str=4', $this->frond->renderString('str={{ n }}', ['n' => 4]));
    }

    /**
     * Template inheritance re-reads the parent from disk rather than the token
     * cache, so an eviction storm must never break an {% extends %} chain.
     */
    public function testInheritanceStillRendersAcrossAnEvictionStorm(): void
    {
        $cap = $this->templateCacheMax();

        $this->writeTemplate('base.twig', 'BASE[{% block body %}dflt{% endblock %}]');
        $this->writeTemplate('child.twig', '{% extends "base.twig" %}{% block body %}{{ n * 2 }}{% endblock %}');

        $this->assertSame('BASE[8]', $this->frond->render('child.twig', ['n' => 4]));

        for ($index = 0; $index < $cap * 2; $index++) {
            $this->frond->renderString('storm' . $index . '={{ n }}', ['n' => $index]);
        }

        $this->assertSame('BASE[8]', $this->frond->render('child.twig', ['n' => 4]));
        $this->assertSame('BASE[20]', $this->frond->render('child.twig', ['n' => 10]));
    }
}
