<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;

/**
 * queue_contract.json :: delay-is-honoured-on-every-backend
 *
 * MEASURED 2026-08-03: push($payload, $priority, $delay) was silently DROPPED
 * on every non-file backend, in ALL FOUR frameworks. A scheduled job fired
 * immediately in production and on time in development — the worst shape of
 * divergence, because the environment you test in is the one that behaves
 * correctly.
 *
 * PHP dropped it earliest of the four: Queue::push() built a SEPARATE message
 * array for the external-backend branch that carried neither priority nor
 * delay_seconds, so the value never reached the backend at all.
 *
 * The fix splits by what each broker can actually do:
 *   mongodb   implemented — a delayed job is stamped available_at in the
 *             future, and dequeue already filtered on available_at <= now.
 *   rabbitmq  RAISES — no per-message delay in core.
 *   kafka     RAISES — no per-message delay at all.
 *
 * Per queue invariant 6, a backend that genuinely cannot perform an operation
 * raises naming the backend AND the operation. It may never silently no-op.
 *
 * NO MOCKS. Every assertion drives a live MongoDB over TCP. If it is
 * unreachable the test skips, unless TINA4_REQUIRE_SERVICES is set — then a
 * missing service is a FAILURE, because a suite that silently skips its only
 * real verification is not verification.
 *
 * The four case names here are shared VERBATIM with the Python, Ruby and Node
 * suites, because scripts/audit-contract-fixtures.py resolves ONE fixture case
 * against EVERY framework's file.
 */
final class QueueDelayInvariantTest extends TestCase
{
    /** Long enough that a dropped delay is unambiguous, short enough to keep
     *  the suite quick. A dropped delay shows up instantly, so this is no race. */
    private const DELAY = 3;

    private string $host;
    private int $port;

    protected function setUp(): void
    {
        $this->host = getenv('TINA4_TEST_MONGO_HOST') ?: '127.0.0.1';
        $this->port = (int)(getenv('TINA4_TEST_MONGO_PORT') ?: 27017);

        putenv("TINA4_MONGO_URI=mongodb://{$this->host}:{$this->port}");
        putenv('TINA4_QUEUE_PATH=' . sys_get_temp_dir() . '/qdelay_' . bin2hex(random_bytes(5)));
    }

    /**
     * Skip when MongoDB is absent — unless the operator demanded real services,
     * in which case a missing one is a failure, not a quiet pass.
     */
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
        return new \Tina4\Queue('mongodb', [], 'delay_' . bin2hex(random_bytes(6)));
    }

    /**
     * NEGATIVE: without this pair, "never return anything" passes both delay
     * tests below. It also proves the queue itself works, so a failure there is
     * really about the delay and not about a broken backend.
     */
    public function testAnUndelayedJobIsVisibleImmediately(): void
    {
        $this->requireMongo();

        $queue = $this->mongoQueue();
        $queue->push(['m' => 'undelayed'], 0, 0);
        sleep(1);

        $this->assertNotNull($queue->pop(), 'an undelayed job must be available at once');
    }

    /**
     * The measured defect: this job used to come straight back.
     */
    public function testADelayedJobIsNotVisibleBeforeItsDelayElapses(): void
    {
        $this->requireMongo();

        $queue = $this->mongoQueue();
        $queue->push(['m' => 'delayed'], 0, self::DELAY);
        sleep(1);

        $this->assertNull($queue->pop(), 'a delayed job must not be claimable before its delay');
    }

    /**
     * NEGATIVE of the negative: "hide it forever" would satisfy the test above
     * while losing the job outright. The delay must expire.
     */
    public function testADelayedJobBecomesVisibleOnceItsDelayElapses(): void
    {
        $this->requireMongo();

        $queue = $this->mongoQueue();
        $queue->push(['m' => 'delayed'], 0, self::DELAY);
        sleep(self::DELAY + 2);

        $this->assertNotNull($queue->pop(), 'a delayed job must be claimable after its delay');
    }

    /**
     * These two brokers have no per-message delay. Silently discarding it is
     * the failure mode invariant 6 exists to forbid, so they raise naming both
     * the backend and the operation — and never touch the network to do it,
     * which is why this case needs no live broker.
     */
    public function testABackendThatCannotDelayRefusesInsteadOfDroppingTheDelay(): void
    {
        foreach (['rabbitmq', 'kafka'] as $backend) {
            $queue = new \Tina4\Queue($backend, [], 'delay_' . bin2hex(random_bytes(6)));

            $message = null;
            try {
                $queue->push(['m' => 'delayed'], 0, self::DELAY);
            } catch (\RuntimeException $e) {
                $message = $e->getMessage();
            }

            // Asserted OUTSIDE the catch: PHPUnit's AssertionFailedError extends
            // \RuntimeException, so a $this->fail() inside the try would be caught
            // here and hide a push() that dropped the delay instead of refusing
            // (the ghost). assertNotNull makes this a real gate.
            $this->assertNotNull($message, "the {$backend} backend must refuse a delayed push, not drop the delay");
            $this->assertStringContainsString($backend, (string)$message, 'the error must name the backend');
            $this->assertStringContainsString('delay', strtolower((string)$message), 'the error must name the operation');
        }
    }
}
