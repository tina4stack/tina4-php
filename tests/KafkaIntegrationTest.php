<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
 *
 * Live Kafka integration test — exercises a REAL broker (no mocks).
 *
 * PHP's KafkaBackend speaks the wire protocol by hand. This test produces a
 * unique payload to a real topic and consumes it back, proving the produce
 * (Produce request + message batch + CRC) and fetch (parse the message set)
 * paths round-trip against a live broker, not a stand-in.
 *
 * Gated on TINA4_TEST_KAFKA_URL (e.g. localhost:9092). Skips cleanly when the
 * env var is unset or the broker is unreachable, with a reason the
 * TINA4_REQUIRE_SERVICES gate recognises ("kafka ... not set / not reachable")
 * so CI fails if Kafka should be up but is not.
 *
 * A KRaft broker silently drops a produce to a leaderless (not-yet-created)
 * topic, so the topic is created up front via the broker's own kafka-topics.sh
 * (container from TINA4_TEST_KAFKA_CONTAINER, default tina4-kafka) — the same
 * approach the Node kafkaIntegration test uses.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Queue;

class KafkaIntegrationTest extends TestCase
{
    private string $topic = '';
    private string $container = '';

    /** @return array{host:string,port:int} first broker from a comma list. */
    private static function firstBroker(string $url): array
    {
        $first = explode(',', $url)[0];
        $first = preg_replace('#^kafka://#', '', $first);
        $host = $first;
        $port = 9092;
        $colon = strrpos($first, ':');
        if ($colon !== false) {
            $host = substr($first, 0, $colon);
            $port = (int)substr($first, $colon + 1);
        }
        return ['host' => $host ?: 'localhost', 'port' => $port ?: 9092];
    }

    private static function reachable(string $host, int $port): bool
    {
        $fp = @fsockopen($host, $port, $errno, $errstr, 1.5);
        if ($fp) {
            fclose($fp);
            return true;
        }
        return false;
    }

    protected function setUp(): void
    {
        $url = getenv('TINA4_TEST_KAFKA_URL');
        if ($url === false || $url === '') {
            $this->markTestSkipped('TINA4_TEST_KAFKA_URL not set — kafka broker not available for the live integration test.');
        }

        $broker = self::firstBroker($url);
        if (!self::reachable($broker['host'], $broker['port'])) {
            $this->markTestSkipped('kafka broker not reachable at ' . $broker['host'] . ':' . $broker['port'] . '.');
        }

        putenv('TINA4_KAFKA_BROKERS=' . $url);
        $_ENV['TINA4_KAFKA_BROKERS'] = $url;
        $_SERVER['TINA4_KAFKA_BROKERS'] = $url;

        $this->container = getenv('TINA4_TEST_KAFKA_CONTAINER') ?: 'tina4-kafka';
        $this->topic = 'tina4_test_kafka_' . bin2hex(random_bytes(6));

        // Create the topic WITH a leader before producing (KRaft drops a produce
        // to a leaderless topic). Not a "not reachable / not set" condition, so a
        // failure here skips cleanly without tripping the require-services gate.
        $out = [];
        $rc = 0;
        exec(sprintf(
            'docker exec %s /opt/kafka/bin/kafka-topics.sh --bootstrap-server localhost:9092 --create --topic %s --partitions 1 --replication-factor 1 --if-not-exists 2>&1',
            escapeshellarg($this->container),
            escapeshellarg($this->topic)
        ), $out, $rc);
        if ($rc !== 0) {
            $this->markTestSkipped('could not create kafka topic via "docker exec ' . $this->container . '" (set TINA4_TEST_KAFKA_CONTAINER): ' . implode(' ', $out));
        }
    }

    protected function tearDown(): void
    {
        if ($this->topic !== '' && $this->container !== '') {
            @exec(sprintf(
                'docker exec %s /opt/kafka/bin/kafka-topics.sh --bootstrap-server localhost:9092 --delete --topic %s 2>&1',
                escapeshellarg($this->container),
                escapeshellarg($this->topic)
            ));
        }
    }

    /**
     * Produce a unique payload to the real broker and consume it back through the
     * Queue facade. The id round-trips and the payload matches — proof the
     * hand-rolled produce + fetch are well-formed against a live Kafka.
     */
    public function testRealProduceConsumeRoundTrip(): void
    {
        $queue = new Queue('kafka', [], $this->topic);

        $payload = [
            'task' => 'kafka-integration',
            'value' => 98765,
            'nonce' => bin2hex(random_bytes(4)),
        ];
        $id = $queue->push($payload);
        $this->assertNotEmpty($id, 'push() must return a message id.');

        // Allow a moment for the broker to surface the freshly produced record.
        $msg = null;
        for ($i = 0; $i < 10; $i++) {
            $msg = $queue->pop();
            if ($msg !== null) {
                break;
            }
            usleep(400000);
        }

        // pop() returns a Tina4\Job now (ADR-0024 parity), and the array read
        // below still resolves through ArrayAccess - both halves asserted so
        // neither can regress alone.
        $this->assertInstanceOf(
            \Tina4\Job::class,
            $msg,
            'pop() must return the produced message from the real broker, as a Job.'
        );
        $body = $msg['payload'] ?? $msg;
        $this->assertSame('kafka-integration', $body['task'] ?? null, 'The produced task must round-trip.');
        $this->assertSame(98765, $body['value'] ?? null, 'The produced value must round-trip.');
        $this->assertSame($payload['nonce'], $body['nonce'] ?? null, 'The unique nonce must round-trip.');
    }

    /**
     * Produce ONE record with the broker's own console producer, in its own
     * batch (the client decodes only the first record of a fetched batch). A
     * value equal to the null marker is written as null: a real tombstone, the
     * record Debezium writes after a delete.
     */
    private function produceRecord(string $key, string $value): void
    {
        $this->produceLines("{$key},{$value}");
    }

    /** Produce newline-separated key,value lines in one console-producer run (one batch). */
    private function produceLines(string $lines): void
    {
        $out = [];
        $rc = 0;
        exec(sprintf(
            'printf %%s %s | docker exec -i %s /opt/kafka/bin/kafka-console-producer.sh --bootstrap-server localhost:9092 --topic %s --property parse.key=true --property key.separator=, --property null.marker=TOMBSTONE 2>&1',
            escapeshellarg($lines),
            escapeshellarg($this->container),
            escapeshellarg($this->topic)
        ), $out, $rc);
        $this->assertSame(0, $rc, 'console producer failed: ' . implode(' ', $out));
    }

    /**
     * A tombstone (null-value record) is stepped over: the record after it is
     * delivered. The fetch answered a null value with "no message" and never
     * moved its offset, so every later pop() fetched the tombstone again and
     * nothing after it was ever consumed -- silently, with no error.
     */
    public function testATombstoneIsSteppedOverAndTheNextRecordDelivered(): void
    {
        $this->produceRecord('k1', '{"n":1}');
        $this->produceRecord('k1', 'TOMBSTONE');
        $this->produceRecord('k2', '{"n":2}');

        $queue = new Queue('kafka', [], $this->topic);
        $got = [];
        for ($i = 0; $i < 10 && count($got) < 2; $i++) {
            $job = $queue->pop();
            if ($job === null) {
                usleep(400000);
                continue;
            }
            $got[] = $job->payload['n'] ?? null;
            $job->complete();
        }

        $this->assertSame([1, 2], $got, 'the consumer stopped at the tombstone');
    }

    /**
     * A tombstone batched ahead of a record must not loop the fetch. The broker
     * returns the whole batch and the client decodes its first record, so the
     * tombstone can come back at an offset already passed; stepping over it must
     * then stop rather than fetch the same offset for ever. An alarm caps the
     * test so a regression fails here instead of hanging the suite.
     */
    public function testATombstoneThatCannotBeSteppedOverDoesNotSpin(): void
    {
        if (!function_exists('pcntl_alarm')) {
            $this->markTestSkipped('pcntl not loaded: cannot cap a possible infinite loop.');
        }
        $this->produceLines("k1,TOMBSTONE\nk2,{\"n\":1}\n");

        $queue = new Queue('kafka', [], $this->topic);
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function (): void {
            throw new \RuntimeException('pop() spun on the tombstone for 30s');
        });
        pcntl_alarm(30);
        try {
            $queue->pop();
            $queue->pop();
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);
        }
        $this->addToAssertionCount(1); // returned: no spin
    }

    /**
     * #264: push(raw=true) produces the value with NO Tina4 envelope and no
     * injected id, so a non-Tina4 consumer reads the exact top-level schema. Read
     * the raw bytes back with the low-level KafkaBackend::dequeue — what a foreign
     * consumer sees off the wire — and assert there is no payload/priority/
     * attempts/id wrapper around it.
     */
    public function testRawPushEmitsUnenvelopedValue(): void
    {
        $url = getenv('TINA4_TEST_KAFKA_URL');
        $schema = [
            'event_type' => 'order.created',
            'order_id' => 4242,
            'amount' => 9.99,
            'nonce' => bin2hex(random_bytes(4)),
        ];

        $queue = new Queue('kafka', [], $this->topic);
        $queue->push($schema, 0, 0, true);

        $reader = new \Tina4\Queue\KafkaBackend(['brokers' => $url]);
        $value = null;
        for ($i = 0; $i < 10; $i++) {
            $value = $reader->dequeue($this->topic);
            if ($value !== null) {
                break;
            }
            usleep(400000);
        }

        $this->assertIsArray($value, 'the raw record must be readable off the wire');
        $this->assertSame($schema, $value, 'a raw produce must carry the value exactly as given');
        $this->assertArrayNotHasKey('payload', $value, 'raw must omit the {payload,...} envelope');
        $this->assertArrayNotHasKey('id', $value, 'raw must not inject an id into the value');
    }

    /**
     * NEGATIVE: a push to an unreachable broker (a real closed port) RAISES; it
     * never returns an id as if the job had been accepted. The positive twin is
     * testRealProduceConsumeRoundTrip against the live broker.
     */
    public function testPushToAnUnreachableBrokerRaisesInsteadOfReportingSuccess(): void
    {
        $queue = new Queue('kafka', ['brokers' => '127.0.0.1:1'], $this->topic);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Kafka connection failed: could not connect to any broker in [127.0.0.1:1]');
        $queue->push(['task' => 'must-not-be-accepted']);
    }

    /**
     * TINA4_QUEUE_URL serves three backends with three URL schemes. An amqp://
     * URL exported for a RabbitMQ queue must not become a kafka queue's broker
     * list (it used to: host "amqp", connect failed). A kafka queue ignores a
     * URL whose scheme is not kafka:// and uses its own broker setting.
     */
    public function testAnAmqpQueueUrlIsNotUsedAsTheKafkaBrokerList(): void
    {
        $savedQueueUrl = getenv('TINA4_QUEUE_URL');
        $savedBrokers = getenv('TINA4_KAFKA_BROKERS');
        putenv('TINA4_QUEUE_URL=amqp://guest:rabbit-s3cret@127.0.0.1:5672/');
        putenv('TINA4_KAFKA_BROKERS');
        try {
            $queue = new Queue('kafka', [], $this->topic);
            $backend = (new \ReflectionProperty($queue, 'externalBackend'))->getValue($queue);
            // Reads the real backend's resolved broker list; substitutes nothing.
            $brokers = (new \ReflectionProperty($backend, 'brokers'))->getValue($backend);
            $this->assertSame('localhost:9092', $brokers, 'an amqp:// TINA4_QUEUE_URL became the kafka broker list');
        } finally {
            putenv($savedQueueUrl === false ? 'TINA4_QUEUE_URL' : "TINA4_QUEUE_URL={$savedQueueUrl}");
            putenv($savedBrokers === false ? 'TINA4_KAFKA_BROKERS' : "TINA4_KAFKA_BROKERS={$savedBrokers}");
        }
    }

    public function testAKafkaQueueUrlIsStillUsedWhenNoBrokersAreGiven(): void
    {
        $savedQueueUrl = getenv('TINA4_QUEUE_URL');
        $savedBrokers = getenv('TINA4_KAFKA_BROKERS');
        putenv('TINA4_QUEUE_URL=kafka://broker-a.example:9093,broker-b.example:9093');
        putenv('TINA4_KAFKA_BROKERS');
        try {
            $queue = new Queue('kafka', [], $this->topic);
            $backend = (new \ReflectionProperty($queue, 'externalBackend'))->getValue($queue);
            $brokers = (new \ReflectionProperty($backend, 'brokers'))->getValue($backend);
            $this->assertSame('broker-a.example:9093,broker-b.example:9093', $brokers);
        } finally {
            putenv($savedQueueUrl === false ? 'TINA4_QUEUE_URL' : "TINA4_QUEUE_URL={$savedQueueUrl}");
            putenv($savedBrokers === false ? 'TINA4_KAFKA_BROKERS' : "TINA4_KAFKA_BROKERS={$savedBrokers}");
        }
    }

    /**
     * NEGATIVE: popping a topic that does not exist yields null, not an exception.
     *
     * A real broker answers a fetch for an unknown topic with
     * UNKNOWN_TOPIC_OR_PARTITION (3), and auto-creation is asynchronous, so a
     * consumer that starts before its producer hits this on every cold start.
     * pop() is documented to return null when there is nothing to read, so error
     * code 3 must not surface as a RuntimeException.
     */
    public function testPopOnAnUnknownTopicReturnsNullAndDoesNotThrow(): void
    {
        $absent = 'tina4_absent_' . bin2hex(random_bytes(6));
        $queue = new Queue('kafka', [], $absent);

        $this->assertNull(
            $queue->pop(),
            'A fetch against a never-created topic must read as "nothing available".'
        );
    }
}
