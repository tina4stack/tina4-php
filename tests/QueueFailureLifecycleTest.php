<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * queue_contract.json :: the-failure-lifecycle-is-real-everywhere
 *
 * MEASURED 2026-08-04, and this invariant was OWED with no suite at all -
 * which is why every defect it covers shipped.
 *
 * The rule: a job's failure reaches the backend on EVERY provider, and a job
 * past maxRetries becomes observable through deadLetters() on EVERY provider.
 * A dead-letter handler written against the file backend must find the same
 * jobs after deploying onto Mongo or a broker.
 *
 * PHP's measured defect was the refusal itself: rabbitmq and kafka THREW from
 * deadLetters(), so a dashboard that worked on file/mongodb crashed the moment
 * the provider changed. The refusal rested on a premise true of the broker but
 * false of this framework - RabbitMQ's own dead-letter EXCHANGE is not
 * queryable, but deadLetter() ENQUEUES to '<topic>.dead_letter', an ordinary
 * queue Tina4 writes and can read. Every record there is a dead letter by
 * construction, so it needs reading, not querying.
 *
 * failed() and retryFailed() still refuse, and correctly: a job that failed but
 * is still retryable is re-published to the MAIN topic, indistinguishable from
 * pending work.
 *
 * NO MOCKS. Every assertion drives a live MongoDB over TCP, and the broker
 * cases drive a live RabbitMQ. A missing service is a FAILURE under
 * TINA4_REQUIRE_SERVICES, never a silent skip.
 *
 * The case names here are shared VERBATIM with the Python, Ruby and Node
 * suites, because scripts/audit-contract-fixtures.py resolves ONE fixture case
 * against EVERY framework's file.
 */
final class QueueFailureLifecycleTest extends TestCase
{
    private const MAX_RETRIES = 2;

    private static function mongoHost(): string
    {
        return getenv('TINA4_TEST_MONGO_HOST') ?: '127.0.0.1';
    }

    private static function mongoPort(): int
    {
        return (int)(getenv('TINA4_TEST_MONGO_PORT') ?: 27017);
    }

    private static function rabbitHost(): string
    {
        return getenv('TINA4_TEST_RABBITMQ_HOST') ?: '127.0.0.1';
    }

    private static function rabbitPort(): int
    {
        return (int)(getenv('TINA4_TEST_RABBITMQ_PORT') ?: 5672);
    }

    private static function reachable(string $host, int $port): bool
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, 2);
        if ($socket === false) {
            return false;
        }
        fclose($socket);
        return true;
    }

    protected function setUp(): void
    {
        // TINA4_QUEUE_BACKEND OVERRIDES the constructor argument, so it must be
        // cleared or every case silently runs on whatever the environment says.
        putenv('TINA4_QUEUE_BACKEND');
        putenv('TINA4_MONGO_URI=mongodb://' . self::mongoHost() . ':' . self::mongoPort());
        putenv('TINA4_QUEUE_MONGO_URI=mongodb://' . self::mongoHost() . ':' . self::mongoPort());

        if (!self::reachable(self::mongoHost(), self::mongoPort())) {
            if (getenv('TINA4_REQUIRE_SERVICES')) {
                self::fail(
                    'TINA4_REQUIRE_SERVICES is set but MongoDB is not reachable at '
                    . self::mongoHost() . ':' . self::mongoPort()
                );
            }
            self::markTestSkipped('MongoDB not reachable at ' . self::mongoHost() . ':' . self::mongoPort());
        }
    }

    /**
     * A FRESH queue per call, deliberately. Reusing one instance across a loop
     * is how the surface-invariant test once passed with its fix reverted: an
     * earlier call had already connected, so the defect could not reproduce.
     */
    private function makeQueue(string $backend): \Tina4\Queue
    {
        return new \Tina4\Queue(
            $backend,
            ['maxRetries' => self::MAX_RETRIES],
            'faillc_' . bin2hex(random_bytes(6))
        );
    }

    private function drainFail(\Tina4\Queue $queue, string $topic, int $times, string $prefix = 'boom'): void
    {
        for ($attempt = 1; $attempt <= $times; $attempt++) {
            $job = $queue->pop();
            if ($job === null) {
                break;
            }
            $queue->failJob($topic, $job, $prefix . '-' . $attempt);
            usleep(300000);
        }
    }

    /**
     * Both backends implement the full lifecycle, so both must answer
     * identically. That equality IS the invariant - testing one proves nothing
     * about the swap.
     *
     * @return array<string, array{0: string}>
     */
    public static function lifecycleBackends(): array
    {
        return ['file' => ['file'], 'mongodb' => ['mongodb']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lifecycleBackends')]
    public function testAFailedJobUnderMaxRetriesIsRetriedRatherThanDeadLettered(string $backend): void
    {
        $queue = $this->makeQueue($backend);
        $topic = $queue->getTopic();
        $queue->push(['m' => 'transient']);
        usleep(400000);

        $job = $queue->pop();
        self::assertNotNull($job, "$backend: nothing to pop");
        $queue->failJob($topic, $job, 'boom-1');
        usleep(400000);

        self::assertSame([], $queue->deadLetters(), "$backend: a job with retries left must NOT be dead-lettered");
        // THE defect class this invariant was owed for: a failed() that can
        // never match returns [] forever, and asserting only "not
        // dead-lettered" would still pass with that bug. The job has to be
        // positively REPORTABLE as failed.
        self::assertCount(1, $queue->failed(), "$backend: a job that failed with retries left must be reported by failed()");
        self::assertNotNull($queue->pop(), "$backend: a job with retries left must come back for another attempt");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lifecycleBackends')]
    public function testAJobPastMaxRetriesBecomesADeadLetter(string $backend): void
    {
        $queue = $this->makeQueue($backend);
        $topic = $queue->getTopic();
        $queue->push(['m' => 'poison']);
        usleep(400000);

        $this->drainFail($queue, $topic, self::MAX_RETRIES);
        usleep(400000);

        self::assertCount(1, $queue->deadLetters(), "$backend: a job past maxRetries must appear in deadLetters()");
        self::assertNull($queue->pop(), "$backend: a dead-lettered job must NOT still be redelivered");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lifecycleBackends')]
    public function testADeadLetterCarriesTheAttemptCountAndTheFailureReason(string $backend): void
    {
        $queue = $this->makeQueue($backend);
        $topic = $queue->getTopic();
        $queue->push(['m' => 'poison']);
        usleep(400000);

        $this->drainFail($queue, $topic, self::MAX_RETRIES);
        usleep(400000);

        $dead = $queue->deadLetters();
        self::assertCount(1, $dead, "$backend: expected exactly one dead letter");
        // A dead-letter handler exists to answer "what died, why, and after how
        // many tries". One that cannot answer that is a row in a table.
        self::assertSame(self::MAX_RETRIES, (int)($dead[0]['attempts'] ?? -1), "$backend: dead letter lost the attempt count");
        self::assertSame('boom-' . self::MAX_RETRIES, $dead[0]['error'] ?? null, "$backend: dead letter lost the failure reason");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lifecycleBackends')]
    public function testACompletedJobNeverAppearsInDeadLetters(string $backend): void
    {
        $queue = $this->makeQueue($backend);
        $topic = $queue->getTopic();
        $queue->push(['m' => 'healthy']);
        usleep(400000);

        $job = $queue->pop();
        self::assertNotNull($job, "$backend: nothing to pop");
        $queue->completeJob($topic, (string)($job['id'] ?? ''));
        usleep(400000);

        // CONTROL: without this, "return every job ever seen" passes the rest.
        self::assertSame([], $queue->deadLetters(), "$backend: a completed job must never be reported as dead");
        self::assertSame([], $queue->failed(), "$backend: a completed job must never be reported as failed");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lifecycleBackends')]
    public function testReadingDeadLettersDoesNotConsumeThem(string $backend): void
    {
        $queue = $this->makeQueue($backend);
        $topic = $queue->getTopic();
        $queue->push(['m' => 'poison']);
        usleep(400000);

        $this->drainFail($queue, $topic, self::MAX_RETRIES);
        usleep(400000);

        // deadLetters() is what a dashboard or health check calls on a timer.
        // On the brokers it drains the dead-letter queue and re-publishes what
        // it read, so an unfaithful round-trip would make a monitor destroy -
        // or endlessly multiply - the backlog it reports on.
        $counts = [];
        for ($i = 0; $i < 3; $i++) {
            $counts[] = count($queue->deadLetters());
        }
        self::assertSame([1, 1, 1], $counts, "$backend: reading deadLetters() changed the result across reads");
    }

    public function testFailingAJobReachesTheConfiguredBackendAndNotJustLocalMemory(): void
    {
        $this->requireRabbit();
        $queue = $this->makeQueue('rabbitmq');
        $topic = $queue->getTopic();

        $queue->push(['m' => 'poison']);
        usleep(500000);
        $job = $queue->pop();
        self::assertNotNull($job, 'nothing to pop from the broker');
        $queue->failJob($topic, $job, 'boom-1');
        usleep(500000);

        // Proof the failure REACHED the broker: redelivery carrying the
        // incremented count. A failure recorded only in local memory would
        // leave the delivery unacked and nothing re-published.
        $redelivered = $queue->pop();
        self::assertNotNull($redelivered, 'the failure did not reach the broker - nothing was redelivered');
        self::assertSame(1, (int)($redelivered['attempts'] ?? -1), 'the attempt count did not survive the broker round-trip');
    }

    public function testABackendThatCannotEnumerateRetryableFailuresRefusesByName(): void
    {
        $this->requireRabbit();
        $queue = $this->makeQueue('rabbitmq');

        // A broker CAN report exhausted jobs (it keeps its own .dead_letter
        // queue), but genuinely cannot enumerate failed-but-still-retryable
        // ones: those are re-published to the MAIN topic. Returning [] would
        // claim nothing has failed, so it raises - naming backend AND operation.
        try {
            $queue->failed();
            self::fail('failed() must refuse on rabbitmq, not return a list');
        } catch (\Throwable $e) {
            self::assertStringContainsString('rabbitmq', $e->getMessage(), 'the refusal must name the BACKEND');
            self::assertStringContainsString('failed()', $e->getMessage(), 'the refusal must name the OPERATION');
        }

        try {
            $queue->retryFailed();
            self::fail('retryFailed() must refuse on rabbitmq, not return a count');
        } catch (\Throwable $e) {
            self::assertStringContainsString('rabbitmq', $e->getMessage(), 'the refusal must name the BACKEND');
            // retryFailed must carry its OWN refusal - letting failed()'s
            // message escape names the wrong operation to the caller.
            self::assertStringContainsString('retryFailed()', $e->getMessage(), 'retryFailed() must name ITSELF');
        }
    }

    private function requireRabbit(): void
    {
        if (self::reachable(self::rabbitHost(), self::rabbitPort())) {
            putenv('TINA4_RABBITMQ_HOST=' . self::rabbitHost());
            putenv('TINA4_RABBITMQ_PORT=' . self::rabbitPort());
            return;
        }
        if (getenv('TINA4_REQUIRE_SERVICES')) {
            self::fail('TINA4_REQUIRE_SERVICES is set but RabbitMQ is not reachable at ' . self::rabbitHost() . ':' . self::rabbitPort());
        }
        self::markTestSkipped('RabbitMQ not reachable at ' . self::rabbitHost() . ':' . self::rabbitPort());
    }
}
