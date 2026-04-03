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

        $document = [
            '_id' => $id,
            'topic' => $topic,
            'status' => 'pending',
            'message' => $message,
            'created_at' => new \MongoDB\BSON\UTCDateTime(),
            'updated_at' => new \MongoDB\BSON\UTCDateTime(),
        ];

        $this->collection->insertOne($document);

        return $id;
    }

    /** {@inheritDoc} */
    public function dequeue(string $topic): ?array
    {
        $this->ensureConnected();

        $result = $this->collection->findOneAndUpdate(
            [
                'topic' => $topic,
                'status' => 'pending',
            ],
            [
                '$set' => [
                    'status' => 'processing',
                    'updated_at' => new \MongoDB\BSON\UTCDateTime(),
                ],
            ],
            [
                'sort' => ['created_at' => 1],
                'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
            ]
        );

        if ($result === null) {
            return null;
        }

        $doc = (array)$result;
        return $this->bsonToArray($doc['message'] ?? []);
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
            // Try to update existing document back to pending
            $result = $this->collection->updateOne(
                ['_id' => $id, 'topic' => $topic],
                [
                    '$set' => [
                        'status' => 'pending',
                        'message' => $message,
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
        $this->enqueue($topic . '.dead_letter', $message);
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

    /** {@inheritDoc} */
    public function close(): void
    {
        $this->client = null;
        $this->collection = null;
        $this->indexesCreated = false;
    }

    // ── Internal Helpers ─────────────────────────────────────────

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
