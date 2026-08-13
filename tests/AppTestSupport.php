<?php declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Plain test helper (not a mock). Loaded from tests/bootstrap.php.
 */

use Tina4\App;

/**
 * Helpers for tests that construct a real Tina4\App.
 */
final class AppTestSupport
{
    /**
     * Cleanly release a Tina4\App's process-global error/exception handlers
     * INSIDE the calling test's boundary.
     *
     * App installs an error handler in its constructor (and an exception handler
     * in start()) and pops them in __destruct(). In tests the App object stays
     * reachable via the static Router route table it registers into, so it is NOT
     * collected when the test's local goes out of scope — its __destruct runs at
     * a non-deterministic garbage-collection during a LATER, unrelated test and
     * pops a handler outside that test's boundary, which PHPUnit flags "removed
     * error/exception handlers other than its own". A bare restore_*_handler() in
     * the test only made it worse: it popped the handler at test time but left
     * the App alive to restore the SAME handler a second time at GC.
     *
     * This restores exactly the handler(s) the App still has installed (balanced
     * against the App's own set_*_handler calls, and never popping a foreign
     * handler), then clears the App's handler-set flags so its eventual
     * __destruct is a no-op. Call it once the test is done exercising the App.
     *
     * @param App $app The App whose handlers should be released.
     */
    public static function releaseHandlers(App $app): void
    {
        $errorFlag = new \ReflectionProperty(App::class, 'errorHandlerSet');
        if ($errorFlag->getValue($app)) {
            restore_error_handler();
            $errorFlag->setValue($app, false);
        }

        $exceptionFlag = new \ReflectionProperty(App::class, 'exceptionHandlerSet');
        if ($exceptionFlag->getValue($app)) {
            restore_exception_handler();
            $exceptionFlag->setValue($app, false);
        }

        self::releaseErrorTrackerHandlers();
    }

    /**
     * Release the process-global handler pair Tina4\ErrorTracker::register()
     * installs when a dev-mode App::start() cascades into DevAdmin::register()
     * (Tina4\DevAdmin::register() -> Tina4\ErrorTracker::register(), both
     * declared in Tina4/DevAdmin.php). This is a SEPARATE handler layer from
     * App's own instance handlers released above — App and ErrorTracker each
     * independently call set_error_handler()/set_exception_handler(), so
     * releasing one never releases the other, and a test that only released
     * App's handlers left ErrorTracker's pair dangling past its own PHPUnit
     * boundary (flagged "did not remove its own error/exception handlers").
     *
     * Tina4\Testing\HandlerStackHygieneExtension resets
     * ErrorTracker::$handlerDepth to 0 immediately before every test's
     * PHPUnit handler-stack snapshot, so a nonzero depth observed here can
     * only mean THIS test's own boot pushed it — releasing it is always safe
     * and can never pop a handler an earlier test left behind. Deliberately
     * narrower than ErrorTracker::reset() (a test-only helper that also wipes
     * captured errors + the on-disk store): this method's only job is
     * handlers, so callers that don't care about ErrorTracker at all see no
     * side effect beyond the OS-level handler stack.
     */
    private static function releaseErrorTrackerHandlers(): void
    {
        // ErrorTracker is declared inside Tina4/DevAdmin.php, not its own
        // PSR-4 file. If it isn't loaded, DevAdmin::register() was never
        // called, so ErrorTracker::register() was never called either —
        // nothing to release. Never force-autoload it here.
        if (!class_exists(\Tina4\ErrorTracker::class, false)) {
            return;
        }

        $depthProperty = new \ReflectionProperty(\Tina4\ErrorTracker::class, 'handlerDepth');
        $depth = $depthProperty->getValue(null);
        if ($depth <= 0) {
            return;
        }

        while ($depth > 0) {
            restore_error_handler();
            restore_exception_handler();
            $depth--;
        }
        $depthProperty->setValue(null, 0);

        $registeredProperty = new \ReflectionProperty(\Tina4\ErrorTracker::class, 'registered');
        $registeredProperty->setValue(null, false);
    }
}
