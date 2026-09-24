<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Frond;

/**
 * Frond globals contract — ADR-0085.
 *
 * A bare zero-argument callable global is invoked and its RETURN VALUE is used
 * for both ``{{ g }}`` output and ``{% if g %}`` conditions. Explicit ``g()``
 * still works and never double-calls. A non-callable global is unchanged, a
 * global that returns a callable is called once (not twice), and an
 * unregistered ``nope()`` is falsy rather than an error.
 *
 * Node is the reference implementation; these cases mirror
 * tina4-nodejs/test/frondGlobalsContract.test.ts against the shared fixture
 * tina4-documentation/plan/v3/fixtures/frond_globals_contract.json.
 *
 * No mocks: the Closures are real and Frond renders real template source.
 */
class FrondGlobalsContractTest extends TestCase
{
    protected function setUp(): void
    {
        Frond::clearRegistry();
    }

    protected function tearDown(): void
    {
        Frond::clearRegistry();
    }

    public function testZeroArgGlobalClosureReturningFalseIsFalsyInIf(): void
    {
        $frond = new Frond();
        $frond->addGlobal('admin_only', fn() => false);
        $this->assertSame('N', $frond->renderString('{% if admin_only %}Y{% else %}N{% endif %}'));
    }

    public function testZeroArgGlobalClosureReturningTrueIsTruthyInIf(): void
    {
        $frond = new Frond();
        $frond->addGlobal('admin_only', fn() => true);
        $this->assertSame('Y', $frond->renderString('{% if admin_only %}Y{% else %}N{% endif %}'));
    }

    public function testZeroArgGlobalClosurePrintsItsReturnValue(): void
    {
        $frond = new Frond();
        $frond->addGlobal('greeting', fn() => 'hello');
        $this->assertSame('hello', $frond->renderString('{{ greeting }}'));
    }

    public function testExplicitCallSyntaxStillWorks(): void
    {
        $frond = new Frond();
        $frond->addGlobal('admin_only', fn() => false);
        $this->assertSame('N', $frond->renderString('{% if admin_only() %}Y{% else %}N{% endif %}'));
    }

    public function testNonCallableGlobalIsUnchanged(): void
    {
        $frond = new Frond();
        $frond->addGlobal('site_name', 'Tina4');
        $this->assertSame('Tina4', $frond->renderString('{{ site_name }}'));
    }

    public function testGlobalReturningACallableIsNotDoubleCalled(): void
    {
        $frond = new Frond();
        $outerCalls = 0;
        $innerCalls = 0;
        $frond->addGlobal('outer', function () use (&$outerCalls, &$innerCalls) {
            $outerCalls++;
            return function () use (&$innerCalls) {
                $innerCalls++;
                return 'inner';
            };
        });
        // A bare reference calls the global ONCE and yields the inner closure;
        // the inner closure must NOT be invoked (no double-call).
        $frond->renderString('{% if outer %}Y{% endif %}');
        $this->assertSame(1, $outerCalls);
        $this->assertSame(0, $innerCalls);
    }

    public function testUnregisteredFunctionCallIsFalsy(): void
    {
        $frond = new Frond();
        $this->assertSame('N', $frond->renderString('{% if nope() %}Y{% else %}N{% endif %}'));
        $this->assertSame('', $frond->renderString('{{ nope() }}'));
    }
}
