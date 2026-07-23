<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Lock-in: the shipped tina4css assets must be fully compiled CSS.
 *
 * The March 2026 artifacts vendored into all four frameworks contained literal
 * SCSS variables inside calc() -- `calc($grid-gutter / 2)` and
 * `calc($border-radius-lg - 1px)`. A browser treats those as invalid and DROPS
 * the whole declaration, so .container padding, .row negative margins,
 * .row > * padding and the card first/last-child corner radii silently did not
 * apply. PHP was additionally inconsistent: a correctly compiled tina4.css next
 * to a broken tina4.min.css.
 *
 * These tests read the REAL shipped files off disk -- no mocks, no fixtures.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CssAssetsTest extends TestCase
{
    /** A `$name` that is not the CSS `[attr$="x"]` suffix operator. */
    private const UNRESOLVED_VARIABLE = '/\$(?!=)[A-Za-z_][A-Za-z0-9_-]*/';
    private const CALC_WITH_VARIABLE = '/calc\([^()]*\$[^()]*\)/';

    public static function shippedFiles(): array
    {
        return [["tina4.css"], ["tina4.min.css"]];
    }

    private function cssDir(): string
    {
        return dirname(__DIR__) . "/src/public/css";
    }

    private function read(string $name): string
    {
        $path = $this->cssDir() . "/" . $name;
        $this->assertFileExists($path, "shipped asset missing: {$path}");

        return file_get_contents($path);
    }

    /** NEGATIVE: no SCSS variable may survive into the shipped CSS. */
    #[DataProvider('shippedFiles')]
    public function testNoUnresolvedScssVariable(string $name): void
    {
        preg_match_all(self::UNRESOLVED_VARIABLE, $this->read($name), $matches);
        $this->assertSame(
            [],
            array_values(array_unique($matches[0])),
            "{$name} ships unresolved SCSS variables"
        );
    }

    /** NEGATIVE: calc() is the exact construct that leaked -- pin it explicitly. */
    #[DataProvider('shippedFiles')]
    public function testNoCalcContainingAVariable(string $name): void
    {
        preg_match_all(self::CALC_WITH_VARIABLE, $this->read($name), $matches);
        $this->assertSame(
            [],
            array_values(array_unique($matches[0])),
            "{$name} ships calc() with a variable"
        );
    }

    /** POSITIVE: an empty file would pass the negative tests, so assert the values. */
    #[DataProvider('shippedFiles')]
    public function testGridGutterIsResolved(string $name): void
    {
        $css = $this->read($name);
        // The minifier drops a leading zero (0.75rem -> .75rem); accept both.
        $this->assertMatchesRegularExpression(
            '/padding-right:\s*0?\.75rem/',
            $css,
            "{$name} lost the gutter padding"
        );
        $this->assertMatchesRegularExpression(
            '/margin-right:\s*-0?\.75rem/',
            $css,
            "{$name} lost the row negative margin"
        );
    }

    /** POSITIVE: mixed units (rem - px) cannot fold, so a real calc() is correct. */
    #[DataProvider('shippedFiles')]
    public function testCardRadiusIsResolved(string $name): void
    {
        $this->assertMatchesRegularExpression(
            '/calc\(0?\.5rem - 1px\)/',
            $this->read($name),
            "{$name} lost the card radius"
        );
    }

    /** The vendored SCSS must be the source the shipped CSS was built from. */
    public function testShippedCssMatchesTheVendoredScssSource(): void
    {
        $scss = file_get_contents(dirname(__DIR__) . "/src/scss/tina4css/_grid.scss");
        $this->assertStringContainsString('$grid-gutter', $scss);
        // The source legitimately uses `calc($grid-gutter / 2)`; the compiler
        // resolves it. What must never happen is that form reaching shipped CSS.
        $this->assertStringContainsString('calc($grid-gutter / 2)', $scss);
        $this->assertStringNotContainsString('calc($grid-gutter / 2)', $this->read("tina4.css"));
    }

    /** Both shipped files must come from the same build -- PHP had them diverge. */
    public function testMinifiedAndReadableComeFromTheSameBuild(): void
    {
        $readable = $this->read("tina4.css");
        $minified = $this->read("tina4.min.css");
        foreach ([".container", ".row", ".card-header", ".pagination"] as $selector) {
            $this->assertStringContainsString($selector, $readable, "tina4.css missing {$selector}");
            $this->assertStringContainsString($selector, $minified, "tina4.min.css missing {$selector}");
        }
    }
}
