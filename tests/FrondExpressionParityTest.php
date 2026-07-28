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
            // Non-finite floats: the tina4-php#184 payload. JSON has no
            // Infinity or NaN, so both must serialize as null everywhere.
            "inf_val" => INF,
            "nan_map" => ["v" => NAN],
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
        $this->assertCount(84, $corpus);
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
     * `|json_encode` output must parse as JSON AND run as JavaScript.
     *
     * 3.13.88 reverts 3.13.87's HTML-escaping of this filter. Entity-encoding
     * the payload produced `{&quot;a&quot;:1}`, a SyntaxError inside <script>,
     * which broke the filter's primary use in all four frameworks at once. The
     * safe form escapes only the characters that are dangerous in HTML, as JSON
     * \uXXXX escapes: valid JSON, valid JavaScript, cannot terminate a
     * </script>, safe in a single-quoted attribute. Jinja2's tojson model.
     */
    public function testJsonEncodeEmitsJsonThatIsValidInAScriptBlock(): void
    {
        $this->assertSame('{"a":1}', $this->engine->renderString("{{ data|json_encode }}", ["data" => ["a" => 1]]));
        // Negative case: escapes must be \uXXXX, never HTML entities, and
        // </script> must not survive intact.
        $out = $this->engine->renderString("{{ data|json_encode }}", ["data" => ["x" => "</script>&'"]]);
        $this->assertSame('{"x":"\u003c/script\u003e\u0026\u0027"}', $out);
        $this->assertStringNotContainsString("&quot;", $out);
        $this->assertStringNotContainsString("</script>", $out);
        // |raw is now a no-op rather than the required opt-out.
        $this->assertSame('{"a":1}', $this->engine->renderString("{{ data|json_encode|raw }}", ["data" => ["a" => 1]]));
    }

    /**
     * tina4-php#184 (justin-k-bruce): a non-finite value must become `null`.
     *
     * PHP's json_encode returns FALSE on INF/NAN, and a false coerced into a
     * string return type is an EMPTY STRING -- the payload silently vanished.
     * `null` is what JSON.stringify has always produced and the only answer the
     * JSON grammar allows.
     */
    public function testJsonEncodeNeverEmitsANonFiniteLiteral(): void
    {
        $this->assertSame("null", $this->engine->renderString("{{ v|json_encode }}", ["v" => INF]));
        $this->assertSame("null", $this->engine->renderString("{{ v|json_encode }}", ["v" => -INF]));
        $this->assertSame("null", $this->engine->renderString("{{ v|json_encode }}", ["v" => NAN]));
        $this->assertSame(
            '{"a":1,"b":null}',
            $this->engine->renderString("{{ v|json_encode }}", ["v" => ["a" => 1, "b" => INF]])
        );
        $this->assertSame("[1,null]", $this->engine->renderString("{{ v|json_encode }}", ["v" => [1, NAN]]));
        // Negative case: none of the old failure outputs, and never empty --
        // an empty payload is a silent, invisible failure.
        $out = $this->engine->renderString("{{ v|json_encode }}", ["v" => ["b" => INF]]);
        foreach (["Infinity", "NaN", "false", "=>"] as $bad) {
            $this->assertStringNotContainsString($bad, $out);
        }
        $this->assertNotSame("", $this->engine->renderString("{{ v|json_encode }}", ["v" => INF]));
    }

    /**
     * {% set name %}...{% endset %} binds the rendered body (3.13.89).
     *
     * Core syntax in BOTH reference engines, and broken identically in all four
     * frameworks until now: the body rendered inline where it stood and the
     * variable was never assigned.
     */
    public function testBlockSetCapturesItsBodyInsteadOfPrintingIt(): void
    {
        $ctx = ["n" => "Andre"];
        $out = $this->engine->renderString("{% set g %}Hi {{ n }}{% endset %}[{{ g }}]", $ctx);
        $this->assertSame("[Hi Andre]", $out);
        // Negative case: the old bug printed the body first and left the
        // variable empty. Neither may happen.
        $this->assertStringStartsNotWith("Hi", $out);
        $this->assertStringNotContainsString("[]", $out);
        $this->assertSame(
            "[12]",
            $this->engine->renderString(
                "{% set g %}{% for i in [1,2] %}{{ i }}{% endfor %}{% endset %}[{{ g }}]",
                []
            )
        );
        // Nesting: the inner endset must not close the outer block.
        $this->assertSame(
            "[AB]",
            $this->engine->renderString("{% set a %}A{% set b %}B{% endset %}{{ b }}{% endset %}[{{ a }}]", [])
        );
    }

    /**
     * The capture is already-escaped output, so it is not escaped again.
     *
     * Twig and Jinja2 both mark a captured block safe. A value interpolated INTO
     * the body is still escaped on the way in -- escaping happens once, in the
     * right place.
     */
    public function testBlockSetCaptureIsSafeAndTheInlineFormStillWorks(): void
    {
        $this->assertSame(
            "[&lt;b&gt;&amp;x&lt;/b&gt;]",
            $this->engine->renderString("{% set g %}{{ h }}{% endset %}[{{ g }}]", ["h" => "<b>&x</b>"])
        );
        $this->assertSame(
            "[<b>hi</b>]",
            $this->engine->renderString("{% set g %}<b>hi</b>{% endset %}[{{ g }}]", [])
        );
        // Negative case: the inline assignment form is untouched, including an
        // "=" inside a quoted value -- that must NOT be read as the block form.
        $this->assertSame('[x]', $this->engine->renderString('{% set g = "x" %}[{{ g }}]', []));
        $this->assertSame('[a = b]', $this->engine->renderString('{% set g = "a = b" %}[{{ g }}]', []));
    }

    /**
     * A typo'd tag must fail loudly, not render the content it was gating.
     *
     * THE security-shaped one. {% iff user.is_admin %}...{% endiff %} used to
     * render the admin block UNCONDITIONALLY: the unknown tag emitted nothing
     * and its body was parsed as ordinary content, so a reviewer read a guard
     * that was not there. Twig and Jinja2 both raise on an unknown tag. There is
     * no user-extension point for tags, so an unknown name is always a mistake.
     */
    public function testAnUnknownTagThrowsInsteadOfLeakingItsBody(): void
    {
        try {
            $this->engine->renderString("{% iff admin %}SECRET{% endiff %}", ["admin" => false]);
            $this->fail("an unknown tag must throw");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('unknown tag "iff"', $e->getMessage());
        }
        try {
            $this->engine->renderString("{% frobnicate 42 %}", []);
            $this->fail("an unknown tag must throw");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('unknown tag "frobnicate"', $e->getMessage());
        }
        // Negative case 1: every real tag still parses.
        $this->assertSame(
            "x1{{ q }} a y",
            $this->engine->renderString(
                "{% if 1 %}x{% endif %}{% for i in [1] %}{{ i }}{% endfor %}"
                . "{% raw %}{{ q }}{% endraw %}{% spaceless %} a {% endspaceless %}"
                . "{% autoescape true %}y{% endautoescape %}",
                []
            )
        );
        // Negative case 2: a STRAY terminator is not an unknown tag. It stays a
        // silent no-op -- it was always one, and unlike an unknown tag it cannot
        // expose gated content.
        $this->assertSame("AB", $this->engine->renderString("A{% endif %}B", []));
    }

    /** The three spellings share one serializer and must not drift apart. */
    public function testJsonEncodeAndToJsonAndTojsonAreOneBehaviour(): void
    {
        $ctx = ["v" => ["a" => 1, "u" => "a/b", "n" => "caf\u{e9}", "bad" => INF]];
        $out = $this->engine->renderString("{{ v|json_encode }}", $ctx);
        $this->assertSame($out, $this->engine->renderString("{{ v|to_json }}", $ctx));
        $this->assertSame($out, $this->engine->renderString("{{ v|tojson }}", $ctx));
        // Slashes stay unescaped and non-ASCII stays raw -- PHP alone used to
        // write "a\/b", and Python alone used to write "caf\u00e9".
        $this->assertStringContainsString('"u":"a/b"', $out);
        $this->assertStringContainsString("caf\u{e9}", $out);
    }
}
