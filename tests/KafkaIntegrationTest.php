<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
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

        $this->assertIsArray($msg, 'pop() must return the produced message from the real broker.');
        $body = $msg['payload'] ?? $msg;
        $this->assertSame('kafka-integration', $body['task'] ?? null, 'The produced task must round-trip.');
        $this->assertSame(98765, $body['value'] ?? null, 'The produced value must round-trip.');
        $this->assertSame($payload['nonce'], $body['nonce'] ?? null, 'The unique nonce must round-trip.');
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
