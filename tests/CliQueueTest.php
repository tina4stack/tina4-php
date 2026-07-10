<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Real tests for the top-level `queue` CLI command (Phase 3, PHP mirror of the
 * Python master's tests/test_cli_queue.py).
 *
 * NO mocks. Every test drives the ACTUAL `bin/tina4php` entrypoint as a real
 * subprocess (exactly how the tina4 Rust client drives it) against a REAL
 * file-backed \Tina4\Queue rooted under a temp dir: push real jobs, run
 * `work` / `stats` / `retry` / `clear`, and assert the real on-disk counts and
 * side effects. `work` is exercised as a bounded single-pass drain (`--once`)
 * running a REAL consumer file (real per-job handler, real processing) — no
 * mock and no leaked worker.
 */

use PHPUnit\Framework\TestCase;

class CliQueueTest extends TestCase
{
    private static string $bin;

    private string $work;   // temp workdir (also the subprocess cwd)
    private string $qp;     // real file-backed queue path
    private string $svc;    // services dir holding the real consumer file

    public static function setUpBeforeClass(): void
    {
        self::$bin = realpath(__DIR__ . '/../bin/tina4php');
        self::assertNotFalse(self::$bin, 'bin/tina4php not found');
    }

    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir() . '/tina4-cliqueue-' . getmypid() . '-' . uniqid();
        $this->qp = $this->work . '/queue';
        $this->svc = $this->work . '/svc';
        mkdir($this->svc, 0755, true);

        // Force a clean, isolated file backend for both the test-process Queue
        // and the CLI subprocess (which inherits this env via getenv()).
        putenv('TINA4_QUEUE_BACKEND=file');
        putenv('TINA4_QUEUE_PATH=' . $this->qp);
    }

    protected function tearDown(): void
    {
        putenv('TINA4_QUEUE_BACKEND');
        putenv('TINA4_QUEUE_PATH');
        $this->rmrf($this->work);
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * A real file-backed Queue in the test process, pointed at the SAME path
     * the CLI subprocess uses — so jobs pushed here are the jobs the CLI sees,
     * and counts asserted here are the CLI's real on-disk state.
     */
    private function queue(string $topic, array $config = []): \Tina4\Queue
    {
        return new \Tina4\Queue('file', array_merge(['path' => $this->qp], $config), $topic);
    }

    /** Drive the REAL bin/tina4php as the tina4 client would. Returns [out, err, exit]. */
    private function runCli(array $args): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::$bin);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $env = getenv();
        $env['TINA4_QUEUE_BACKEND'] = 'file';
        $env['TINA4_QUEUE_PATH'] = $this->qp;

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes, $this->work, $env);
        self::assertIsResource($process, 'failed to start bin/tina4php');

        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [$out, $err, $exit];
    }

    /**
     * Write a REAL consumer file exposing topic + per-job handle, the shape
     * `generate queue <topic>` scaffolds. The handle appends each job's n to a
     * results file (a real, observable side effect) and throws on a poison job.
     */
    private function writeConsumer(string $topic, string $resultsFile): void
    {
        $rf = var_export($resultsFile, true);
        $tp = var_export($topic, true);
        file_put_contents(
            $this->svc . "/{$topic}_consumer.php",
            "<?php\n"
            . "\$handle = function (array \$data): void {\n"
            . "    \$results = {$rf};\n"
            . "    if (!empty(\$data['boom'])) { throw new \\RuntimeException('boom'); }\n"
            . "    file_put_contents(\$results, \$data['n'] . \"\\n\", FILE_APPEND);\n"
            . "};\n"
            . "return ['name' => {$tp} . '-consumer', 'topic' => {$tp}, 'handle' => \$handle, 'daemon' => true];\n"
        );
    }

    // ── stats ────────────────────────────────────────────────────────────

    public function testStatsReportsRealPendingCount(): void
    {
        $q = $this->queue('emails');
        $q->push(['n' => 1]);
        $q->push(['n' => 2]);
        $q->push(['n' => 3]);

        [$out, , $exit] = $this->runCli(['queue', 'stats', 'emails']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('pending    3', $out);
    }

    public function testStatsJsonIsMachineReadableAndExact(): void
    {
        $this->queue('emails')->push(['n' => 1]);

        [$out, , $exit] = $this->runCli(['queue', 'stats', 'emails', '--json']);
        $this->assertSame(0, $exit);
        $this->assertSame(
            ['topic' => 'emails', 'pending' => 1, 'reserved' => 0, 'failed' => 0, 'dead' => 0, 'completed' => 0],
            json_decode($out, true)
        );
    }

    public function testStatsDeadCountViaRealJobLifecycle(): void
    {
        // Create a real dead-letter through the Job lifecycle: max_retries=1 so
        // the first fail() dead-letters it. Then the CLI must report dead == 1.
        $q = $this->queue('emails', ['maxRetries' => 1]);
        $q->push(['n' => 1]);
        foreach ($q->consume('emails', null, 0.0) as $job) {
            $job->fail('boom');    // attempts 1 >= maxRetries 1 -> dead-letter
            break;
        }
        $this->assertSame(1, $this->queue('emails')->size('dead'), 'setup: expected a real dead-letter');

        [$out, , $exit] = $this->runCli(['queue', 'stats', 'emails', '--json']);
        $this->assertSame(0, $exit);
        $this->assertSame(1, json_decode($out, true)['dead']);
    }

    public function testStatsDefaultTopicWhenOmitted(): void
    {
        $this->queue('default')->push(['n' => 1]);

        [$out, , $exit] = $this->runCli(['queue', 'stats']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString("Queue 'default'", $out);
        $this->assertStringContainsString('pending    1', $out);
    }

    // ── retry ────────────────────────────────────────────────────────────

    public function testRetryRevivesDeadLetterJobBackToPending(): void
    {
        $q = $this->queue('jobs', ['maxRetries' => 1]);
        $q->push(['n' => 1]);
        foreach ($q->consume('jobs', null, 0.0) as $job) {
            $job->fail('boom');    // -> dead-letter
            break;
        }
        $this->assertSame(1, $this->queue('jobs')->size('dead'));

        [$out, , $exit] = $this->runCli(['queue', 'retry', 'jobs']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Re-queued 1 job(s)', $out);

        // Real files moved out of the dead-letter store back to pending.
        $this->assertSame(1, $this->queue('jobs')->size('pending'));
        $this->assertSame(0, $this->queue('jobs')->size('dead'));
    }

    public function testRetryNothingToRetryIsZero(): void
    {
        [$out, , $exit] = $this->runCli(['queue', 'retry', 'jobs']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Re-queued 0 job(s)', $out);
    }

    // ── clear ────────────────────────────────────────────────────────────

    public function testClearPendingPurgesRealJobs(): void
    {
        $q = $this->queue('tasks');
        $q->push(['n' => 1]);
        $q->push(['n' => 2]);
        $this->assertSame(2, $q->size('pending'));

        [$out, , $exit] = $this->runCli(['queue', 'clear', 'pending', 'tasks']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString("Cleared 2 'pending' job(s)", $out);
        $this->assertSame(0, $this->queue('tasks')->size('pending'));
    }

    public function testClearDefaultsToCompletedAndDefaultTopic(): void
    {
        $this->queue('tasks')->push(['n' => 1]);

        [$out, , $exit] = $this->runCli(['queue', 'clear']); // status=completed, topic=default
        $this->assertSame(0, $exit);
        $this->assertStringContainsString("Cleared 0 'completed' job(s)", $out);
        // A completed-clear on the default topic leaves the tasks topic intact.
        $this->assertSame(1, $this->queue('tasks')->size('pending'));
    }

    public function testClearDeadLetter(): void
    {
        $q = $this->queue('tasks', ['maxRetries' => 1]);
        $q->push(['n' => 1]);
        foreach ($q->consume('tasks', null, 0.0) as $job) {
            $job->fail('boom');    // -> dead-letter
            break;
        }
        $this->assertSame(1, $this->queue('tasks')->size('dead'));

        [$out, , $exit] = $this->runCli(['queue', 'clear', 'dead', 'tasks']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString("Cleared 1 'dead' job(s)", $out);
        $this->assertSame(0, $this->queue('tasks')->size('dead'));
    }

    // ── work ─────────────────────────────────────────────────────────────

    public function testWorkOnceDrainsAndProcessesRealJobs(): void
    {
        $results = $this->work . '/results.txt';
        $this->writeConsumer('greetings', $results);

        $q = $this->queue('greetings');
        $q->push(['n' => 1]);
        $q->push(['n' => 2]);
        $q->push(['n' => 3]);

        [$out, , $exit] = $this->runCli(['queue', 'work', 'greetings', '--once', '--services', $this->svc]);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Processed 3 job(s), 0 failed', $out);

        // The REAL handler ran for every job (real side effect on disk).
        $lines = array_values(array_filter(explode("\n", trim((string)@file_get_contents($results)))));
        sort($lines);
        $this->assertSame(['1', '2', '3'], $lines);
        $this->assertSame(0, $this->queue('greetings')->size('pending'));
    }

    public function testWorkOnceNacksFailingJobToDeadLetter(): void
    {
        $results = $this->work . '/results.txt';
        $this->writeConsumer('greetings', $results);

        $q = $this->queue('greetings');
        $q->push(['n' => 1]);        // good
        $q->push(['boom' => true]);  // poison

        [$out, , $exit] = $this->runCli(['queue', 'work', 'greetings', '--once', '--services', $this->svc]);
        $this->assertSame(0, $exit);

        // Good job processed; poison job burned its retries -> dead-letter.
        $this->assertSame('1', trim((string)@file_get_contents($results)));
        $this->assertSame(1, $this->queue('greetings')->size('dead'));
        $this->assertStringContainsString('Processed 1 job(s)', $out);
    }

    public function testWorkOnceWithoutHandlerWarnsAndDrains(): void
    {
        $this->queue('orphans')->push(['n' => 1]);

        [$out, , $exit] = $this->runCli(['queue', 'work', 'orphans', '--once', '--services', $this->work . '/nope']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No consumer handler found', $out);
        $this->assertStringContainsString('Processed 1 job(s)', $out);
        $this->assertSame(0, $this->queue('orphans')->size('pending'));
    }

    public function testWorkResolvesHandlerByTopicNotWrongSibling(): void
    {
        // A sibling consumer for a DIFFERENT topic must not be driven — proves
        // the real resolver matches on topic, end to end.
        $results = $this->work . '/results.txt';
        $this->writeConsumer('other', $results);   // wrong topic on disk
        $this->queue('greetings')->push(['n' => 9]);

        [$out, , $exit] = $this->runCli(['queue', 'work', 'greetings', '--once', '--services', $this->svc]);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No consumer handler found', $out); // no matching topic
        $this->assertFileDoesNotExist($results);                              // handler never ran
        $this->assertStringContainsString('Processed 1 job(s)', $out);        // still drained + acked
    }

    // ── dispatch guards ────────────────────────────────────────────────────

    public function testNoSubcommandExitsNonZero(): void
    {
        [$out, , $exit] = $this->runCli(['queue']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('work', $out);
    }

    public function testUnknownSubcommandExitsNonZero(): void
    {
        [$out, , $exit] = $this->runCli(['queue', 'frobnicate']);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unknown queue subcommand', $out);
    }
}
