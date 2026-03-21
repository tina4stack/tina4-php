<?php

/**
 * Tina4 - This is not a 4ramework.
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

    private function makeQueue(): Queue
    {
        return new Queue('file', ['path' => $this->testPath]);
    }

    // ── Push tests ──────────────────────────────────────────────

    public function testPushReturnsJobId(): void
    {
        $q = $this->makeQueue();
        $id = $q->push('emails', ['to' => 'alice@test.com']);
        $this->assertIsString($id);
        $this->assertNotEmpty($id);
    }

    public function testPushCreatesFile(): void
    {
        $q = $this->makeQueue();
        $q->push('emails', ['to' => 'bob@test.com']);
        $files = glob($this->testPath . '/emails/*.json');
        $this->assertCount(1, $files);
    }

    public function testPushMultipleJobs(): void
    {
        $q = $this->makeQueue();
        $id1 = $q->push('tasks', ['action' => 'a']);
        $id2 = $q->push('tasks', ['action' => 'b']);
        $id3 = $q->push('tasks', ['action' => 'c']);

        $this->assertNotEquals($id1, $id2);
        $this->assertNotEquals($id2, $id3);
        $this->assertEquals(3, $q->size('tasks'));
    }

    public function testPushWithScalarPayload(): void
    {
        $q = $this->makeQueue();
        $id = $q->push('simple', 'hello world');
        $this->assertNotEmpty($id);
        $job = $q->pop('simple');
        $this->assertEquals('hello world', $job['payload']);
    }

    // ── Pop tests ───────────────────────────────────────────────

    public function testPopReturnsJob(): void
    {
        $q = $this->makeQueue();
        $q->push('work', ['task' => 'process']);
        $job = $q->pop('work');

        $this->assertIsArray($job);
        $this->assertEquals(['task' => 'process'], $job['payload']);
        $this->assertEquals('pending', $job['status']);
    }

    public function testPopReturnsNullOnEmpty(): void
    {
        $q = $this->makeQueue();
        $this->assertNull($q->pop('nonexistent'));
    }

    public function testPopRemovesJob(): void
    {
        $q = $this->makeQueue();
        $q->push('work', ['x' => 1]);
        $q->pop('work');
        $this->assertEquals(0, $q->size('work'));
    }

    public function testPopOrderFIFO(): void
    {
        $q = $this->makeQueue();
        $q->push('ordered', ['seq' => 1]);
        usleep(10000); // small gap for timestamp ordering
        $q->push('ordered', ['seq' => 2]);
        usleep(10000);
        $q->push('ordered', ['seq' => 3]);

        $first = $q->pop('ordered');
        $second = $q->pop('ordered');
        $third = $q->pop('ordered');

        $this->assertEquals(1, $first['payload']['seq']);
        $this->assertEquals(2, $second['payload']['seq']);
        $this->assertEquals(3, $third['payload']['seq']);
    }

    // ── Size tests ──────────────────────────────────────────────

    public function testSizeEmpty(): void
    {
        $q = $this->makeQueue();
        $this->assertEquals(0, $q->size('empty'));
    }

    public function testSizeAfterPush(): void
    {
        $q = $this->makeQueue();
        $q->push('count', ['a' => 1]);
        $q->push('count', ['b' => 2]);
        $this->assertEquals(2, $q->size('count'));
    }

    public function testSizeAfterPop(): void
    {
        $q = $this->makeQueue();
        $q->push('count', ['a' => 1]);
        $q->push('count', ['b' => 2]);
        $q->pop('count');
        $this->assertEquals(1, $q->size('count'));
    }

    // ── Clear tests ─────────────────────────────────────────────

    public function testClear(): void
    {
        $q = $this->makeQueue();
        $q->push('cleanup', ['x' => 1]);
        $q->push('cleanup', ['x' => 2]);
        $q->clear('cleanup');
        $this->assertEquals(0, $q->size('cleanup'));
    }

    public function testClearNonexistent(): void
    {
        $q = $this->makeQueue();
        $q->clear('doesnotexist'); // should not throw
        $this->assertEquals(0, $q->size('doesnotexist'));
    }

    // ── Failed jobs tests ───────────────────────────────────────

    public function testFailedJobsEmpty(): void
    {
        $q = $this->makeQueue();
        $this->assertEmpty($q->failed('nofails'));
    }

    public function testProcessFailedJobGoesToFailed(): void
    {
        $q = $this->makeQueue();
        $q->push('failtest', ['action' => 'boom']);

        $q->process('failtest', function ($job) {
            throw new \RuntimeException('Something went wrong');
        });

        $failed = $q->failed('failtest');
        $this->assertCount(1, $failed);
        $this->assertEquals('failed', $failed[0]['status']);
        $this->assertEquals('Something went wrong', $failed[0]['error']);
    }

    // ── Retry tests ─────────────────────────────────────────────

    public function testRetryMovesJobBackToPending(): void
    {
        $q = $this->makeQueue();
        $q->push('retryq', ['action' => 'retry_me']);

        $q->process('retryq', function ($job) {
            throw new \RuntimeException('fail once');
        });

        $this->assertEquals(0, $q->size('retryq'));
        $failed = $q->failed('retryq');
        $this->assertCount(1, $failed);

        $result = $q->retry($failed[0]['id']);
        $this->assertTrue($result);

        $this->assertEquals(1, $q->size('retryq'));
        $this->assertEmpty($q->failed('retryq'));
    }

    public function testRetryNonexistentJob(): void
    {
        $q = $this->makeQueue();
        $this->assertFalse($q->retry('nonexistent-id'));
    }

    public function testRetryExceedsMaxRetries(): void
    {
        $q = new Queue('file', ['path' => $this->testPath, 'maxRetries' => 1]);
        $q->push('maxretry', ['x' => 1]);

        // Fail it once (attempts becomes 1)
        $q->process('maxretry', function ($job) {
            throw new \RuntimeException('fail');
        });

        $failed = $q->failed('maxretry');
        $this->assertCount(1, $failed);

        // Should not retry because attempts >= maxRetries
        $result = $q->retry($failed[0]['id']);
        $this->assertFalse($result);
    }

    // ── Delay tests ─────────────────────────────────────────────

    public function testDelayedJobNotImmediatelyAvailable(): void
    {
        $q = $this->makeQueue();
        $q->push('delayed', ['x' => 1], 60); // 60 second delay
        $job = $q->pop('delayed');
        $this->assertNull($job); // not yet available
        $this->assertEquals(1, $q->size('delayed')); // still counts as pending
    }

    // ── Process tests ───────────────────────────────────────────

    public function testProcessHandlesAllJobs(): void
    {
        $q = $this->makeQueue();
        $q->push('batch', ['n' => 1]);
        $q->push('batch', ['n' => 2]);
        $q->push('batch', ['n' => 3]);

        $processed = [];
        $q->process('batch', function ($job) use (&$processed) {
            $processed[] = $job['payload']['n'];
        });

        $this->assertEquals([1, 2, 3], $processed);
        $this->assertEquals(0, $q->size('batch'));
    }

    public function testProcessMaxJobs(): void
    {
        $q = $this->makeQueue();
        $q->push('limited', ['n' => 1]);
        $q->push('limited', ['n' => 2]);
        $q->push('limited', ['n' => 3]);

        $processed = [];
        $q->process('limited', function ($job) use (&$processed) {
            $processed[] = $job['payload']['n'];
        }, ['maxJobs' => 2]);

        $this->assertCount(2, $processed);
        $this->assertEquals(1, $q->size('limited'));
    }

    // ── Multiple queues ─────────────────────────────────────────

    public function testMultipleQueuesIsolated(): void
    {
        $q = $this->makeQueue();
        $q->push('queue_a', ['from' => 'a']);
        $q->push('queue_b', ['from' => 'b']);

        $this->assertEquals(1, $q->size('queue_a'));
        $this->assertEquals(1, $q->size('queue_b'));

        $jobA = $q->pop('queue_a');
        $this->assertEquals('a', $jobA['payload']['from']);

        $jobB = $q->pop('queue_b');
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
        // You can still pass explicit queue names to methods (legacy compat)
        $q = new Queue('file', ['path' => $this->testPath], topic: 'default_topic');
        $q->push(['x' => 1], 'other_topic');
        $this->assertEquals(0, $q->size()); // default_topic is empty
        $this->assertEquals(1, $q->size('other_topic')); // other_topic has the job
    }
}
