<?php

/**
 * Tina4 - The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Regression: Queue::retry() with no args must revive EVERY dead letter.
 *
 * PY-12-04 (3.13.105). The Python master's no-arg branch used
 * ``any(self._backend.retry_job(j.id) for j in dead)``, so as soon as the FIRST
 * dead-letter revived (retry_job returning truthy) Python's short-circuiting
 * any() stopped iterating — the second and third jobs were never re-queued and
 * stayed silently in the dead-letter store. PHP's foreach never short-circuits
 * on a plain OR, but the parity contract is that a caller who calls retry()
 * with no args gets ALL dead letters revived, so PHP grows the matching lock-in
 * test alongside the fix in the other frameworks.
 *
 * The invariant: with N dead letters, retry() moves ALL N to pending. Named
 * positive AND negative cases below.
 *
 * NOT a mock: a real file-backed queue on disk, real dead-letters written to
 * disk, real pop/fail lifecycle.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Queue;

class QueueRetryReviveEveryDeadLetterTest extends TestCase
{
    private string $queueDir = '';

    protected function setUp(): void
    {
        // The env var overrides the constructor argument and would silently
        // pin us to whatever backend the outer environment names; strip it.
        putenv('TINA4_QUEUE_BACKEND');
        putenv('TINA4_QUEUE_PATH');
        $this->queueDir = \TempPath::dir('queue_retry_all_');
    }

    protected function tearDown(): void
    {
        putenv('TINA4_QUEUE_BACKEND');
        putenv('TINA4_QUEUE_PATH');
    }

    /**
     * Push three, dead-letter each (maxRetries=1 -> 1 attempt = dead), assert
     * the store carries three. Returns the primed queue.
     */
    private function deadLetterThreeJobs(): Queue
    {
        $queue = new Queue(
            'file',
            ['path' => $this->queueDir, 'maxRetries' => 1],
            'revive_all_' . bin2hex(random_bytes(4))
        );
        for ($i = 0; $i < 3; $i++) {
            $queue->push(['task' => 'doomed-' . $i]);
        }
        for ($i = 0; $i < 3; $i++) {
            $job = $queue->pop();
            $this->assertNotNull($job, 'prime: could not pop the job');
            $job->fail('err');
        }
        $this->assertCount(3, $queue->deadLetters(), 'prime: three dead letters expected');
        $this->assertSame(0, $queue->size('pending'));
        return $queue;
    }

    public function testPositiveRetryNoArgRevivesAllThree(): void
    {
        // With three dead letters, retry() must revive all three, not just the
        // first (the short-circuit-any() footgun the parity contract forbids).
        $queue = $this->deadLetterThreeJobs();

        $ok = $queue->retry();

        $this->assertTrue($ok, 'at least one dead letter should have been revived');
        $this->assertSame(
            3,
            $queue->size('pending'),
            'expected all three dead letters revived to pending, got '
            . $queue->size('pending')
        );
    }

    public function testNegativeRetryNoArgLeavesDeadStoreEmpty(): void
    {
        // After a successful retry() no dead letter must remain on disk — the
        // retry path must fully consume the dead-letter store, not leave two
        // behind for a future accidental re-run.
        $queue = $this->deadLetterThreeJobs();

        $queue->retry();

        $this->assertSame(
            [],
            $queue->deadLetters(),
            'no dead letter must remain after retry() revives all three; '
            . 'stale entries lead to double-processing on a later retry()'
        );
    }
}
