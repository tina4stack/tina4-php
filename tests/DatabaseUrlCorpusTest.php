<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\DatabaseUrl;

/**
 * The shared DATABASE_URL corpus (feature 5 of the feature audit).
 *
 * `tests/fixtures/database_url_corpus.json` is byte-identical in all four
 * frameworks. One answer key, four suites: a case that passes here and fails in
 * Ruby is a parity bug with a name, not a difference somebody has to notice.
 *
 * Core Principle 6 says a connection string means literally the same thing in
 * every framework. Nothing could check that before this file, because two of the
 * four parse URLs inside the Database constructor and cannot be asked what a URL
 * means without opening a connection.
 *
 * Pure string-to-struct. No database, no socket, no driver import.
 */
class DatabaseUrlCorpusTest extends TestCase
{
    /** @return array<string, mixed> */
    private function corpus(): array
    {
        $path = __DIR__ . '/fixtures/database_url_corpus.json';
        $this->assertFileExists($path, 'the shared corpus fixture is missing');
        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testEveryCorpusCaseParsesToTheAgreedStruct(): void
    {
        $failures = [];

        foreach ($this->corpus()['cases'] as $case) {
            $url = new DatabaseUrl($case['url']);

            foreach (['engine', 'host', 'port', 'database', 'username', 'password'] as $field) {
                $want = $case[$field] ?? null;
                $got = $url->{$field};
                if ($got !== $want) {
                    $failures[] = sprintf(
                        "%s: %s got %s, want %s",
                        $case['name'],
                        $field,
                        var_export($got, true),
                        var_export($want, true)
                    );
                }
            }
        }

        $this->assertSame([], $failures, "corpus mismatches:\n" . implode("\n", $failures));
    }

    /**
     * A connection URL in a log is a credential leak, so the redacted form is the
     * only shape allowed in a log line or an error message. It round-trips the
     * input with the password replaced, which is what makes it readable as well
     * as safe.
     */
    public function testToSafeStringRoundTripsWithoutThePassword(): void
    {
        $failures = [];

        foreach ($this->corpus()['cases'] as $case) {
            $url = new DatabaseUrl($case['url']);
            $safe = $url->toSafeString();

            if ($safe !== $case['safe']) {
                $failures[] = sprintf("%s: got %s, want %s", $case['name'], $safe, $case['safe']);
            }

            if ($case['password'] !== null && str_contains($safe, $case['password'])) {
                $failures[] = sprintf("%s: LEAKED the password into %s", $case['name'], $safe);
            }
        }

        $this->assertSame([], $failures, "safe-string mismatches:\n" . implode("\n", $failures));
    }

    public function testAnUnparseableUrlRaisesANamedErrorInsteadOfGuessing(): void
    {
        foreach ($this->corpus()['errors'] as $case) {
            try {
                new DatabaseUrl($case['url']);
                $this->fail("{$case['name']}: '{$case['url']}' parsed instead of raising");
            } catch (\InvalidArgumentException $e) {
                // A silent fallback to sqlite would be the dangerous outcome here:
                // the app boots, writes to a local file, and nobody learns the real
                // database was never reached.
                $this->assertStringContainsString('DatabaseUrl', $e->getMessage());
            }
        }
    }

    /**
     * The D4 leak cannot come back: `engine` is a canonical engine name, never an
     * adapter class. It used to be `$driver` holding `DataPostgresql` on a public
     * readonly property.
     */
    public function testEngineNeverHoldsAnAdapterClassName(): void
    {
        foreach ($this->corpus()['cases'] as $case) {
            $engine = (new DatabaseUrl($case['url']))->engine;
            $this->assertStringStartsNotWith('Data', $engine, "{$case['name']} leaked a class name");
            $this->assertContains(
                $engine,
                ['sqlite', 'postgres', 'mysql', 'mssql', 'firebird'],
                "{$case['name']} produced a non-canonical engine"
            );
        }
    }

    /** Aliases resolve ONCE, at parse, so nothing downstream compares raw schemes. */
    public function testEveryAliasResolvesToItsCanonicalEngine(): void
    {
        foreach ($this->corpus()['aliases'] as $alias => $canonical) {
            $sample = $alias === 'sqlite3'
                ? "{$alias}:///app.db"
                : "{$alias}://localhost/db";
            $this->assertSame(
                $canonical,
                (new DatabaseUrl($sample))->engine,
                "{$alias} did not resolve to {$canonical}"
            );
        }
    }

    /**
     * A URL without a port yields the same struct everywhere. The port used to be
     * left unset in Node and filled in by the third-party driver's own default,
     * which hid the divergence behind someone else's assumption.
     */
    public function testAUrlWithoutAPortGetsTheEngineDefault(): void
    {
        foreach ($this->corpus()['default_ports'] as $engine => $port) {
            $scheme = $engine === 'mssql' ? 'mssql' : $engine;
            $url = new DatabaseUrl("{$scheme}://localhost/db");
            $this->assertSame($port, $url->port, "{$engine} did not default to {$port}");
        }
    }
}
