<?php

/**
 * Tina4 - The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * QUEUE CONTRACT: TINA4_QUEUE_URL never overrides an explicit uri, and never
 * crosses backends.
 *
 * THE BUG THIS PINS, measured 2026-08-05 on the lab host (Ubuntu 24.04.4 LTS
 * x86_64, PHP 8.3.6, live MongoDB on 127.0.0.1:27017, live RabbitMQ on 5672). With
 * TINA4_QUEUE_URL=amqp://guest:guest@127.0.0.1:5672/tina4_php exported - the
 * ordinary shape for an app whose primary queue is RabbitMQ - constructing a
 * MongoDB queue in the same process died inside the driver with:
 *
 *     RuntimeException: MongoDB connection failed: Failed to parse MongoDB URI:
 *     'amqp://guest:guest@127.0.0.1:5672/tina4_php'. Invalid URI scheme
 *     "amqp://". Expected one of "mongodb://" or "mongodb+srv://".
 *
 * TWO defects produced it, both in Queue::resolveMongoConfig():
 *
 *   1. THE ENV BEAT AN EXPLICIT uri. `new Queue('mongodb', ['uri' => $mine])`
 *      was overwritten by TINA4_QUEUE_URL. Every other resolver in the same
 *      file has this the right way round - resolveRabbitMQConfig() does
 *      array_merge($parsed, $config), so $config wins - and every framework
 *      Tina4 competes with resolves explicit configuration over environment.
 *
 *   2. THE URL WAS APPLIED WITHOUT LOOKING AT ITS SCHEME. TINA4_QUEUE_URL is
 *      ONE variable serving three backends that speak three unrelated URL
 *      schemes; Queue.php's own docblock has always described it as the
 *      rabbitmq/kafka connection URL. An amqp:// URL is not a Mongo URI, so it
 *      now goes only to the backend whose scheme it names and MongoDB falls
 *      back to TINA4_MONGO_URI / TINA4_MONGO_HOST+PORT as documented.
 *
 * WHY THREE CASES. Case 1 and case 2 are both satisfied by deleting the
 * TINA4_QUEUE_URL support outright, which would silently break every app that
 * points a Mongo queue at it. Case 3 is the control that forbids that: a
 * mongodb:// URL in TINA4_QUEUE_URL must still be consumed, and it is proven by
 * pointing the FALLBACK at a REAL port nobody is listening on, so the round
 * trip can only succeed through the URL under test.
 *
 * NO MOCKS. Every case pushes and pops through a real MongoDB over the real
 * ext-mongodb driver, and every claim about where the document landed is
 * checked with an INDEPENDENT \MongoDB\Client, not by asking the queue. Each
 * case owns a throwaway, uniquely-named database that tearDown drops.
 *
 * PARITY: the same shape is present in the Python master
 * (tina4_python/queue/mongo_backend.py reads TINA4_QUEUE_URL unconditionally
 * and accepts no caller uri at all), and therefore in Ruby and Node. Reported
 * rather than fixed here - this repo is PHP.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Queue;

class QueueUrlPrecedenceTest extends TestCase
{
    private const COLLECTION = 'tina4_test_queue_url_precedence';
    private const TOPIC = 'tina4_test_queue_url_precedence_topic';

    /** An AMQP URL of exactly the shape the lab exports for the RabbitMQ suite. */
    private const FOREIGN_SCHEME_URL = 'amqp://guest:guest@127.0.0.1:5672/tina4_php';

    /** @var array<string, string|false> Pre-test value of every env var touched. */
    private array $originalEnv = [];

    private string $mongoUri = '';
    private string $databaseName = '';

    protected function setUp(): void
    {
        if (!extension_loaded('mongodb') || !class_exists('MongoDB\\Client')) {
            $this->skipOrFail('the mongo client (ext-mongodb / the mongodb library) is not installed');
        }

        $this->mongoUri = getenv('TINA4_MONGO_URI') ?: 'mongodb://127.0.0.1:27017';
        if (!$this->mongoReachable($this->mongoUri)) {
            $this->skipOrFail('mongo is not reachable at ' . $this->mongoUri);
        }

        $this->databaseName = 'tina4_test_queue_url_' . bin2hex(random_bytes(6));

        // Pin the WHOLE selection chain. A case that asserts which URL won must
        // own every variable that could decide it, or the ambient environment
        // silently answers instead - the lab exports TINA4_QUEUE_URL,
        // TINA4_MONGO_URI, TINA4_MONGO_DB and TINA4_QUEUE_PATH for real.
        $this->clearEnv('TINA4_QUEUE_BACKEND');
        $this->clearEnv('TINA4_QUEUE_URL');
        $this->clearEnv('TINA4_MONGO_URI');
        $this->clearEnv('TINA4_MONGO_DB');
        $this->clearEnv('TINA4_MONGO_COLLECTION');
        $this->clearEnv('TINA4_MONGO_HOST');
        $this->clearEnv('TINA4_MONGO_PORT');
    }

    protected function tearDown(): void
    {
        $this->dropDatabase();

        foreach ($this->originalEnv as $name => $value) {
            $value === false ? putenv($name) : putenv($name . '=' . $value);
        }
        $this->originalEnv = [];
    }

    // ---------------------------------------------------------------------
    // 1. POSITIVE: an explicit uri beats TINA4_QUEUE_URL
    // ---------------------------------------------------------------------

    public function testAnExplicitMongoUriBeatsTinaQueueUrl(): void
    {
        $this->setEnv('TINA4_QUEUE_URL', self::FOREIGN_SCHEME_URL);

        $queue = new Queue('mongodb', [
            'uri' => $this->mongoUri,
            'db' => $this->databaseName,
            'collection' => self::COLLECTION,
        ], self::TOPIC);

        $queue->push(['to' => 'a@example.com']);

        $job = $queue->pop();
        $this->assertNotNull(
            $job,
            'nothing came back off the queue, so the explicit uri never reached a real MongoDB'
        );
        $this->assertSame(['to' => 'a@example.com'], $job['payload']);

        // The witness: an INDEPENDENT client confirms the document really is in
        // the database the explicit uri names. Asking the queue where it wrote
        // would just be the code under test answering for itself.
        $this->assertGreaterThan(
            0,
            $this->independentCollection()->countDocuments(['topic' => self::TOPIC]),
            'the job is not in the database the explicit uri named - TINA4_QUEUE_URL won'
        );
    }

    // ---------------------------------------------------------------------
    // 2. THE MEASURED FAILURE: an amqp:// URL is not a Mongo URI
    //
    // No explicit uri here, so the resolver is on its own: it must leave the
    // RabbitMQ URL for RabbitMQ and let MongoBackend fall back to
    // TINA4_MONGO_URI. Before the fix this raised "Invalid URI scheme".
    // ---------------------------------------------------------------------

    public function testAnAmqpQueueUrlIsNeverHandedToTheMongoBackend(): void
    {
        $this->setEnv('TINA4_QUEUE_URL', self::FOREIGN_SCHEME_URL);
        $this->setEnv('TINA4_MONGO_URI', $this->mongoUri);

        $queue = new Queue('mongodb', [
            'db' => $this->databaseName,
            'collection' => self::COLLECTION,
        ], self::TOPIC);

        $queue->push(['to' => 'b@example.com']);

        $job = $queue->pop();
        $this->assertNotNull(
            $job,
            'nothing came back off the queue: the mongodb backend did not fall back to '
            . 'TINA4_MONGO_URI when TINA4_QUEUE_URL named another backend'
        );
        $this->assertSame(['to' => 'b@example.com'], $job['payload']);
        $this->assertGreaterThan(
            0,
            $this->independentCollection()->countDocuments(['topic' => self::TOPIC]),
            'the job is not in the database TINA4_MONGO_URI named'
        );
    }

    // ---------------------------------------------------------------------
    // 3. CONTROL: a mongodb:// TINA4_QUEUE_URL is STILL consumed
    //
    // Cases 1 and 2 are both satisfied by ignoring TINA4_QUEUE_URL altogether,
    // which would silently break every app that points a Mongo queue at it. The
    // FALLBACK is aimed at a REAL port nobody is listening on, so the round trip
    // below can only succeed through the URL this case is about.
    //
    // The fallback used here is TINA4_MONGO_HOST/PORT, deliberately NOT
    // TINA4_MONGO_URI: PHP resolves TINA4_QUEUE_URL ABOVE TINA4_MONGO_URI while
    // the Node master documents the opposite order (packages/core/src/
    // queueBackends/mongoBackend.ts: "TINA4_MONGO_URI (override; wins over
    // TINA4_QUEUE_URL)"). That divergence is older than this file and is
    // REPORTED rather than settled here, so this case gates only what every
    // framework already agrees on - the URL beats the host/port fields.
    // ---------------------------------------------------------------------

    public function testAMongodbQueueUrlIsStillHonouredByTheMongoBackend(): void
    {
        $deadPort = \FreePort::get();

        $this->setEnv('TINA4_QUEUE_URL', $this->mongoUri);
        $this->setEnv('TINA4_MONGO_HOST', '127.0.0.1');
        $this->setEnv('TINA4_MONGO_PORT', (string)$deadPort);

        $queue = new Queue('mongodb', [
            'db' => $this->databaseName,
            'collection' => self::COLLECTION,
        ], self::TOPIC);

        $queue->push(['to' => 'c@example.com']);

        $job = $queue->pop();
        $this->assertNotNull(
            $job,
            "nothing came back off the queue: TINA4_QUEUE_URL={$this->mongoUri} was ignored and "
            . "the backend fell through to the dead port {$deadPort}"
        );
        $this->assertSame(['to' => 'c@example.com'], $job['payload']);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** A live collection opened by an INDEPENDENT client, for the witness reads. */
    private function independentCollection(): \MongoDB\Collection
    {
        return (new \MongoDB\Client($this->mongoUri))
            ->selectCollection($this->databaseName, self::COLLECTION);
    }

    /** Never let cleanup mask a case's own result. */
    private function dropDatabase(): void
    {
        if ($this->databaseName === '' || !extension_loaded('mongodb')) {
            return;
        }
        try {
            (new \MongoDB\Client($this->mongoUri))->dropDatabase($this->databaseName);
        } catch (\Throwable) {
            // cleanup only
        }
    }

    /** True if a TCP connection to the Mongo host:port opens within ~1.5s. */
    private function mongoReachable(string $uri): bool
    {
        if (!preg_match('#^mongodb(\+srv)?://([^:/?]+)(?::(\d+))?#', $uri, $matches)) {
            return false;
        }
        $host = $matches[2] ?: 'localhost';
        $port = isset($matches[3]) && $matches[3] !== '' ? (int)$matches[3] : 27017;
        $socket = @fsockopen($host, $port, $errNo, $errStr, 1.5);
        if ($socket === false) {
            return false;
        }
        fclose($socket);
        return true;
    }

    /**
     * One place that decides skip-vs-fail, so the message always carries the
     * "mongo" + "not reachable"/"not installed" keywords the #252 service gate
     * looks for and a missing service is a FAILURE under TINA4_REQUIRE_SERVICES.
     */
    private function skipOrFail(string $message): void
    {
        if (getenv('TINA4_REQUIRE_SERVICES')) {
            $this->fail('TINA4_REQUIRE_SERVICES is set but ' . $message);
        }
        $this->markTestSkipped($message);
    }

    /** Record a variable's pre-test value once, then set it for this test. */
    private function setEnv(string $name, string $value): void
    {
        if (!array_key_exists($name, $this->originalEnv)) {
            $this->originalEnv[$name] = getenv($name);
        }
        putenv($name . '=' . $value);
    }

    /** Record a variable's pre-test value once, then remove it for this test. */
    private function clearEnv(string $name): void
    {
        if (!array_key_exists($name, $this->originalEnv)) {
            $this->originalEnv[$name] = getenv($name);
        }
        putenv($name);
    }
}
