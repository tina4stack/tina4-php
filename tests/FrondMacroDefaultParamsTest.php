<?php

/**
 * Contract lock: a macro parameter declared WITH a default must work.
 *
 * PHP was already CORRECT here -- this test exists so it stays correct. Ruby and
 * Node both split the macro parameter list on "," only, which produced a
 * parameter literally NAMED "greeting='Hello'": the body's {{ greeting }} matched
 * no key (rendered empty) AND the caller's positional argument was stored under
 * that junk key and lost.
 *
 *   {% macro d(a, b='B') %}[{{ a }}|{{ b }}]{% endmacro %}{{ d(1) }}{{ d(1,2) }}
 *   Ruby/Node before their fix: "[1|][1|]"    <- default gone AND the explicit 2 gone
 *   PHP / Python / fixed:       "[1|B][1|2]"
 *
 * Parameters with no default always worked in every framework, which is why the
 * Ruby/Node defect hid for so long. These expectations were verified against a
 * real Python render (Python is the reference implementation) of the same
 * templates, so all four frameworks now agree byte-for-byte.
 *
 * No mocks: writes real .twig files to a real temp dir and renders them through
 * the real Frond engine.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Frond;

final class FrondMacroDefaultParamsTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tina4_frond_macro_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Reap what you create — leave no temp files behind.
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function render(string $source, string $name = 't.twig'): string
    {
        file_put_contents($this->dir . '/' . $name, $source);
        return (new Frond($this->dir))->render($name, []);
    }

    // ------------------------------------------------------------- positive

    public function testSingleQuotedDefaultAppliesWhenArgumentOmitted(): void
    {
        $out = $this->render("{% macro d(a, b='B') %}[{{ a }}|{{ b }}]{% endmacro %}{{ d(1) }}");
        $this->assertSame('[1|B]', $out);
    }

    public function testDoubleQuotedDefaultAppliesWhenArgumentOmitted(): void
    {
        $out = $this->render('{% macro d(a, b="dq") %}[{{ a }}|{{ b }}]{% endmacro %}{{ d(1) }}');
        $this->assertSame('[1|dq]', $out);
    }

    public function testExplicitArgumentOverridesTheDefault(): void
    {
        $out = $this->render("{% macro d(a, b='B') %}[{{ a }}|{{ b }}]{% endmacro %}{{ d(1,2) }}");
        $this->assertSame('[1|2]', $out);
    }

    public function testParametersWithoutDefaultsStillBind(): void
    {
        $out = $this->render('{% macro t(a, b, c) %}[{{ a }}|{{ b }}|{{ c }}]{% endmacro %}{{ t(1,2,3) }}');
        $this->assertSame('[1|2|3]', $out);
    }

    public function testDefaultsSurviveFromImport(): void
    {
        file_put_contents(
            $this->dir . '/macros.twig',
            "{% macro greet(name, greeting='Hello') %}<p>{{ greeting }}, {{ name }}!</p>{% endmacro %}"
        );
        $out = $this->render(
            '{% from "macros.twig" import greet %}{{ greet("Andre") }}|{{ greet("Ann","Yo") }}',
            'fromimp.twig'
        );
        $this->assertSame('<p>Hello, Andre!</p>|<p>Yo, Ann!</p>', $out);
    }

    // ------------------------------------------------------------- negative

    public function testDefaultIsNotRenderedAsAnEmptyValue(): void
    {
        $out = $this->render("{% macro d(a, b='B') %}[{{ a }}|{{ b }}]{% endmacro %}{{ d(1) }}");
        $this->assertNotSame('[1|]', $out, 'the default was dropped (the Ruby/Node pre-fix behaviour)');
        $this->assertStringContainsString('B', $out);
    }

    public function testExplicitArgumentIsNotSilentlyDropped(): void
    {
        $out = $this->render("{% macro d(a, b='B') %}[{{ a }}|{{ b }}]{% endmacro %}{{ d(1,2) }}");
        $this->assertNotSame('[1|]', $out, 'the explicit argument was swallowed (the Ruby/Node pre-fix behaviour)');
        $this->assertStringContainsString('2', $out);
    }

    public function testDefaultDeclarationSyntaxNeverLeaksIntoOutput(): void
    {
        $out = $this->render("{% macro d(a, b='B') %}[{{ a }}|{{ b }}]{% endmacro %}{{ d(1) }}");
        $this->assertStringNotContainsString('=', $out);
        $this->assertStringNotContainsString("'", $out);
    }
}
