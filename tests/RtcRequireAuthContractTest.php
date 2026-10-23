<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

/**
 * RTC config + signalling require auth by default (ADR-0101, tina4-python#252).
 *
 * The /api/rtc/config route mints a fresh TURN credential on every call and the
 * /ws/rtc/{room} signalling socket relays a call. Left public, anyone can use the
 * operator's TURN server as a relay. Both are secured by default; an app that
 * genuinely wants anonymous calling opts out with TINA4_RTC_REQUIRE_AUTH=false.
 *
 * Answer key: tina4-documentation/plan/v3/fixtures/rtc_auth_contract.json. NO
 * MOCKS - a real Realtime::mount() registration, auth_required read straight off
 * the registered route.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Router;
use Tina4\Realtime\Realtime;

final class RtcRequireAuthContractTest extends TestCase
{
    protected function setUp(): void
    {
        Router::clear();
        putenv('TINA4_RTC_REQUIRE_AUTH');
    }

    protected function tearDown(): void
    {
        Router::clear();
        putenv('TINA4_RTC_REQUIRE_AUTH');
    }

    private function authRequired(string $method, string $pathContains): bool
    {
        foreach (Router::getRoutes() as $r) {
            if ($r['method'] === $method && str_contains($r['path'], $pathContains)) {
                return (bool)$r['auth_required'];
            }
        }
        $this->fail("no $method route containing $pathContains");
    }

    private function mount(?string $value): void
    {
        if ($value === null) {
            putenv('TINA4_RTC_REQUIRE_AUTH');
        } else {
            putenv("TINA4_RTC_REQUIRE_AUTH=$value");
        }
        Realtime::mount('/rtc-t', ['features' => ['calls']]);
    }

    public function testConfigRouteRequiresAuthByDefault(): void
    {
        $this->mount(null);
        $this->assertTrue($this->authRequired('GET', 'api/rtc/config'));
    }

    public function testSignallingSocketRequiresAuthByDefault(): void
    {
        $this->mount(null);
        $this->assertTrue($this->authRequired('WS', 'ws/rtc'));
    }

    public function testRequireAuthFalseOpensTheConfigRoute(): void
    {
        $this->mount('false');
        $this->assertFalse($this->authRequired('GET', 'api/rtc/config'));
    }

    public function testRequireAuthFalseOpensTheSignallingSocket(): void
    {
        $this->mount('false');
        $this->assertFalse($this->authRequired('WS', 'ws/rtc'));
    }

    public function testRequireAuthExplicitTrueSecuresBoth(): void
    {
        $this->mount('true');
        $this->assertTrue($this->authRequired('GET', 'api/rtc/config'));
        $this->assertTrue($this->authRequired('WS', 'ws/rtc'));
    }

    public function testOnlyExplicitFalseyOpensTheRoute(): void
    {
        $this->mount('yes-please');
        $this->assertTrue($this->authRequired('GET', 'api/rtc/config'));
    }
}
