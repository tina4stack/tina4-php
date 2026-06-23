<?php declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Testing;

use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;

/**
 * Feeds every Test\Skipped event into the RequireServicesGate. The gate decides
 * (when armed via TINA4_REQUIRE_SERVICES) whether the skip reason names a
 * provisioned-service outage that should fail the run.
 */
final class TestSkippedSubscriber implements SkippedSubscriber
{
    public function notify(Skipped $event): void
    {
        RequireServicesGate::instance()->recordSkip(
            $event->test()->id(),
            $event->message(),
        );
    }
}
