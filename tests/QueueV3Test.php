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

    public function testProcessFailedJobGoesToFailed(): void
    {
        $q = $this->makeQueue('failtest');
        $q->push(['action' => 'boom']);

        $q->process(function ($job) {
            throw new \RuntimeException('Something went wrong');
        });

        $failed = $q->failed();
        $this->assertCount(1, $failed);
        $this->assertEquals('failed', $failed[0]['status']);
        $this->assertEquals('Something went wrong', $failed[0]['error']);
    }

    // ── Retry tests ─────────────────────────────────────────────

    public function testRetryMovesJobBackToPending(): void
    {
        $q = $this->makeQueue('retryq');
        $q->push(['action' => 'retry_me']);

        $q->process(function ($job) {
            throw new \RuntimeException('fail once');
        });

        $this->assertEquals(0, $q->size());
        $failed = $q->failed();
        $this->assertCount(1, $failed);

        $result = $q->retry($failed[0]['id']);
        $this->assertTrue($result);

        $this->assertEquals(1, $q->size());
        $this->assertEmpty($q->failed());
    }

    public function testRetryNonexistentJob(): void
    {
        $q = $this->makeQueue();
        $this->assertFalse($q->retry('nonexistent-id'));
    }

    public function testRetryExceedsMaxRetries(): void
    {
        $q = new Queue('file', ['path' => $this->testPath, 'maxRetries' => 1], topic: 'maxretry');
        $q->push(['x' => 1]);

        // Fail it once (attempts becomes 1)
        $q->process(function ($job) {
            throw new \RuntimeException('fail');
        });

        $failed = $q->failed();
        $this->assertCount(1, $failed);

        // Should not retry because attempts >= maxRetries
        $result = $q->retry($failed[0]['id']);
        $this->assertFalse($result);
    }

    // ── Delay tests ─────────────────────────────────────────────

    public function testDelayedJobNotImmediatelyAvailable(): void
    {
        $q = $this->makeQueue('delayed');
        $q->push(['x' => 1], 60); // 60 second delay
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
        $q = new Queue('file', ['path' => $this->testPath, 'maxRetries' => 3], topic: 'retrytest');
        $q->push(['x' => 1]);

        $q->process(function ($job) {
            throw new \RuntimeException('fail');
        });

        $count = $q->retryFailed();
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
}
