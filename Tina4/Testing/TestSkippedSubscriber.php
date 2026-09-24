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
