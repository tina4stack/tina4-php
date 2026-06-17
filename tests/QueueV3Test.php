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
}
