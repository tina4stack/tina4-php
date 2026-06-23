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
 * PHPUnit 11 event Extension that arms the real-service test gate.
 *
 * Registered in phpunit.xml under <extensions>. It always loads, but the gate
 * only has teeth when TINA4_REQUIRE_SERVICES is truthy (CI sets it to '1'):
 * a provisioned-service skip then becomes a hard failure. Locally, with the
 * flag unset, skips behave exactly as before.
 */
final class RequireServicesExtension implements Extension
{
    public function bootstrap(
        Configuration $configuration,
        Facade $facade,
        ParameterCollection $parameters,
    ): void {
        $facade->registerSubscribers(
            new TestSkippedSubscriber(),
            new ApplicationFinishedSubscriber(),
        );
    }
}
