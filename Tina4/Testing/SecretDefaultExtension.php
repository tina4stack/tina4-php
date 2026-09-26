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

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * PHPUnit 11 event Extension that keeps a usable HMAC signing secret in place
 * for the whole suite (ADR-0079 s2).
 *
 * WHY THIS EXISTS
 * ---------------
 * tests/bootstrap.php sets a >=32-byte TINA4_SECRET once at start, because Auth
 * refuses to sign — and App::start() refuses to boot outside dev — with a blank
 * or short secret. But ~15 test classes legitimately set their OWN secret in
 * setUp() and then `putenv('TINA4_SECRET')` (UNSET it) in tearDown to clean up.
 * Before ADR-0079 a blank secret was harmless; now it makes EVERY subsequent
 * test that boots App/Auth throw "TINA4_SECRET is not set", cascading ~97
 * failures across FrondTest, HealthTest, DotenvSettings, I18n and more —
 * classic cross-test pollution, order-dependent under a randomised suite.
 *
 * THE FIX
 * -------
 * Before EVERY test (Test\PreparationStarted fires at the top of runBare, before
 * the test's own setUp), re-apply the suite default secret if the current one is
 * missing or under 32 bytes. This heals a value a prior test's tearDown left
 * blank, while a test that wants its own secret still wins: its setUp() runs
 * AFTER this hook and overrides it, and a negative "no/weak secret" test sets the
 * blank/short value inside its own body. Touches only the process env, no
 * framework runtime code, so production behaviour is identical.
 *
 * Registered in phpunit.xml under <extensions>, alongside
 * RequireServicesExtension and HandlerStackHygieneExtension.
 */
final class SecretDefaultExtension implements Extension
{
    public function bootstrap(
        Configuration $configuration,
        Facade $facade,
        ParameterCollection $parameters,
    ): void {
        $facade->registerSubscribers(
            new RestoreDefaultSecretBeforeSuiteSubscriber(),
            new RestoreDefaultSecretSubscriber(),
        );
    }
}
