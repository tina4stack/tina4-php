<?php

use PHPUnit\Framework\TestCase;
use Tina4\Frond;

/**
 * Lock-in tests for the Frond ahead-of-time compiler (Tina4\FrondCompiler).
 *
 * Every compiled construct is asserted BYTE-IDENTICAL to the interpreter AND
 * asserted to actually take the compiled path (a Closure was cached, not the
 * null fallback). The interpreter reference is produced by a sandboxed engine,
 * which always interprets (renderAst skips compilation in sandbox mode). Every
 * unsupported construct is asserted to fall back (null cached) while still
 * rendering correctly. No mocks: these exercise the real Frond engine, the real
 * eval'd closures, and the real filesystem for file/extends/include templates.
 */
class FrondCompilerTest extends TestCase
{
    private string $templateDir;

    protected function setUp(): void
    {
        $this->templateDir = sys_get_temp_dir() . '/tina4-frond-compiler-test';
        if (!is_dir($this->templateDir)) {
            mkdir($this->templateDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->templateDir . '/*') as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
    }

    private function writeTemplate(string $name, string $content): void
    {
        file_put_contents($this->templateDir . '/' . $name, $content);
    }

    /** @return array<string, \Closure|null> The engine's private compiled-closure cache. */
    private function compiledMap(Frond $engine): array
    {
        return (new \ReflectionProperty(Frond::class, 'compiledFn'))->getValue($engine);
    }

    /** Assert the last render took the compiled path (a Closure was cached). */
    private function assertCompiledPath(Frond $engine): void
    {
        $map = $this->compiledMap($engine);
        $this->assertCount(1, $map, 'exactly one compile outcome should be cached');
        $this->assertInstanceOf(\Closure::class, reset($map), 'template should take the COMPILED path, not fall back');
    }

    /** Assert the last render fell back to the interpreter (null was cached). */
    private function assertFellBack(Frond $engine): void
    {
        $map = $this->compiledMap($engine);
        $this->assertCount(1, $map, 'exactly one compile outcome should be cached');
        $this->assertNull(reset($map), 'template should FALL BACK to the interpreter (unsupported construct)');
    }

    /**
     * Render $tpl once compiled and once through a sandboxed (always-interpreted)
     * engine, assert the two outputs are byte-identical, assert the compiled path
     * was taken, and assert the sandbox never compiled. Returns the output.
     */
    private function assertByteIdentical(string $tpl, array $data): string
    {
        $compiledEngine = new Frond();
        $compiled = $compiledEngine->renderString($tpl, $data);
        $this->assertCompiledPath($compiledEngine);

        $interpEngine = new Frond();
        $interpEngine->sandbox(); // no restrictions passed => everything allowed, but forces the interpreter
        $interp = $interpEngine->renderString($tpl, $data);
        $this->assertCount(0, $this->compiledMap($interpEngine), 'a sandboxed engine must never compile');

        $this->assertSame($interp, $compiled, 'compiled output must be byte-identical to the interpreter');
        return $compiled;
    }

    /* ═══════════ byte-identical + compiled-path-taken ═══════════ */

    public function testVariableFilterTernaryCompiledMatchesInterpreter(): void
    {
        $this->assertSame('&lt;b&gt;x&lt;/b&gt;', $this->assertByteIdentical('{{ v }}', ['v' => '<b>x</b>']));
        $this->assertSame('HELLO', $this->assertByteIdentical('{{ name | upper }}', ['name' => 'hello']));
        $this->assertSame('1,234.50', $this->assertByteIdentical('{{ n | number_format(2) }}', ['n' => 1234.5]));
        $this->assertSame('yes', $this->assertByteIdentical('{{ a ? "yes" : "no" }}', ['a' => true]));
        $this->assertSame('no', $this->assertByteIdentical('{{ a ? "yes" : "no" }}', ['a' => false]));
        $this->assertSame('Hi Bob', $this->assertByteIdentical('{{ "Hi " ~ name }}', ['name' => 'Bob']));
    }

    public function testIfElifElseCompiledMatchesInterpreter(): void
    {
        $tpl = '{% if n > 5 %}big{% elseif n > 0 %}small{% else %}zero{% endif %}';
        $this->assertSame('big', $this->assertByteIdentical($tpl, ['n' => 9]));
        $this->assertSame('small', $this->assertByteIdentical($tpl, ['n' => 3]));
        $this->assertSame('zero', $this->assertByteIdentical($tpl, ['n' => 0]));
        // `elseif` and `elif` are both accepted by the parser.
        $this->assertSame('B', $this->assertByteIdentical('{% if a %}A{% elif b %}B{% endif %}', ['a' => false, 'b' => true]));
        // No branch matches, no else => empty (matches executeIf returning '').
        $this->assertSame('', $this->assertByteIdentical('{% if a %}A{% endif %}', ['a' => false]));
    }

    public function testForWithLoopVarsCompiledMatchesInterpreter(): void
    {
        $tpl = '{% for item in items %}[{{ loop.index }}/{{ loop.length }}:{{ item | upper }}'
             . '{{ loop.first ? "F" : "" }}{{ loop.last ? "L" : "" }}{{ loop.even ? "e" : "o" }}]{% endfor %}';
        $out = $this->assertByteIdentical($tpl, ['items' => ['a', 'b', 'c']]);
        $this->assertSame('[1/3:AFo][2/3:Be][3/3:CLo]', $out);
    }

    public function testForElseCompiledMatchesInterpreter(): void
    {
        $tpl = '{% for x in items %}{{ x }}{% else %}EMPTY{% endfor %}';
        $this->assertSame('EMPTY', $this->assertByteIdentical($tpl, ['items' => []]));
        $this->assertSame('EMPTY', $this->assertByteIdentical($tpl, ['items' => null]));
        $this->assertSame('12', $this->assertByteIdentical($tpl, ['items' => [1, 2]]));
    }

    public function testForKeyValueCompiledMatchesInterpreter(): void
    {
        $out = $this->assertByteIdentical('{% for k, v in m %}{{ k }}={{ v }};{% endfor %}', ['m' => ['x' => 1, 'y' => 2]]);
        $this->assertSame('x=1;y=2;', $out);
    }

    public function testNestedForCompiledMatchesInterpreter(): void
    {
        $tpl = '{% for i in xs %}{% for j in ys %}{{ i }}{{ j }} {% endfor %}{% endfor %}';
        $out = $this->assertByteIdentical($tpl, ['xs' => [1, 2], 'ys' => ['a', 'b']]);
        $this->assertSame('1a 1b 2a 2b ', $out);
    }

    public function testLoopVarDoesNotLeakAfterForCompiledMatchesInterpreter(): void
    {
        // valueVar/keyVar/loop are restored after the loop (standard Twig scoping).
        $tpl = '{% for item in items %}{{ item }}{% endfor %}|{{ item ?? "gone" }}';
        $out = $this->assertByteIdentical($tpl, ['items' => ['a', 'b']]);
        $this->assertSame('ab|gone', $out);
    }

    public function testSetCompiledMatchesInterpreter(): void
    {
        $this->assertSame('42', $this->assertByteIdentical('{% set t = n * 2 %}{{ t }}', ['n' => 21]));
        // set inside a loop accumulates (set vars are NOT loop-scoped).
        $tpl = '{% set total = 0 %}{% for p in prices %}{% set total = total + p %}{% endfor %}{{ total }}';
        $this->assertSame('6', $this->assertByteIdentical($tpl, ['prices' => [1, 2, 3]]));
    }

    public function testCommentsCompiledMatchesInterpreter(): void
    {
        $this->assertSame('hi', $this->assertByteIdentical('{# a comment #}hi', []));
        $this->assertSame('ab', $this->assertByteIdentical('a{# mid #}b', []));
    }

    public function testRawBlockCompiledMatchesInterpreter(): void
    {
        // {% raw %} content is turned into literal TEXT by the tokenizer.
        $this->assertSame('{{ notvar }}', $this->assertByteIdentical('{% raw %}{{ notvar }}{% endraw %}', []));
    }

    public function testWhitespaceControlBlockCompiledMatchesInterpreter(): void
    {
        // {%- trims trailing ws of the previous text; -%} trims leading ws of the next.
        $out = $this->assertByteIdentical("a\n{%- if x %}b{% endif -%}\nc", ['x' => true]);
        $this->assertSame('abc', $out);
    }

    public function testWhitespaceControlVariableCompiledMatchesInterpreter(): void
    {
        // {{- -}} trims whitespace on both sides.
        $out = $this->assertByteIdentical('x  {{- v -}}  y', ['v' => 'V']);
        $this->assertSame('xVy', $out);
    }

    public function testRawAndSafeFilterCompiledMatchesInterpreter(): void
    {
        $this->assertSame('<b>x</b>', $this->assertByteIdentical('{{ h | raw }}', ['h' => '<b>x</b>']));
        $this->assertSame('<b>x</b>', $this->assertByteIdentical('{{ h | safe }}', ['h' => '<b>x</b>']));
    }

    /* ═══════════ file render (prod-by-name key) ═══════════ */

    public function testFileRenderCompilesAndCachesByName(): void
    {
        $this->writeTemplate('page.twig', 'Hi {{ name }}, {% for i in items %}{{ i }}{% endfor %}');
        $engine = new Frond($this->templateDir);
        $first = $engine->render('page.twig', ['name' => 'Al', 'items' => [1, 2, 3]]);
        $this->assertSame('Hi Al, 123', $first);
        $this->assertCompiledPath($engine); // keyed by template name

        // Second render hits the cached closure and produces identical output.
        $second = $engine->render('page.twig', ['name' => 'Bo', 'items' => [4, 5]]);
        $this->assertSame('Hi Bo, 45', $second);
        $this->assertCount(1, $this->compiledMap($engine), 'the compiled closure is reused, not recompiled');
    }

    /* ═══════════ fallback (unsupported constructs) ═══════════ */

    public function testExtendsFallsBackButRendersCorrectly(): void
    {
        $this->writeTemplate('base.twig', '<html>{% block body %}base{% endblock %}</html>');
        $this->writeTemplate('child.twig', '{% extends "base.twig" %}{% block body %}child{% endblock %}');
        $engine = new Frond($this->templateDir);
        $out = $engine->render('child.twig', []);
        $this->assertSame('<html>child</html>', $out);
        $this->assertFellBack($engine);
    }

    public function testIncludeFallsBackButRendersCorrectly(): void
    {
        $this->writeTemplate('partial.twig', 'PART:{{ x }}');
        $this->writeTemplate('main.twig', 'A {% include "partial.twig" %} B');
        $engine = new Frond($this->templateDir);
        $out = $engine->render('main.twig', ['x' => 1]);
        $this->assertSame('A PART:1 B', $out);
        $this->assertFellBack($engine);
    }

    public function testSpacelessFallsBackButRendersCorrectly(): void
    {
        $engine = new Frond();
        $out = $engine->renderString('{% spaceless %}<a>  <b>x</b></a>{% endspaceless %}', []);
        $this->assertSame('<a><b>x</b></a>', $out);
        $this->assertFellBack($engine);
    }

    public function testMacroFallsBack(): void
    {
        $engine = new Frond();
        $engine->renderString('{% macro hi(n) %}Hi {{ n }}{% endmacro %}{{ hi("Al") }}', []);
        $this->assertFellBack($engine);
    }

    public function testUnsupportedNodeInsideIfBodyFallsBack(): void
    {
        // An include nested inside a compilable if makes the WHOLE template fall back.
        $this->writeTemplate('p.twig', 'P');
        $this->writeTemplate('wrap.twig', '{% if show %}{% include "p.twig" %}{% endif %}');
        $engine = new Frond($this->templateDir);
        $out = $engine->render('wrap.twig', ['show' => true]);
        $this->assertSame('P', $out);
        $this->assertFellBack($engine);
    }

    /* ═══════════ cache lifecycle ═══════════ */

    public function testClearCacheClearsCompiledClosures(): void
    {
        $engine = new Frond();
        $engine->renderString('{{ a }}', ['a' => 1]);
        $this->assertCount(1, $this->compiledMap($engine));
        $engine->clearCache();
        $this->assertCount(0, $this->compiledMap($engine), 'clearCache() must clear the compiled-closure cache');
        // Re-render recompiles cleanly.
        $this->assertSame('2', $engine->renderString('{{ a }}', ['a' => 2]));
        $this->assertCompiledPath($engine);
    }

    public function testSandboxNeverCompilesButRendersCorrectly(): void
    {
        // Even a compilable template renders through the interpreter under sandbox
        // (the tag/filter/var gates live on the interpreter path), so no closure is
        // ever produced. sandbox() with no args allows everything, isolating the
        // "sandbox always interprets" contract from the restriction semantics.
        $engine = new Frond();
        $engine->sandbox();
        $out = $engine->renderString('{% for i in items %}{{ i | upper }}{% endfor %}', ['items' => ['a', 'b']]);
        $this->assertSame('AB', $out);
        $this->assertCount(0, $this->compiledMap($engine), 'sandbox mode must always interpret, never compile');
    }
}
