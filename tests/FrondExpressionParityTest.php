<?php

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Frond;

/**
 * Frond expression parity gate -- the cross-framework output contract.
 *
 * WHY THIS FILE EXISTS. "Frond expressions behave the same in all four
 * frameworks" was an assumption, never a measurement. When it was finally
 * measured -- 72 expressions rendered through Python, PHP, Ruby and Node
 * against one identical dataset -- 11 of the 72 disagreed. Booleans disagreed
 * in ALL FOUR (PHP printed false as an EMPTY STRING; Ruby was inconsistent
 * with itself; Python emitted Python's True/False), {{ not x }} was silently
 * dropped in three, and PHP's |json_encode skipped HTML escaping. Each
 * implementation looked correct in isolation, which is exactly why the drift
 * survived for so long.
 *
 * So the corpus is no longer a one-off script -- it is a fixture, and it lives
 * in all four repos as the SAME BYTES:
 *
 *   tina4-python/tests/fixtures/frond_expression_{corpus,expected}.txt
 *   tina4-php/tests/fixtures/...
 *   tina4-ruby/spec/fixtures/...
 *   tina4-nodejs/test/fixtures/...
 *
 * expected.txt is a single agreed answer key, not a per-language snapshot. If
 * one framework drifts, ITS suite goes red while the other three stay green,
 * and the diff names the expression. Changing the contract on purpose means
 * changing the answer key in all four repos in the same change -- the point.
 *
 * Keep the dataset below byte-identical to the other three runners.
 */
class FrondExpressionParityTest extends TestCase
{
    private Frond $engine;

    protected function setUp(): void
    {
        $this->engine = new Frond();
    }

    /**
     * The shared dataset. Must stay identical across all four frameworks -- an
     * expression can only be compared if it is fed the same values.
     *
     * @return array<string, mixed>
     */
    private function context(): array
    {
        return [
            "name" => "Andre",
            "lower_name" => "andre van zuydam",
            "padded" => "  pad  ",
            "empty_str" => "",
            "n" => 5,
            "f" => 1234.5678,
            "neg" => -42,
            "t" => true,
            "f_bool" => false,
            "nil_val" => null,
            "user" => ["name" => "Ann", "addr" => ["city" => "CPT"]],
            "list" => ["a", "b", "c"],
            "map" => ["a" => 1, "b" => 2],
            "html" => "<b>&x</b>",
        ];
    }

    /**
     * Parse a `label<sep>value` fixture into an ordered list of pairs.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function loadFixture(string $file, string $separator): array
    {
        $path = __DIR__ . "/fixtures/" . $file;
        $this->assertFileExists($path, "parity fixture missing: {$file}");
        $pairs = [];
        foreach (explode("\n", (string)file_get_contents($path)) as $line) {
            if (trim($line) === "") {
                continue;
            }
            $pos = strpos($line, $separator);
            $pairs[] = [substr($line, 0, (int)$pos), substr($line, (int)$pos + 1)];
        }
        return $pairs;
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public static function corpusProvider(): array
    {
        $cases = [];
        $path = __DIR__ . "/fixtures/frond_expression_corpus.txt";
        $expectedPath = __DIR__ . "/fixtures/frond_expression_expected.txt";
        $expected = [];
        foreach (explode("\n", (string)file_get_contents($expectedPath)) as $line) {
            if (trim($line) === "") {
                continue;
            }
            $pos = (int)strpos($line, "\t");
            $expected[substr($line, 0, $pos)] = substr($line, $pos + 1);
        }
        foreach (explode("\n", (string)file_get_contents($path)) as $line) {
            if (trim($line) === "") {
                continue;
            }
            $pos = (int)strpos($line, "|");
            $label = substr($line, 0, $pos);
            $cases[$label] = [substr($line, $pos + 1), $expected[$label] ?? "<<NO EXPECTED VALUE>>"];
        }
        return $cases;
    }

    /**
     * Guard the guard: a corpus entry with no expected value would otherwise
     * pass by never being asserted.
     */
    public function testCorpusAndAnswerKeyLineUp(): void
    {
        $corpus = $this->loadFixture("frond_expression_corpus.txt", "|");
        $expected = $this->loadFixture("frond_expression_expected.txt", "\t");
        $this->assertCount(72, $corpus);
        $this->assertSame(
            array_column($corpus, 0),
            array_column($expected, 0),
            "corpus labels and answer-key labels must match, in order"
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider("corpusProvider")]
    public function testExpressionMatchesCrossFrameworkContract(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->engine->renderString($source, $this->context()));
    }

    // -- Named regressions for the bugs the corpus actually caught -----------
    // The data-provided gate above would catch these too, but only as "some
    // line changed". These name the behaviour, and each carries the NEGATIVE
    // case that was failing before the fix.

    /**
     * 3.13.87 contract: a boolean renders lowercase `true`/`false`.
     *
     * PHP used to be Twig-faithful -- `1` for true and an EMPTY STRING for
     * false -- so a template printing a false boolean showed BLANK, which is
     * silently wrong rather than visibly wrong. Lowercase is the only form
     * usable in a template (`data-active="true"` is testable from JS) and it
     * never renders blank.
     */
    public function testBooleansRenderLowercaseTrueFalse(): void
    {
        $ctx = ["t" => true, "f" => false, "n" => 5];
        $this->assertSame("true", $this->engine->renderString("{{ t }}", $ctx));
        $this->assertSame("false", $this->engine->renderString("{{ f }}", $ctx));
        $this->assertSame("true", $this->engine->renderString("{{ n > 3 }}", $ctx));
        $this->assertSame("false", $this->engine->renderString("{{ n < 3 }}", $ctx));
        // A false boolean must NOT vanish -- the exact bug this contract retired.
        $this->assertSame("[false]", $this->engine->renderString("[{{ f }}]", $ctx));
        // An integer 1 still renders as 1, not as "true".
        $this->assertSame("1", $this->engine->renderString("{{ one }}", ["one" => 1]));
    }

    /**
     * `{{ not x }}` renders the boolean instead of being silently dropped.
     *
     * PHP already handled this correctly; the assertion is here so the four
     * frameworks are held to ONE contract from ONE place, and so a future PHP
     * refactor cannot quietly reintroduce the gap the other three had.
     */
    public function testNotOperatorInAStandaloneOutputExpression(): void
    {
        $ctx = ["t" => true, "f" => false];
        $this->assertSame("false", $this->engine->renderString("{{ not t }}", $ctx));
        $this->assertSame("true", $this->engine->renderString("{{ not f }}", $ctx));
        $this->assertSame("true", $this->engine->renderString("{{ not missing }}", $ctx));
        $this->assertSame("Y", $this->engine->renderString("{% if not f %}Y{% else %}N{% endif %}", $ctx));
        $this->assertSame("true", $this->engine->renderString("{{ t and not f }}", $ctx));
        $this->assertSame("B", $this->engine->renderString("{{ not t ? 'A' : 'B' }}", $ctx));
        // NEGATIVE: an identifier that merely starts with "not" is a variable,
        // and "not" inside a string literal is text. Neither is the operator.
        $this->assertSame("", $this->engine->renderString("{{ notes }}", ["notes" => null]));
        $this->assertSame("x", $this->engine->renderString("{{ nothing }}", ["nothing" => "x"]));
        $this->assertSame("not a var", $this->engine->renderString('{{ "not a var" }}', $ctx));
    }

    /**
     * `|json_encode` escapes; `|json_encode|raw` does not.
     *
     * Python, Ruby and Node always escaped here; PHP alone returned raw JSON
     * (the filter prefixed RAW_MARKER), and raw JSON dropped into an HTML
     * attribute is an injection vector. Breaking in 3.13.87: PHP now matches
     * the other three, and the `<script>` use case is served by an explicit
     * `|raw` at the call site.
     */
    public function testJsonEncodeIsHtmlEscapedWithRawAsTheOptOut(): void
    {
        $ctx = ["data" => ["a" => 1]];
        $this->assertSame("{&quot;a&quot;:1}", $this->engine->renderString("{{ data|json_encode }}", $ctx));
        $this->assertSame('{"a":1}', $this->engine->renderString("{{ data|json_encode|raw }}", $ctx));
    }
}
