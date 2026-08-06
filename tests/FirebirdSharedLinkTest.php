<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\FirebirdAdapter;

/**
 * Regression tests for the two Firebird connection-lifetime defects that made
 * a whole suite fail with "invalid database handle (no active connection)".
 *
 * DEFECT 1 — the per-signature reference count.
 * ext-interbase hands back ONE physical link for connections opened with
 * identical arguments, and FirebirdAdapter used to reference-count holders per
 * connection signature to decide when it was allowed to close it. That count
 * was incremented by open() and decremented only by an EXPLICIT close(), and
 * the adapter has no destructor — so the first adapter that was garbage
 * collected instead of closed (the normal end of a request, a job, or a test)
 * pinned the count at >= 1 forever. From that moment the native close was
 * suppressed for every later holder of the same signature, for the life of the
 * process, and every adapter piled onto one increasingly stale native link.
 *
 * DEFECT 2 — isDeadConnection() did not recognise the message that says
 * exactly this, so the reconnect-and-retry path that exists for it never fired
 * and the caller got the error instead of a recovered connection.
 *
 * Both live tests run in a CHILD PROCESS on purpose. The behaviour under test
 * is process-global (an extension-level connection cache plus, formerly, a
 * static counter), so an in-process version would both be perturbed by every
 * other live-Firebird test in the suite and perturb them in turn — which is
 * precisely how this defect was discovered. Nothing here is a double: a real
 * PHP process, the real adapter, the real ext-interbase driver, a real
 * Firebird server, and a real server-side kill.
 */
class FirebirdSharedLinkTest extends TestCase
{
    /** Seconds a child gets before it is treated as wedged. */
    private const CHILD_CAP = 60;

    /** @var list<string> temp worker scripts to clean up */
    private array $scripts = [];

    protected function tearDown(): void
    {
        foreach ($this->scripts as $script) {
            if (is_file($script)) {
                @unlink($script);
            }
        }
        $this->scripts = [];
    }

    /** A live Firebird URL pinned to the native driver, or a loud skip. */
    private function nativeUrl(): string
    {
        $url = getenv('TINA4_TEST_FIREBIRD_URL');
        if ($url === false || $url === '') {
            $this->markTestSkipped(
                'Set TINA4_TEST_FIREBIRD_URL to run the live Firebird shared-link tests '
                . '(e.g. firebird://SYSDBA:masterkey@localhost:3050//tmp/test.fdb)'
            );
        }
        if (!function_exists('ibase_connect') && !function_exists('fbird_connect')) {
            $this->markTestSkipped('ext-interbase not installed — native link sharing is UNVERIFIED here.');
        }
        return $url . (str_contains($url, '?') ? '&' : '?') . 'driver=interbase';
    }

    /**
     * The worker script, dispatched by role. One file for every role keeps the
     * connection setup identical across them, so the only thing that differs
     * between roles is what they do to the connection.
     */
    private function workerScript(string $url): string
    {
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        $target = var_export($url, true);
        // A different charset is a different connect signature, so the killer
        // holds its OWN physical link and cannot be the link it is killing.
        $killerUrl = var_export($url . '&charset=WIN1252', true);

        $body = <<<PHP
        <?php
        require {$autoload};
        use Tina4\\Database\\Database;

        /** This connection's server-side attachment id. Firebird never reuses one. */
        \$attachmentId = static function (\$database): int {
            \$row = array_change_key_case((array) \$database->fetchOne(
                'SELECT CURRENT_CONNECTION AS C FROM RDB\$DATABASE'
            ));
            return (int) (\$row['c'] ?? -1);
        };

        \$role = \$argv[1] ?? '';

        /**
         * Kill \$victim's connection for real, from a SECOND real connection,
         * through MON\$ATTACHMENTS - Firebird's supported way to drop an
         * attachment. Nothing is simulated and nothing is stubbed.
         */
        \$killAndRetry = static function (\$victim, int \$victimId) use (\$attachmentId): void {
            // A different charset is a different connect signature, so the
            // killer holds its OWN physical link and cannot be the link it kills.
            \$killer = Database::create({$killerUrl});
            \$killer->execute('DELETE FROM MON\$ATTACHMENTS WHERE MON\$ATTACHMENT_ID = ?', [\$victimId]);
            \$killer->close();

            try {
                \$revivedId = \$attachmentId(\$victim);
                echo "KILLED={\$victimId} REVIVED={\$revivedId}\\n";
            } catch (\\Throwable \$e) {
                echo "KILLED={\$victimId} STILLDEAD=" . str_replace("\\n", ' ', \$e->getMessage()) . "\\n";
            }
        };

        // DEFECT 2, control shape: a genuinely dead connection, and no sibling
        // anywhere. The next statement must reconnect and retry.
        if (\$role === 'revive-after-real-kill') {
            \$victim = Database::create({$target});
            \$killAndRetry(\$victim, \$attachmentId(\$victim));
            exit(0);
        }

        // DEFECT 1: identical to the above except that ONE earlier holder was
        // garbage collected instead of closed, exactly as a finished request,
        // job or test leaves it. That holder must not be counted forever - if
        // it is, the reconnect cannot release the dead native link and
        // ibase_connect() hands the very same dead link straight back.
        if (\$role === 'revive-after-real-kill-with-abandoned-sibling') {
            \$abandoned = Database::create({$target});
            \$abandoned->fetchOne('SELECT 1 AS N FROM RDB\$DATABASE');
            unset(\$abandoned);            // garbage collected, never closed
            gc_collect_cycles();

            \$victim = Database::create({$target});
            \$killAndRetry(\$victim, \$attachmentId(\$victim));
            exit(0);
        }

        fwrite(STDERR, "unknown role\\n");
        exit(2);
        PHP;

        $script = sys_get_temp_dir() . '/tina4_fb_shared_link_' . getmypid() . '_' . count($this->scripts) . '.php';
        file_put_contents($script, $body);
        $this->scripts[] = $script;

        return $script;
    }

    /**
     * Run one role to completion and return its output. A child that outlives
     * the cap is killed and reported — a wedged child must never read as a pass.
     */
    private function runRole(string $role): string
    {
        $script = $this->workerScript($this->nativeUrl());

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $script, $role],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__)
        );
        $this->assertIsResource($process, "could not start the {$role} worker");

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $deadline = microtime(true) + self::CHILD_CAP;
        $finished = false;
        while (microtime(true) < $deadline) {
            $output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            if (proc_get_status($process)['running'] === false) {
                $finished = true;
                break;
            }
            usleep(50_000);
        }
        $output .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (!$finished) {
            proc_terminate($process, SIGKILL);
        }
        proc_close($process);

        $this->assertTrue($finished, "the {$role} worker never finished. Got: {$output}");

        return $output;
    }

    /** Pull an integer field out of a worker's "KEY=value" output. */
    private function field(string $output, string $key): int
    {
        $this->assertMatchesRegularExpression(
            '/\b' . preg_quote($key, '/') . '=(\d+)\b/',
            $output,
            "worker did not report {$key}. Got: {$output}"
        );
        preg_match('/\b' . preg_quote($key, '/') . '=(\d+)\b/', $output, $matches);

        return (int) $matches[1];
    }

    /** Assert a worker recovered from a real server-side kill onto a NEW attachment. */
    private function assertRecovered(string $output, string $why): void
    {
        $this->assertStringNotContainsString('STILLDEAD', $output, $why . " Worker said: {$output}");

        $killed = $this->field($output, 'KILLED');
        $revived = $this->field($output, 'REVIVED');

        $this->assertNotSame(
            $killed,
            $revived,
            "the retry must run on a NEW attachment, not the killed one ({$killed}). Worker said: {$output}"
        );
    }

    /**
     * DEFECT 2, live: a REAL dead connection must be transparently recovered.
     *
     * The connection is killed server-side through MON$ATTACHMENTS by a second
     * real connection — Firebird's supported way to drop an attachment — so the
     * handle really is dead. The next statement must reconnect and succeed on a
     * NEW attachment rather than surfacing the driver error to the caller.
     *
     * This failed because isDeadConnection() did not recognise "invalid
     * database handle (no active connection)", so the reconnect-and-retry that
     * exists for exactly this never fired.
     */
    public function testAQueryOnAKilledConnectionTransparentlyReconnects(): void
    {
        $this->assertRecovered(
            $this->runRole('revive-after-real-kill'),
            'a statement on a connection that was killed server-side must reconnect and retry, '
            . 'not surface the driver error.'
        );
    }

    /**
     * DEFECT 1, the regression: the same recovery, with ONE earlier adapter
     * that was garbage collected instead of closed.
     *
     * The per-signature holder count was incremented by open() and decremented
     * only by an explicit close(), and the adapter had no destructor — so that
     * one abandoned holder pinned the count above zero for the life of the
     * process. The reconnect's release was then suppressed, the dead native
     * link was never handed back to the driver, and ibase_connect() returned
     * the very same dead link, so the recovery could not work no matter how
     * many times it was retried. That is what left twelve unrelated
     * live-Firebird tests erroring with "invalid database handle (no active
     * connection)" the moment one more holder appeared in the process.
     */
    public function testAKilledConnectionStillRecoversAfterASiblingWasAbandoned(): void
    {
        $this->assertRecovered(
            $this->runRole('revive-after-real-kill-with-abandoned-sibling'),
            'an adapter that was garbage collected instead of closed must not be counted as a live '
            . 'holder forever — it suppresses the release of the dead link and the reconnect then '
            . 'gets the same dead link straight back.'
        );
    }

    /**
     * DEFECT 2, the marker list: every wording below is a verbatim string from
     * Firebird's own message catalogue (firebird.msg, the file the client
     * library formats its errors from), so these are the messages a caller can
     * actually receive — not invented ones.
     */
    public function testIsDeadConnectionRecognisesRealFirebirdDisconnectWordings(): void
    {
        $realWordings = [
            'invalid database handle (no active connection)',
            'Unable to complete network request to host "127.0.0.1".',
            'Error writing data to the connection.',
            'Error reading data from the connection.',
            'connection shutdown',
            'connection lost to database',
        ];

        foreach ($realWordings as $wording) {
            $this->assertTrue(
                FirebirdAdapter::isDeadConnection($wording),
                "a dead connection must be recognised so reconnect-and-retry can fire: {$wording}"
            );
        }

        // Case-insensitive, and tolerant of the driver's surrounding text.
        $this->assertTrue(
            FirebirdAdapter::isDeadConnection('Firebird fetch() failed: INVALID DATABASE HANDLE (no active connection) ')
        );
    }

    /**
     * DEFECT 2, the negative half: an ordinary SQL error must NOT be mistaken
     * for a dead connection. Reconnect-and-retry re-runs the statement, so a
     * false positive would silently execute a failed write a second time.
     */
    public function testIsDeadConnectionIgnoresOrdinarySqlErrors(): void
    {
        $ordinaryErrors = [
            'Dynamic SQL Error SQL error code = -204 Table unknown T_MISSING',
            'violation of PRIMARY or UNIQUE KEY constraint "INTEG_2" on table "T_USERS"',
            'validation error for column "NAME", value "*** null ***"',
            'arithmetic exception, numeric overflow, or string truncation',
            'Token unknown - line 1, column 8',
            'attempt to store duplicate value (visible to active transactions) in unique index',
            'deadlock',
        ];

        foreach ($ordinaryErrors as $error) {
            $this->assertFalse(
                FirebirdAdapter::isDeadConnection($error),
                "an ordinary SQL error must never trigger a reconnect-and-retry: {$error}"
            );
        }

        $this->assertFalse(FirebirdAdapter::isDeadConnection(''));
        $this->assertFalse(FirebirdAdapter::isDeadConnection(null));
    }
}
