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

    // ── Color functions (issue #124) ────────────────────────────

    public function testRgbaHexBecomesValidCss(): void
    {
        // rgba(<hex>, a) is invalid CSS; must become rgba(r, g, b, a).
        $css = $this->compiler->compile('$c: #0f3460; .box { box-shadow: 0 0 4px rgba($c, 0.12); }');
        $this->assertStringContainsString('rgba(15, 52, 96, 0.12)', $css);
        $this->assertStringNotContainsString('rgba(#', $css);
    }

    public function testRgbaNumericFormUntouched(): void
    {
        $css = $this->compiler->compile('.box { color: rgba(10, 20, 30, 0.4); }');
        $this->assertStringContainsString('rgba(10, 20, 30, 0.4)', $css);
    }

    public function testRgbHexBecomesValidCss(): void
    {
        $css = $this->compiler->compile('.box { color: rgb(#ffffff); }');
        $this->assertStringContainsString('rgb(255, 255, 255)', $css);
    }

    public function testMix(): void
    {
        $css = $this->compiler->compile('.box { background: mix(#ffffff, #000000, 50%); }');
        $this->assertStringContainsString('#808080', $css);
        $this->assertStringNotContainsString('mix(', $css);
    }

    public function testLightenDarkenEvaluated(): void
    {
        $css = $this->compiler->compile('.box { color: lighten(#0f3460, 20%); border-color: darken(#336699, 10%); }');
        $this->assertStringNotContainsString('lighten(', $css);
        $this->assertStringNotContainsString('darken(', $css);
        // Byte-parity with the Python master.
        $this->assertStringContainsString('#1c63b8', $css);
        $this->assertStringContainsString('#264c72', $css);
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

    // ── !default flag ──────────────────────────────────────────────
    // The `!default` flag means "assign only if this variable is not already
    // set". It is a compiler directive and must never reach the CSS: `padding:
    // 1.5rem !default` is invalid CSS and browsers drop the declaration.
    // Reference behaviour for every case below was measured against Dart Sass
    // 1.101.6.

    public function testDefaultFlagNeverReachesOutput(): void
    {
        $css = $this->compiler->compile("\$g: 1.5rem !default;\n.x { padding: \$g; }");
        $this->assertStringContainsString('padding: 1.5rem', $css);
        $this->assertStringNotContainsString('!default', $css);
    }

    public function testDefaultDoesNotOverwriteAnAlreadySetVariable(): void
    {
        // The themeing contract: a user sets $primary BEFORE the partial that
        // declares it !default, and must keep their value.
        $css = $this->compiler->compile(
            "\$primary: red;\n\$primary: blue !default;\n.y { color: \$primary; }"
        );
        $this->assertStringContainsString('color: red', $css);
        $this->assertStringNotContainsString('blue', $css);
        $this->assertStringNotContainsString('!default', $css);
    }

    public function testDefaultDoesAssignAnUnsetVariable(): void
    {
        $css = $this->compiler->compile("\$primary: blue !default;\n.y { color: \$primary; }");
        $this->assertStringContainsString('color: blue', $css);
        $this->assertStringNotContainsString('!default', $css);
    }

    public function testNullCountsAsUnset(): void
    {
        // Sass treats a null variable as unset, so !default fills it.
        $css = $this->compiler->compile("\$c: null;\n\$c: teal !default;\n.w { color: \$c; }");
        $this->assertStringContainsString('color: teal', $css);
        $this->assertStringNotContainsString('null', $css);
    }

    public function testFirstDefaultWinsOverSecondDefault(): void
    {
        $css = $this->compiler->compile(
            "\$a: 1rem !default;\n\$a: 2rem !default;\n.v { margin: \$a; }"
        );
        $this->assertStringContainsString('margin: 1rem', $css);
        $this->assertStringNotContainsString('2rem', $css);
    }

    public function testPlainDeclarationAfterDefaultDoesOverwrite(): void
    {
        // !default only guards against being overwritten; a plain assignment wins.
        $css = $this->compiler->compile("\$a: 1rem !default;\n\$a: 2rem;\n.v { margin: \$a; }");
        $this->assertStringContainsString('margin: 2rem', $css);
    }

    public function testGlobalFlagIsAlsoConsumed(): void
    {
        $css = $this->compiler->compile("\$a: 5px !default !global;\n.i { top: \$a; }");
        $this->assertStringContainsString('top: 5px', $css);
        $this->assertStringNotContainsString('!default', $css);
        $this->assertStringNotContainsString('!global', $css);
    }

    public function testMultilineDefaultDeclarationIsConsumed(): void
    {
        // A map literal spanning lines with the flag on the closing line - the
        // exact shape tina4-css's _variables.scss uses.
        $scss = "\$m: (\n  \"a\": 1,\n  \"b\": 2\n) !default;\n.m { z-index: 1; }";
        $css = $this->compiler->compile($scss);
        $this->assertStringNotContainsString('!default', $css);
        $this->assertStringNotContainsString('$m', $css);
    }

    public function testLiteralDefaultInAStringIsPreserved(): void
    {
        // NEGATIVE guard on the strip's scope: only a *variable declaration*
        // value is stripped. Dart Sass keeps this string verbatim; so must we.
        $css = $this->compiler->compile('.s { content: "x !default y"; }');
        $this->assertStringContainsString('"x !default y"', $css);
    }

    public function testDefaultInAFunctionArgumentIsLeftVerbatim(): void
    {
        // NEGATIVE guard: `rgba(#000 !default, .1)` is a syntax error in Dart
        // Sass and never appears in valid SCSS. We do not silently "fix" it into
        // something Sass would never emit - it is not a variable declaration, so
        // it is left exactly as written and stays visibly wrong.
        $css = $this->compiler->compile('.z { color: rgba(#000 !default, 0.1); }');
        $this->assertStringContainsString('!default', $css);
    }

    public function testHexVariableWithDefaultIsUsableInsideRgba(): void
    {
        // The real-world case behind the 41 broken rgba() calls: the flag rode
        // inside the stored value and corrupted every function call using it.
        $css = $this->compiler->compile(
            "\$black: #000 !default;\n.z { box-shadow: 0 1px 2px rgba(\$black, 0.075); }"
        );
        $this->assertStringContainsString('rgba(0, 0, 0, 0.075)', $css);
        $this->assertStringNotContainsString('!default', $css);
    }

    public function testOverrideSurvivesAnImportOfARealPartial(): void
    {
        // End-to-end over REAL files: set the variable, then @import a partial
        // that declares it !default. The override must win.
        $dir = sys_get_temp_dir() . '/tina4_scss_default_' . uniqid();
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/_theme.scss', "\$primary: navy !default;\n\$accent: gold !default;\n");
        file_put_contents(
            $dir . '/main.scss',
            "\$primary: hotpink;\n@import \"theme\";\n.k { color: \$primary; border-color: \$accent; }\n"
        );
        try {
            $css = (new ScssCompiler())->compileFile($dir . '/main.scss');
            $this->assertStringContainsString('color: hotpink', $css);
            $this->assertStringContainsString('border-color: gold', $css);
            $this->assertStringNotContainsString('navy', $css);
            $this->assertStringNotContainsString('!default', $css);
        } finally {
            @unlink($dir . '/_theme.scss');
            @unlink($dir . '/main.scss');
            @rmdir($dir);
        }
    }

    public function testPresetVariableBeatsASourceDefault(): void
    {
        $compiler = new ScssCompiler();
        $compiler->setVariable('primary', 'rebeccapurple');
        $css = $compiler->compile("\$primary: navy !default;\n.p { color: \$primary; }");
        $this->assertStringContainsString('color: rebeccapurple', $css);
        $this->assertStringNotContainsString('navy', $css);
    }

    public function testCompileScssDirectoryStripsTheFlag(): void
    {
        $dir = sys_get_temp_dir() . '/tina4_scss_dir_' . uniqid();
        mkdir($dir . '/scss', 0777, true);
        file_put_contents($dir . '/scss/_vars.scss', "\$gap: 4px !default;\n");
        file_put_contents($dir . '/scss/app.scss', "@import \"vars\";\n.a { margin: \$gap; }\n");
        $out = $dir . '/css/default.css';
        try {
            $css = (new ScssCompiler())->compileScss($dir . '/scss', $out);
            $this->assertStringContainsString('margin: 4px', $css);
            $this->assertStringNotContainsString('!default', $css);
            $this->assertStringNotContainsString('!default', (string)file_get_contents($out));
        } finally {
            @unlink($dir . '/scss/_vars.scss');
            @unlink($dir . '/scss/app.scss');
            @unlink($out);
            @rmdir($dir . '/css');
            @rmdir($dir . '/scss');
            @rmdir($dir);
        }
    }
}
