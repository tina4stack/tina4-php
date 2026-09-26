<?php

/**
 * Tina4 — size('dead'/'failed') counts the dead-letter store on Mongo and
 * RabbitMQ; Kafka honestly returns 0 (ADR-0022 dec 5).
 *
 * Bug 2 (book review). Queue::size($status) dropped $status for external
 * backends and returned the PENDING count for size('dead') — the exact
 * "answers a different question than the one asked" case ADR-0022 decision 7
 * names as worse than 0. Fixed: the dead aliases count the dead-letter store on
 * Mongo (count docs on <topic>.dead_letter) and RabbitMQ (dead-letter queue
 * depth); Kafka stays 0 (decision 5 — a log has no depth), pinned here.
 *
 * NO MOCKS: live MongoDB / RabbitMQ / Kafka. Each case guards its own service;
 * under TINA4_REQUIRE_SERVICES an unreachable service FAILS.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Queue;

final class QueueSizeDeadStoreTest extends TestCase
{
    private static function reachable(string $host, int $port): bool
    {
        $s = @fsockopen($host, $port, $e, $m, 2);
        if ($s === false) {
            return false;
        }
        fclose($s);
        return true;
    }

    private function requireOrSkip(bool $ok, string $svc, string $where): void
    {
        if ($ok) {
            return;
        }
        if (getenv('TINA4_REQUIRE_SERVICES')) {
            self::fail("TINA4_REQUIRE_SERVICES set but {$svc} not reachable at {$where}");
        }
        self::markTestSkipped("{$svc} not reachable at {$where}");
    }

    protected function setUp(): void
    {
        putenv('TINA4_QUEUE_BACKEND'); // never let the env override the backend
    }

    private function primeDeadLetter(Queue $q): void
    {
        $q->push(['task' => 'doomed']);
        $job = $q->pop();
        self::assertNotNull($job, 'prime failed: pop returned null');
        $job->fail('boom'); // attempts=1 == maxRetries=1 -> dead
    }

    public function testMongoSizeDeadCountsStore(): void
    {
        $host = getenv('TINA4_TEST_MONGO_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('TINA4_TEST_MONGO_PORT') ?: 27017);
        $this->requireOrSkip(
            extension_loaded('mongodb') && class_exists('MongoDB\\Client') && self::reachable($host, $port),
            'MongoDB',
            "{$host}:{$port}"
        );
        putenv("TINA4_MONGO_URI=mongodb://{$host}:{$port}");
        putenv("TINA4_QUEUE_MONGO_URI=mongodb://{$host}:{$port}");
        $q = new Queue('mongodb', ['maxRetries' => 1], 'sizedead_' . bin2hex(random_bytes(6)));
        try {
            $this->primeDeadLetter($q);
            self::assertCount(1, $q->deadLetters());
            self::assertSame(1, $q->size('dead'), "Mongo size('dead') must count the dead-letter store");
            self::assertSame(1, $q->size('failed'), "'failed' is an alias for the dead count");
            self::assertSame(0, $q->size('pending'), 'the failed job must not count as pending');
        } finally {
            $q->clear();
            $q->close();
        }
    }

    public function testRabbitMqSizeDeadCountsStore(): void
    {
        $host = getenv('TINA4_TEST_RABBITMQ_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('TINA4_TEST_RABBITMQ_PORT') ?: 5672);
        $this->requireOrSkip(self::reachable($host, $port), 'RabbitMQ', "{$host}:{$port}");
        putenv('TINA4_RABBITMQ_HOST=' . $host);
        putenv('TINA4_RABBITMQ_PORT=' . $port);
        $q = new Queue('rabbitmq', ['maxRetries' => 1], 'sizedead_' . bin2hex(random_bytes(6)));
        try {
            $this->primeDeadLetter($q);
            // RabbitMQ delivery is asynchronous: the dead-letter Basic.Publish
            // (fire-and-forget, no publisher confirm — same as tina4-nodejs) may
            // not be counted the instant fail() returns. Poll briefly for it,
            // matching the sleep the Node/Python queue-lifecycle tests use.
            $size = 0;
            for ($i = 0; $i < 40; $i++) {
                $size = $q->size('dead');
                if ($size >= 1) {
                    break;
                }
                usleep(50000);
            }
            self::assertSame(1, $size, "RabbitMQ size('dead') must count the .dead_letter queue depth");
        } finally {
            $q->close();
        }
    }

    public function testKafkaSizeDeadStaysZero(): void
    {
        $url = getenv('TINA4_TEST_KAFKA_URL');
        [$host, $port] = $url ? array_pad(explode(':', $url, 2), 2, '9092') : ['127.0.0.1', '9092'];
        $this->requireOrSkip((bool)$url && self::reachable($host, (int)$port), 'Kafka', $url ?: 'unset');
        putenv('TINA4_KAFKA_BROKERS=' . $url);
        $q = new Queue('kafka', ['maxRetries' => 1], 'sizedead_' . bin2hex(random_bytes(6)));
        try {
            // ADR-0022 dec 5: a Kafka log has no depth, so size() returns 0 for
            // every status including the dead aliases — the documented answer.
            self::assertSame(0, $q->size('dead'), "Kafka size('dead') stays 0 per ADR-0022 decision 5");
            self::assertSame(0, $q->size('failed'), 'Kafka size stays 0 for every status');
        } finally {
            $q->close();
        }
    }
}
