<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * MongoDB Queue Backend — uses ext-mongodb (MongoDB\Client).
 *
 * Environment variables:
 *   TINA4_MONGO_URI        — full MongoDB URI (overrides host/port/user/pass)
 *   TINA4_MONGO_HOST       — hostname (default: localhost)
 *   TINA4_MONGO_PORT       — port (default: 27017)
 *   TINA4_MONGO_USERNAME   — username (default: empty)
 *   TINA4_MONGO_PASSWORD   — password (default: empty)
 *   TINA4_MONGO_DB         — database name (default: tina4)
 *   TINA4_MONGO_COLLECTION — collection name (default: tina4_queue)
 */

namespace Tina4\Queue;

class MongoBackend implements QueueBackend
{
    private string $uri;
    private string $dbName;
    private string $collectionName;

    /**
     * Reservation/visibility timeout (seconds): a dequeued message is held
     * with available_at = now + timeout; reclaimExpired() returns it once that
     * passes (consumer died mid-flight). <= 0 disables the reclaim.
     */
    private float $visibilityTimeout;

    /** Max attempts before a reclaimed reservation is dead-lettered. */
    private int $maxRetries;

    /**
     * Seconds to delay a requeued (rejected/retried) job before it is eligible
     * again. 0 = available on the very next dequeue, so a fail()'d job retries
     * immediately (matching the file backend) instead of waiting out the
     * visibility window.
     */
    private float $retryBackoff;

    /** @var \MongoDB\Client|null */
    private $client = null;

    /** @var \MongoDB\Collection|null */
    private $collection = null;

    /** @var bool Whether indexes have been created */
    private bool $indexesCreated = false;

    /**
     * @param array $config Configuration overrides:
     *   'uri', 'host', 'port', 'username', 'password', 'db', 'collection'
     */
    public function __construct(array $config = [])
    {
        if (!extension_loaded('mongodb')) {
            throw new \RuntimeException('The mongodb extension is required for MongoBackend. Install it with: pecl install mongodb');
        }

        $uri = $config['uri'] ?? (getenv('TINA4_MONGO_URI') ?: '');

        if ($uri === '') {
            $host = $config['host'] ?? (getenv('TINA4_MONGO_HOST') ?: 'localhost');
            $port = (int)($config['port'] ?? (getenv('TINA4_MONGO_PORT') ?: 27017));
            $username = $config['username'] ?? (getenv('TINA4_MONGO_USERNAME') ?: '');
            $password = $config['password'] ?? (getenv('TINA4_MONGO_PASSWORD') ?: '');

            if ($username !== '' && $password !== '') {
                $uri = sprintf('mongodb://%s:%s@%s:%d', urlencode($username), urlencode($password), $host, $port);
            } else {
                $uri = sprintf('mongodb://%s:%d', $host, $port);
            }
        }

        $this->uri = $uri;
        $this->dbName = $config['db'] ?? (getenv('TINA4_MONGO_DB') ?: 'tina4');
        $this->collectionName = $config['collection'] ?? (getenv('TINA4_MONGO_COLLECTION') ?: 'tina4_queue');

        if (array_key_exists('visibility_timeout', $config)) {
            $this->visibilityTimeout = (float)$config['visibility_timeout'];
        } else {
            $env = getenv('TINA4_QUEUE_VISIBILITY_TIMEOUT');
            $this->visibilityTimeout = ($env !== false && $env !== '') ? (float)$env : 300.0;
        }
        $this->maxRetries = (int)($config['max_retries'] ?? 3);
        $this->retryBackoff = (float)($config['retry_backoff'] ?? 0);
    }

    /**
     * Connect to MongoDB and ensure indexes exist.
     *
     * @throws \RuntimeException If connection fails
     */
    public function connect(): void
    {
        try {
            $this->client = new \MongoDB\Client($this->uri);
            $this->collection = $this->client->selectCollection($this->dbName, $this->collectionName);
            $this->ensureIndexes();
        } catch (\Throwable $e) {
            throw new \RuntimeException("MongoDB connection failed: " . $e->getMessage());
        }
    }

    /** {@inheritDoc} */
    public function enqueue(string $topic, array $message): string
    {
        $this->ensureConnected();

        $id = $message['id'] ?? bin2hex(random_bytes(8));
        $message['id'] = $id;

        // A delayed job is simply one stamped available_at in the future —
        // dequeue() already filters on available_at <= now, so that is the whole
        // implementation. The delay_seconds key is the same one LiteBackend reads.
        $delaySeconds = (int)($message['delay_seconds'] ?? 0);

        $now = new \MongoDB\BSON\UTCDateTime();
        $document = [
            '_id' => $id,
            'topic' => $topic,
            'status' => 'pending',
            'message' => $message,
            'attempts' => (int)($message['attempts'] ?? 0),
            // Stored TOP-LEVEL so the dequeue sort can order on it. It also
            // lives inside 'message', but a sort cannot reach in there.
            'priority' => (int)($message['priority'] ?? 0),
            'available_at' => $delaySeconds > 0 ? $this->futureDate($delaySeconds) : $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->collection->insertOne($document);

        return $id;
    }

    /** {@inheritDoc} */
    public function dequeue(string $topic): ?array
    {
        $this->ensureConnected();

        // First return any reservations whose consumer died mid-flight.
        $this->reclaimExpired($topic, $this->maxRetries);

        $now = new \MongoDB\BSON\UTCDateTime();

        // The claim advances available_at to now + visibilityTimeout and records
        // reserved_at so reclaimExpired() can return the job if the consumer dies
        // before acknowledge()/requeue(). This is the fix for the "reserved
        // forever" bug — previously available_at was never set or advanced.
        $result = $this->collection->findOneAndUpdate(
            [
                'topic' => $topic,
                'status' => 'pending',
                'available_at' => ['$lte' => $now],
            ],
            [
                '$set' => [
                    'status' => 'processing',
                    'reserved_at' => $now,
                    'available_at' => $this->futureDate($this->visibilityTimeout),
                    'updated_at' => $now,
                ],
            ],
            [
                // Highest priority first, ties broken oldest-first — the same
                // ordering policy LiteBackend applies. Sorting on created_at
                // alone made the backend pure FIFO and silently ignored
                // priority, so an urgent job queued behind a backlog waited
                // for all of it.
                'sort' => ['priority' => -1, 'created_at' => 1],
                'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
            ]
        );

        if ($result === null) {
            return null;
        }

        // Surface the LIVE document-level attempts/priority, not the push-time
        // snapshot stored inside 'message'. reclaimExpired()/requeue() increment
        // the top-level 'attempts'; without this the consumer always saw the
        // original 0, so fail()'s attempts >= maxRetries check never tripped and
        // a job could be retried forever instead of dead-lettering (Bug C).
        return $this->docToJob((array)$result);
    }

    /**
     * Return reservations whose visibility window expired (at-least-once).
     *
     * A message left 'processing' with available_at <= now had a consumer die
     * before acknowledging. Each is atomically flipped back to 'pending' with
     * attempts incremented (so the next dequeue re-delivers it); once attempts
     * reach maxRetries it is dead-lettered (moved to topic.dead_letter and the
     * original removed). Returns the number reclaimed. Disabled when
     * visibilityTimeout <= 0.
     *
     * @param string $topic      The queue/topic name
     * @param int    $maxRetries Attempts at/after which a reclaim dead-letters
     * @return int Number of reservations reclaimed
     */
    public function reclaimExpired(string $topic, int $maxRetries): int
    {
        if ($this->visibilityTimeout <= 0) {
            return 0;
        }
        $this->ensureConnected();

        $reclaimed = 0;
        while (true) {
            $now = new \MongoDB\BSON\UTCDateTime();
            $result = $this->collection->findOneAndUpdate(
                [
                    'topic' => $topic,
                    'status' => 'processing',
                    'available_at' => ['$lte' => $now],
                ],
                [
                    '$set' => ['status' => 'pending', 'available_at' => $now, 'reserved_at' => null],
                    '$inc' => ['attempts' => 1],
                ],
                [
                    'sort' => ['available_at' => 1],
                    'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
                ]
            );
            if ($result === null) {
                break;
            }
            $reclaimed++;

            $doc = (array)$result;
            $attempts = (int)($doc['attempts'] ?? 0);
            if ($attempts >= $maxRetries) {
                // Out of retries — move it to the dead-letter queue and remove
                // the original so it is not re-delivered.
                $message = $this->bsonToArray($doc['message'] ?? []);
                $message['attempts'] = $attempts;
                $message['error'] = 'reservation timed out — consumer did not acknowledge within the visibility timeout';
                $this->deadLetter($topic, $message);
                $this->collection->deleteOne(['_id' => $doc['_id'], 'topic' => $topic]);
            }
        }

        return $reclaimed;
    }

    /** {@inheritDoc} */
    public function acknowledge(string $topic, string $messageId): void
    {
        $this->ensureConnected();

        $this->collection->deleteOne([
            '_id' => $messageId,
            'topic' => $topic,
        ]);
    }

    /** {@inheritDoc} */
    public function requeue(string $topic, array $message): void
    {
        $this->ensureConnected();

        $id = $message['id'] ?? '';

        if ($id !== '') {
            // Reset available_at so the requeued job is visible again right
            // away (or after retryBackoff) and clear reserved_at. dequeue()
            // pushed available_at out to the reservation expiry; leaving it
            // there stranded a fail()'d/retried job for the full visibility
            // window instead of retrying it on the next pop() (Bug B).
            $available = $this->retryBackoff > 0
                ? $this->futureDate($this->retryBackoff)
                : new \MongoDB\BSON\UTCDateTime();

            // Try to update existing document back to pending. Advance the
            // LIVE top-level attempts to the failing consumer's count (carried
            // on the message by Queue::failJob/retryJob). dequeue()/docToJob()
            // surface the top-level attempts, not the snapshot inside 'message'
            // (Bug C); without setting it here the surfaced attempts stayed at
            // the original 0 across every requeue, so fail()'s attempts >=
            // maxRetries check never tripped and a job retried forever instead
            // of dead-lettering. (A FakeMongoCollection masked this by scripting
            // canned attempts; the live Mongo dead-letter test catches it.)
            $attempts = (int)($message['attempts'] ?? 0);
            $result = $this->collection->updateOne(
                ['_id' => $id, 'topic' => $topic],
                [
                    '$set' => [
                        'status' => 'pending',
                        'message' => $message,
                        'attempts' => $attempts,
                        'available_at' => $available,
                        'reserved_at' => null,
                        'updated_at' => new \MongoDB\BSON\UTCDateTime(),
                    ],
                ]
            );

            if ($result->getModifiedCount() > 0) {
                return;
            }
        }

        // Fall back to inserting as a new message
        $this->enqueue($topic, $message);
    }

    /** {@inheritDoc} */
    public function deadLetter(string $topic, array $message): void
    {
        $this->ensureConnected();

        // A dead-letter is a SEPARATE record on the '<topic>.dead_letter' topic.
        // It must NOT reuse the failing job's id as its _id: the live original
        // (same id, original topic) is still present when the lifecycle moves a
        // job here (failJob/reclaimExpired insert the dead letter THEN delete the
        // original), so reusing the id raised a duplicate-key BulkWriteException
        // and the job never reached the dead-letter store. Give the dead-letter
        // doc a fresh _id but keep the original job id inside 'message' so
        // deadLetters()/retryFailed() still report and requeue by the real id.
        $now = new \MongoDB\BSON\UTCDateTime();
        $dlTopic = $topic . '.dead_letter';
        $this->collection->insertOne([
            '_id' => bin2hex(random_bytes(8)) . '-dl-' . ($message['id'] ?? bin2hex(random_bytes(4))),
            'topic' => $dlTopic,
            'status' => 'dead',
            'message' => $message,
            'attempts' => (int)($message['attempts'] ?? 0),
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** {@inheritDoc} */
    public function size(string $topic): int
    {
        $this->ensureConnected();

        return $this->collection->countDocuments([
            'topic' => $topic,
            'status' => 'pending',
        ]);
    }

    /**
     * Get jobs that have failed at least once but are still being retried.
     *
     * Mirrors LiteBackend::failed() — a failed-but-retryable job is still
     * pending (it gets re-enqueued on fail) with 0 < attempts < maxRetries.
     *
     * @param string $topic The queue/topic name
     * @return array<int, array> List of failed (retrying) job arrays
     */
    public function failed(string $topic): array
    {
        $this->ensureConnected();

        $cursor = $this->collection->find([
            'topic' => $topic,
            'status' => 'pending',
            'attempts' => ['$gt' => 0, '$lt' => $this->maxRetries],
        ]);

        $jobs = [];
        foreach ($cursor as $doc) {
            $jobs[] = $this->docToJob((array)$doc);
        }
        return $jobs;
    }

    /**
     * Get dead-letter jobs — failed jobs that exhausted their retries.
     *
     * Mongo dead letters live on the '<topic>.dead_letter' topic (written by
     * deadLetter()). Accepts the $maxRetries kwarg the Queue passes so the call
     * matches the LiteBackend contract — without it, queue.deadLetters() on a
     * Mongo-backed queue would mismatch the lite signature (Bug D).
     *
     * @param string   $topic      The queue/topic name
     * @param int|null $maxRetries Accepted for contract parity (dead letters are terminal)
     * @return array<int, array> List of dead job arrays
     */
    public function deadLetters(string $topic, ?int $maxRetries = null): array
    {
        $this->ensureConnected();

        $cursor = $this->collection->find(['topic' => $topic . '.dead_letter']);

        $jobs = [];
        foreach ($cursor as $doc) {
            $job = $this->docToJob((array)$doc);
            $job['status'] = 'dead';
            $jobs[] = $job;
        }
        return $jobs;
    }

    /**
     * Re-queue dead-letter jobs that are under the (possibly raised) limit back
     * to pending. Accepts the $maxRetries kwarg the Queue passes so the call
     * matches the LiteBackend contract (Bug D). Resets available_at so a revived
     * job is visible again right away (Bug B reason).
     *
     * @param string   $topic      The queue/topic name
     * @param int|null $maxRetries Override the threshold (defaults to the backend's)
     * @return int Number of jobs re-queued
     */
    public function retryFailed(string $topic, ?int $maxRetries = null): int
    {
        $this->ensureConnected();

        $limit = $maxRetries ?? $this->maxRetries;
        $dlTopic = $topic . '.dead_letter';
        $count = 0;

        $cursor = $this->collection->find([
            'topic' => $dlTopic,
            'attempts' => ['$lt' => $limit],
        ]);

        foreach ($cursor as $doc) {
            $doc = (array)$doc;
            $message = $this->bsonToArray($doc['message'] ?? []);
            $message['attempts'] = (int)($doc['attempts'] ?? 0);
            // requeue() resets available_at/reserved_at and writes to the live
            // topic, then we drop the dead-letter copy.
            $this->requeue($topic, $message);
            $this->collection->deleteOne(['_id' => $doc['_id'], 'topic' => $dlTopic]);
            $count++;
        }

        return $count;
    }

    /** {@inheritDoc} */
    public function clear(string $topic): int
    {
        $this->ensureConnected();
        $result = $this->collection->deleteMany(['topic' => $topic, 'status' => 'pending']);
        return $result->getDeletedCount();
    }

    /** {@inheritDoc} */
    public function purge(string $status, string $topic, ?int $maxRetries = null): int
    {
        $this->ensureConnected();
        $result = $this->collection->deleteMany(['topic' => $topic, 'status' => $status]);
        return $result->getDeletedCount();
    }

    /** {@inheritDoc} */
    public function popById(string $topic, string $id): ?array
    {
        $this->ensureConnected();
        $now = new \MongoDB\BSON\UTCDateTime();

        // Claim THIS id the same way dequeue() claims the head: flip it to
        // processing and stamp the reservation, so a crashed consumer's job is
        // still reclaimed by reclaimExpired().
        $doc = $this->collection->findOneAndUpdate(
            ['_id' => $id, 'topic' => $topic, 'status' => 'pending'],
            ['$set' => [
                'status' => 'processing',
                'reserved_at' => $now,
                'available_at' => $this->futureDate($this->visibilityTimeout),
                'updated_at' => $now,
            ]],
            ['returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
        );

        if ($doc === null) {
            return null;
        }

        return $this->docToJob((array)$doc);
    }

    /** {@inheritDoc} */
    public function close(): void
    {
        $this->client = null;
        $this->collection = null;
        $this->indexesCreated = false;
    }

    /**
     * Get the resolved reservation/visibility timeout in seconds.
     */
    public function getVisibilityTimeout(): float
    {
        return $this->visibilityTimeout;
    }

    // ── Internal Helpers ─────────────────────────────────────────

    /**
     * A UTCDateTime $seconds in the future (BSON stores ms since epoch).
     */
    private function futureDate(float $seconds): \MongoDB\BSON\UTCDateTime
    {
        return new \MongoDB\BSON\UTCDateTime((int)round((microtime(true) + $seconds) * 1000));
    }

    private function ensureConnected(): void
    {
        if ($this->collection === null) {
            $this->connect();
        }
    }

    /**
     * Create indexes for efficient queue operations.
     */
    private function ensureIndexes(): void
    {
        if ($this->indexesCreated) {
            return;
        }

        $this->collection->createIndex(
            ['topic' => 1, 'status' => 1, 'created_at' => 1],
            ['name' => 'queue_dequeue_idx']
        );

        $this->collection->createIndex(
            ['topic' => 1, 'status' => 1],
            ['name' => 'queue_size_idx']
        );

        $this->indexesCreated = true;
    }

    /**
     * Convert a stored Mongo queue document into a job array, surfacing the
     * LIVE top-level attempts/priority (not the push-time snapshot in 'message')
     * — the same correctness fix dequeue() applies (Bug C).
     *
     * @param array $doc Raw document (already cast to array)
     * @return array Job data
     */
    private function docToJob(array $doc): array
    {
        $message = $this->bsonToArray($doc['message'] ?? []);
        if (array_key_exists('attempts', $doc)) {
            $message['attempts'] = (int)$doc['attempts'];
        }
        if (array_key_exists('priority', $doc)) {
            $message['priority'] = $doc['priority'];
        }
        return $message;
    }

    /**
     * Convert BSON types back to plain PHP arrays.
     */
    private function bsonToArray(mixed $data): array
    {
        if ($data instanceof \MongoDB\Model\BSONDocument || $data instanceof \MongoDB\Model\BSONArray) {
            $data = (array)$data;
        }

        if (!is_array($data)) {
            return [];
        }

        $result = [];
        foreach ($data as $key => $value) {
            if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
                $result[$key] = $this->bsonToArray($value);
            } elseif ($value instanceof \MongoDB\BSON\UTCDateTime) {
                $result[$key] = (string)$value;
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
