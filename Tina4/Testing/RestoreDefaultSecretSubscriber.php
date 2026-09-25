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

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;

/**
 * Fires just before PHPUnit prepares each test (before the test's setUp). If the
 * current TINA4_SECRET is missing or under the 32-byte ADR-0079 minimum, restore
 * the suite default so a value a previous test's tearDown left blank cannot make
 * this test's App/Auth boot throw. A test that sets its own secret in setUp() (or
 * a negative test that blanks it in its body) still wins, because both run after
 * this hook. See SecretDefaultExtension for the full rationale.
 */
final class RestoreDefaultSecretSubscriber implements PreparationStartedSubscriber
{
    /** Matches the default in tests/bootstrap.php (>=32 bytes). */
    public const DEFAULT_SECRET = 'tina4-php-test-suite-secret-0123456789abcdef';

    /**
     * Restore the suite default secret when the current one is missing or below
     * the ADR-0079 32-byte minimum. Shared by the per-test and per-suite hooks.
     */
    public static function ensure(): void
    {
        if (strlen((string)(getenv('TINA4_SECRET') ?: '')) < 32) {
            putenv('TINA4_SECRET=' . self::DEFAULT_SECRET);
            $_ENV['TINA4_SECRET'] = self::DEFAULT_SECRET;
        }
    }

    public function notify(PreparationStarted $event): void
    {
        self::ensure();
    }
}
