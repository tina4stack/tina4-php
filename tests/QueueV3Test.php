<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Queue;

class QueueV3Test extends TestCase
{
    private string $testPath;

    protected function setUp(): void
    {
        $this->testPath = sys_get_temp_dir() . '/tina4_queue_test_' . bin2hex(random_bytes(4));
        mkdir($this->testPath, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->testPath);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function makeQueue(string $topic = 'default'): Queue
    {
        return new Queue('file', ['path' => $this->testPath], topic: $topic);
    }

    // ── Push tests ──────────────────────────────────────────────

    public function testPushReturnsJobId(): void
    {
        $q = $this->makeQueue('emails');
        $id = $q->push(['to' => 'alice@test.com']);
        $this->assertIsString($id);
        $this->assertNotEmpty($id);
    }

    public function testPushCreatesFile(): void
    {
        $q = $this->makeQueue('emails');
        $q->push(['to' => 'bob@test.com']);
        $files = glob($this->testPath . '/emails/*.queue-data');
        $this->assertCount(1, $files);
    }

    public function testPushMultipleJobs(): void
    {
        $q = $this->makeQueue('tasks');
        $id1 = $q->push(['action' => 'a']);
        $id2 = $q->push(['action' => 'b']);
        $id3 = $q->push(['action' => 'c']);

        $this->assertNotEquals($id1, $id2);
        $this->assertNotEquals($id2, $id3);
        $this->assertEquals(3, $q->size());
    }

    public function testPushWithScalarPayload(): void
    {
        $q = $this->makeQueue('simple');
        $id = $q->push('hello world');
        $this->assertNotEmpty($id);
        $job = $q->pop();
        $this->assertEquals('hello world', $job['payload']);
    }

    // ── Pop tests ───────────────────────────────────────────────

    public function testPopReturnsJob(): void
    {
        $q = $this->makeQueue('work');
        $q->push(['task' => 'process']);
        $job = $q->pop();

        $this->assertIsArray($job);
        $this->assertEquals(['task' => 'process'], $job['payload']);
        $this->assertEquals('pending', $job['status']);
    }

    public function testPopReturnsNullOnEmpty(): void
    {
        $q = $this->makeQueue('nonexistent');
        $this->assertNull($q->pop());
    }

    public function testPopRemovesJob(): void
    {
        $q = $this->makeQueue('work');
        $q->push(['x' => 1]);
        $q->pop();
        $this->assertEquals(0, $q->size());
    }

    public function testPopOrderFIFO(): void
    {
        $q = $this->makeQueue('ordered');
        $q->push(['seq' => 1]);
        usleep(10000); // small gap for timestamp ordering
        $q->push(['seq' => 2]);
        usleep(10000);
        $q->push(['seq' => 3]);

        $first = $q->pop();
        $second = $q->pop();
        $third = $q->pop();

        $this->assertEquals(1, $first['payload']['seq']);
        $this->assertEquals(2, $second['payload']['seq']);
        $this->assertEquals(3, $third['payload']['seq']);
    }

    // ── Size tests ──────────────────────────────────────────────

    public function testSizeEmpty(): void
    {
        $q = $this->makeQueue('empty');
        $this->assertEquals(0, $q->size());
    }

    public function testSizeAfterPush(): void
    {
        $q = $this->makeQueue('count');
        $q->push(['a' => 1]);
        $q->push(['b' => 2]);
        $this->assertEquals(2, $q->size());
    }

    public function testSizeAfterPop(): void
    {
        $q = $this->makeQueue('count');
        $q->push(['a' => 1]);
        $q->push(['b' => 2]);
        $q->pop();
        $this->assertEquals(1, $q->size());
    }

    // ── Clear tests ─────────────────────────────────────────────

    public function testClear(): void
    {
        $q = $this->makeQueue('cleanup');
        $q->push(['x' => 1]);
        $q->push(['x' => 2]);
        $q->clear();
        $this->assertEquals(0, $q->size());
    }

    public function testClearNonexistent(): void
    {
        $q = $this->makeQueue('doesnotexist');
        $q->clear(); // should not throw
        $this->assertEquals(0, $q->size());
    }

    // ── Failed jobs tests ───────────────────────────────────────

    public function testFailedJobsEmpty(): void
    {
        $q = $this->makeQueue('nofails');
        $this->assertEmpty($q->failed());
    }

    public function testProcessFailingJobDeadLettersAfterRetries(): void
    {
        // maxRetries=1 → a single failure exhausts retries and the job is
        // moved straight to the dead-letter store (process() routes the
        // handler exception through the auto retry → dead-letter lifecycle).
        $q = new Queue('file', ['path' => $this->testPath, 'maxRetries' => 1], topic: 'failtest');
        $q->push(['action' => 'boom']);

        $q->process(function ($job) {
            throw new \RuntimeException('Something went wrong');
        });

        $this->assertEquals(0, $q->size());
        $dead = $q->deadLetters();
        $this->assertCount(1, $dead);
        $this->assertEquals('dead', $dead[0]['status']);
        $this->assertEquals('Something went wrong', $dead[0]['error']);
        $this->assertEquals(1, $dead[0]['attempts']);
    }

    // ── Retry tests ─────────────────────────────────────────────

    public function testRetryRevivesDeadLetterToPending(): void
    {
        // With maxRetries=1 a single failure dead-letters the job, making it
        // addressable by id. Queue::retry($id) revives it back to pending.
        $q = new Queue('file', ['path' => $this->testPath, 'maxRetries' => 1], topic: 'retryq');
        $q->push(['action' => 'retry_me']);

        $q->process(function ($job) {
            throw new \RuntimeException('fail once');
        });

        $this->assertEquals(0, $q->size());
        $dead = $q->deadLetters();
        $this->assertCount(1, $dead);

        $result = $q->retry($dead[0]['id']);
        $this->assertTrue($result);

        $this->assertEquals(1, $q->size());
        $this->assertEmpty($q->deadLetters());
    }

    public function testRetryNonexistentJob(): void
    {
        $q = $this->makeQueue();
        $this->assertFalse($q->retry('nonexistent-id'));
    }

    public function testRetryAlwaysRevivesSpecificDeadLetter(): void
    {
        // Queue::retry($jobId) is a manual override — it always revives a
        // specific dead-letter regardless of attempt count vs maxRetries.
        $q = new Queue('file', ['path' => $this->testPath, 'maxRetries' => 1], topic: 'maxretry');
        $q->push(['x' => 1]);

        // Fail it once (attempts becomes 1 >= maxRetries=1) → dead-lettered.
        $q->process(function ($job) {
            throw new \RuntimeException('fail');
        });

        $dead = $q->deadLetters();
        $this->assertCount(1, $dead);

        // Manual retry by id succeeds even though retries are exhausted.
        $result = $q->retry($dead[0]['id']);
        $this->assertTrue($result);
        $this->assertEquals(1, $q->size());
    }

    // ── Delay tests ─────────────────────────────────────────────

    public function testDelayedJobNotImmediatelyAvailable(): void
    {
        $q = $this->makeQueue('delayed');
        $q->push(['x' => 1], 0, 60); // 60 second delay (priority=0, delay=60)
        $job = $q->pop();
        $this->assertNull($job); // not yet available
        $this->assertEquals(1, $q->size()); // still counts as pending
    }

    // ── Process tests ───────────────────────────────────────────

    public function testProcessHandlesAllJobs(): void
    {
        $q = $this->makeQueue('batch');
        $q->push(['n' => 1]);
        $q->push(['n' => 2]);
        $q->push(['n' => 3]);

        $processed = [];
        $q->process(function ($job) use (&$processed) {
            $processed[] = $job['payload']['n'];
        });

        $this->assertEquals([1, 2, 3], $processed);
        $this->assertEquals(0, $q->size());
    }

    public function testProcessMaxJobs(): void
    {
        $q = $this->makeQueue('limited');
        $q->push(['n' => 1]);
        $q->push(['n' => 2]);
        $q->push(['n' => 3]);

        $processed = [];
        $q->process(function ($job) use (&$processed) {
            $processed[] = $job['payload']['n'];
        }, ['maxJobs' => 2]);

        $this->assertCount(2, $processed);
        $this->assertEquals(1, $q->size());
    }

    // ── Multiple queues ─────────────────────────────────────────

    public function testMultipleQueuesIsolated(): void
    {
        $qa = $this->makeQueue('queue_a');
        $qb = $this->makeQueue('queue_b');
        $qa->push(['from' => 'a']);
        $qb->push(['from' => 'b']);
        $qa->push(['from' => 'a2']);

        $this->assertEquals(2, $qa->size());
        $this->assertEquals(1, $qb->size());

        $jobA = $qa->pop();
        $this->assertEquals('a', $jobA['payload']['from']);

        $jobB = $qb->pop();
        $this->assertEquals('b', $jobB['payload']['from']);
    }

    // ── Backend switching / topic-based API tests ─────────────

    public function testTopicConstructor(): void
    {
        $q = new Queue('file', ['path' => $this->testPath], topic: 'emails');
        $q->push(['to' => 'alice@test.com']);
        $this->assertEquals(1, $q->size());
        $job = $q->pop();
        $this->assertIsArray($job);
        $this->assertEquals(['to' => 'alice@test.com'], $job['payload']);
    }

    public function testTopicDefaultsToDefault(): void
    {
        $q = new Queue('file', ['path' => $this->testPath]);
        $q->push(['task' => 'hello']);
        $this->assertEquals(1, $q->size());
    }

    public function testPushPopWithTopic(): void
    {
        $q = new Queue('file', ['path' => $this->testPath], topic: 'tasks');
        $q->push(['action' => 'send']);
        $q->push(['action' => 'process']);
        $this->assertEquals(2, $q->size());

        $job = $q->pop();
        $this->assertEquals('send', $job['payload']['action']);
        $this->assertEquals(1, $q->size());
    }

    public function testEnvBackendOverride(): void
    {
        // If TINA4_QUEUE_BACKEND is set but we pass 'file' explicitly,
        // the explicit argument should win
        putenv('TINA4_QUEUE_BACKEND=file');
        $q = new Queue('file', ['path' => $this->testPath], topic: 'env_test');
        $q->push(['task' => 'env']);
        $this->assertEquals(1, $q->size());
        putenv('TINA4_QUEUE_BACKEND');
    }

    public function testGetTopic(): void
    {
        $q = new Queue('file', ['path' => $this->testPath], topic: 'my_topic');
        $this->assertEquals('my_topic', $q->getTopic());
    }

    public function testTopicBasedDeadLetters(): void
    {
        $q = new Queue('file', ['path' => $this->testPath, 'maxRetries' => 1], topic: 'deadtest');
        $q->push(['x' => 1]);

        $q->process(function ($job) {
            throw new \RuntimeException('fail');
        });

        $dead = $q->deadLetters();
        $this->assertCount(1, $dead);
        $this->assertEquals('dead', $dead[0]['status']);
    }

    public function testTopicBasedRetryFailed(): void
    {
        // maxRetries=1 → the job is dead-lettered after one failure. At the
        // original limit retryFailed() revives nothing (retries exhausted);
        // a raised limit gives the dead-letter another chance.
        $q = new Queue('file', ['path' => $this->testPath, 'maxRetries' => 1], topic: 'retrytest');
        $q->push(['x' => 1]);

        $q->process(function ($job) {
            throw new \RuntimeException('fail');
        });

        $this->assertCount(1, $q->deadLetters());
        $this->assertEquals(0, $q->size());

        // At the original limit, nothing is revived.
        $this->assertEquals(0, $q->retryFailed());

        // Raise the limit → the dead-letter is re-queued to pending.
        $count = $q->retryFailed(5);
        $this->assertEquals(1, $count);
        $this->assertEquals(1, $q->size());
    }

    public function testTopicBasedPurge(): void
    {
        $q = new Queue('file', ['path' => $this->testPath, 'maxRetries' => 3], topic: 'purgetest');
        $q->push(['x' => 1]);

        $q->process(function ($job) {
            throw new \RuntimeException('fail');
        });

        $purged = $q->purge('failed');
        $this->assertEquals(1, $purged);
    }

    public function testTopicOverrideInMethods(): void
    {
        // With the topic-based API, each queue instance is bound to its constructor topic.
        // Use separate queue instances to push to different topics.
        $qDefault = new Queue('file', ['path' => $this->testPath], topic: 'default_topic');
        $qOther = new Queue('file', ['path' => $this->testPath], topic: 'other_topic');

        $qOther->push(['x' => 1]);
        $this->assertEquals(0, $qDefault->size()); // default_topic is empty
        $this->assertEquals(1, $qOther->size());   // other_topic has the job
    }

    // ── Queue isolation contract ────────────────────────────────
    // A job pushed to one topic must never leak into another, and a queue on
    // a fresh storage path must start empty. Locks in the per-topic directory
    // layout so a future backend change can't silently cross-contaminate.

    public function testTopicsAreIsolatedOnSamePath(): void
    {
        $topicA = new Queue('file', ['path' => $this->testPath], topic: 'topic_a');
        $topicB = new Queue('file', ['path' => $this->testPath], topic: 'topic_b');

        $topicA->push(['to' => 'a']);
        $topicA->push(['to' => 'a2']);

        // topic_b shares the base path but must see none of topic_a's jobs.
        $this->assertEquals(0, $topicB->size());
        $this->assertNull($topicB->pop());
        $this->assertEquals(2, $topicA->size());
    }

    public function testDrainingOneTopicLeavesTheOtherIntact(): void
    {
        $topicA = new Queue('file', ['path' => $this->testPath], topic: 'topic_a');
        $topicB = new Queue('file', ['path' => $this->testPath], topic: 'topic_b');
        $topicA->push(['to' => 'a']);
        $topicB->push(['to' => 'b']);

        $this->assertEquals('a', $topicA->pop()['payload']['to']);
        $this->assertNull($topicA->pop());

        // topic_b is untouched.
        $this->assertEquals(1, $topicB->size());
        $this->assertEquals('b', $topicB->pop()['payload']['to']);
    }

    public function testFreshStoragePathStartsEmpty(): void
    {
        $pathTwo = sys_get_temp_dir() . '/tina4_queue_iso_' . bin2hex(random_bytes(4));
        try {
            $first = new Queue('file', ['path' => $this->testPath], topic: 'jobs');
            $first->push(['n' => 1]);
            $first->push(['n' => 2]);
            $this->assertEquals(2, $first->size());

            // A second queue on a DIFFERENT base path sees zero jobs.
            $second = new Queue('file', ['path' => $pathTwo], topic: 'jobs');
            $this->assertEquals(0, $second->size());
            $this->assertNull($second->pop());
        } finally {
            $this->removeDir($pathTwo);
        }
    }

    // ── Priority-ordered pop ────────────────────────────────────

    public function testHigherPriorityPopsBeforeOlderLowerPriority(): void
    {
        // A higher-priority job pops before a lower-priority OLDER job.
        $q = $this->makeQueue('prio');
        $q->push(['task' => 'old_low'], 1);   // pushed first, low priority
        usleep(5000);
        $q->push(['task' => 'mid'], 5);
        usleep(5000);
        $q->push(['task' => 'new_high'], 10); // pushed last, highest priority

        $order = [$q->pop()['payload']['task'], $q->pop()['payload']['task'], $q->pop()['payload']['task']];
        $this->assertEquals(['new_high', 'mid', 'old_low'], $order);
    }

    public function testPriorityTieBrokenOldestFirst(): void
    {
        // Same priority → oldest (earliest created_at) first.
        $q = $this->makeQueue('prio_tie');
        $q->push(['n' => 1], 5);
        usleep(5000);
        $q->push(['n' => 2], 5);
        usleep(5000);
        $q->push(['n' => 3], 5);

        $this->assertEquals(1, $q->pop()['payload']['n']);
        $this->assertEquals(2, $q->pop()['payload']['n']);
        $this->assertEquals(3, $q->pop()['payload']['n']);
    }

    public function testPopBatchOrdersByPriority(): void
    {
        $q = $this->makeQueue('prio_batch');
        $q->push(['t' => 'low'], 0);
        $q->push(['t' => 'high'], 10);
        $q->push(['t' => 'mid'], 5);

        $jobs = $q->popBatch(3);
        $this->assertEquals(['high', 'mid', 'low'], array_map(fn($j) => $j['payload']['t'], $jobs));
    }

    public function testDelayedHighPrioritySkippedUntilAvailable(): void
    {
        // A delayed high-priority job must NOT jump ahead while still delayed;
        // an available lower-priority job pops first.
        $q = $this->makeQueue('prio_delay');
        $q->push(['t' => 'delayed_high'], 100, 3600); // priority 100, delay 1h
        $q->push(['t' => 'available_low'], 0);

        $job = $q->pop();
        $this->assertEquals('available_low', $job['payload']['t']);
        // The delayed high-priority job is still not available.
        $this->assertNull($q->pop());
    }

    // ── Automatic retry → dead-letter lifecycle ─────────────────

    public function testFailRequeuesWhileRetriesRemain(): void
    {
        // Default maxRetries=3: a single failure (attempts=1 < 3) is
        // automatically re-enqueued to pending — NOT moved to dead-letter.
        $q = $this->makeQueue('requeue');
        $q->push(['task' => 'broken']);

        foreach ($q->consume('requeue', null, 0, 1) as $job) {
            $job->fail('something went wrong');
        }

        // Not dead yet — failed/ directory has no entries.
        $this->assertEmpty($q->deadLetters());
        // The job is back in pending with attempts incremented + error carried.
        $this->assertEquals(1, $q->size('pending'));
        $requeued = $q->pop();
        $this->assertEquals(1, $requeued['attempts']);
        $this->assertEquals('something went wrong', $requeued['error']);
    }

    public function testFailThenConsumeRetriesThenDeadLetters(): void
    {
        // A job that fails on every attempt is retried exactly maxRetries
        // times via a plain consume loop, then lands in deadLetters() —
        // with NO manual retryFailed() call.
        $q = new Queue('file', ['path' => $this->testPath, 'maxRetries' => 3], topic: 'auto_dl');
        $q->push(['task' => 'always_fails']);

        $attempts = 0;
        foreach ($q->consume('auto_dl', null, 0) as $job) {
            $attempts++;
            $job->fail("boom {$attempts}");
        }

        $this->assertEquals(3, $attempts); // executed maxRetries times total
        $this->assertEquals(0, $q->size('pending'));

        $dead = $q->deadLetters();
        $this->assertCount(1, $dead);
        $this->assertEquals(3, $dead[0]['attempts']);
        $this->assertEquals('always_fails', $dead[0]['payload']['task']);
        $this->assertEquals('boom 3', $dead[0]['error']);
    }

    public function testSuccessOnSecondAttemptNotDeadLettered(): void
    {
        // Job fails the first time (re-enqueued), succeeds the second time —
        // it must NOT end up dead-lettered.
        $q = new Queue('file', ['path' => $this->testPath, 'maxRetries' => 3], topic: 'eventual');
        $q->push(['task' => 'flaky']);

        $seen = 0;
        foreach ($q->consume('eventual', null, 0) as $job) {
            $seen++;
            if ($seen === 1) {
                $job->fail('first attempt failed');
            } else {
                $job->complete(); // succeeds on the 2nd attempt
            }
        }

        $this->assertEquals(2, $seen);
        $this->assertEquals(0, $q->size('pending'));
        $this->assertEmpty($q->deadLetters());
        $this->assertEmpty($q->failed());
    }

    public function testFailedListsRetryingJobs(): void
    {
        // attempts=1, maxRetries=3 → still retryable → shows in failed(),
        // and NOT yet in deadLetters().
        $q = new Queue('file', ['path' => $this->testPath, 'maxRetries' => 3], topic: 'ftopic');
        $q->push(['task' => 'will_fail']);

        foreach ($q->consume('ftopic', null, 0, 1) as $job) {
            $job->fail('first error');
        }

        $failed = $q->failed();
        $this->assertCount(1, $failed);
        $this->assertEquals('will_fail', $failed[0]['payload']['task']);
        $this->assertEmpty($q->deadLetters());
    }

    public function testRetryBackoffDelaysReEnqueue(): void
    {
        // retryBackoff schedules the automatic re-enqueue into the future, so
        // the job is not immediately re-poppable but still counts as pending.
        $q = new Queue('file', ['path' => $this->testPath, 'maxRetries' => 3, 'retryBackoff' => 3600], topic: 'backoff');
        $q->push(['task' => 'slow_retry']);

        $job = $q->pop();
        $this->assertNotNull($job);
        (new \Tina4\Job($job, $q, 'backoff'))->fail('retry later');

        // Still pending (counts in size) but delayed → pop() returns null.
        $this->assertEquals(1, $q->size('pending'));
        $this->assertNull($q->pop());
    }

    public function testJobRetryIsManualOverride(): void
    {
        // Job::retry() always re-queues regardless of the retry limit and
        // increments attempts, distinct from the automatic fail() path.
        $q = new Queue('file', ['path' => $this->testPath, 'maxRetries' => 1], topic: 'manual');
        $q->push(['task' => 'retry_me']);

        $data = $q->pop();
        $wrapped = new \Tina4\Job($data, $q, 'manual');
        $wrapped->retry();

        $this->assertEquals(1, $q->size('pending'));
        $retried = $q->pop();
        $this->assertEquals(1, $retried['attempts']);
        // Manual retry does not dead-letter even at maxRetries=1.
        $this->assertEmpty($q->deadLetters());
    }

    // ── Job::$topic visibility (bug fix) ────────────────────────

    public function testJobTopicIsPubliclyReadable(): void
    {
        $q = $this->makeQueue('topictest');
        $q->push(['task' => 'x']);

        foreach ($q->consume('topictest', null, 0, 1) as $job) {
            // The docs use $job->topic — it must resolve without a fatal.
            $this->assertEquals('topictest', $job->topic);
            $this->assertSame('topictest', $job->toHash()['topic']);
            $job->complete();
        }
    }

    // ── Reservation / visibility timeout (production bug regression) ──
    //
    // Regression lock for the production bug where a consumer that dies before
    // job.complete() left the message stuck forever — never re-delivered, never
    // retried, never dead-lettered. A popped job is now reserved for
    // visibilityTimeout seconds; if it is not acked in time the next pop()
    // reclaims it (incrementing attempts) or dead-letters it past maxRetries.

    private function makeQueueVt(string $topic, float $visibilityTimeout, int $maxRetries = 3): Queue
    {
        return new Queue('file', [
            'path' => $this->testPath,
            'maxRetries' => $maxRetries,
            'visibilityTimeout' => $visibilityTimeout,
        ], topic: $topic);
    }

    public function testReservedJobReclaimedAfterTimeout(): void
    {
        $q = $this->makeQueueVt('vt', 0.05);

        $q->push(['job' => 'import']);
        $job = $q->pop();                       // consumer A reserves it
        $this->assertNotNull($job);
        $this->assertEquals(0, $job['attempts']);
        $this->assertEquals(0, $q->size('pending'));   // claimed out of pending
        $this->assertEquals(1, $q->size('reserved'));  // held as a reservation

        // Consumer A "dies" — never calls complete()/fail(). After the window
        // expires the next pop() reclaims the abandoned reservation.
        usleep(120_000);
        $reclaimed = $q->pop();
        $this->assertNotNull($reclaimed);
        $this->assertEquals('import', $reclaimed['payload']['job']);
        $this->assertEquals(1, $reclaimed['attempts']);  // reclaim counted as one attempt
    }

    public function testReservedJobNotReclaimedBeforeTimeout(): void
    {
        $q = $this->makeQueueVt('vt', 30);

        $q->push(['job' => 'import']);
        $this->assertNotNull($q->pop());        // reserved
        // The reservation is still valid — a second consumer must NOT get it.
        $this->assertNull($q->pop());
        $this->assertEquals(1, $q->size('reserved'));
    }

    public function testReclaimDeadLettersAfterMaxRetries(): void
    {
        $q = $this->makeQueueVt('vt', 0.05, maxRetries: 1);

        $q->push(['job' => 'import']);
        $this->assertNotNull($q->pop());        // reserve (attempts 0)
        usleep(120_000);
        // Reclaim bumps attempts to 1 (>= maxRetries) -> dead-letter, not re-served.
        $this->assertNull($q->pop());
        $this->assertEquals(0, $q->size('reserved'));
        $dead = $q->deadLetters();
        $this->assertCount(1, $dead);
        $this->assertEquals('import', $dead[0]['payload']['job']);
    }

    public function testCompleteRemovesReservationNoReclaim(): void
    {
        $q = $this->makeQueueVt('vt', 0.05);

        $q->push(['job' => 'import']);
        $job = $q->pop();
        (new \Tina4\Job($job, $q, 'vt'))->complete();   // acked — reservation cleared
        $this->assertEquals(0, $q->size('reserved'));
        usleep(120_000);
        $this->assertNull($q->pop());           // nothing to reclaim
    }

    public function testFailRemovesReservation(): void
    {
        $q = $this->makeQueueVt('vt', 0.05, maxRetries: 3);

        $q->push(['job' => 'import']);
        $job = $q->pop();
        (new \Tina4\Job($job, $q, 'vt'))->fail('boom');  // acked with failure -> requeued
        $this->assertEquals(0, $q->size('reserved'));    // reservation cleared by fail()
        // The requeued job is pending again with attempts incremented.
        $retried = $q->pop();
        $this->assertNotNull($retried);
        $this->assertEquals(1, $retried['attempts']);
    }

    public function testDefaultVisibilityTimeoutIs300(): void
    {
        putenv('TINA4_QUEUE_VISIBILITY_TIMEOUT');
        $q = new Queue('file', ['path' => $this->testPath], topic: 'vt');
        $this->assertEquals(300.0, $q->getVisibilityTimeout());
    }

    public function testVisibilityTimeoutEnvOverride(): void
    {
        putenv('TINA4_QUEUE_VISIBILITY_TIMEOUT=42');
        try {
            $q = new Queue('file', ['path' => $this->testPath], topic: 'vt');
            $this->assertEquals(42.0, $q->getVisibilityTimeout());
        } finally {
            putenv('TINA4_QUEUE_VISIBILITY_TIMEOUT');
        }
    }

    public function testZeroVisibilityTimeoutDisablesReclaim(): void
    {
        $q = $this->makeQueueVt('vt', 0);

        $q->push(['job' => 'import']);
        $this->assertNotNull($q->pop());
        usleep(50_000);
        // Reclaim is disabled — the reservation stays put (opt-out / old behaviour).
        $this->assertNull($q->pop());
        $this->assertEquals(1, $q->size('reserved'));
    }

    // ── Bug A: consume()/process()/produce() honour the topic argument ──
    //
    // Regression lock for the production bug (Python commit 04f9f05) where
    // consume(topic)/process(topic) ignored their argument and drained the
    // construction-time topic instead — so consuming a topic the queue was not
    // built with silently yielded nothing. The asymmetry tell was that
    // produce(topic) DID retarget. These run on the file backend but assert a
    // framework-wide contract. (PHP was already correct because consume/process
    // pass the topic straight through to the backend; this locks it in.)

    public function testConsumeReadsFromTheRequestedTopicNotTheConstructorTopic(): void
    {
        // Built with 'default', but jobs live on 'alerts'.
        $q = $this->makeQueueVt('default', 0);
        $q->produce('alerts', ['msg' => 'fire']);

        // The constructor topic is empty — the asymmetry tell.
        $this->assertEquals(0, $q->size()); // size() uses the constructor topic 'default'

        $seen = [];
        foreach ($q->consume('alerts', null, 0) as $job) {
            $seen[] = $job->payload['msg'];
            $job->complete();
        }

        // consume('alerts') drained the 'alerts' jobs, not 'default' (which had none).
        $this->assertEquals(['fire'], $seen);
    }

    public function testConsumeCompleteAcksTheRequestedTopic(): void
    {
        // complete() must route the ack/reservation-clear to the SAME topic the
        // job was consumed from — otherwise a finished job is re-delivered by the
        // reclaim path on the wrong topic. Use a live visibility timeout so a
        // stranded reservation would be observable as a 'reserved' record.
        $q = $this->makeQueueVt('default', 300);
        $q->produce('alerts', ['msg' => 'ack-me']);

        foreach ($q->consume('alerts', null, 0, 1) as $job) {
            $job->complete();
        }

        // The reservation on 'alerts' was cleared (ack hit the right topic);
        // the constructor topic 'default' was never touched.
        $this->assertEquals(0, $q->size('reserved'));
        $alertsReserved = glob($this->testPath . '/alerts/reserved/*.queue-data');
        $this->assertCount(0, $alertsReserved);
    }

    public function testConsumeFailRoutesRetryToTheRequestedTopic(): void
    {
        // fail() must re-enqueue onto the topic the job was consumed from, not
        // the constructor topic.
        $q = $this->makeQueueVt('default', 0);
        $q->produce('alerts', ['msg' => 'retry-me']);

        foreach ($q->consume('alerts', null, 0, 1) as $job) {
            $job->fail('boom');
        }

        // The retry landed back on 'alerts' (attempts incremented), and the
        // constructor topic 'default' is still empty.
        $this->assertEquals(0, $q->size()); // 'default'
        $alertsQueue = new Queue('file', ['path' => $this->testPath, 'visibilityTimeout' => 0], topic: 'alerts');
        $this->assertEquals(1, $alertsQueue->size('pending'));
        $requeued = $alertsQueue->pop();
        $this->assertEquals(1, $requeued['attempts']);
        $this->assertEquals('boom', $requeued['error']);
    }

    public function testProcessReadsFromTheRequestedTopicNotTheConstructorTopic(): void
    {
        $q = $this->makeQueueVt('default', 0);
        $q->produce('alerts', ['msg' => 'processed']);

        $handled = [];
        $q->process(function ($job) use (&$handled) {
            $handled[] = $job['payload']['msg'];
        }, 'alerts');

        $this->assertEquals(['processed'], $handled);
        // The 'alerts' topic was drained; 'default' was never touched.
        $this->assertEquals(0, $q->size());
    }

    public function testProduceRetargetsTheNamedTopic(): void
    {
        // produce(topic) was always correct — lock it in alongside the consume
        // fix so the symmetry can't regress.
        $q = $this->makeQueueVt('default', 0);
        $q->produce('alerts', ['msg' => 'one']);
        $q->produce('reports', ['msg' => 'two']);

        $this->assertEquals(0, $q->size()); // 'default'
        $alerts = new Queue('file', ['path' => $this->testPath, 'visibilityTimeout' => 0], topic: 'alerts');
        $reports = new Queue('file', ['path' => $this->testPath, 'visibilityTimeout' => 0], topic: 'reports');
        $this->assertEquals(1, $alerts->size('pending'));
        $this->assertEquals(1, $reports->size('pending'));
        $this->assertEquals('one', $alerts->pop()['payload']['msg']);
        $this->assertEquals('two', $reports->pop()['payload']['msg']);
    }

    /**
     * Unified lifecycle (3.13.43): when an external backend is active, the Queue
     * routes the FULL lifecycle (complete/fail/failed/deadLetters/retryFailed) to
     * it - not the lite backend. Previously failJob/retryJob returned early for
     * external backends and the inspection verbs read lite, so a Mongo job's
     * fail/dead-letter/inspection silently hit the wrong store. Engine-agnostic:
     * injects an in-memory stub backend via reflection (no Mongo driver needed).
     */
    public function testQueueRoutesFullLifecycleToExternalBackend(): void
    {
        $stub = new class implements \Tina4\Queue\QueueBackend {
            public array $calls = [];
            public function enqueue(string $t, array $m): string { $this->calls[] = ['enqueue', $m]; return (string)($m['id'] ?? 'x'); }
            public function dequeue(string $t): ?array { return null; }
            public function acknowledge(string $t, string $id): void { $this->calls[] = ['acknowledge', $id]; }
            public function requeue(string $t, array $m): void { $this->calls[] = ['requeue', $m]; }
            public function deadLetter(string $t, array $m): void { $this->calls[] = ['deadLetter', $m]; }
            public function size(string $t): int { return 0; }
            public function close(): void {}
            public function failed(string $t): array { $this->calls[] = ['failed']; return ['F']; }
            public function deadLetters(string $t, ?int $mr = null): array { $this->calls[] = ['deadLetters']; return ['D']; }
            public function retryFailed(string $t, ?int $mr = null): int { $this->calls[] = ['retryFailed']; return 7; }
        };

        $q = new Queue('file', ['maxRetries' => 3], 'jobs');
        $ref = new \ReflectionProperty(Queue::class, 'externalBackend');
        $ref->setAccessible(true);
        $ref->setValue($q, $stub);

        $q->completeJob('jobs', 'j1');                                  // -> acknowledge
        $q->failJob('jobs', ['id' => 'j2', 'attempts' => 0], 'boom');  // under max -> requeue
        $q->failJob('jobs', ['id' => 'j3', 'attempts' => 2], 'boom');  // attempts 3 >= 3 -> deadLetter + ack
        $this->assertSame(['F'], $q->failed());
        $this->assertSame(['D'], $q->deadLetters());
        $this->assertSame(7, $q->retryFailed());

        $names = array_map(fn ($c) => $c[0], $stub->calls);
        $this->assertContains('acknowledge', $names, 'complete() must ack the external backend');
        $this->assertContains('requeue', $names, 'fail() under maxRetries must requeue on the external backend');
        $this->assertContains('deadLetter', $names, 'fail() at maxRetries must dead-letter on the external backend');
        $this->assertContains('failed', $names);
        $this->assertContains('deadLetters', $names);
        $this->assertContains('retryFailed', $names);
        $dl = array_values(array_filter($stub->calls, fn ($c) => $c[0] === 'deadLetter'))[0][1];
        $this->assertSame('j3', $dl['id']);
        $this->assertSame(3, $dl['attempts']);
    }
}
