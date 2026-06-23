<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Dead-letter + retryFailed end-to-end through the Queue facade against a LIVE
 * MongoDB (no mocks). Mirrors the Python master's TestMongoQueueDeadLetterLive
 * (tina4-python/tests/test_queue_backends.py).
 *
 * This replaces an earlier set of cases in QueueBackendTest that injected an
 * in-test fake collection onto the adapter — a test double standing in for the
 * real dependency, which the project's no-mock rule forbids and which never
 * exercised real Mongo (the kind of gap that let the queue bugs ship). The
 * maxRetries-kwarg contract is still locked mock-free by
 * QueueBackendTest::testMongoQueueDeadLettersRetryFailedSignaturesMatchLite;
 * THIS test proves the actual behaviour against a real broker. It uses a
 * THROWAWAY, framework-namespaced database (tina4_test_queue_php) and DROPS it
 * on teardown, so it never touches application data.
 *
 * Gated on a reachable Mongo (localhost:27017) + the client library being
 * present. The skip reason contains "mongo" + "not reachable"/"not installed"
 * so the #252 service gate treats an unavailable Mongo as a failure under
 * TINA4_REQUIRE_SERVICES.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Queue;
use Tina4\Job;

class MongoQueueDeadLetterLiveTest extends TestCase
{
    private const TOPIC = 'tina4_test_dead_letter';
    private const DB_NAME = 'tina4_test_queue_php';
    private const COLLECTION = 'tina4_test_queue_jobs';

    private string $mongoUri = '';
    private ?Queue $queue = null;

    /**
     * Skip cleanly (never fake) when ext-mongodb / the mongodb library is
     * missing or Mongo is not listening on localhost:27017.
     */
    protected function setUp(): void
    {
        if (!extension_loaded('mongodb') || !class_exists('MongoDB\\Client')) {
            $this->markTestSkipped('mongo client (ext-mongodb / mongodb library) not installed — skipping live Mongo queue dead-letter test');
        }

        $this->mongoUri = getenv('TINA4_MONGO_URI') ?: 'mongodb://localhost:27017';
        if (!$this->mongoReachable($this->mongoUri)) {
            $this->markTestSkipped('mongo not reachable at ' . $this->mongoUri . ' — skipping live Mongo queue dead-letter test');
        }

        // Point the Mongo queue backend at a throwaway, namespaced database.
        $config = [
            'uri' => $this->mongoUri,
            'db' => self::DB_NAME,
            'collection' => self::COLLECTION,
            'maxRetries' => 2,
        ];
        $this->queue = new Queue('mongodb', $config, self::TOPIC);

        // Pristine slate, even if a prior run left state behind.
        $coll = $this->liveCollection();
        $coll->deleteMany(['topic' => self::TOPIC]);
        $coll->deleteMany(['topic' => self::TOPIC . '.dead_letter']);
    }

    protected function tearDown(): void
    {
        if ($this->queue === null) {
            return;
        }
        try {
            $client = new \MongoDB\Client($this->mongoUri);
            $client->dropDatabase(self::DB_NAME);
        } catch (\Throwable $e) {
            // best-effort cleanup
        }
        $this->queue = null;
    }

    public function testFailPastMaxRetriesLandsInDeadLetters(): void
    {
        // push -> pop+fail until attempts hit maxRetries (2) -> the job moves to
        // the dead-letter store and is gone from pending. Real Mongo, no mocks.
        $this->queue->push(['to' => 'a@example.com']);

        for ($i = 0; $i < 5; $i++) {            // bounded; converges in 2 cycles
            $job = $this->queue->pop();
            if ($job === null) {
                break;
            }
            $wrapped = new Job($job, $this->queue, self::TOPIC);
            $wrapped->fail('smtp 550');
            if (!empty($this->queue->deadLetters())) {
                break;
            }
        }

        $dead = $this->queue->deadLetters();
        $this->assertCount(1, $dead);
        $this->assertSame('smtp 550', $dead[0]['error']);   // failure reason survives to the DLQ
        $this->assertSame(0, $this->queue->size());          // original no longer pending
    }

    public function testRetryFailedRequeuesFailedJobs(): void
    {
        // retryFailed() re-queues dead-letter docs (attempts < the raised limit)
        // back to pending against a real collection. The auto-retry fail() path
        // never leaves a job in the dead-letter store under the limit, so seed
        // ONE real failed document directly in the live dead-letter topic — real
        // data, not a mock — to exercise the real find + requeue.
        // Mirror the real dead-letter doc shape the backend writes: a SEPARATE
        // record on the '<topic>.dead_letter' topic whose _id differs from the
        // original job id (carried inside 'message'). retryFailed() requeues by
        // message.id onto the live topic, then drops this dead-letter copy.
        $coll = $this->liveCollection();
        $now = new \MongoDB\BSON\UTCDateTime();
        $coll->insertOne([
            '_id' => 'dl-tina4-test-failed-1',
            'topic' => self::TOPIC . '.dead_letter',
            'status' => 'dead',
            'attempts' => 1,
            'message' => ['id' => 'tina4-test-failed-1', 'payload' => ['x' => 1]],
            'error' => 'boom',
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $n = $this->queue->retryFailed(3);
        $this->assertSame(1, $n);

        // The job is back on the live topic as pending, and the dead-letter copy
        // is gone.
        $this->assertSame(1, $this->queue->size());          // requeued -> pending
        $this->assertSame(0, $coll->countDocuments(['topic' => self::TOPIC . '.dead_letter']));
        $pending = $coll->findOne(['topic' => self::TOPIC, 'status' => 'pending']);
        $this->assertNotNull($pending);
    }

    /** Live \MongoDB\Collection for the throwaway namespaced db (seed/assert). */
    private function liveCollection(): \MongoDB\Collection
    {
        $client = new \MongoDB\Client($this->mongoUri);
        return $client->selectCollection(self::DB_NAME, self::COLLECTION);
    }

    /** True if a TCP connection to the Mongo host:port opens within ~1.5s. */
    private function mongoReachable(string $uri): bool
    {
        if (!preg_match('#^mongodb(\+srv)?://([^:/?]+)(?::(\d+))?#', $uri, $m)) {
            return false;
        }
        $host = $m[2] ?: 'localhost';
        $port = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : 27017;
        $sock = @fsockopen($host, $port, $errno, $errstr, 1.5);
        if ($sock === false) {
            return false;
        }
        fclose($sock);
        return true;
    }
}
