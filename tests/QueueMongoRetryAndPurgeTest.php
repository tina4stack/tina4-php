<?php

/**
 * Tina4 - The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Regression: MongoDB Queue::retry($id) revives a dead letter, and
 * Queue::purge($status) returns a real deleted count for every status.
 *
 * MongoDB retry_job/purge (3.13.105). Two definite bugs in the MongoDB backend
 * before this release:
 *
 *   * retry(id) fetched deadLetters() and requeued the matching one but never
 *     deleted the dead-letter record — so the next deadLetters() call reported
 *     the same job again and a caller processing both lists processed it twice.
 *     Python's equivalent search filter was
 *     {_id: id, topic: self._topic, status: "failed"} which never matched a
 *     dead letter (fresh _id, .dead_letter topic, status "dead"), so retry_job
 *     returned False for every id.
 *
 *   * purge($status) called delete_many({topic, status}) unconditionally, so
 *     purge('dead') / purge('failed') / purge('dead_letter') never matched a
 *     real dead letter (which lives under the "{topic}.dead_letter" namespace).
 *
 * Named positive AND negative cases below; each proven a real gate by
 * mutation of the fix.
 *
 * NOT a mock: real live MongoDB. Skipped when unreachable; the lab provisions
 * Mongo on 127.0.0.1:27017 and this suite runs there under
 * TINA4_REQUIRE_SERVICES=1.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Job;
use Tina4\Queue;

class QueueMongoRetryAndPurgeTest extends TestCase
{
    private const DB_NAME = 'tina4_test_queue_php_retry_purge';
    private const COLLECTION = 'tina4_test_queue_jobs';

    private string $mongoUri = '';
    private string $topic = '';
    private ?Queue $queue = null;

    protected function setUp(): void
    {
        if (!extension_loaded('mongodb') || !class_exists('MongoDB\\Client')) {
            $this->markTestSkipped(
                'mongo client (ext-mongodb / mongodb library) not installed — '
                . 'skipping live Mongo retry/purge test'
            );
        }

        $this->mongoUri = getenv('TINA4_MONGO_URI') ?: 'mongodb://127.0.0.1:27017';
        if (!$this->mongoReachable($this->mongoUri)) {
            if (getenv('TINA4_REQUIRE_SERVICES')) {
                $this->fail(
                    'TINA4_REQUIRE_SERVICES is set but MongoDB is not reachable at '
                    . $this->mongoUri
                );
            }
            $this->markTestSkipped(
                'mongo not reachable at ' . $this->mongoUri
                . ' — skipping live Mongo retry/purge test'
            );
        }

        putenv('TINA4_QUEUE_BACKEND');
        $this->topic = 'mongo_retry_purge_' . bin2hex(random_bytes(5));

        $this->queue = new Queue(
            'mongodb',
            [
                'uri' => $this->mongoUri,
                'db' => self::DB_NAME,
                'collection' => self::COLLECTION,
                'maxRetries' => 1,
            ],
            $this->topic
        );

        // Pristine slate for this run.
        $coll = $this->liveCollection();
        $coll->deleteMany(['topic' => $this->topic]);
        $coll->deleteMany(['topic' => $this->topic . '.dead_letter']);
    }

    protected function tearDown(): void
    {
        if ($this->queue !== null) {
            try {
                $coll = $this->liveCollection();
                $coll->deleteMany(['topic' => $this->topic]);
                $coll->deleteMany(['topic' => $this->topic . '.dead_letter']);
                $this->queue->close();
            } catch (\Throwable) {
                // best-effort cleanup
            }
        }
        $this->queue = null;
    }

    /**
     * Push one job, fail it enough times to dead-letter, return its id.
     * Does NOT assert the total dead-letter count — callers stack this to
     * build up N dead letters.
     */
    private function deadLetterOne(): string
    {
        $prior = count($this->queue->deadLetters());
        $jobId = $this->queue->push(['task' => 'doomed']);

        // maxRetries = 1 → the FIRST fail() dead-letters (attempts becomes 1
        // inside failJob's ">= maxRetries" check).
        for ($i = 0; $i < 3; $i++) {
            $data = $this->queue->pop();
            if ($data === null) {
                break;
            }
            $wrapped = new Job($data, $this->queue, $this->topic);
            $wrapped->fail('boom');
            if (count($this->queue->deadLetters()) > $prior) {
                break;
            }
        }

        $this->assertSame(
            $prior + 1,
            count($this->queue->deadLetters()),
            'prime failed: deadLetters grew by '
            . (count($this->queue->deadLetters()) - $prior) . ', expected +1'
        );
        return $jobId;
    }

    public function testPositiveRetryJobRevivesDeadLetter(): void
    {
        // retry(id) on a genuinely dead-lettered job returns true and puts the
        // job back in pending; a re-pop must see it and the dead-letter store
        // must be empty.
        $jobId = $this->deadLetterOne();

        $this->assertTrue(
            $this->queue->retry($jobId),
            'retry(id) must revive an existing dead letter; before 3.13.105 the '
            . 'PHP Mongo path only requeued without deleting the dead-letter '
            . 'doc, so a follow-up deadLetters() saw the same job'
        );
        $this->assertCount(
            0,
            $this->queue->deadLetters(),
            'the dead-letter store must be empty after a successful revival; '
            . 'leftovers cause double-processing'
        );
        $this->assertSame(
            1,
            $this->queue->size('pending'),
            'the revived job must be visible to pop() as pending'
        );
        $revived = $this->queue->pop();
        $this->assertNotNull($revived);
        $this->assertSame(
            $jobId,
            $revived->id,
            'revived job\'s id must match the original (payload continuity)'
        );
    }

    public function testNegativeRetryJobReturnsFalseForUnknownId(): void
    {
        // retry on an id that never existed must return false and not create
        // ghost pending docs.
        $this->assertFalse(
            $this->queue->retry('does-not-exist-' . bin2hex(random_bytes(6))),
            'retry(id) must return false when no dead letter matches'
        );
        $this->assertSame(0, $this->queue->size('pending'));
        $this->assertCount(0, $this->queue->deadLetters());
    }

    public function testPositivePurgePendingReturnsDeletedCount(): void
    {
        // purge('pending') deletes only pending docs under the topic and
        // returns the deleted count (pre-3.13.105 returned None/null-equivalent
        // in Python; in PHP already returned an int, but the scoping is what
        // matters).
        $this->queue->push(['n' => 1]);
        $this->queue->push(['n' => 2]);
        $this->queue->push(['n' => 3]);
        $this->assertSame(3, $this->queue->size('pending'));

        $removed = $this->queue->purge('pending');

        $this->assertSame(
            3,
            $removed,
            'purge(\'pending\') must return the deleted count, got '
            . var_export($removed, true)
        );
        $this->assertSame(0, $this->queue->size('pending'));
    }

    public function testNegativePurgePendingLeavesDeadLettersAlone(): void
    {
        // purge('pending') MUST NOT touch dead letters (pre-3.13.105 the Mongo
        // purge was topic+status scoped but did not route dead statuses to the
        // .dead_letter namespace, so purge('dead') never matched a real dead
        // letter — meanwhile purge('pending') correctly stayed on the topic).
        $this->deadLetterOne();               // 1 dead letter
        $this->queue->push(['n' => 'keep-pending']);
        $this->assertSame(1, $this->queue->size('pending'));
        $this->assertCount(1, $this->queue->deadLetters());

        $this->queue->purge('pending');

        $this->assertSame(0, $this->queue->size('pending'), 'purge should have removed the pending');
        $this->assertCount(
            1,
            $this->queue->deadLetters(),
            'purge(\'pending\') removed the dead letter — purge must scope by '
            . 'status, never nuke the whole topic'
        );
    }

    public function testPositivePurgeDeadRemovesDeadLetters(): void
    {
        // purge('dead') must remove dead letters (routed to the .dead_letter
        // topic namespace) and return the count. Pre-3.13.105 the Mongo purge
        // took {topic, status} literally, so it never matched a real dead
        // letter and returned 0.
        $this->deadLetterOne();
        $this->deadLetterOne();
        $this->assertCount(2, $this->queue->deadLetters());

        $removed = $this->queue->purge('dead');

        $this->assertSame(
            2,
            $removed,
            'purge(\'dead\') must return the deleted dead-letter count, got '
            . var_export($removed, true)
        );
        $this->assertCount(0, $this->queue->deadLetters());
    }

    private function liveCollection(): \MongoDB\Collection
    {
        $client = new \MongoDB\Client($this->mongoUri);
        return $client->selectCollection(self::DB_NAME, self::COLLECTION);
    }

    private function mongoReachable(string $uri): bool
    {
        if (!preg_match('#^mongodb(\+srv)?://([^:/?]+)(?::(\d+))?#', $uri, $m)) {
            return false;
        }
        $host = $m[2] ?: '127.0.0.1';
        $port = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : 27017;
        $sock = @fsockopen($host, $port, $errno, $errstr, 1.5);
        if ($sock === false) {
            return false;
        }
        fclose($sock);
        return true;
    }
}
