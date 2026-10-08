<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */

/**
 * Regression for tina4-php#280: Database::create() must accept every scheme
 * DatabaseUrl resolves, so a documented alias is not refused by the factory.
 *
 * No mocks: create() really tries to connect, to a port nothing listens on, so
 * a RESOLVED scheme fails with the adapter's connect error while an UNRESOLVED
 * one fails earlier with InvalidArgumentException ("Unsupported database
 * scheme"). The two exception types are the instrument; no live server needed.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;

class DatabaseSchemeAliasTest extends TestCase
{
    /** A scheme the factory accepts gets past resolution and fails at CONNECT, never at the scheme gate. */
    private function assertSchemeAccepted(string $url): void
    {
        try {
            Database::create($url);
            $this->fail("expected a connect failure for {$url} (nothing listens on the port)");
        } catch (\InvalidArgumentException $e) {
            $this->fail("scheme of {$url} was refused by the factory: " . $e->getMessage());
        } catch (\Throwable $e) {
            // A connect/driver error (RuntimeException and friends) is the pass:
            // the scheme resolved and the adapter was reached.
            $this->assertStringNotContainsStringIgnoringCase('unsupported database scheme', $e->getMessage());
        }
    }

    public function testPgsqlSchemeResolvesLikePostgres(): void
    {
        // Both must behave identically: DatabaseUrl maps pgsql -> postgres, so
        // the factory must too (issue #280). Port 1 has no listener.
        $this->assertSame('postgres', (new \Tina4\DatabaseUrl('pgsql://127.0.0.1:1/x'))->engine);
        $this->assertSchemeAccepted('pgsql://127.0.0.1:1/x');
        $this->assertSchemeAccepted('postgres://127.0.0.1:1/x');
    }

    public function testEveryDatabaseUrlAliasIsAcceptedByTheFactory(): void
    {
        // Lock the whole alias set so the factory and DatabaseUrl cannot drift
        // again. sqlite is excluded (no network connect) and odbc needs a DSN.
        foreach (['postgres', 'postgresql', 'pgsql', 'mysql', 'mssql', 'sqlserver', 'firebird'] as $scheme) {
            $this->assertSchemeAccepted("{$scheme}://127.0.0.1:1/x");
        }
    }

    public function testAGenuinelyUnknownSchemeIsStillRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unsupported database scheme/i');
        Database::create('notadb://127.0.0.1:1/x');
    }
}
