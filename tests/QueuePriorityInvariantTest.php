<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;

/**
 * queue_contract.json :: priority-and-availability-are-honoured
 *
 * MEASURED 2026-08-03: `push($payload, $priority, ...)` was honoured ONLY on
 * the file backend. An urgent job queued behind a backlog waited for all of it
 * in production, while development on the file backend prioritised correctly.
 *
 * PHP lost it twice over. `Queue::push()` built a SEPARATE message array for
 * the external-backend branch that carried no priority at all, and even once
 * that was unified, MongoBackend stored priority only INSIDE the 'message'
 * sub-document (where a sort cannot reach it) and sorted on created_at alone.
 *
 * The fix splits by what each backend can actually do:
 *   file      already correct - highest priority first, ties oldest-first.
 *   mongodb   implemented - priority is stored top-level and the dequeue sorts
 *             on it.
 *   rabbitmq  RAISES - a RabbitMQ queue is FIFO. Native priority needs the
 *             queue DECLARED with x-max-priority, and an existing queue cannot
 *             be redeclared with one (PRECONDITION_FAILED).
 *   kafka     RAISES - no priority concept at all.
 *
 * Per queue invariant 6, a backend that genuinely cannot perform an operation
 * raises naming the backend AND the operation. It may never silently no-op.
 *
 * The AVAILABILITY half of this invariant is proved by the
 * delay-is-honoured-on-every-backend cases and is not duplicated here.
 *
 * NO MOCKS. Every assertion drives a live MongoDB over TCP. If it is
 * unreachable the test skips, unless TINA4_REQUIRE_SERVICES is set - then a
 * missing service is a FAILURE.
 *
 * The three case names here are shared VERBATIM with the Python, Ruby and Node
 * suites, because scripts/audit-contract-fixtures.py resolves ONE fixture case
 * against EVERY framework's file.
 */
final class QueuePriorityInvariantTest extends TestCase
{
    private string $host;
    private int $port;

    protected function setUp(): void
    {
        $this->host = getenv('TINA4_TEST_MONGO_HOST') ?: '127.0.0.1';
        $this->port = (int)(getenv('TINA4_TEST_MONGO_PORT') ?: 27017);

        putenv("TINA4_MONGO_URI=mongodb://{$this->host}:{$this->port}");
        putenv('TINA4_QUEUE_PATH=' . sys_get_temp_dir() . '/qprio_' . bin2hex(random_bytes(5)));
    }

    private function requireMongo(): void
    {
        if (!extension_loaded('mongodb')) {
            $this->skipOrFail('ext-mongodb is not loaded');
        }
        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, 2);
        if ($socket === false) {
            $this->skipOrFail("MongoDB is not reachable at {$this->host}:{$this->port}");
        }
        fclose($socket);
    }

    private function skipOrFail(string $why): void
    {
        if (getenv('TINA4_REQUIRE_SERVICES')) {
            $this->fail("TINA4_REQUIRE_SERVICES is set but {$why}");
        }
        $this->markTestSkipped($why);
    }

    private function mongoQueue(): \Tina4\Queue
    {
        return new \Tina4\Queue('mongodb', [], 'prio_' . bin2hex(random_bytes(6)));
    }

    /**
     * pop() returns a Tina4\Job, not an array. The BODY needed no change - Job
     * implements ArrayAccess so `$job['payload']` still resolves - only this
     * parameter type did, which is exactly the non-breaking guarantee working
     * as intended.
     *
     * @param array<string, mixed>|\Tina4\Job|null $job
     */
    private function payload(array|\Tina4\Job|null $job): mixed
    {
        $p = $job['payload'] ?? $job;
        return is_array($p) ? ($p['m'] ?? null) : $p;
    }

    /**
     * The measured defect. LOW is pushed FIRST, so FIFO order and priority
     * order disagree - the only arrangement that can tell them apart.
     */
    public function testAHigherPriorityJobIsDeliveredBeforeAnOlderLowerPriorityOne(): void
    {
        $this->requireMongo();

        $queue = $this->mongoQueue();
        $queue->push(['m' => 'low'], 0, 0);
        usleep(500000);                    // distinct created_at, so FIFO is unambiguous
        $queue->push(['m' => 'high'], 9, 0);
        sleep(1);

        $first = $queue->pop();
        $this->assertNotNull($first, 'the queue returned nothing - priority is unmeasurable');
        $this->assertSame('high', $this->payload($first), 'the higher-priority job must be delivered first');
    }

    /**
     * NEGATIVE: pins the TIE-BREAK. Without it, "always deliver the newest job"
     * passes the case above while breaking FIFO for everything else. It also
     * proves both jobs come back at all, so this doubles as the control.
     */
    public function testEqualPriorityJobsAreDeliveredOldestFirst(): void
    {
        $this->requireMongo();

        $queue = $this->mongoQueue();
        $queue->push(['m' => 'first'], 5, 0);
        usleep(500000);
        $queue->push(['m' => 'second'], 5, 0);
        sleep(1);

        $first = $queue->pop();
        $second = $queue->pop();
        $this->assertNotNull($first, 'both jobs must come back');
        $this->assertNotNull($second, 'both jobs must come back');
        $this->assertSame('first', $this->payload($first), 'equal priority must fall back to oldest-first');
        $this->assertSame('second', $this->payload($second));
    }

    /**
     * Neither broker can order by priority. Silently discarding it is the
     * failure mode invariant 6 exists to forbid, so they raise naming both the
     * backend and the operation - and never touch the network to do it, which
     * is why this case needs no live broker.
     */
    public function testABackendThatCannotPrioritiseRefusesInsteadOfDroppingThePriority(): void
    {
        foreach (['rabbitmq', 'kafka'] as $backend) {
            $queue = new \Tina4\Queue($backend, [], 'prio_' . bin2hex(random_bytes(6)));

            $message = null;
            try {
                $queue->push(['m' => 'high'], 9, 0);
            } catch (\RuntimeException $e) {
                $message = $e->getMessage();
            }

            // Asserted OUTSIDE the catch on purpose. PHPUnit's AssertionFailedError
            // extends \RuntimeException, so a $this->fail() inside the try would be
            // swallowed by the catch above - the ghost that stays green even when
            // push() did NOT refuse (proven: mutating KafkaBackend::enqueue() to
            // return without throwing left this test green). Capturing the message
            // and asserting out here makes it a real gate: a non-refusing push
            // leaves $message null and assertNotNull fails.
            $this->assertNotNull($message, "the {$backend} backend must refuse a prioritised push, not drop the priority");
            $this->assertStringContainsString($backend, (string)$message, 'the error must name the backend');
            $this->assertStringContainsString('priority', strtolower((string)$message), 'the error must name the operation');
        }
    }
}
