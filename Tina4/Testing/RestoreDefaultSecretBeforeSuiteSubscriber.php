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

use PHPUnit\Event\TestSuite\Started;
use PHPUnit\Event\TestSuite\StartedSubscriber;

/**
 * Fires when a test suite (a test class) starts, BEFORE its setUpBeforeClass.
 * Child-server tests (e.g. DotenvSettingsAndContentTypeTest) build the env they
 * hand to a spawned `App::run()` process inside setUpBeforeClass by copying
 * getenv() — so a TINA4_SECRET left blank by an earlier class's tearDown would
 * be copied blank into the child, which then refuses to boot (ADR-0079). The
 * per-test PreparationStarted hook fires too late for that. Restoring the
 * default here covers the per-class boundary too. See SecretDefaultExtension.
 */
final class RestoreDefaultSecretBeforeSuiteSubscriber implements StartedSubscriber
{
    public function notify(Started $event): void
    {
        RestoreDefaultSecretSubscriber::ensure();
    }
}
