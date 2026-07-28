<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Frond;

/**
 * Sandbox contract: a sandbox denies by revoking capability, not by skipping a step.
 *
 * Audit feature 38 (plan/v3/features/038-sandboxing.md), P1. Mirrors
 * tina4-python/tests/test_frond_sandbox_contract.py.
 *
 * PHP is the framework the escaping mechanism was ported FROM: it already decides
 * escaping from what actually ran, so the raw/safe pairs pass here unchanged and
 * are locked in so they cannot drift. It also already treats an empty allow-list
 * as "permit nothing" (`!== null`), which Python got wrong.
 *
 * What PHP did NOT have: {% autoescape false %} bypassed the TAG allow-list,
 * because the tag gate was a per-name conditional at a handful of call sites
 * rather than one check where a node is dispatched.
 *
 * Pure string rendering. No I/O, no dependency, no doubles.
 */
class FrondSandboxContractTest extends TestCase
{
    private const XSS = '<script>alert(1)</script>';
    private const ESCAPED = '&lt;script&gt;alert(1)&lt;/script&gt;';

    /** A sandbox whose filter allow-list does NOT include raw or safe. */
    private function denied(): Frond
    {
        return (new Frond())->sandbox(['upper'], ['if'], ['x']);
    }

    /** The same sandbox, but raw and safe ARE on the allow-list. */
    private function allowed(): Frond
    {
        return (new Frond())->sandbox(['upper', 'raw', 'safe'], ['if'], ['x']);
    }

    // --- pair 1: raw is revocable -------------------------------------------

    public function testDenyingRawEscapesTheValue(): void
    {
        $this->assertSame(
            self::ESCAPED,
            $this->denied()->renderString('{{ x|raw }}', ['x' => self::XSS])
        );
    }

    public function testNegativeADeniedRawFilterNeverProducesUnescapedOutput(): void
    {
        $out = $this->denied()->renderString('{{ x|raw }}', ['x' => self::XSS]);
        $this->assertStringNotContainsString('<script>', $out, 'a DENIED raw filter produced live markup');
    }

    // --- pair 2: safe is revocable -----------------------------------------

    public function testDenyingSafeEscapesTheValue(): void
    {
        $this->assertSame(
            self::ESCAPED,
            $this->denied()->renderString('{{ x|safe }}', ['x' => self::XSS])
        );
    }

    public function testNegativeADeniedSafeFilterNeverProducesUnescapedOutput(): void
    {
        $out = $this->denied()->renderString('{{ x|safe }}', ['x' => self::XSS]);
        $this->assertStringNotContainsString('<script>', $out, 'a DENIED safe filter produced live markup');
    }

    // --- pair 3: deny must differ from allow -------------------------------

    public function testAllowingRawRendersVerbatimAndDenyingItDoesNot(): void
    {
        $this->assertSame(self::XSS, $this->allowed()->renderString('{{ x|raw }}', ['x' => self::XSS]));
        $this->assertSame(self::ESCAPED, $this->denied()->renderString('{{ x|raw }}', ['x' => self::XSS]));
    }

    public function testNegativeDenyingAFilterNeverProducesTheSameOutputAsAllowingIt(): void
    {
        $this->assertNotSame(
            $this->allowed()->renderString('{{ x|raw }}', ['x' => self::XSS]),
            $this->denied()->renderString('{{ x|raw }}', ['x' => self::XSS]),
            'denying raw and allowing raw produced identical output - the gate is inert'
        );
    }

    // --- pair 4: the tag gate cannot be bypassed (P1b) ---------------------

    public function testADeniedAutoescapeTagDoesNotDisableEscaping(): void
    {
        $out = $this->denied()->renderString(
            '{% autoescape false %}{{ x }}{% endautoescape %}',
            ['x' => self::XSS]
        );
        $this->assertStringNotContainsString(
            '<script>',
            $out,
            '{% autoescape false %} disabled escaping despite not being on the tag allow-list'
        );
    }

    public function testNegativeNoTagCanDisableEscapingInsideASandbox(): void
    {
        foreach (
            [
                '{% autoescape false %}{{ x }}{% endautoescape %}',
                '{% autoescape off %}{{ x }}{% endautoescape %}',
            ] as $tpl
        ) {
            $out = $this->denied()->renderString($tpl, ['x' => self::XSS]);
            $this->assertStringNotContainsString('<script>', $out, "$tpl disabled escaping");
        }
    }

    // --- pair 5: escape is revocable too ----------------------------------
    // PHP is immune to this BY CONSTRUCTION and these tests keep it that way. The
    // raw/safe filters prepend a RAW_MARKER sentinel, so safety is carried by a
    // value the filter produces only when it actually RUNS -- deny it and no marker
    // exists, so the value is still auto-escaped. Python and Ruby use a SafeString
    // for the same purpose. Node instead set a flag from the filter NAME and
    // therefore DID emit live markup for a denied `escape` (fixed in 1eb1c4a).
    // Anyone who later swaps the marker for a name check reopens that hole here.

    public function testNegativeADeniedEscapeFilterNeverProducesUnescapedOutput(): void
    {
        $out = $this->denied()->renderString('{{ x|escape }}', ['x' => self::XSS]);
        $this->assertStringNotContainsString(
            '<script>',
            $out,
            'a DENIED escape filter produced live markup - escaping must be conferred by '
                . 'RUNNING the filter, never by its name'
        );
    }

    public function testNegativeADeniedEFilterNeverProducesUnescapedOutput(): void
    {
        $out = $this->denied()->renderString('{{ x|e }}', ['x' => self::XSS]);
        $this->assertStringNotContainsString('<script>', $out, 'a DENIED e filter produced live markup');
    }

    public function testAnAllowedEscapeFilterEscapesExactlyOnce(): void
    {
        $e = (new Frond())->sandbox(['escape'], ['if'], ['x']);
        $this->assertSame(self::ESCAPED, $e->renderString('{{ x|escape }}', ['x' => self::XSS]));
    }

    // --- pair 6: nested tags stay gated -----------------------------------
    // The collapse moved the tag gate to the central dispatch. Every body
    // handler re-enters execute(), so a nested tag must still be gated - if it
    // is not, the sandbox LOOKS intact and leaks.

    public function testANestedDeniedTagIsStillGated(): void
    {
        // `for` is not on the allow-list; nesting it inside an ALLOWED `if`
        // must not smuggle it past the gate.
        $e = (new Frond())->sandbox(['upper'], ['if'], ['x', 'items']);
        $out = $e->renderString(
            '{% if x %}{% for i in items %}LEAK{% endfor %}{% endif %}',
            ['x' => true, 'items' => [1, 2]]
        );
        $this->assertStringNotContainsString('LEAK', $out, 'a nested DENIED tag ran');
    }

    public function testAnAllowedNestedTagStillRuns(): void
    {
        $e = (new Frond())->sandbox(['upper'], ['if', 'for'], ['x', 'items']);
        $out = $e->renderString(
            '{% if x %}{% for i in items %}Y{% endfor %}{% endif %}',
            ['x' => true, 'items' => [1, 2]]
        );
        $this->assertSame('YY', $out, 'an ALLOWED nested tag was blocked');
    }

    // --- pair 6: what must NOT change -------------------------------------

    public function testOutputIsNeverGatedByTheTagAllowList(): void
    {
        // `output` is NOT a gateable tag. A tag allow-list of ['if'] must not
        // blank every {{ }} in the template.
        $e = (new Frond())->sandbox(null, ['if'], null);
        $this->assertSame('hello', $e->renderString('{{ greeting }}', ['greeting' => 'hello']));
    }

    public function testAnAllowedFilterStillRunsAndADeniedOneIsSkipped(): void
    {
        $e = (new Frond())->sandbox(['upper'], ['if'], ['v']);
        $this->assertSame('MIXED', $e->renderString('{{ v|upper }}', ['v' => 'MiXeD']));
        $this->assertSame('MiXeD', $e->renderString('{{ v|lower }}', ['v' => 'MiXeD']));
    }

    public function testEscapingOutsideASandboxIsUnchanged(): void
    {
        $plain = new Frond();
        $this->assertSame(self::ESCAPED, $plain->renderString('{{ x }}', ['x' => self::XSS]));
        $this->assertSame(self::XSS, $plain->renderString('{{ x|raw }}', ['x' => self::XSS]));
        $this->assertSame(self::XSS, $plain->renderString('{{ x|safe }}', ['x' => self::XSS]));
    }

    public function testUnsandboxRestoresRaw(): void
    {
        $e = $this->denied();
        $this->assertSame(self::ESCAPED, $e->renderString('{{ x|raw }}', ['x' => self::XSS]));
        $e->unsandbox();
        $this->assertSame(self::XSS, $e->renderString('{{ x|raw }}', ['x' => self::XSS]));
    }

    // --- empty vs null allow-list ----------------------------------------

    public function testANullAllowListPermitsEverything(): void
    {
        $e = (new Frond())->sandbox(null, null, null);
        $this->assertSame(self::XSS, $e->renderString('{{ x|raw }}', ['x' => self::XSS]));
    }

    public function testNegativeAnEmptyAllowListDoesNotPermitEverything(): void
    {
        $e = (new Frond())->sandbox([], [], ['x']);
        $out = $e->renderString('{{ x|raw }}', ['x' => self::XSS]);
        $this->assertStringNotContainsString(
            '<script>',
            $out,
            'an EMPTY filter allow-list behaved like null (allow all)'
        );
    }
}
