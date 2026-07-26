<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tina4\Frond;

/**
 * Lock-in tests for Frond's per-expression structural descriptor cache.
 *
 * The descriptor memoises operator detection and the operand split for an
 * expression STRING. Two properties must hold forever:
 *
 *  1. BEHAVIOUR-PRESERVING -- every rendered byte is what the un-memoised
 *     interpreter produced. Every expected value below was captured from the
 *     interpreter BEFORE the cache existed, so this suite passes on both the old
 *     and the new implementation: it pins behaviour rather than a bug fix.
 *  2. RENDER-INDEPENDENT + BOUNDED -- the descriptor holds no context value (so
 *     the same expression against different data still tracks the data), and no
 *     memo cache may grow without limit (ADR-0004).
 *
 * No mocks: every assertion renders through the real engine.
 */
class FrondExpressionCacheTest extends TestCase
{
    private Frond $frond;

    protected function setUp(): void
    {
        $this->frond = new Frond(sys_get_temp_dir());
        $this->frond->addFilter('shout', static fn($v) => strtoupper((string)$v) . '!');
        $this->frond->addTest('spicy', static fn($v) => $v === 'chilli');
        $this->frond->addGlobal('SITE', 'tina4.com');
    }

    /** The single data set every expression case below is evaluated against. */
    private function fixture(): array
    {
        return [
            'name' => 'Alice', 'empty' => '', 'nothing' => null, 'zero' => 0, 'one' => 1,
            'n' => 7, 'm' => 3, 'f' => 2.5, 'neg' => -4,
            'flagTrue' => true, 'flagFalse' => false,
            'items' => ['a', 'b', 'c'],
            'nums' => [3, 1, 2],
            'user' => [
                'first' => 'Bob', 'last' => 'Smith', 'roles' => ['admin', 'dev'],
                'address' => ['city' => 'Cape Town'],
            ],
            'rows' => [
                ['label' => 'x', 'qty' => 2, 'price' => 10.5],
                ['label' => 'y', 'qty' => 0, 'price' => 3.25],
                ['label' => 'z', 'qty' => 5, 'price' => 100.0],
            ],
            'word' => 'chilli',
            'html' => '<b>bold</b>',
            'longText' => 'The quick brown fox jumps over the lazy dog and keeps on running.',
            'tilde' => 'a ~ b',
            'quoted' => "it's fine",
        ];
    }

    /**
     * Every branch the descriptor memoises, with the bytes the un-memoised
     * interpreter produced. Grouped by descriptor slot so a gap is visible.
     *
     * @return array<string, array{string, string}>
     */
    public static function cachedExpressionProvider(): array
    {
        return [
            // -- 'lit': literals (short-circuit; no operator scan reached) ----
            'literal single-quoted'   => ["'plain'", 'plain'],
            'literal double-quoted'   => ['"double"', 'double'],
            'literal int'             => ['42', '42'],
            'literal float'           => ['3.14', '3.14'],
            'literal true'            => ['true', '1'],
            'literal false'           => ['false', ''],
            'literal none'            => ['none', ''],
            'literal null'            => ['null', ''],
            'literal containing ~'    => ["'a ~ b'", 'a ~ b'],
            'literal containing if'   => ["'x if y else z'", 'x if y else z'],

            // -- 'paren': parenthesized sub-expression ------------------------
            'paren var'               => ['(name)', 'Alice'],
            'paren nested'            => ['((n))', '7'],
            'paren arithmetic'        => ['(n + m)', '10'],
            'paren both operands'     => ['(n) + (m)', '10'],

            // -- 'ternary': C-style ? : --------------------------------------
            'ternary true'            => ['flagTrue ? "yes" : "no"', 'yes'],
            'ternary false'           => ['flagFalse ? "yes" : "no"', 'no'],
            'ternary falsy zero'      => ['zero ? "t" : "f"', 'f'],
            'ternary on comparison'   => ['n > m ? "bigger" : "smaller"', 'bigger'],
            'ternary paren branches'  => ['flagTrue ? (n + m) : (n - m)', '10'],
            'ternary colon in string' => ['flagTrue ? "a:b" : "c:d"', 'a:b'],

            // -- 'inlineIf': Jinja2 ` if ` / ` else ` ------------------------
            'inline-if true'          => ['"yes" if flagTrue else "no"', 'yes'],
            'inline-if false'         => ['"yes" if flagFalse else "no"', 'no'],
            'inline-if truthy var'    => ['name if name else "anon"', 'Alice'],
            'inline-if empty string'  => ['empty if empty else "fallback"', 'fallback'],
            'inline-if comparison'    => ['n if n > m else m', '7'],

            // -- 'coalesce': ?? ----------------------------------------------
            'coalesce null left'      => ['nothing ?? "dflt"', 'dflt'],
            'coalesce set left'       => ['name ?? "dflt"', 'Alice'],
            'coalesce missing key'    => ['missingKey ?? "dflt"', 'dflt'],
            'coalesce zero is not null' => ['zero ?? "dflt"', '0'],
            'coalesce chained'        => ['nothing ?? nothing ?? "last"', 'last'],

            // -- 'or' / 'and' / 'not' ----------------------------------------
            'and false'               => ['flagTrue and flagFalse', ''],
            'or true'                 => ['flagTrue or flagFalse', '1'],
            'not true'                => ['not flagTrue', ''],
            'not false'               => ['not flagFalse', '1'],
            'and of comparisons'      => ['n > m and m > 0', '1'],
            'or of comparison'        => ['n < m or flagTrue', '1'],

            // -- 'in' / 'notIn' ----------------------------------------------
            'in hit'                  => ['"a" in items', '1'],
            'in miss'                 => ['"q" in items', ''],
            'not in hit'              => ['"a" not in items', ''],
            'not in miss'             => ['"q" not in items', '1'],
            'in nested list'          => ['"admin" in user.roles', '1'],

            // -- 'isTest' / 'isNotTest' (isKnownTest stays live) -------------
            'is custom test hit'      => ['word is spicy', '1'],
            'is custom test miss'     => ['name is spicy', ''],
            'is not custom test'      => ['word is not spicy', ''],
            'is null'                 => ['nothing is null', '1'],
            'is not null'             => ['nothing is not null', ''],
            'is defined'              => ['name is defined', '1'],
            'is defined miss'         => ['missingKey is defined', ''],
            'is iterable'             => ['nums is iterable', '1'],
            'is odd'                  => ['one is odd', '1'],
            'is even miss'            => ['n is even', ''],

            // -- 'compare': != == <= >= < > ----------------------------------
            'equal'                   => ['n == 7', '1'],
            'not equal'               => ['n != 7', ''],
            'greater'                 => ['n > m', '1'],
            'less'                    => ['n < m', ''],
            'greater or equal'        => ['n >= 7', '1'],
            'less or equal miss'      => ['n <= 6', ''],
            'string equal'            => ['name == "Alice"', '1'],
            'string not equal'        => ['name != "Bob"', '1'],
            'apostrophe in string'    => ['quoted == "it\'s fine"', '1'],
            'operator inside string'  => ['"a>b" == "a>b"', '1'],
            'equals inside string'    => ['"x==y" != "z"', '1'],

            // -- 'concat': ~ -------------------------------------------------
            'concat literal and var'  => ['"Hello " ~ name', 'Hello Alice'],
            'concat three parts'      => ['name ~ " " ~ user.last', 'Alice Smith'],
            'concat numbers'          => ['n ~ "-" ~ m', '7-3'],
            'concat value holding ~'  => ['tilde ~ "!"', 'a ~ b!'],
            'concat with paren maths' => ['"pre" ~ (n + m) ~ "post"', 'pre10post'],
            // ~ binds looser than | (issue #171) -- order must survive caching
            'concat after filter'     => ['name|shout ~ " done"', 'ALICE! done'],
            'concat after filter args' => ['f|number_format(2) ~ " EUR"', '2.50 EUR'],

            // -- 'pipe': filter chains ---------------------------------------
            'pipe single'             => ['name|upper', 'ALICE'],
            'pipe chained'            => ['name|lower|shout', 'ALICE!'],
            'pipe with arg'           => ['longText|truncate(20)', 'The quick brown fox ...'],
            'pipe number_format'      => ['f|number_format(2)', '2.50'],
            'pipe raw'                => ['html|raw', '<b>bold</b>'],
            'pipe escape'             => ['html|e', '&lt;b&gt;bold&lt;/b&gt;'],
            'pipe join'               => ['items|join(",")', 'a,b,c'],
            'pipe length'             => ['items|length', '3'],
            'pipe sort then join'     => ['nums|sort|join("-")', '1-2-3'],
            'pipe default kept'       => ['name|default("x")', 'Alice'],
            'pipe default used'       => ['missingKey|default("fallback")', 'fallback'],
            'pipe first'              => ['items|first', 'a'],
            'pipe first on nested'    => ['user.roles|first', 'admin'],
            'pipe arg with dot'       => ['f|number_format(1)', '2.5'],
            'pipe char inside string' => ['"a|b"|upper', 'A|B'],
            'pipe two args'           => ['longText|truncate(10, "...")', 'The quick ...'],

            // -- 'arithmetic' ------------------------------------------------
            'add'                     => ['n + m', '10'],
            'subtract'                => ['n - m', '4'],
            'multiply'                => ['n * m', '21'],
            'divide exact'            => ['n / 2', '3.5'],
            'floor divide'            => ['n // m', '2'],
            'modulo'                  => ['n % m', '1'],
            'power'                   => ['n ** 2', '49'],
            'add float'               => ['f + 1', '3.5'],
            'add negative operand'    => ['neg + n', '3'],
            'precedence unparenthesised' => ['n + m * 2', '13'],
            'precedence parenthesised' => ['(n + m) * 2', '20'],

            // -- live paths that must stay unaffected by the descriptor ------
            'array literal joined'    => ['[1, 2, 3]|join("|")', '1|2|3'],
            'array literal length'    => ['["a", "b"]|length', '2'],
            'dict literal length'     => ['{"k": "v"}|length', '1'],
            'range joined'            => ['1..4|join(",")', '1,2,3,4'],
            'global'                  => ['SITE', 'tina4.com'],
            'global filtered'         => ['SITE|upper', 'TINA4.COM'],
            'deep dotted path'        => ['user.address.city', 'Cape Town'],
            'deep dotted filtered'    => ['user.address.city|upper', 'CAPE TOWN'],
            'bracket index'           => ['items[0]', 'a'],
            'bracket index filtered'  => ['items[1]|upper', 'B'],
            'bracket string key'      => ['user["first"]', 'Bob'],
            'bracket then dot'        => ['rows[2].label', 'z'],

            // -- whitespace variants: different strings, same semantics ------
            'no spaces around plus'   => ['n+m', '10'],
            'extra spaces around plus' => ['n  +  m', '10'],
            'spaces around pipe'      => [' name | upper ', 'ALICE'],
        ];
    }

    /**
     * Each expression renders the pre-change bytes, and renders them AGAIN off
     * the memoised descriptor -- a second render must not drift from the first.
     */
    #[DataProvider('cachedExpressionProvider')]
    public function testCachedExpressionPathRendersPreChangeBytes(string $expression, string $expected): void
    {
        $data = $this->fixture();
        $source = '{{ ' . $expression . ' }}';

        $firstRender = $this->frond->renderString($source, $data);
        $this->assertSame($expected, $firstRender, "first render of: {$expression}");

        $secondRender = $this->frond->renderString($source, $data);
        $this->assertSame($expected, $secondRender, "memoised re-render of: {$expression}");
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function controlFlowProvider(): array
    {
        return [
            'if elseif else'      => ['{% if n > m %}big{% elseif n == m %}same{% else %}small{% endif %}', 'big'],
            'if elif else'        => ['{% if flagFalse %}a{% elif flagTrue %}b{% else %}c{% endif %}', 'b'],
            'for with filters'    => ['{% for r in rows %}{{ loop.index }}:{{ r.label|upper }}={{ (r.qty * r.price)|number_format(2) }} {% endfor %}', '1:X=21.00 2:Y=0.00 3:Z=500.00 '],
            'for with if inside'  => ['{% for r in rows %}{% if r.qty > 0 %}{{ r.label }}{% endif %}{% endfor %}', 'xz'],
            'for with ternary'    => ['{% for r in rows %}{{ loop.even ? "E" : "O" }}{% endfor %}', 'OEO'],
            'for key value'       => ['{% for k, v in user.address %}{{ k }}={{ v }}{% endfor %}', 'city=Cape Town'],
            'for else empty'      => ['{% for x in [] %}n{% else %}none{% endfor %}', 'none'],
            'set arithmetic'      => ['{% set total = n * m %}{{ total }}', '21'],
            'set concat'          => ['{% set label = "a" ~ "b" %}{{ label }}', 'ab'],
            'nested for'          => ['{% for r in rows %}{% for c in items %}{{ r.label }}{{ c }}{% endfor %}{% endfor %}', 'xaxbxcyaybyczazbzc'],
            'mixed outputs'       => ['{{ nothing ?? "none" }}/{{ name|shout }}/{{ n + m }}', 'none/ALICE!/10'],
            'if custom test'      => ['{% if word is spicy %}hot{% else %}mild{% endif %}', 'hot'],
            'if in and compare'   => ['{% if "admin" in user.roles and n > 0 %}ok{% endif %}', 'ok'],
            'raw block untouched' => ['{% raw %}{{ not_parsed }}{% endraw %}', '{{ not_parsed }}'],
            'two inline ifs'      => ["{{ 'a' if flagTrue else 'b' }}{{ 'c' if flagFalse else 'd' }}", 'ad'],
        ];
    }

    /**
     * Control-flow conditions route through the same expression evaluator, so
     * they are pinned to their pre-change bytes too.
     */
    #[DataProvider('controlFlowProvider')]
    public function testControlFlowRendersPreChangeBytes(string $template, string $expected): void
    {
        $data = $this->fixture();
        $this->assertSame($expected, $this->frond->renderString($template, $data), 'first render');
        $this->assertSame($expected, $this->frond->renderString($template, $data), 'memoised re-render');
    }

    /**
     * THE correctness invariant of the whole design: the descriptor is keyed on
     * the expression string and must bake in NO context value. The same
     * expression rendered against different data must track the data.
     */
    public function testDescriptorBakesInNoContextValue(): void
    {
        $cases = [
            '{{ a + b }}'                      => [['a' => 1, 'b' => 2], ['a' => 10, 'b' => 20], '3', '30'],
            '{{ a ~ "-" ~ b }}'                => [['a' => 'x', 'b' => 'y'], ['a' => 'p', 'b' => 'q'], 'x-y', 'p-q'],
            '{{ a ?? "dflt" }}'                => [['a' => null], ['a' => 'set'], 'dflt', 'set'],
            '{{ "yes" if a else "no" }}'       => [['a' => true], ['a' => false], 'yes', 'no'],
            '{{ a ? "T" : "F" }}'              => [['a' => true], ['a' => false], 'T', 'F'],
            '{{ a > b }}'                      => [['a' => 5, 'b' => 1], ['a' => 1, 'b' => 5], '1', ''],
            '{{ a|upper }}'                    => [['a' => 'one'], ['a' => 'two'], 'ONE', 'TWO'],
            '{{ a and b }}'                    => [['a' => true, 'b' => true], ['a' => true, 'b' => false], '1', ''],
            '{{ a in b }}'                     => [['a' => 'k', 'b' => ['k']], ['a' => 'k', 'b' => ['z']], '1', ''],
        ];

        foreach ($cases as $template => [$firstData, $secondData, $firstExpected, $secondExpected]) {
            // Render with the first data set, which populates the descriptor...
            $this->assertSame(
                $firstExpected,
                $this->frond->renderString($template, $firstData),
                "first data set for {$template}"
            );
            // ...then a DIFFERENT data set must produce a different result.
            $this->assertSame(
                $secondExpected,
                $this->frond->renderString($template, $secondData),
                "second data set for {$template} -- a stale cached VALUE would fail here"
            );
            // ...and going back must still give the original answer.
            $this->assertSame(
                $firstExpected,
                $this->frond->renderString($template, $firstData),
                "back to first data set for {$template}"
            );
        }
    }

    /**
     * A descriptor must never bake in an `is` test's outcome: whether a test
     * NAME is registered is live engine state (addTest can run at any time).
     */
    public function testTestRegisteredAfterFirstUseStillApplies(): void
    {
        $template = '{% if word is zesty %}Y{% else %}N{% endif %}';
        $data = ['word' => 'chilli'];

        // Unknown test: falls through, renders the else branch.
        $this->assertSame('N', $this->frond->renderString($template, $data));

        // Register it AFTER the descriptor for that expression is already cached.
        $this->frond->addTest('zesty', static fn($v) => $v === 'chilli');
        $this->assertSame('Y', $this->frond->renderString($template, $data));
    }

    /** A filter registered after first use must also take effect. */
    public function testFilterRegisteredAfterFirstUseStillApplies(): void
    {
        $template = '{{ name|zing }}';
        $data = ['name' => 'Alice'];

        $this->assertSame('Alice', $this->frond->renderString($template, $data));

        $this->frond->addFilter('zing', static fn($v) => $v . '-zing');
        $this->assertSame('Alice-zing', $this->frond->renderString($template, $data));
    }

    /** Read the MEMO_CACHE_MAX cap straight off the class. */
    private function memoCacheMax(): int
    {
        return (new ReflectionClass(Frond::class))->getConstant('MEMO_CACHE_MAX');
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

    /**
     * ADR-0004: the expression descriptor cache is BOUNDED. A template that
     * builds expression strings dynamically must not grow it without limit --
     * the exact footgun the previous plain unbounded arrays carried.
     */
    public function testExpressionDescriptorCacheIsBounded(): void
    {
        $cap = $this->memoCacheMax();
        $this->assertGreaterThan(0, $cap, 'MEMO_CACHE_MAX must be a positive cap');

        // Well past the cap, every one a DISTINCT expression string.
        $distinct = $cap * 2 + 137;
        $source = '';
        for ($i = 0; $i < $distinct; $i++) {
            $source .= '{{ ' . $i . ' + 1 }},';
        }

        $rendered = $this->frond->renderString($source, []);

        $cacheSize = count($this->readCache('exprScanCache'));
        $this->assertLessThanOrEqual(
            $cap,
            $cacheSize,
            "exprScanCache grew to {$cacheSize} entries for {$distinct} distinct expressions; cap is {$cap}"
        );

        // Bounding must not cost correctness: every value is still right, both
        // for entries that survived and for ones evicted mid-render.
        $expected = '';
        for ($i = 0; $i < $distinct; $i++) {
            $expected .= ($i + 1) . ',';
        }
        $this->assertSame($expected, $rendered, 'arithmetic stayed correct across eviction');

        // An expression evicted by the sweep must still render correctly when it
        // comes back (recomputed, not lost).
        $this->assertSame('1', $this->frond->renderString('{{ 0 + 1 }}', []));
        $this->assertSame('2', $this->frond->renderString('{{ 1 + 1 }}', []));
    }

    /**
     * ADR-0004: the dotted-path split cache -- previously a plain unbounded
     * instance array -- is bounded on the same cap.
     */
    public function testDottedSplitCacheIsBounded(): void
    {
        $cap = $this->memoCacheMax();

        $distinct = $cap * 2 + 41;
        $data = ['bag' => []];
        $source = '';
        for ($i = 0; $i < $distinct; $i++) {
            $data['bag']["k{$i}"] = $i;
            $source .= '{{ bag.k' . $i . ' }},';
        }

        $rendered = $this->frond->renderString($source, $data);

        $cacheSize = count($this->readCache('dottedSplitCache'));
        $this->assertLessThanOrEqual(
            $cap,
            $cacheSize,
            "dottedSplitCache grew to {$cacheSize} entries for {$distinct} distinct paths; cap is {$cap}"
        );

        $expected = '';
        for ($i = 0; $i < $distinct; $i++) {
            $expected .= $i . ',';
        }
        $this->assertSame($expected, $rendered, 'path resolution stayed correct across eviction');
    }

    /** clearCache() must empty every memo cache, not just the template ones. */
    public function testClearCacheEmptiesEveryMemoCache(): void
    {
        $this->frond->renderString('{{ user.name|upper ~ "!" }}{{ n + 1 }}', [
            'user' => ['name' => 'Alice'], 'n' => 1,
        ]);

        $this->assertNotEmpty($this->readCache('exprScanCache'), 'descriptor cache should be warm');
        $this->assertNotEmpty($this->readCache('dottedSplitCache'), 'dotted split cache should be warm');

        $this->frond->clearCache();

        $this->assertSame([], $this->readCache('exprScanCache'), 'exprScanCache not cleared');
        $this->assertSame([], $this->readCache('dottedSplitCache'), 'dottedSplitCache not cleared');

        // Still renders correctly from cold after a clear.
        $this->assertSame('ALICE!2', $this->frond->renderString('{{ user.name|upper ~ "!" }}{{ n + 1 }}', [
            'user' => ['name' => 'Alice'], 'n' => 1,
        ]));
    }

    /**
     * Repeated renders inside one loop hit the memoised descriptor on every
     * iteration -- the case the cache exists for -- and must stay byte-correct.
     */
    public function testLoopRepeatsUseTheCacheAndStayCorrect(): void
    {
        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = ['name' => "Product {$i}", 'price' => $i * 9.99];
        }

        $template = '{% for item in rows %}'
            . '{{ loop.index }}:{{ item.name|upper }}:{{ item.price|number_format(2) }}:'
            . '{{ loop.even ? "E" : "O" }};'
            . '{% endfor %}';

        $expected = '';
        foreach ($rows as $index => $row) {
            $expected .= ($index + 1) . ':' . strtoupper($row['name']) . ':'
                . number_format($row['price'], 2) . ':'
                . (($index + 1) % 2 === 0 ? 'E' : 'O') . ';';
        }

        $first = $this->frond->renderString($template, ['rows' => $rows]);
        $this->assertSame($expected, $first);
        // Second render is fully served off the descriptor cache.
        $this->assertSame($expected, $this->frond->renderString($template, ['rows' => $rows]));
    }
}
