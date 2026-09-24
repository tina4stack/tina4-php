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

use PHPUnit\Event\TestSuite\Skipped;
use PHPUnit\Event\TestSuite\SkippedSubscriber;

/**
 * Feeds every TestSuite\Skipped event into the RequireServicesGate.
 *
 * WHY THIS EXISTS (it is not a duplicate of TestSkippedSubscriber): a skip
 * declared in setUpBeforeClass() — the class-wide gate shape — never produces a
 * per-test Test\Skipped event. PHPUnit's TestSuite::invokeMethodsBeforeFirstTest()
 * catches the SkippedTest thrown by the hook and emits a SINGLE testSuiteSkipped()
 * for the whole class instead:
 *
 *     if (isset($t) && $t instanceof SkippedTest) {
 *         $emitter->testSuiteSkipped($testSuiteValueObjectForEvents, $t->getMessage());
 *         return false;
 *     }
 *
 * (vendor/phpunit/phpunit/src/Framework/TestSuite.php). A gate subscribing only
 * to Test\Skipped therefore never sees it, and the whole class skips GREEN under
 * TINA4_REQUIRE_SERVICES — the exact no-green-skips guarantee the gate exists to
 * provide. Reproduced against real PHPUnit 11.5.55: a class whose
 * setUpBeforeClass() calls markTestSkipped("Kafka not reachable on
 * localhost:9092") exited 0 with no report, while the identical skip inside a
 * test method exited 1.
 *
 * Found by auditing the same class of hole across all four frameworks after it
 * was fixed in tina4-ruby's spec/spec_helper.rb (RSpec does not run after(:each)
 * for an example skipped by a before(:context) hook, for the same reason).
 * tina4-python (pytest makereport fires per item) and tina4-nodejs (the gate
 * scans printed SKIP lines) are not affected.
 *
 * Locked in by tests/RequireServicesGateTest.php.
 */
final class TestSuiteSkippedSubscriber implements SkippedSubscriber
{
    public function notify(Skipped $event): void
    {
        RequireServicesGate::instance()->recordSkip(
            $event->testSuite()->name(),
            $event->message(),
        );
    }
}
