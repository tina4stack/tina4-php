<?php

/**
 * {% import "file" as alias %} — load every macro in a file under one namespace.
 *
 * PHP, Ruby and Node did not implement this tag at all: it was silently ignored and
 * {{ m.greet("Andre") }} rendered as EMPTY, which is worse than an error because a
 * template using it fails with no signal. Only Python had it (and there it shifted
 * every argument — fixed separately in the Python master).
 *
 * Implementation note: macros are registered in the SAME registry as {% from %}
 * import, under the dotted key "alias.name", and evaluateFunctionCall checks that
 * registry BEFORE the dotted-object branch. So an aliased call reuses callMacro and
 * gets identical argument binding, default handling and raw-output marking.
 *
 * Every expectation was verified against a real Python render of the same templates
 * (Python is the reference implementation), so all four frameworks agree byte-for-byte.
 *
 * No mocks: real .twig files in a real temp dir through the real engine.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Frond;

final class FrondImportAsTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tina4_frond_importas_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
        file_put_contents(
            $this->dir . '/macros.twig',
            "{% macro greet(name, greeting='Hello') %}<p>{{ greeting }}, {{ name }}!</p>{% endmacro %}"
            . "{% macro shout(w) %}<b>{{ w }}</b>{% endmacro %}"
            . "{% macro three(a, b, c) %}[{{ a }}|{{ b }}|{{ c }}]{% endmacro %}"
        );
    }

    protected function tearDown(): void
    {
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

    public function testAliasedMacroReceivesItsArgument(): void
    {
        $out = $this->render('{% import "macros.twig" as m %}{{ m.greet("Andre") }}');
        $this->assertSame('<p>Hello, Andre!</p>', $out);
    }

    public function testAliasedMacroHonoursASecondArgument(): void
    {
        $out = $this->render('{% import "macros.twig" as m %}{{ m.greet("Ann","Yo") }}', 'two.twig');
        $this->assertSame('<p>Yo, Ann!</p>', $out);
    }

    public function testAliasedMacroArgumentsAreNotShifted(): void
    {
        $out = $this->render('{% import "macros.twig" as m %}{{ m.three(1, 2, 3) }}', 'three.twig');
        $this->assertSame('[1|2|3]', $out);
    }

    public function testAliasExposesEveryMacroInTheFile(): void
    {
        $out = $this->render(
            '{% import "macros.twig" as m %}{{ m.shout("x") }}{{ m.greet("Z") }}',
            'many.twig'
        );
        $this->assertSame('<b>x</b><p>Hello, Z!</p>', $out);
    }

    public function testImportAsMatchesFromImportExactly(): void
    {
        $asOut = $this->render('{% import "macros.twig" as m %}{{ m.greet("Andre") }}', 'cmp_as.twig');
        $fromOut = $this->render('{% from "macros.twig" import greet %}{{ greet("Andre") }}', 'cmp_from.twig');
        $this->assertSame($fromOut, $asOut, 'the two import forms must render identically');
    }

    // ------------------------------------------------------------- negative

    public function testAliasedMacroDoesNotRenderEmpty(): void
    {
        $out = $this->render('{% import "macros.twig" as m %}{{ m.greet("Andre") }}', 'neg1.twig');
        $this->assertNotSame('', $out, 'the import tag was ignored (the pre-implementation behaviour)');
        $this->assertStringContainsString('Andre', $out);
    }

    public function testNamespaceObjectNeverLeaksIntoOutput(): void
    {
        $out = $this->render('{% import "macros.twig" as m %}{{ m.greet("Andre") }}', 'neg2.twig');
        $this->assertStringNotContainsString('Namespace', $out);
        $this->assertStringNotContainsString('Object', $out);
        $this->assertDoesNotMatchRegularExpression('/0x[0-9a-f]+/', $out, 'an object address leaked');
    }

    public function testMalformedImportTagRendersNothingAndDoesNotCrash(): void
    {
        $out = $this->render('{% import "macros.twig" %}after', 'bad.twig');
        $this->assertSame('after', $out, 'a malformed import must stay silent, not crash');
    }
}
