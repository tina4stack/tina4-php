<?php declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Testing;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * PHPUnit 11 event Extension that keeps ErrorTracker's process-global handler
 * bookkeeping in sync with the real error/exception handler stack across the
 * whole suite, so no test is flagged risky for "removed error/exception
 * handlers other than its own".
 *
 * WHY THIS EXISTS
 * ---------------
 * `Tina4\ErrorTracker::register()` (in Tina4/DevAdmin.php) installs a
 * PROCESS-LIFETIME error + exception handler pair — by design, for the dev
 * server — and records how many pairs it pushed in a private static
 * `$handlerDepth`. `ErrorTracker::reset()` (a test-only helper) pops
 * `$handlerDepth` pairs back off. In production this is correct: register()
 * runs once at boot and the handlers live for the whole process.
 *
 * Under PHPUnit it desyncs. PHPUnit snapshots the handler stack at the start of
 * every test and, after each test, RESTORES that stack to the test's own
 * baseline (TestCase::restoreGlobalErrorExceptionHandlers). So the OS-level
 * handler stack never actually leaks across tests — but ErrorTracker's static
 * `$handlerDepth`/`$registered` DO, because PHPUnit never touches them. A test
 * (or a class-level `setUpBeforeClass`) that calls register() without a matching
 * reset() therefore leaves `$handlerDepth > 0` behind. A LATER test that calls
 * reset() then pops a handler that lives OUTSIDE its own boundary (PHPUnit had
 * already re-normalised the OS stack), which PHPUnit flags as
 * "removed error/exception handlers other than its own".
 *
 * THE FIX
 * -------
 * Before EVERY test (Test\PreparationStarted fires just before PHPUnit takes its
 * per-test handler snapshot), force ErrorTracker's `$handlerDepth` back to its
 * suite-start baseline of 0. reset() then becomes a no-op on any handler the
 * calling test did not push itself, while a within-test register()/reset() pair
 * still balances (the body's register() re-increments the depth the same test's
 * reset() pops). This clears the notice suite-wide and, crucially, touches ONLY
 * the test-scoped static counter via reflection: it adds/removes NO OS handler
 * (so self-cleaning tests that call restore_error_handler()/... directly are
 * unaffected) and changes ZERO framework runtime code, so production behaviour
 * is identical.
 *
 * Registered in phpunit.xml under <extensions>, alongside
 * RequireServicesExtension.
 */
final class HandlerStackHygieneExtension implements Extension
{
    public function bootstrap(
        Configuration $configuration,
        Facade $facade,
        ParameterCollection $parameters,
    ): void {
        $facade->registerSubscribers(
            new ResetErrorTrackerDepthSubscriber(),
        );
    }
}
