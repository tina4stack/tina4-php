<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

// DevAdmin is a secondary class in DevAdmin.php; PSR-4 cannot autoload it
// individually, so force-include the file.
require_once __DIR__ . '/../Tina4/DevAdmin.php';

use PHPUnit\Framework\TestCase;
use Tina4\DevAdmin;

/**
 * FREE-TOKEN trial: the grounding panel reports which credential the coder
 * uses so an unregistered developer gets nudged to sign up. Pure logic — no
 * router, no mocks — parity with tina4-python TestGroundingSnapshot and the
 * Rust agent's resolve().
 */
class DevAdminGroundingTest extends TestCase
{
    public function testFreeTrialWhenNoPersonalToken(): void
    {
        // No personal token → the panel advertises the shared FREE-TOKEN trial.
        $this->assertSame('free', DevAdmin::groundingSourceFor(''));
    }

    public function testPersonalWhenTokenSet(): void
    {
        $this->assertSame('personal', DevAdmin::groundingSourceFor('t4_my_own_1234'));
    }
}
