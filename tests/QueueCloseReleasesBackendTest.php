<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Queue::close() - the connection an app opens must be one it can hand back.
 *
 * MEASURED 2026-08-04: close() was ABSENT on the top-level Queue class in ALL
 * FOUR frameworks. The backends below it were in four different states -
 *
 *   php     close() on the QueueBackend INTERFACE, implemented by all four
 *           backends, and reachable from nothing. THIS repo had the whole
 *           mechanism and never wired the last inch of it.
 *   ruby    close on rabbitmq/mongo/kafka, missing on lite.
 *   nodejs  nowhere on any backend class.
 *   python  nowhere at all on the queue adapters.
 *
 * - so an application holding a broker- or Mongo-backed queue had NO WAY to
 * release the connection. Build a Queue per request and you leak one client per
 * request, invisibly, until the broker refuses new connections. That is exactly
 * the leak ADR-0025 corollary 4 (client-lifecycle-is-bounded) fixed in
 * DocStore.
 *
 * The three cases are positive AND negative on purpose:
 *
 *   1. closing releases the connection      - the POSITIVE rule. A close() that
 *                                             exists but delegates nowhere
 *                                             fails here and nowhere else.
 *   2. closing twice is safe                - NEGATIVE. Shutdown paths run
 *                                             twice (an explicit close plus a
 *                                             finally); a close that raises the
 *                                             second time turns a clean
 *                                             shutdown into a crash.
 *   3. closing the file backend is no error - NEGATIVE. The zero-config default
 *                                             holds no connection and close()
 *                                             must be a no-op there.
 *
 * NO MOCKS. Every handle inspected here belongs to a REAL backend holding a
 * REAL socket to a REAL MongoDB / RabbitMQ / Kafka over TCP; the file cases use
 * a real temp directory. Reflection reads the real object's real private
 * property - it substitutes nothing and simulates nothing. A service that is
 * unreachable skips, unless TINA4_REQUIRE_SERVICES is set - then it is a
 * FAILURE, because a suite that silently skips its only real verification is
 * not verification.
 *
 * The three case names are shared VERBATIM with the Python, Ruby and Node
 * suites, so one fixture case in scripts/audit-contract-fixtures.py resolves
 * against EVERY framework's file.
 */
final class QueueCloseReleasesBackendTest extends TestCase
{
    /**
     * Every private property a queue backend uses to hold a LIVE connection.
     * Configuration strings (uri, host, brokers) are deliberately absent -
     * close() releases connections, not settings.
     *
     * @var string[]
     */
    private const HANDLE_PROPERTIES = ['client', 'collection', 'socket'];

    /** Backends that hold a real connection, and the service each one needs. */
    private const CONNECTED_BACKENDS = ['mongodb', 'rabbitmq', 'kafka'];

    private string $queuePath = '';

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

    private static function kafkaHost(): string
    {
        return getenv('TINA4_TEST_KAFKA_HOST') ?: '127.0.0.1';
    }

    private static function kafkaPort(): int
    {
        return (int)(getenv('TINA4_TEST_KAFKA_PORT') ?: 9092);
    }

    /**
     * @return array{0: string, 1: string, 2: int} name, host, port
     */
    private static function service(string $backend): array
    {
        return match ($backend) {
            'mongodb' => ['MongoDB', self::mongoHost(), self::mongoPort()],
            'rabbitmq' => ['RabbitMQ', self::rabbitHost(), self::rabbitPort()],
            'kafka' => ['Kafka', self::kafkaHost(), self::kafkaPort()],
            default => ['none', '', 0],
        };
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

    /** Skip, or FAIL under TINA4_REQUIRE_SERVICES. A silent skip is not proof. */
    private function requireService(string $backend): void
    {
        [$name, $host, $port] = self::service($backend);
        if ($host === '' || self::reachable($host, $port)) {
            return;
        }
        if (getenv('TINA4_REQUIRE_SERVICES')) {
            self::fail("TINA4_REQUIRE_SERVICES is set but {$name} is not reachable at {$host}:{$port}");
        }
        self::markTestSkipped("{$name} not reachable at {$host}:{$port}");
    }

    protected function setUp(): void
    {
        // TINA4_QUEUE_BACKEND OVERRIDES the constructor argument, and a stray
        // TINA4_QUEUE_URL re-points every broker at someone else's host.
        putenv('TINA4_QUEUE_BACKEND');
        putenv('TINA4_QUEUE_URL');
        putenv('TINA4_MONGO_URI=mongodb://' . self::mongoHost() . ':' . self::mongoPort());
        putenv('TINA4_RABBITMQ_HOST=' . self::rabbitHost());
        putenv('TINA4_RABBITMQ_PORT=' . self::rabbitPort());
        putenv('TINA4_KAFKA_BROKERS=' . self::kafkaHost() . ':' . self::kafkaPort());

        $this->queuePath = sys_get_temp_dir() . '/tina4_qclose_' . bin2hex(random_bytes(6));
        putenv('TINA4_QUEUE_PATH=' . $this->queuePath);
    }

    /**
     * A FRESH queue per call, deliberately: reusing one instance across
     * backends is how a connection opened by an earlier call makes a later
     * assertion pass for the wrong reason.
     */
    private function makeQueue(string $backend): \Tina4\Queue
    {
        return new \Tina4\Queue(
            $backend,
            ['maxRetries' => 2, 'path' => $this->queuePath],
            'qclose_' . bin2hex(random_bytes(6))
        );
    }

    /**
     * The backend the queue is ACTUALLY using (external when configured, else
     * the file store) - read off the real object, never assumed.
     */
    private function resolvedBackend(\Tina4\Queue $queue): object
    {
        $external = (new \ReflectionProperty(\Tina4\Queue::class, 'externalBackend'))->getValue($queue);
        if ($external !== null) {
            return $external;
        }
        return (new \ReflectionProperty(\Tina4\Queue::class, 'liteBackend'))->getValue($queue);
    }

    /**
     * Names of the connection handles the queue's backend is holding RIGHT NOW.
     *
     * @return string[]
     */
    private function liveHandles(\Tina4\Queue $queue): array
    {
        $backend = $this->resolvedBackend($queue);
        $reflection = new \ReflectionObject($backend);
        $held = [];
        foreach (self::HANDLE_PROPERTIES as $name) {
            if (!$reflection->hasProperty($name)) {
                continue;
            }
            $property = $reflection->getProperty($name);
            if ($property->isInitialized($backend) && $property->getValue($backend) !== null) {
                $held[] = $name;
            }
        }
        return $held;
    }

    /**
     * POSITIVE: close() reaches the backend and the live handle is given back.
     *
     * The push is not decoration - every connected backend connects LAZILY, so
     * without a real operation first there would be no connection to release
     * and this case would pass against a close() that does nothing at all.
     */
    public function testClosingAQueueReleasesTheBackendConnection(): void
    {
        foreach (self::CONNECTED_BACKENDS as $backend) {
            $this->requireService($backend);

            $queue = $this->makeQueue($backend);
            $queue->push(['m' => 'connect']);

            $held = $this->liveHandles($queue);
            self::assertNotEmpty(
                $held,
                "{$backend}: expected a live connection handle after a real push, found none "
                . '- the test cannot prove a release that never had anything to release'
            );

            $queue->close();

            self::assertSame(
                [],
                $this->liveHandles($queue),
                "{$backend}: close() left " . implode(', ', $this->liveHandles($queue))
                . ' still held - the connection was never released, which is the leak this exists to stop'
            );
        }
    }

    /**
     * NEGATIVE: a second close() must be a no-op, never an exception.
     *
     * Shutdown paths run twice in real apps (an explicit close plus a finally).
     * A close that throws on the second call turns a clean shutdown into a
     * crash, and a `finally { $queue->close(); }` into a masked original error.
     */
    public function testClosingAQueueTwiceIsSafe(): void
    {
        foreach (array_merge(['file'], self::CONNECTED_BACKENDS) as $backend) {
            if ($backend !== 'file') {
                $this->requireService($backend);
            }

            $queue = $this->makeQueue($backend);
            $queue->push(['m' => 'connect']);

            try {
                $queue->close();
                $queue->close();
            } catch (\Throwable $e) {
                self::fail("{$backend}: closing twice threw " . get_class($e) . ': ' . $e->getMessage());
            }

            self::assertSame(
                [],
                $this->liveHandles($queue),
                "{$backend}: a handle is still held after two closes"
            );
        }
    }

    /**
     * NEGATIVE: the zero-config default has no connection, and must not care.
     *
     * The file backend is what every app gets before it configures anything. If
     * close() were only defined on the connected backends, adding a shutdown
     * path would break the default with a fatal "call to undefined method" - so
     * this pins that the no-op is real, and that it does not disturb the
     * queue's contents.
     */
    public function testClosingAFileBackedQueueIsNotAnError(): void
    {
        $queue = $this->makeQueue('file');
        $queue->push(['m' => 'on disk']);

        $before = $queue->size('pending');
        self::assertSame(1, $before, "file: expected the pushed job to be pending, got {$before}");

        try {
            $queue->close();
        } catch (\Throwable $e) {
            self::fail('file: close() threw ' . get_class($e) . ': ' . $e->getMessage());
        }

        self::assertSame([], $this->liveHandles($queue), 'file: the file backend must hold no connection');
        self::assertSame(
            $before,
            $queue->size('pending'),
            'file: close() must not disturb the queue contents - it has nothing to close'
        );
    }
}
