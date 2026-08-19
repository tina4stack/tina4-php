<?php

/**
 * Tina4 - The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Regression: $job->retry() on a dead-lettered job must remove the dead-letter
 * file, not leave a duplicate on disk.
 *
 * PY-12-05 (3.13.105). Before the fix, LiteBackend::retryJob(topic, jobData)
 * (the path Job::retry() routes through via Queue::retryJob) requeued the job
 * to the pending directory but never unlinked the file in the failed/
 * directory. Because deadLetters() scans that directory, a manual dead-letter
 * recovery loop —
 *
 *     foreach ($queue->deadLetters() as $data) {
 *         (new Job($data, $queue, $topic))->retry();
 *     }
 *
 * left the dead-letter store carrying every "revived" job, so deadLetters()
 * reported the same items on the next call and a consumer that acted on both
 * lists processed the job twice.
 *
 * Contrast: Queue::retry($jobId) and the no-arg Queue::retry() route through
 * LiteBackend::retry($jobId) (a different method) which DID unlink correctly.
 * Two spellings of the same intent that diverged — the fix aligns them.
 *
 * NOT a mock: a real file-backed queue on disk.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Job;
use Tina4\Queue;

class QueueJobRetryRemovesDeadLetterTest extends TestCase
{
    private string $queueDir = '';
    private string $topic = '';

    protected function setUp(): void
    {
        putenv('TINA4_QUEUE_BACKEND');
        putenv('TINA4_QUEUE_PATH');
        $this->queueDir = \TempPath::dir('queue_job_retry_');
        $this->topic = 'job_retry_clean_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        putenv('TINA4_QUEUE_BACKEND');
        putenv('TINA4_QUEUE_PATH');
    }

    private function deadLetterTwoJobs(): Queue
    {
        $queue = new Queue(
            'file',
            ['path' => $this->queueDir, 'maxRetries' => 1],
            $this->topic
        );
        for ($i = 0; $i < 2; $i++) {
            $queue->push(['task' => 'doomed-' . $i]);
        }
        for ($i = 0; $i < 2; $i++) {
            $job = $queue->pop();
            $this->assertNotNull($job, 'prime: could not pop the job');
            $job->fail('boom');
        }
        $this->assertCount(2, $queue->deadLetters(), 'prime: expected two dead letters');
        return $queue;
    }

    public function testPositiveJobRetryRemovesDeadLetterFile(): void
    {
        // After iterating dead_letters() and calling ->retry() on each, the
        // failed/ directory is empty — no duplicate carrying the same id.
        $queue = $this->deadLetterTwoJobs();

        foreach ($queue->deadLetters() as $data) {
            (new Job($data, $queue, $this->topic))->retry();
        }

        $this->assertSame(
            [],
            $queue->deadLetters(),
            'dead-letter store must be empty after $job->retry() revives every '
            . 'job; a leftover file re-appears on the next deadLetters() call '
            . 'and the job is processed twice'
        );
    }

    public function testNegativeJobRetryStillPlacesJobInPending(): void
    {
        // Revival still puts every job back in pending — the unlink must not
        // accidentally drop the requeue path.
        $queue = $this->deadLetterTwoJobs();

        foreach ($queue->deadLetters() as $data) {
            (new Job($data, $queue, $this->topic))->retry();
        }

        $this->assertSame(
            2,
            $queue->size('pending'),
            'expected two pending jobs after revival, got ' . $queue->size('pending')
        );
    }
}
