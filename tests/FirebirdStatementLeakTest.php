<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Live Firebird regression guard for #170 — the RETAINED-TABLE LOCK.
 *
 * Root cause (native ext-interbase, pre-fix): the parameterised branch of
 * FirebirdAdapter::doExecute() ran statements through ibase_prepare() +
 * ibase_execute(). A prepared statement's compiled request stays registered on
 * the ATTACHMENT, and ext-interbase does not detach on ibase_close() while the
 * PHP process is alive — so the table stayed locked for the life of the process
 * even though ibase_commit() reported success and close() had been called. The
 * next DDL against that table from ANY other connection then waited forever:
 * both the native default transaction (IBASE_WAIT) and pdo_firebird use a WAIT
 * lock policy, so the loser blocks instead of erroring. That is what hung
 * FirebirdWriteVisibilityTest and MigrationV3Test on a host where ext-interbase
 * is actually installed.
 *
 * The tell was that only PARAMETERISED statements did it — reads as well as
 * writes. An unparameterised statement goes through ibase_query(), whose
 * statement is driver-owned and dropped with its result. Measured on the lab,
 * Firebird 5.0.4, PHP 8.3.6, ext-interbase built from source: unparameterised
 * statements always released the table, parameterised ones never did, and no
 * ordering of ibase_free_query()/ibase_free_result() changed that. The fix is to
 * bind through ibase_query() as well, which keeps real driver-side parameter
 * binding (no interpolation, no injection risk) without a persistent statement.
 *
 * SHAPE MATTERS, and it was measured rather than assumed. The leak is only
 * observable when the probing connection is a genuinely separate ATTACHMENT
 * (ext-interbase de-duplicates identical-argument connections onto one link, so
 * a second connection in the same process sees its own transaction and never
 * blocks), and when the holder did not itself create the table — a first draft
 * of this test had the holder create it and passed against the UNFIXED adapter,
 * which is to say it was not a gate at all. Hence three real php processes:
 *
 *   creator  makes the table and EXITS, so nothing it did can be mistaken for
 *            the holder's leak
 *   holder   does the parameterised write, commits, closes, and stays alive
 *            (a long-running app is the real-world shape of this bug)
 *   prober   must still be able to run DDL, under a wall-clock deadline
 *
 * No mocks and no doubles — every step is a real process talking to a real
 * Firebird server, and the assertion is simply whether real DDL returns.
 */

use PHPUnit\Framework\TestCase;

class FirebirdStatementLeakTest extends TestCase
{
    /** Seconds a worker is allowed before we call it blocked. */
    private const PROBE_TIMEOUT = 20;

    /** Seconds the holder stays alive after closing its connection. */
    private const HOLD_SECONDS = 40;

    private string $table = '';
    private string $script = '';
    /** @var resource|null */
    private $holder = null;

    protected function setUp(): void
    {
        $this->table = 't_stmt_leak_' . getmypid();
    }

    protected function tearDown(): void
    {
        $this->stopHolder();
        if ($this->script !== '') {
            @unlink($this->script);
            $this->script = '';
        }
    }

    private function stopHolder(): void
    {
        if (is_resource($this->holder)) {
            proc_terminate($this->holder, 9);
            proc_close($this->holder);
            $this->holder = null;
        }
    }

    /** Base URL for a real Firebird server, or a loud skip (never a silent pass). */
    private function nativeUrl(): string
    {
        $url = getenv('TINA4_TEST_FIREBIRD_URL');
        if ($url === false || $url === '') {
            $this->markTestSkipped(
                'Set TINA4_TEST_FIREBIRD_URL to run the live Firebird statement-leak test '
                . '(e.g. firebird://SYSDBA:masterkey@localhost:3050//tmp/test.fdb)'
            );
        }
        if (!function_exists('ibase_connect') && !function_exists('fbird_connect')) {
            $this->markTestSkipped('ext-interbase not installed — the statement leak is UNVERIFIED here.');
        }
        return $url . (str_contains($url, '?') ? '&' : '?') . 'driver=interbase';
    }

    /**
     * The one worker script, dispatched by role. Keeping the three roles in a
     * single file keeps the connection setup identical across them, so the only
     * difference between creator, holder and prober is what they do.
     */
    private function workerScript(string $url, string $table): string
    {
        if ($this->script !== '') {
            return $this->script;
        }
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        $u = var_export($url, true);
        $t = $table;
        $hold = self::HOLD_SECONDS;

        $body = <<<PHP
        <?php
        require {$autoload};
        use Tina4\\Database\\Database;

        \$role = \$argv[1] ?? '';
        \$db = Database::create({$u});

        if (\$role === 'creator') {
            try { \$db->execute('DROP TABLE {$t}'); \$db->commit(); } catch (\\Throwable) {}
            \$db->execute('CREATE TABLE {$t} (id INTEGER NOT NULL PRIMARY KEY, name VARCHAR(50))');
            \$db->commit();
            \$db->close();
            echo "CREATOR_DONE\\n";
            exit(0);
        }

        if (\$role === 'holder-bound') {
            // The path that used to retain the lock: bound parameters.
            \$db->execute('INSERT INTO {$t} (id, name) VALUES (?, ?)', [1, 'alpha']);
            \$db->commit();
            \$db->close();
            echo "HOLDER_READY\\n";
            sleep({$hold});
            exit(0);
        }

        if (\$role === 'holder-literal') {
            // The control: no parameters. This shape always released.
            \$db->execute("INSERT INTO {$t} (id, name) VALUES (2, 'beta')");
            \$db->commit();
            \$db->close();
            echo "HOLDER_READY\\n";
            sleep({$hold});
            exit(0);
        }

        if (\$role === 'prober') {
            \$db->execute('DROP TABLE {$t}');
            \$db->commit();
            \$db->close();
            echo "PROBER_DONE\\n";
            exit(0);
        }

        fwrite(STDERR, "unknown role\\n");
        exit(2);
        PHP;

        $this->script = sys_get_temp_dir() . '/tina4_fb_stmt_leak_' . getmypid() . '.php';
        file_put_contents($this->script, $body);
        return $this->script;
    }

    /**
     * Run a worker role to completion and return [finished, output]. `finished`
     * is false when the deadline passed — which is exactly the failure this test
     * exists to catch, so it must never be allowed to hang the suite.
     *
     * @return array{0: bool, 1: string}
     */
    private function runRole(string $script, string $role, int $seconds): array
    {
        $pipes = [];
        $proc = proc_open(
            [PHP_BINARY, $script, $role],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($proc, "could not start worker role '{$role}'");
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = '';
        $deadline = microtime(true) + $seconds;
        $finished = false;
        while (microtime(true) < $deadline) {
            $out .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            if (!proc_get_status($proc)['running']) {
                $finished = true;
                break;
            }
            usleep(100_000);
        }
        $out .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        if (!$finished) {
            proc_terminate($proc, 9);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return [$finished, $out];
    }

    /** Start a holder role and wait for it to announce that it has closed. */
    private function startHolder(string $script, string $role): string
    {
        $pipes = [];
        $this->holder = proc_open(
            [PHP_BINARY, $script, $role],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($this->holder, "could not start holder role '{$role}'");
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = '';
        $deadline = microtime(true) + 30;
        while (microtime(true) < $deadline && !str_contains($out, 'HOLDER_READY')) {
            $out .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            if (!proc_get_status($this->holder)['running']) {
                break;
            }
            usleep(100_000);
        }
        return $out . (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    }

    /**
     * A committed, closed PARAMETERISED write must not keep holding the table.
     *
     * Before the fix the prober never returned; the assertion below failed only
     * after PROBE_TIMEOUT, which is the point — a wall-clock bound turns "hangs
     * forever" into an ordinary red test.
     */
    public function testParameterisedWriteReleasesTheTableAfterClose(): void
    {
        $this->assertLeakFree('holder-bound', 'a PARAMETERISED write');
    }

    /**
     * The control. An unparameterised write was always clean, so this shows the
     * guard above measures the statement leak and not merely "Firebird locks
     * tables" — if both ever fail together, the cause is the server or the
     * fixture, not the prepared-statement path.
     */
    public function testUnparameterisedWriteReleasesTheTableAfterClose(): void
    {
        $this->assertLeakFree('holder-literal', 'an UNPARAMETERISED write');
    }

    private function assertLeakFree(string $holderRole, string $what): void
    {
        $script = $this->workerScript($this->nativeUrl(), $this->table);

        [$made, $out] = $this->runRole($script, 'creator', self::PROBE_TIMEOUT);
        if (!$made || !str_contains($out, 'CREATOR_DONE')) {
            $this->markTestSkipped('Firebird is not usable from a worker here: ' . trim($out));
        }

        $ready = $this->startHolder($script, $holderRole);
        if (!str_contains($ready, 'HOLDER_READY')) {
            $this->markTestSkipped('the Firebird holder could not complete its write: ' . trim($ready));
        }

        [$finished, $probe] = $this->runRole($script, 'prober', self::PROBE_TIMEOUT);

        $this->assertTrue(
            $finished,
            "DROP TABLE from a separate connection BLOCKED for " . self::PROBE_TIMEOUT
            . "s while a process that had already committed and closed {$what} was still "
            . 'alive — the statement was leaked and its transaction still holds the table '
            . '(#170). Prober output: ' . trim($probe)
        );
        $this->assertStringContainsString(
            'PROBER_DONE',
            $probe,
            'the prober did not complete the DDL: ' . trim($probe)
        );

        // Release the holder now so a later test never inherits its attachment.
        $this->stopHolder();
    }
}
