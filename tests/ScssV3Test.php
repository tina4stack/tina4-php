<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\ScssCompiler;

class ScssV3Test extends TestCase
{
    private ScssCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new ScssCompiler();
    }

    public function testVariableSubstitution(): void
    {
        $scss = '$color: #333; .text { color: $color; }';
        $css = $this->compiler->compile($scss);
        $this->assertStringContainsString('#333', $css);
        $this->assertStringNotContainsString('$color', $css);
    }

    public function testVariableInVariable(): void
    {
        $scss = '$base: 10px; $double: $base; .box { width: $double; }';
        $css = $this->compiler->compile($scss);
        $this->assertStringContainsString('10px', $css);
    }

    public function testBasicNesting(): void
    {
        $scss = '.parent { .child { color: red; } }';
        $css = $this->compiler->compile($scss);
        $this->assertStringContainsString('.parent .child', $css);
        $this->assertStringContainsString('color: red', $css);
    }

    public function testParentSelector(): void
    {
        $scss = '.btn { &:hover { color: blue; } }';
        $css = $this->compiler->compile($scss);
        $this->assertStringContainsString('.btn:hover', $css);
        $this->assertStringContainsString('color: blue', $css);
    }

    public function testParentSelectorWithSuffix(): void
    {
        $scss = '.btn { &--primary { background: green; } }';
        $css = $this->compiler->compile($scss);
        $this->assertStringContainsString('.btn--primary', $css);
    }

    public function testSingleLineCommentStripped(): void
    {
        $scss = "// This is a comment\n.box { color: red; }";
        $css = $this->compiler->compile($scss);
        $this->assertStringNotContainsString('This is a comment', $css);
        $this->assertStringContainsString('color: red', $css);
    }

    public function testMultiLineCommentPreserved(): void
    {
        $scss = "/* Keep this */\n.box { color: red; }";
        $css = $this->compiler->compile($scss);
        $this->assertStringContainsString('/* Keep this */', $css);
    }

    public function testBasicMixin(): void
    {
        $scss = '@mixin rounded { border-radius: 5px; } .box { @include rounded; }';
        $css = $this->compiler->compile($scss);
        $this->assertStringContainsString('border-radius: 5px', $css);
        $this->assertStringNotContainsString('@mixin', $css);
        $this->assertStringNotContainsString('@include', $css);
    }

    public function testMixinWithParameters(): void
    {
        $scss = '@mixin size($w, $h) { width: $w; height: $h; } .box { @include size(100px, 50px); }';
        $css = $this->compiler->compile($scss);
        $this->assertStringContainsString('width: 100px', $css);
        $this->assertStringContainsString('height: 50px', $css);
    }

    public function testMathAddition(): void
    {
        $scss = '.box { width: 10px + 5px; }';
        $css = $this->compiler->compile($scss);
        $this->assertStringContainsString('15px', $css);
    }

    public function testMathMultiplication(): void
    {
        $scss = '.box { width: 10px * 2; }';
        $css = $this->compiler->compile($scss);
        $this->assertStringContainsString('20px', $css);
    }

    public function testMathDivision(): void
    {
        $scss = '.box { width: 100px / 4; }';
        $css = $this->compiler->compile($scss);
        $this->assertStringContainsString('25px', $css);
    }

    // ── Mixed-unit arithmetic — regression for tina4-php#116 ──
    //
    // Before the fix, the evaluator extracted the unit from the first
    // operand only and dropped the second's, silently producing wrong CSS:
    //   100vh - 170px → -70vh (negative, layout-breaking)
    //   100% - 20px   → 80%
    // After the fix, mixed-unit expressions are left verbatim so the
    // browser computes them — that is exactly what calc() is for.

    public function testMixedUnitsOutsideCalcLeftVerbatim(): void
    {
        $scss = '.box { max-height: 100vh - 170px; }';
        $css = $this->compiler->compile($scss);
        $this->assertStringContainsString('100vh', $css);
        $this->assertStringContainsString('170px', $css);
        $this->assertStringNotContainsString('-70vh', $css);
    }

    public function testMixedUnitsPercentPxLeftVerbatim(): void
    {
        $scss = '.box { width: 100% - 20px; }';
        $css = $this->compiler->compile($scss);
        $this->assertStringContainsString('100%', $css);
        $this->assertStringContainsString('20px', $css);
        $this->assertStringNotContainsString('80%', $css);
    }

    public function testCalcWithMixedUnitsPreserved(): void
    {
        $scss = '.box { max-height: calc(100vh - 170px); }';
        $css = $this->compiler->compile($scss);
        $this->assertStringContainsString('calc(100vh - 170px)', $css);
    }

    public function testCalcWithPercentAndPxPreserved(): void
    {
        $scss = '.box { width: calc(50% + 10px); }';
        $css = $this->compiler->compile($scss);
        $this->assertStringContainsString('calc(50% + 10px)', $css);
    }

    public function testSameUnitStillFolds(): void
    {
        // Regression guard — the fix must NOT break valid arithmetic.
        $scss = '.box { width: 10px + 5px; padding: 1rem + 2rem; }';
        $css = $this->compiler->compile($scss);
        $this->assertStringContainsString('15px', $css);
        $this->assertStringContainsString('3rem', $css);
    }

    public function testUnitlessMultiplicationStillFolds(): void
    {
        // 2 * 5px is valid CSS-style arithmetic and should fold.
        $scss = '.box { width: 2 * 5px; }';
        $css = $this->compiler->compile($scss);
        $this->assertStringContainsString('10px', $css);
    }

    public function testDeepNesting(): void
    {
        $scss = '.a { .b { .c { color: red; } } }';
        $css = $this->compiler->compile($scss);
        $this->assertStringContainsString('.a .b .c', $css);
    }

    public function testMultipleSelectorsInNesting(): void
    {
        $scss = '.parent { h1, h2 { font-weight: bold; } }';
        $css = $this->compiler->compile($scss);
        $this->assertStringContainsString('.parent h1', $css);
        $this->assertStringContainsString('.parent h2', $css);
    }

    public function testSetVariableViaApi(): void
    {
        $compiler = new ScssCompiler();
        $compiler->setVariable('$primary', '#ff0000');
        $css = $compiler->compile('.btn { color: $primary; }');
        $this->assertStringContainsString('#ff0000', $css);
    }

    public function testImportFile(): void
    {
        $tempDir = sys_get_temp_dir() . '/tina4_scss_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        file_put_contents($tempDir . '/_variables.scss', '$bg: #fff;');
        file_put_contents($tempDir . '/main.scss', "@import 'variables';\n.body { background: \$bg; }");

        $compiler = new ScssCompiler();
        $css = $compiler->compileFile($tempDir . '/main.scss');
        $this->assertStringContainsString('#fff', $css);

        // Cleanup
        unlink($tempDir . '/_variables.scss');
        unlink($tempDir . '/main.scss');
        rmdir($tempDir);
    }

    public function testMediaQueryNesting(): void
    {
        $scss = '.container { @media (max-width: 768px) { width: 100%; } }';
        $css = $this->compiler->compile($scss);
        $this->assertStringContainsString('@media', $css);
        $this->assertStringContainsString('width: 100%', $css);
    }

    public function testPropertiesAndNestedBlocks(): void
    {
        $scss = '.card { padding: 10px; .title { font-size: 16px; } }';
        $css = $this->compiler->compile($scss);
        $this->assertStringContainsString('.card {', $css);
        $this->assertStringContainsString('padding: 10px', $css);
        $this->assertStringContainsString('.card .title', $css);
        $this->assertStringContainsString('font-size: 16px', $css);
    }

    public function testEmptyInput(): void
    {
        $css = $this->compiler->compile('');
        $this->assertSame('', $css);
    }

    // -- Import with underscore partial prefix --------------------------------

    public function testImportPartialNotCompiledStandalone(): void
    {
        $tempDir = sys_get_temp_dir() . '/tina4_scss_import_' . uniqid();
        mkdir($tempDir, 0777, true);

        file_put_contents($tempDir . '/_helpers.scss', '.helper { display: block; }');
        file_put_contents($tempDir . '/app.scss', '.app { color: red; }');

        $compiler = new ScssCompiler();
        $css = $compiler->compileFile($tempDir . '/app.scss');
        $this->assertStringContainsString('.app', $css);

        unlink($tempDir . '/_helpers.scss');
        unlink($tempDir . '/app.scss');
        rmdir($tempDir);
    }

    // -- Mixin without parameters (basic test expansion) ----------------------

    public function testMixinWithoutParamsExpands(): void
    {
        $scss = '@mixin clearfix { overflow: hidden; display: block; } .container { @include clearfix; color: blue; }';
        $css = $this->compiler->compile($scss);
        $this->assertStringContainsString('overflow: hidden', $css);
        $this->assertStringContainsString('display: block', $css);
        $this->assertStringContainsString('color: blue', $css);
    }

    // -- Variable used in multiple properties --------------------------------

    public function testVariableInMultiplePlaces(): void
    {
        $scss = '$primary: blue; .btn { color: $primary; border: 1px solid $primary; }';
        $css = $this->compiler->compile($scss);
        $numBlue = substr_count($css, 'blue');
        $this->assertGreaterThanOrEqual(2, $numBlue);
    }

    // -- Multiple selectors in nesting ---------------------------------------

    public function testMultipleSelectorsInNestingBothExpanded(): void
    {
        $scss = '.parent { h1, h2 { color: blue; } }';
        $css = $this->compiler->compile($scss);
        $this->assertStringContainsString('.parent h1', $css);
        $this->assertStringContainsString('.parent h2', $css);
    }

    // -- Math subtraction ----------------------------------------------------

    public function testMathSubtraction(): void
    {
        $scss = '.box { margin: 20px - 5px; }';
        $css = $this->compiler->compile($scss);
        $this->assertStringContainsString('15px', $css);
    }

    // -- Media query within nesting produces correct output ------------------

    public function testMediaQueryNestingCorrectOutput(): void
    {
        $scss = '.container { width: 960px; @media (max-width: 768px) { width: 100%; } }';
        $css = $this->compiler->compile($scss);
        $this->assertStringContainsString('@media', $css);
        $this->assertStringContainsString('max-width: 768px', $css);
        $this->assertStringContainsString('width: 100%', $css);
        $this->assertStringContainsString('width: 960px', $css);
    }

    // -- Comment only input produces no rules --------------------------------

    public function testCommentOnlyInput(): void
    {
        $scss = "// Just a comment\n// Another comment";
        $css = $this->compiler->compile($scss);
        $this->assertStringNotContainsString('Just a comment', $css);
    }

    public function testCompileScssDirectory(): void
    {
        $tempDir = sys_get_temp_dir() . '/tina4_scss_dir_' . uniqid();
        mkdir($tempDir, 0777, true);

        file_put_contents($tempDir . '/main.scss', '.main { color: green; }');
        file_put_contents($tempDir . '/_partial.scss', '$x: red;');

        $output = sys_get_temp_dir() . '/tina4_scss_out_' . uniqid() . '/default.css';

        $compiler = new ScssCompiler();
        $css = $compiler->compileScss($tempDir, $output);
        $this->assertStringContainsString('.main', $css);
        $this->assertStringContainsString('color: green', $css);
        $this->assertFileExists($output);

        // Cleanup
        unlink($tempDir . '/main.scss');
        unlink($tempDir . '/_partial.scss');
        rmdir($tempDir);
        unlink($output);
        rmdir(dirname($output));
    }

    public function testCompileScssDirectoryNonexistent(): void
    {
        $compiler = new ScssCompiler();
        $css = $compiler->compileScss('/nonexistent/path');
        $this->assertSame('', $css);
    }

    // ── #{ } interpolation (issue #116) ──────────────────────────

    public function testInterpolationVariableInsideCalc(): void
    {
        $css = $this->compiler->compile("\$gap: 20px;\n.box { width: calc(100% - #{\$gap}); }");
        $this->assertStringContainsString('calc(100% - 20px)', $css);
        $this->assertStringNotContainsString('#{', $css);
        $this->assertStringNotContainsString('$gap', $css);
    }

    public function testInterpolationInSelector(): void
    {
        $css = $this->compiler->compile("\$name: home;\n.icon-#{\$name} { color: red; }");
        $this->assertStringContainsString('.icon-home', $css);
        $this->assertStringNotContainsString('#{', $css);
    }

    public function testInterpolationLiteralInlinesVerbatim(): void
    {
        $css = $this->compiler->compile('.x { margin: #{10px}; }');
        $this->assertStringContainsString('margin: 10px', $css);
        $this->assertStringNotContainsString('#{', $css);
    }

    public function testMixedUnitCalcStillPreserved(): void
    {
        // Regression guard for the already-fixed half: mixed-unit calc preserved.
        $css = $this->compiler->compile('.z { height: calc(100vh - 170px); }');
        $this->assertStringContainsString('calc(100vh - 170px)', $css);
    }
}
