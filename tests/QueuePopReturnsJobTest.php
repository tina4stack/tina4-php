<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Queue::pop() returns a Job - and every existing array caller keeps working.
 *
 * MEASURED 2026-08-04: Tina4\Queue::pop(), popBatch() and popById() returned
 * the backend's RAW ARRAY, while Python, Ruby and Node all return a job object
 * carrying complete()/fail()/reject()/retry(). So this worked in three
 * frameworks and was a fatal in the fourth:
 *
 *     $job = $queue->pop();
 *     $job->fail("boom");        // PHP: Error, pop() gave you an array
 *
 * PHP already HAD the class - Tina4\Job, with the full lifecycle - and
 * consume() already yielded `new Job(...)`. Only the pop family handed back the
 * raw record, so the lifecycle was reachable from consume() and unreachable
 * from pop(). ADR-0024: the same concept must be the same shape in all four.
 * The shipped example even carried the workaround
 * (`new \Tina4\Job($queue->pop(), $queue, $topic)`), which is how you know
 * users hit it.
 *
 * THE HARD PART IS NOT THE RETURN TYPE, IT IS NOT BREAKING ANYONE. `$job['id']`
 * appears throughout this repo's own tests and in user code. So Job implements
 * ArrayAccess (reads only) and JsonSerializable, and its constructor accepts an
 * existing Job so the hand-rolled re-wrap above still works. This file pins
 * every one of those guarantees, because a "non-breaking" claim nobody tests is
 * just a hope:
 *
 *   POSITIVE  the lifecycle actually reaches the backend from a popped job
 *   POSITIVE  $job['id'] and $job->id resolve to the SAME value
 *   POSITIVE  json_encode($job) still produces the job shape
 *   POSITIVE  a field Job does not model (created_at) still reads through
 *   POSITIVE  popBatch()/popById() return Jobs too, not a mixed bag
 *   NEGATIVE  $job['x'] = 1 THROWS rather than silently mutating a job
 *   NEGATIVE  an empty queue still returns null, not an empty Job
 *
 * NO MOCKS: every case drives the real file backend against a real temp
 * directory. No service is required, so this suite can never be silently
 * skipped.
 */
final class QueuePopReturnsJobTest extends TestCase
{
    private string $queuePath = '';

    protected function setUp(): void
    {
        // TINA4_QUEUE_BACKEND OVERRIDES the constructor argument, so it must be
        // cleared or every case silently runs on whatever the environment says.
        putenv('TINA4_QUEUE_BACKEND');
        $this->queuePath = \TempPath::dir('tina4_popjob_');
    }

    private function makeQueue(int $maxRetries = 2): \Tina4\Queue
    {
        return new \Tina4\Queue(
            'file',
            ['path' => $this->queuePath, 'maxRetries' => $maxRetries],
            'popjob_' . bin2hex(random_bytes(6))
        );
    }

    /**
     * POSITIVE: fail() on a popped job reaches the backend, not just the object.
     *
     * The assertion is deliberately on the QUEUE's state afterwards, not on the
     * job's own fields: a fail() that only set $this->status would pass an
     * object-level check while the queue never heard about it.
     */
    public function testFailOnAPoppedJobReachesTheBackend(): void
    {
        $queue = $this->makeQueue(maxRetries: 2);
        $queue->push(['m' => 'transient']);

        $job = $queue->pop();
        self::assertNotNull($job, 'nothing to pop');
        $job->fail('boom');

        self::assertCount(
            1,
            $queue->failed(),
            'fail() on a popped job must be recorded by the backend, not only on the object'
        );
        self::assertNotNull(
            $queue->pop(),
            'a job that failed with retries left must come back for another attempt'
        );
    }

    /**
     * POSITIVE: complete() on a popped job reaches the backend.
     *
     * Checked through the reservation count, because a complete() that only set
     * the in-memory status leaves the reservation behind - and the reclaim timer
     * then re-delivers a job that was actually finished.
     */
    public function testCompleteOnAPoppedJobReachesTheBackend(): void
    {
        $queue = $this->makeQueue();
        $queue->push(['m' => 'work']);

        $job = $queue->pop();
        self::assertNotNull($job, 'nothing to pop');
        self::assertSame(1, $queue->size('reserved'), 'a popped job must be reserved');

        $job->complete();

        self::assertSame(0, $queue->size('reserved'), 'complete() must clear the reservation');
        self::assertSame(0, $queue->size('pending'), 'a completed job must not be pending again');
    }

    /**
     * POSITIVE, and this is the whole non-breaking guarantee: array reads that
     * worked against the raw record still resolve, and agree with the object.
     */
    public function testArrayAccessAndPropertyAccessAgree(): void
    {
        $queue = $this->makeQueue();
        $id = $queue->push(['to' => 'alice@test.com', 'subject' => 'Hello']);

        $job = $queue->pop();
        self::assertNotNull($job, 'nothing to pop');

        self::assertSame($id, $job['id'], '$job[\'id\'] must still resolve');
        self::assertSame($job->id, $job['id'], '$job->id and $job[\'id\'] must be the same value');
        self::assertSame($job->payload, $job['payload']);
        self::assertSame('alice@test.com', $job['payload']['to'], 'a nested array read must still work');
        self::assertSame($job->status, $job['status']);
        self::assertSame($job->attempts, $job['attempts']);
        self::assertSame($job->topic, $job['topic']);
        self::assertSame($job->priority, $job['priority']);
        self::assertSame($job->error, $job['error']);

        self::assertTrue(isset($job['id']), 'isset() must see a field that exists');
        self::assertFalse(isset($job['no_such_field']), 'isset() must not invent a field');
        self::assertNull($job['no_such_field'], 'an unknown key reads as null, as it did on the array');
    }

    /**
     * POSITIVE: a field the Job class does not model still reads through.
     *
     * created_at is written by the backend and has no property on Job. Dropping
     * it would break a caller silently - the read would return null instead of
     * a timestamp, with nothing to notice.
     */
    public function testAFieldJobDoesNotModelStillReadsThrough(): void
    {
        $queue = $this->makeQueue();
        $queue->push(['m' => 'x']);

        $job = $queue->pop();
        self::assertNotNull($job, 'nothing to pop');
        self::assertNotNull($job['created_at'], 'created_at is stored by the backend and must still read');
    }

    /**
     * POSITIVE: a read after fail() sees the NEW attempt count.
     *
     * The canonical field has to win over the stored record, or `$job['attempts']`
     * would keep reporting the value the backend wrote when the job was claimed.
     */
    public function testArrayReadReflectsTheLifecycleNotTheStaleRecord(): void
    {
        $queue = $this->makeQueue(maxRetries: 3);
        $queue->push(['m' => 'x']);

        $job = $queue->pop();
        self::assertNotNull($job, 'nothing to pop');
        self::assertSame(0, $job['attempts'], 'a freshly popped job has no attempts yet');

        $job->fail('boom');

        self::assertSame(1, $job['attempts'], '$job[\'attempts\'] must reflect the fail() that just happened');
        self::assertSame('boom', $job['error']);
        self::assertSame('failed', $job['status']);
    }

    /**
     * POSITIVE: json_encode() still produces the job shape.
     */
    public function testJsonEncodeStillProducesTheJobShape(): void
    {
        $queue = $this->makeQueue();
        $id = $queue->push(['m' => 'shape']);

        $job = $queue->pop();
        self::assertNotNull($job, 'nothing to pop');

        $decoded = json_decode(json_encode($job), true);
        self::assertIsArray($decoded, 'json_encode(job) must not produce a scalar or null');
        self::assertSame($id, $decoded['id']);
        self::assertSame(['m' => 'shape'], $decoded['payload']);
        foreach (['id', 'topic', 'payload', 'priority', 'status', 'attempts', 'error'] as $field) {
            self::assertArrayHasKey($field, $decoded, "json_encode(job) must carry '{$field}'");
        }
        self::assertSame($decoded, json_decode($job->toJson(), true), 'json_encode and toJson must agree');
    }

    /**
     * POSITIVE: the rest of the pop family returns Jobs too.
     *
     * Half-converting would be worse than not converting: a caller could not
     * tell which method gave them a lifecycle and which gave them an array.
     */
    public function testPopBatchAndPopByIdAlsoReturnJobs(): void
    {
        $queue = $this->makeQueue();
        $queue->push(['n' => 1]);
        $queue->push(['n' => 2]);

        $batch = $queue->popBatch(2);
        self::assertCount(2, $batch);
        foreach ($batch as $job) {
            self::assertInstanceOf(\Tina4\Job::class, $job, 'popBatch() must return Jobs');
            $job->complete();
        }

        $id = $queue->push(['n' => 3]);
        $byId = $queue->popById($id);
        self::assertInstanceOf(\Tina4\Job::class, $byId, 'popById() must return a Job');
        self::assertSame($id, $byId['id']);
        $byId->complete();
    }

    /**
     * POSITIVE: a Job can still be re-wrapped by hand.
     *
     * `new \Tina4\Job($queue->pop(), $queue, $topic)` was the ONLY way to reach
     * the lifecycle before this change, and the shipped example taught it - so
     * refusing a Job here would break every app that copied the workaround, on
     * the very upgrade that removes the need for it.
     */
    public function testAJobCanStillBeReWrappedByHand(): void
    {
        $queue = $this->makeQueue();
        $id = $queue->push(['m' => 'rewrap']);

        $job = $queue->pop();
        self::assertNotNull($job, 'nothing to pop');

        $rewrapped = new \Tina4\Job($job, $queue, $queue->getTopic());
        self::assertSame($id, $rewrapped->id);
        self::assertSame(['m' => 'rewrap'], $rewrapped->payload);
        self::assertNotNull($rewrapped['created_at'], 're-wrapping must not lose the stored fields');

        $rewrapped->complete();
        self::assertSame(0, $queue->size('reserved'), 'the re-wrapped job must still drive the lifecycle');
    }

    /**
     * NEGATIVE: writing through the array interface THROWS.
     *
     * Accepting `$job['attempts'] = 9` would let a caller believe it had changed
     * the queue when it had changed one in-memory object - the backend would
     * never hear about it. A job is a claim on a message, not a bag.
     */
    public function testWritingAFieldThrowsRatherThanSilentlyMutating(): void
    {
        $queue = $this->makeQueue();
        $queue->push(['m' => 'x']);
        $job = $queue->pop();
        self::assertNotNull($job, 'nothing to pop');

        $threw = null;
        try {
            $job['attempts'] = 9;
        } catch (\LogicException $e) {
            $threw = $e;
        }
        self::assertNotNull($threw, 'assigning a field must throw, not silently mutate');
        self::assertStringContainsString('read-only', $threw->getMessage());
        self::assertStringContainsString('fail(', $threw->getMessage(), 'the error must name the way to change a job');
        self::assertSame(0, $job->attempts, 'the refused write must not have landed');
    }

    /**
     * NEGATIVE: unsetting a field THROWS, for the same reason.
     */
    public function testUnsettingAFieldThrows(): void
    {
        $queue = $this->makeQueue();
        $queue->push(['m' => 'x']);
        $job = $queue->pop();
        self::assertNotNull($job, 'nothing to pop');

        $this->expectException(\LogicException::class);
        unset($job['payload']);
    }

    /**
     * NEGATIVE: an empty queue still returns null, not an empty Job.
     *
     * Every `if ($queue->pop() === null)` in the wild depends on this, and an
     * always-truthy empty Job would spin a consumer loop forever.
     */
    public function testAnEmptyQueueStillReturnsNull(): void
    {
        $queue = $this->makeQueue();
        self::assertNull($queue->pop(), 'an empty queue must return null');
        self::assertNull($queue->popById('no-such-id'), 'an unknown id must return null');
        self::assertSame([], $queue->popBatch(3), 'an empty queue must batch to an empty array');
    }
}
