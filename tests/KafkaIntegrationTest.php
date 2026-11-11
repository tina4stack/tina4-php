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

        // ONE pass, no retry after a null: a single-pass drain treats null as an
        // empty queue, so the tombstone must be stepped over within the pop.
        $queue = new Queue('kafka', [], $this->topic);
        $got = [];
        while (count($got) < 5 && ($job = $queue->pop()) !== null) {
            $got[] = $job->payload['n'] ?? null;
            $job->complete();
        }

        $this->assertSame([1, 2], $got, 'the consumer stopped at the tombstone');
    }

    /**
     * A tombstone batched ahead of a record must not loop the fetch. The broker
     * returns the whole batch and the client decodes only its first record, so
     * the tombstone comes back at an offset already passed; stepping over it
     * must then stop at once rather than fetch the same offset until the
     * deadline. (The record after it is still not delivered by this client:
     * that is the separate batch-decoding limit, not covered here.) An alarm
     * caps the test so a regression fails here instead of hanging the suite.
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
            $queue->pop(); // connects and reads the committed offset too
            $started = microtime(true);
            $queue->pop();
            $took = microtime(true) - $started;
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, SIG_DFL);
        }
        $this->assertLessThan(0.5, $took, sprintf('pop() re-fetched the same offset for %.2fs', $took));
    }

    /**
     * Past a tombstone the broker's long-poll wait is held to the deadline. The
     * fetch asks the broker to wait up to 5 s for data; after stepping past
     * tombstones to the end of the topic, the next fetch sat out the whole 5 s.
     */
    public function testPopKeepsItsDeadlineWhenTheTombstonesEndTheTopic(): void
    {
        $this->produceRecord('k0', '{"n":0}');
        $queue = new Queue('kafka', [], $this->topic);
        $warm = $queue->pop();
        $this->assertSame(0, $warm?->payload['n'] ?? null);
        $warm->complete();
        for ($i = 0; $i < 3; $i++) {
            $this->produceRecord('k', 'TOMBSTONE');
        }

        $started = microtime(true);
        $this->assertNull($queue->pop());
        $took = microtime(true) - $started;
        $this->assertLessThan(1.6, $took, sprintf('pop() took %.1fs past the tombstones (deadline ~1s)', $took));
    }

    /**
     * Capping the broker's wait past a tombstone must not turn it into no wait:
     * a record produced while the capped fetch waits is delivered by that same
     * pop(). A forked child produces it 0.4 s into the wait.
     */
    public function testPopStillWaitsOutItsDeadlineForARecordAfterTombstones(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl and posix needed to produce during the wait.');
        }
        $url = getenv('TINA4_TEST_KAFKA_URL');
        $this->produceRecord('k0', '{"n":0}');
        $this->produceRecord('k1', 'TOMBSTONE');
        $this->produceRecord('k1', 'TOMBSTONE');
        $backend = new \Tina4\Queue\KafkaBackend([
            'brokers' => $url, 'group_id' => 'tina4_php_tomb_wait_' . bin2hex(random_bytes(4)),
        ]);
        $this->assertSame(0, $backend->dequeueRecord($this->topic)['payload']['n'] ?? null);

        // Connected before the fork, so the child only has to send the record.
        $producer = new \Tina4\Queue\KafkaBackend(['brokers' => $url]);
        foreach (['ensureConnected' => [], 'ensureTopicMetadata' => [$this->topic]] as $name => $args) {
            $call = new \ReflectionMethod($producer, $name);
            $call->setAccessible(true);
            $call->invoke($producer, ...$args);
        }
        $produce = new \ReflectionMethod($producer, 'sendProduce');
        $produce->setAccessible(true);

        $child = pcntl_fork();
        $this->assertNotSame(-1, $child, 'fork failed');
        if ($child === 0) {
            try {
                usleep(400000);
                $produce->invoke($producer, $this->topic, 'k2', '{"n":"late"}');
            } finally {
                posix_kill(getmypid(), SIGKILL); // never return into PHPUnit in the child
            }
        }
        try {
            $started = microtime(true);
            $job = $backend->dequeueRecord($this->topic);
            $took = microtime(true) - $started;
        } finally {
            pcntl_waitpid($child, $status);
        }
        $this->assertSame('late', $job['payload']['n'] ?? null, 'the capped fetch did not wait for the record');
        $this->assertSame("{$this->topic}:0:3", $job['id'] ?? null);
        $this->assertLessThan(1.6, $took, sprintf('pop() took %.1fs (deadline ~1s)', $took));
        $offsets = new \ReflectionProperty($backend, 'offsets');
        $offsets->setAccessible(true);
        $this->assertSame(4, $offsets->getValue($backend)[$this->topic]);
    }

    /**
     * A backlog of skipped records longer than one deadline is cut off at it.
     * Each fetch of a skipped record returns at once, so only the deadline check
     * stops the loop: without it one pop() walked the whole backlog. The backlog
     * is empty values (skipped like tombstones) written one record per batch by
     * the backend's own producer, because the console producer is far too slow
     * to write thousands of single-record batches.
     */
    public function testPopKeepsItsDeadlineThroughABacklogOfSkippedRecords(): void
    {
        $url = getenv('TINA4_TEST_KAFKA_URL');
        $producer = new \Tina4\Queue\KafkaBackend(['brokers' => $url]);
        foreach (['ensureConnected' => [], 'ensureTopicMetadata' => [$this->topic]] as $name => $args) {
            $call = new \ReflectionMethod($producer, $name);
            $call->setAccessible(true);
            $call->invoke($producer, ...$args);
        }
        $produce = new \ReflectionMethod($producer, 'sendProduce');
        $produce->setAccessible(true);
        $total = 9000;
        for ($i = 0; $i < $total; $i++) {
            $produce->invoke($producer, $this->topic, 'k', '');
        }

        $backend = new \Tina4\Queue\KafkaBackend([
            'brokers' => $url, 'group_id' => 'tina4_php_tomb_backlog_' . bin2hex(random_bytes(4)),
        ]);
        $started = microtime(true);
        $this->assertNull($backend->dequeueRecord($this->topic));
        $took = microtime(true) - $started;
        $this->assertLessThan(1.6, $took, sprintf('pop() took %.1fs on a backlog (deadline ~1s)', $took));
        $offsets = new \ReflectionProperty($backend, 'offsets');
        $offsets->setAccessible(true);
        $this->assertLessThan($total, $offsets->getValue($backend)[$this->topic], 'one pop() walked the whole backlog');
    }

    /**
     * An empty value is stepped over like a null one; both used to stop the
     * consumer on the spot for ever.
     */
    public function testAnEmptyValueIsSteppedOver(): void
    {
        $this->produceRecord('k1', '');
        $this->produceRecord('k2', '{"n":2}');

        $job = (new Queue('kafka', [], $this->topic))->pop();
        $this->assertSame(2, $job?->payload['n'] ?? null, 'the consumer stopped at the empty value');
    }

    /**
     * pop() keeps its ~1 s deadline while a stream of tombstones arrives
     * (unbounded it ran as long as the stream, 14 s here).
     * Stepping over one went straight back to the fetch and never checked a
     * deadline, so one pop() blocked for as long as the tombstones kept coming.
     */
    public function testPopKeepsItsDeadlineUnderAStreamOfTombstones(): void
    {
        $this->produceRecord('k0', '{"n":0}');
        $queue = new Queue('kafka', [], $this->topic);
        $warm = $queue->pop();
        $this->assertSame(0, $warm?->payload['n'] ?? null);
        $warm->complete();

        // Tombstones for up to 60 s, one console-producer run each so every one
        // lands in its own batch (one producer reading stdin packs several per
        // batch, and the decoder reads only a batch's first record).
        $feeder = proc_open(sprintf(
            'end=$((SECONDS+60)); while [ $SECONDS -lt $end ]; do printf %%s k,TOMBSTONE | docker exec -i %s /opt/kafka/bin/kafka-console-producer.sh --bootstrap-server localhost:9092 --topic %s --property parse.key=true --property key.separator=, --property null.marker=TOMBSTONE >/dev/null 2>&1; done',
            escapeshellarg($this->container),
            escapeshellarg($this->topic)
        ), [], $pipes);
        try {
            // Start timing only once the first tombstone is on the topic: each
            // console-producer run starts a JVM, which can take seconds on a busy
            // runner, and a pop() on an empty topic waits out the broker's 5 s
            // long-poll on any code.
            $probe = new \Tina4\Queue\KafkaBackend(['brokers' => getenv('TINA4_TEST_KAFKA_URL')]);
            foreach (['ensureConnected' => [], 'ensureTopicMetadata' => [$this->topic]] as $name => $args) {
                $call = new \ReflectionMethod($probe, $name);
                $call->setAccessible(true);
                $call->invoke($probe, ...$args);
            }
            $fetch = new \ReflectionMethod($probe, 'sendFetch');
            $fetch->setAccessible(true);
            $waitUntil = microtime(true) + 45;
            while ($fetch->invoke($probe, $this->topic, 1, 200) === null) {
                $this->assertLessThan($waitUntil, microtime(true), 'no tombstone reached the topic in 45 s');
            }
            $started = microtime(true);
            $job = $queue->pop();
            $took = microtime(true) - $started;
            $this->assertNull($job);
            $this->assertLessThan(2.5, $took, sprintf('pop() took %.1fs under a tombstone stream (deadline ~1s)', $took));
        } finally {
            proc_terminate($feeder);
            proc_close($feeder);
        }
    }

    /**
     * Nothing commits on the tombstone path; acknowledging the record after it
     * commits past both, so a restarted consumer in the same group neither stops
     * at the tombstone nor redelivers anything.
     */
    public function testAFreshProcessGetsPastATombstoneThroughTheNormalAcknowledge(): void
    {
        $url = getenv('TINA4_TEST_KAFKA_URL');
        $group = 'tina4_php_tomb_restart_' . bin2hex(random_bytes(4));
        // One record per batch: the decoder reads only a batch's first record.
        $this->produceRecord('k1', '{"n":1}');
        $this->produceRecord('k1', 'TOMBSTONE');
        $this->produceRecord('k2', '{"n":2}');
        $committed = function () use ($url, $group): int {
            $probe = new \Tina4\Queue\KafkaBackend(['brokers' => $url, 'group_id' => $group]);
            $connect = new \ReflectionMethod($probe, 'ensureConnected');
            $connect->setAccessible(true);
            $connect->invoke($probe);
            $read = new \ReflectionMethod($probe, 'fetchCommittedOffset');
            $read->setAccessible(true);
            return $read->invoke($probe, $this->topic, 0);
        };

        $first = new \Tina4\Queue\KafkaBackend(['brokers' => $url, 'group_id' => $group]);
        $job = $first->dequeueRecord($this->topic);
        $this->assertSame(1, $job['payload']['n'] ?? null);
        $first->acknowledge($this->topic, (string) $job['id']);
        $this->assertSame(1, $committed(), 'the ack did not commit up to the tombstone');

        $second = new \Tina4\Queue\KafkaBackend(['brokers' => $url, 'group_id' => $group]);
        $job = $second->dequeueRecord($this->topic);
        $this->assertSame(2, $job['payload']['n'] ?? null, 'a restart did not get past the tombstone');
        $second->acknowledge($this->topic, (string) $job['id']);
        $this->assertSame(3, $committed());

        $third = new \Tina4\Queue\KafkaBackend(['brokers' => $url, 'group_id' => $group]);
        $this->assertNull($third->dequeueRecord($this->topic));
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
