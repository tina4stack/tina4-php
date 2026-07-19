<?php declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Testing;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;

/**
 * Fires just before PHPUnit snapshots the error/exception handler stack for a
 * test (Test\PreparationStarted is emitted at the top of TestCase::runBare,
 * before snapshotGlobalErrorExceptionHandlers). Resets ErrorTracker's private
 * static handler-depth counter to its suite baseline (0) so a value leaked by an
 * earlier test/class can never make this test's reset() pop a handler outside
 * its own boundary. See HandlerStackHygieneExtension for the full rationale.
 */
final class ResetErrorTrackerDepthSubscriber implements PreparationStartedSubscriber
{
    /** @var \ReflectionProperty|null Cached accessor for the private static counter */
    private static ?\ReflectionProperty $depthProperty = null;

    /** @var bool True once we have decided whether the property is reachable */
    private static bool $resolved = false;

    public function notify(PreparationStarted $event): void
    {
        $property = self::depthProperty();
        if ($property === null) {
            return;
        }
        // Only the test-scoped bookkeeping counter is touched — no OS-level
        // error/exception handler is added or removed here.
        $property->setValue(null, 0);
    }

    /**
     * Resolve (once) the ErrorTracker::$handlerDepth reflection accessor.
     *
     * ErrorTracker is declared INSIDE Tina4/DevAdmin.php, not in its own PSR-4
     * file, so autoloading `Tina4\ErrorTracker` directly fails; DevAdmin must be
     * loaded first (it defines both classes). Returns null when neither is
     * present or the property is absent (e.g. a framework refactor), so the
     * subscriber degrades to a harmless no-op instead of breaking the suite.
     */
    private static function depthProperty(): ?\ReflectionProperty
    {
        if (self::$resolved) {
            return self::$depthProperty;
        }
        self::$resolved = true;

        // Loading DevAdmin defines ErrorTracker (same source file).
        class_exists(\Tina4\DevAdmin::class);

        if (
            class_exists(\Tina4\ErrorTracker::class, false)
            && property_exists(\Tina4\ErrorTracker::class, 'handlerDepth')
        ) {
            self::$depthProperty = new \ReflectionProperty(
                \Tina4\ErrorTracker::class,
                'handlerDepth',
            );
        }

        return self::$depthProperty;
    }
}
