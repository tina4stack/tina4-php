<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
 declare(strict_types=1);

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
 */

namespace Tina4\Testing;

use PHPUnit\Event\Application\Finished;
use PHPUnit\Event\Application\FinishedSubscriber;

/**
 * Fires at the very end of the run (after PHPUnit has already printed its
 * result summary and computed the shell exit code). If the gate recorded any
 * provisioned-service skips while TINA4_REQUIRE_SERVICES was armed, print the
 * report and exit non-zero so CI fails — overriding PHPUnit's own (zero) exit
 * code, which it returns immediately after emitting this event.
 */
final class ApplicationFinishedSubscriber implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        $gate = RequireServicesGate::instance();
        if (!$gate->hasViolations()) {
            return;
        }

        $gate->reportTo(static function (string $line): void {
            fwrite(STDERR, $line . PHP_EOL);
        });

        exit(1);
    }
}
