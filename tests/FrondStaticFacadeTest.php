<?php

use PHPUnit\Framework\TestCase;
use Tina4\Frond;

/**
 * Cross-framework parity: Python tina4_python ships ``Frond.add_filter`` /
 * ``add_global`` / ``add_test`` as a ``_ClassOrInstanceMethod`` so they work
 * BOTH on the class (``Frond.add_filter("money", fn)`` — convenient at
 * app-startup before any instance exists) AND on an instance (the existing
 * form, which also updates the live instance's local registry).
 *
 * These tests verify the PHP port ships the same behaviour via
 * ``__callStatic`` (class-level) + ``__call`` (instance-level) magic
 * methods, with the constructor draining the class registry into each new
 * Frond instance.
 */
class FrondStaticFacadeTest extends TestCase
{
    protected function setUp(): void
    {
        // Wipe class-level state so leakage between tests is impossible.
        Frond::clearRegistry();
    }

    protected function tearDown(): void
    {
        // Clean up so other test suites don't inherit stale class state.
        Frond::clearRegistry();
    }

    /* ───────── Static (class-level) registration ─────────── */

    public function testClassRegistrationIsProcessGlobal(): void
    {
        Frond::addFilter('class_filter', fn($value) => 'class:' . $value);
        $this->assertSame('class:value', (new Frond())->renderString('{{ "value" | class_filter }}'));
    }

    public function testStaticAddFilterRegistersAtClassLevel(): void
    {
        Frond::addFilter('money', fn($v) => number_format((float)$v, 2));

        // A brand new Frond constructed AFTER the static call should
        // already know the filter — that's the point of the facade.
        $frond = new Frond();
        $this->assertArrayHasKey('money', $frond->getFilters());
    }

    public function testNewInstanceInheritsStaticallyRegisteredFilter(): void
    {
        Frond::addFilter('money', fn($v) => '$' . number_format((float)$v, 2));

        $frond = new Frond();
        $out = $frond->renderString('{{ 100 | money }}');

        $this->assertSame('$100.00', $out);
    }

    public function testStaticAddGlobalIsAvailableInNewInstanceTemplates(): void
    {
        Frond::addGlobal('APP', 'MyApp');

        $frond = new Frond();
        $out = $frond->renderString('{{ APP }}');

        $this->assertSame('MyApp', $out);
    }

    public function testStaticAddTestWorksInIfExpression(): void
    {
        Frond::addTest('positive', fn($v) => $v > 0);

        $frond = new Frond();
        $yes = $frond->renderString('{% if x is positive %}yes{% else %}no{% endif %}', ['x' => 5]);
        $no  = $frond->renderString('{% if x is positive %}yes{% else %}no{% endif %}', ['x' => -1]);

        $this->assertSame('yes', $yes);
        $this->assertSame('no', $no);
    }

    /* ───────── Instance (live-update) registration ─────────── */

    public function testInstanceAddFilterStillWorks(): void
    {
        // The existing instance-form API must keep working — apps that
        // already call $frond->addFilter("x", $fn) must not break.
        $frond = new Frond();
        $frond->addFilter('double', fn($v) => (int)$v * 2);

        $out = $frond->renderString('{{ 5 | double }}');
        $this->assertSame('10', $out);
    }

    public function testInstanceFilterRegistrationIsInstanceLocal(): void
    {
        $first = new Frond();
        $first->addFilter('shout', fn($v) => strtoupper((string)$v) . '!');

        $second = new Frond();
        $this->assertArrayNotHasKey('shout', $second->getFilters());
        $this->assertSame('hello', $second->renderString('{{ "hello" | shout }}'));
    }

    public function testInstanceGlobalRegistrationIsInstanceLocal(): void
    {
        $first = new Frond();
        $first->addGlobal('SITE', 'Tina4');

        $second = new Frond();
        $this->assertSame('', $second->renderString('{{ SITE }}'));
    }

    public function testInstanceTestRegistrationIsInstanceLocal(): void
    {
        $first = new Frond();
        $first->addTest('is_four_only', fn($v) => ((int)$v) === 4);

        $second = new Frond();
        $out = $second->renderString('{% if n is is_four_only %}yes{% else %}no{% endif %}', ['n' => 4]);
        $this->assertSame('no', $out);
    }

    /* ───────── clearRegistry ─────────── */

    public function testClearRegistryEmptiesAllThreeClassRegistries(): void
    {
        Frond::addFilter('money', fn($v) => 'X');
        Frond::addGlobal('APP', 'Y');
        Frond::addTest('positive', fn($v) => $v > 0);

        // Sanity: a fresh instance does see them
        $before = new Frond();
        $this->assertArrayHasKey('money', $before->getFilters());
        $this->assertArrayHasKey('APP', $before->getGlobals());

        Frond::clearRegistry();

        $after = new Frond();
        $this->assertArrayNotHasKey('money', $after->getFilters());
        $this->assertArrayNotHasKey('APP', $after->getGlobals());
        // And the test ``positive`` should no longer be wired — using it
        // in a template should render the ``else`` branch (test missing
        // → falsy) rather than blow up.
        $out = $after->renderString('{% if x is positive %}yes{% else %}no{% endif %}', ['x' => 5]);
        $this->assertSame('no', $out);
    }

    public function testClearRegistryDoesNotAffectBuiltinFilters(): void
    {
        Frond::addFilter('money', fn($v) => 'X');
        Frond::clearRegistry();

        $frond = new Frond();

        // Built-in filters survive clearRegistry — only user-registered
        // entries are affected. ``upper``/``lower`` are core built-ins
        // registered in ``registerBuiltinFilters()``.
        $this->assertSame('HELLO', $frond->renderString('{{ "hello" | upper }}'));
        $this->assertSame('world', $frond->renderString('{{ "WORLD" | lower }}'));
    }

    /* ───────── Mixed class + instance registration ─────────── */

    public function testMixedClassAndInstanceFiltersBothWork(): void
    {
        // App-startup style: register one filter via the static facade…
        Frond::addFilter('money', fn($v) => '$' . number_format((float)$v, 2));

        // …then per-request, an instance registers an additional filter
        // via the instance form.
        $frond = new Frond();
        $frond->addFilter('shout', fn($v) => strtoupper((string)$v) . '!');

        // Both filters must be usable in the same template.
        $out = $frond->renderString(
            '{{ price | money }} - {{ label | shout }}',
            ['price' => 99.5, 'label' => 'sale']
        );
        $this->assertSame('$99.50 - SALE!', $out);
    }

    public function testInstanceRegisteredBeforeClassConstructionStaysLocal(): void
    {
        $earlier = new Frond();
        $earlier->addFilter('reverse_local_only', fn($v) => strrev((string)$v));

        // A later static registration adds a second filter
        Frond::addFilter('upper2', fn($v) => strtoupper((string)$v));

        $later = new Frond();
        $this->assertSame('abc', $later->renderString('{{ "abc" | reverse_local_only }}'));
        $this->assertSame('HI', $later->renderString('{{ "hi" | upper2 }}'));
    }

    /* ───────── Built-ins remain intact ─────────── */

    public function testBuiltinsSurviveClearRegistry(): void
    {
        // Register some user-level entries, then wipe the class registry.
        // The built-ins (``upper``, ``lower``, etc.) are wired in the
        // constructor's ``registerBuiltinFilters()`` and are not part of
        // the class registry, so they must remain on a freshly-constructed
        // Frond after ``clearRegistry()``.
        Frond::addFilter('money', fn($v) => 'X');
        Frond::addGlobal('APP', 'Y');
        Frond::clearRegistry();

        $fresh = new Frond();

        // Built-ins still work
        $this->assertSame('HELLO', $fresh->renderString('{{ "hello" | upper }}'));
        $this->assertSame('world', $fresh->renderString('{{ "WORLD" | lower }}'));
        $this->assertSame('5', $fresh->renderString('{{ "hello" | length }}'));

        // User-level entries are gone
        $this->assertArrayNotHasKey('money', $fresh->getFilters());
        $this->assertArrayNotHasKey('APP', $fresh->getGlobals());
    }
}
