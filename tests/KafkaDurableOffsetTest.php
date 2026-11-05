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
 * Live Kafka durable-offset test (no mocks) — ADR-0022 decisions 2/3/4 and
 * tina4#274. Proves the hand-rolled consumer now commits consumer-group offsets
 * (OffsetCommit/OffsetFetch via FindCoordinator) so that:
 *   - a job that was completed is NEVER re-delivered after a restart (decision 4);
 *   - a job that was NOT acknowledged IS re-delivered after a restart (the
 *     negative control — proves it is the durable COMMIT, not the in-memory read
 *     cursor, that prevents the replay);
 *   - an externally-produced record (no Tina4 envelope, no top-level id — the
 *     Debezium/CDC case from #274) is consumable at all AND commits durably, its
 *     payload delivered byte-for-byte with the record coordinate as its id.
 *
 * A fresh KafkaBackend instance per "restart" stands in for a new process: the
 * read cursor resets with the instance, but the committed offset lives on the
 * broker, so resume is real. Gated on TINA4_TEST_KAFKA_URL exactly like
 * KafkaIntegrationTest; skips cleanly (reason recognised by the
 * TINA4_REQUIRE_SERVICES gate) when no broker is available.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Queue;
use Tina4\Queue\KafkaBackend;

class KafkaDurableOffsetTest extends TestCase
{
    private string $topic = '';
    private string $container = '';
    private string $group = '';
    private ?string $savedGroup = null;

    /** @return array{host:string,port:int} first broker from a comma list. */
    private static function firstBroker(string $url): array
    {
        $first = preg_replace('#^kafka://#', '', explode(',', $url)[0]);
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
            $this->markTestSkipped('TINA4_TEST_KAFKA_URL not set — kafka broker not available for the live durable-offset test.');
        }

        $broker = self::firstBroker($url);
        if (!self::reachable($broker['host'], $broker['port'])) {
            $this->markTestSkipped('kafka broker not reachable at ' . $broker['host'] . ':' . $broker['port'] . '.');
        }

        putenv('TINA4_KAFKA_BROKERS=' . $url);
        $_ENV['TINA4_KAFKA_BROKERS'] = $url;
        $_SERVER['TINA4_KAFKA_BROKERS'] = $url;

        // A unique consumer group per test, so a prior test's committed offsets
        // never bleed in. Restored in tearDown.
        $this->savedGroup = getenv('TINA4_KAFKA_GROUP_ID') ?: null;
        $this->group = 'tina4_durable_grp_' . bin2hex(random_bytes(6));
        putenv('TINA4_KAFKA_GROUP_ID=' . $this->group);

        $this->container = getenv('TINA4_TEST_KAFKA_CONTAINER') ?: 'tina4-kafka';
        $this->topic = 'tina4_durable_' . bin2hex(random_bytes(6));

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
        putenv($this->savedGroup === null ? 'TINA4_KAFKA_GROUP_ID' : 'TINA4_KAFKA_GROUP_ID=' . $this->savedGroup);
    }

    /** Pop one job, waiting briefly for the broker to surface it. */
    private function popWithWait(Queue $queue, int $tries = 15): ?\Tina4\Job
    {
        for ($i = 0; $i < $tries; $i++) {
            $job = $queue->pop();
            if ($job !== null) {
                return $job;
            }
            usleep(300000);
        }
        return null;
    }

    /**
     * ADR-0022 decision 4: a completed job is never re-delivered, including
     * across a restart. Produce three, consume + complete them with one Queue,
     * then resume with a FRESH Queue in the same group — it must read nothing.
     */
    public function testCompletedJobsDoNotReplayAcrossRestart(): void
    {
        $producer = new Queue('kafka', [], $this->topic);
        for ($i = 1; $i <= 3; $i++) {
            $producer->push(['n' => $i, 'nonce' => bin2hex(random_bytes(4))]);
        }

        $consumer = new Queue('kafka', [], $this->topic);
        $completed = 0;
        for ($i = 0; $i < 3; $i++) {
            $job = $this->popWithWait($consumer);
            $this->assertInstanceOf(\Tina4\Job::class, $job, 'each produced job must be consumable');
            $job->complete();
            $completed++;
        }
        $this->assertSame(3, $completed, 'all three jobs must be consumed and completed');

        // Restart: a new instance resets the in-memory cursor; only the durable
        // commit decides where it resumes.
        $afterRestart = new Queue('kafka', [], $this->topic);
        $replay = $this->popWithWait($afterRestart, 6);
        $this->assertNull(
            $replay,
            'a completed job must NOT be re-delivered after a restart (offset was committed)'
        );
    }

    /**
     * The negative control: a job popped but NOT acknowledged MUST be
     * re-delivered after a restart. Without it, testCompletedJobsDoNotReplay
     * could pass simply because the broker had nothing to give — this proves the
     * committed offset, not an empty topic, is what suppresses the replay.
     */
    public function testUnacknowledgedJobReplaysAcrossRestart(): void
    {
        $nonce = bin2hex(random_bytes(6));
        (new Queue('kafka', [], $this->topic))->push(['keep' => true, 'nonce' => $nonce]);

        // Consume it but deliberately never complete()/fail() — no commit.
        $first = new Queue('kafka', [], $this->topic);
        $job = $this->popWithWait($first);
        $this->assertInstanceOf(\Tina4\Job::class, $job, 'the record must be consumable');
        $this->assertSame($nonce, ($job['payload']['nonce'] ?? null), 'sanity: the right record');

        // Restart WITHOUT having acknowledged: the broker still has no committed
        // offset, so the same record is delivered again.
        $second = new Queue('kafka', [], $this->topic);
        $again = $this->popWithWait($second);
        $this->assertInstanceOf(\Tina4\Job::class, $again, 'an un-acknowledged record must replay after restart');
        $this->assertSame(
            $nonce,
            ($again['payload']['nonce'] ?? null),
            'the replayed record must be the same un-acknowledged one'
        );
    }

    /**
     * tina4#274: an externally-produced record (no Tina4 envelope, hence no
     * top-level id) must be consumable — Job requires an id, so a bare value used
     * to crash pop() — its payload delivered byte-for-byte, and its offset
     * committed durably on complete() so it does not replay after a restart.
     */
    public function testExternalRecordIsConsumableAndCommitsDurably(): void
    {
        $external = ['source' => 'debezium', 'op' => 'c', 'row' => ['id' => 42, 'name' => 'ext']];

        // raw=true => produced with NO Tina4 envelope, as a foreign producer would.
        (new Queue('kafka', [], $this->topic))->push($external, 0, 0, true);

        $consumer = new Queue('kafka', [], $this->topic);
        $job = $this->popWithWait($consumer);
        $this->assertInstanceOf(\Tina4\Job::class, $job, 'an external record must be consumable (not a crash)');
        $this->assertSame(
            $external,
            $job['payload'],
            'the external payload must be delivered byte-for-byte, with no envelope leak'
        );
        $this->assertStringContainsString(
            $this->topic . ':0:',
            (string) $job->id,
            'an id-less external record takes its topic:partition:offset coordinate as its id'
        );
        $job->complete();

        $afterRestart = new Queue('kafka', [], $this->topic);
        $this->assertNull(
            $this->popWithWait($afterRestart, 6),
            'the completed external record must not replay after a restart'
        );
    }
}
