<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Graph\GraphUrl;

/**
 * GHSA-h2mr-m3v3-cq8c: a GraphUrl dumped with print_r()/var_dump(), or returned
 * from a route (Tina4 auto-serialises an object to JSON), printed the password
 * twice - in $password and inside $raw. DatabaseUrl guards the same way.
 */
class GraphUrlDumpTest extends TestCase
{
    private const URL = 'neo4j://graphuser:Pq1Rs2-graph@graphhost:7687/movies';

    public function testPrintRShowsNoPassword(): void
    {
        $out = print_r(new GraphUrl(self::URL), true);
        $this->assertStringNotContainsString('Pq1Rs2', $out);
        $this->assertStringContainsString('graphhost', $out);
        $this->assertStringContainsString('graphuser', $out);
    }

    public function testVarDumpShowsNoPassword(): void
    {
        ob_start();
        var_dump(new GraphUrl(self::URL));
        $out = (string) ob_get_clean();
        $this->assertStringNotContainsString('Pq1Rs2', $out);
        $this->assertStringContainsString('graphhost', $out);
    }

    public function testJsonShowsNoPassword(): void
    {
        $out = json_encode(new GraphUrl(self::URL));
        $this->assertIsString($out);
        $this->assertStringNotContainsString('Pq1Rs2', $out);
        $this->assertStringContainsString('graphhost', $out);
    }

    public function testTheCredentialsAreStillThereForTheDriver(): void
    {
        $url = new GraphUrl(self::URL);
        $this->assertSame('Pq1Rs2-graph', $url->password);
        $this->assertSame('graphuser', $url->username);
    }
}
