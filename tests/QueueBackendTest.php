<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Queue\QueueBackend;
use Tina4\Queue\RabbitMQBackend;
use Tina4\Queue\KafkaBackend;
use Tina4\Queue\MongoBackend;

class QueueBackendTest extends TestCase
{
    use MissingExtensionCase;

    // -- Interface contract (ONE consolidated rename guard) ------------------
    //
    // The Queue facade calls a fixed method set on whatever backend is active;
    // if a connector drops or renames one this must fail loudly HERE (cheap,
    // infra-free) rather than at runtime against a live broker. This single
    // loop replaces the ~26 per-method existence / parameter-count / return-type
    // smoke tests that used to live here — the real BEHAVIOUR of these methods
    // is proven by the live RabbitMQ / Kafka / Mongo tests further down. Mirrors
    // tina4_python TestQueueBackendContract.

    /** Every backend must implement the QueueBackend interface contract. */
    private const BACKEND_CLASSES = [
        'rabbitmq' => RabbitMQBackend::class,
        'kafka' => KafkaBackend::class,
        'mongodb' => MongoBackend::class,
    ];

    /** Methods the Queue facade calls on every active backend. */
    private const REQUIRED_METHODS = [
        'enqueue', 'dequeue', 'acknowledge', 'requeue',
        'deadLetter', 'size', 'close',
    ];

    public function testQueueBackendInterfaceContract(): void
    {
        $this->assertTrue(interface_exists(QueueBackend::class));

        // The interface itself declares every required method with the exact
        // arity the facade relies on (enqueue/acknowledge/requeue/deadLetter take
        // 2, dequeue/size take 1, close takes 0) and the return-type contract
        // (enqueue->string, dequeue nullable, size->int, close->void).
        $ref = new \ReflectionClass(QueueBackend::class);
        $expected = [
            'enqueue' => ['params' => 2, 'return' => 'string', 'nullable' => false],
            'dequeue' => ['params' => 1, 'return' => 'array', 'nullable' => true],
            'acknowledge' => ['params' => 2, 'return' => 'void', 'nullable' => false],
            'requeue' => ['params' => 2, 'return' => 'void', 'nullable' => false],
            'deadLetter' => ['params' => 2, 'return' => 'void', 'nullable' => false],
            'size' => ['params' => 1, 'return' => 'int', 'nullable' => false],
            'close' => ['params' => 0, 'return' => 'void', 'nullable' => false],
        ];
        foreach ($expected as $name => $spec) {
            $this->assertTrue($ref->hasMethod($name), "interface missing {$name}()");
            $method = $ref->getMethod($name);
            $this->assertSame($spec['params'], $method->getNumberOfParameters(), "{$name}() arity drifted");
            $rt = $method->getReturnType();
            $this->assertNotNull($rt, "{$name}() has no return type");
            $this->assertSame($spec['return'], $rt->getName(), "{$name}() return type drifted");
            $this->assertSame($spec['nullable'], $rt->allowsNull(), "{$name}() nullability drifted");
        }
        // enqueue's parameters are named (topic, message) — the facade binds by order.
        $enqueue = $ref->getMethod('enqueue')->getParameters();
        $this->assertSame('topic', $enqueue[0]->getName());
        $this->assertSame('message', $enqueue[1]->getName());

        // Every concrete backend implements the interface AND exposes connect().
        foreach (self::BACKEND_CLASSES as $name => $class) {
            $cref = new \ReflectionClass($class);
            $this->assertTrue(
                $cref->implementsInterface(QueueBackend::class),
                "{$name} backend ({$class}) does not implement QueueBackend"
            );
            foreach (self::REQUIRED_METHODS as $method) {
                $this->assertTrue(
                    $cref->hasMethod($method),
                    "{$name} backend is missing {$method}()"
                );
            }
            $this->assertTrue(
                method_exists($class, 'connect'),
                "{$name} backend is missing connect()"
            );
        }
    }

    // -- Env var detection ---------------------------------------------------

    public function testRabbitMQBackendReadsEnvVars(): void
    {
        putenv('TINA4_RABBITMQ_HOST=envhost');
        putenv('TINA4_RABBITMQ_PORT=15672');
        putenv('TINA4_RABBITMQ_USERNAME=envuser');
        putenv('TINA4_RABBITMQ_PASSWORD=envpass');
        putenv('TINA4_RABBITMQ_VHOST=/envvhost');

        $backend = new RabbitMQBackend();
        $this->assertInstanceOf(RabbitMQBackend::class, $backend);

        // Use reflection to verify the env values were picked up
        $ref = new \ReflectionClass($backend);

        $hostProp = $ref->getProperty('host');
        $this->assertEquals('envhost', $hostProp->getValue($backend));

        $portProp = $ref->getProperty('port');
        $this->assertEquals(15672, $portProp->getValue($backend));

        // Clean up env vars
        putenv('TINA4_RABBITMQ_HOST');
        putenv('TINA4_RABBITMQ_PORT');
        putenv('TINA4_RABBITMQ_USERNAME');
        putenv('TINA4_RABBITMQ_PASSWORD');
        putenv('TINA4_RABBITMQ_VHOST');
    }

    public function testKafkaBackendReadsEnvVars(): void
    {
        putenv('TINA4_KAFKA_BROKERS=kafka1:9092,kafka2:9092');
        putenv('TINA4_KAFKA_GROUP_ID=test_group');

        $backend = new KafkaBackend();

        $ref = new \ReflectionClass($backend);

        $brokersProp = $ref->getProperty('brokers');
        $this->assertEquals('kafka1:9092,kafka2:9092', $brokersProp->getValue($backend));

        $groupProp = $ref->getProperty('groupId');
        $this->assertEquals('test_group', $groupProp->getValue($backend));

        // Clean up
        putenv('TINA4_KAFKA_BROKERS');
        putenv('TINA4_KAFKA_GROUP_ID');
    }

    public function testConfigOverridesEnvVars(): void
    {
        putenv('TINA4_RABBITMQ_HOST=envhost');

        $backend = new RabbitMQBackend(['host' => 'confighost']);

        $ref = new \ReflectionClass($backend);
        $hostProp = $ref->getProperty('host');
        $this->assertEquals('confighost', $hostProp->getValue($backend));

        putenv('TINA4_RABBITMQ_HOST');
    }

    // (MongoBackend interface conformance is covered by the consolidated
    //  testQueueBackendInterfaceContract loop above.)

    // -- RabbitMQ default config -------------------------------------------

    public function testRabbitMQBackendDefaultConfig(): void
    {
        $backend = new RabbitMQBackend();

        $ref = new \ReflectionClass($backend);

        $hostProp = $ref->getProperty('host');
        $this->assertEquals('localhost', $hostProp->getValue($backend));

        $portProp = $ref->getProperty('port');
        $this->assertEquals(5672, $portProp->getValue($backend));

        $userProp = $ref->getProperty('username');
        $this->assertEquals('guest', $userProp->getValue($backend));

        $passProp = $ref->getProperty('password');
        $this->assertEquals('guest', $passProp->getValue($backend));

        $vhostProp = $ref->getProperty('vhost');
        $this->assertEquals('/', $vhostProp->getValue($backend));
    }

    public function testRabbitMQBackendCustomConfig(): void
    {
        $backend = new RabbitMQBackend([
            'host' => 'rabbitmq.example.com',
            'port' => 5673,
            'username' => 'admin',
            'password' => 'secret',
            'vhost' => '/test',
        ]);

        $ref = new \ReflectionClass($backend);

        $hostProp = $ref->getProperty('host');
        $this->assertEquals('rabbitmq.example.com', $hostProp->getValue($backend));

        $portProp = $ref->getProperty('port');
        $this->assertEquals(5673, $portProp->getValue($backend));

        $userProp = $ref->getProperty('username');
        $this->assertEquals('admin', $userProp->getValue($backend));

        $passProp = $ref->getProperty('password');
        $this->assertEquals('secret', $passProp->getValue($backend));

        $vhostProp = $ref->getProperty('vhost');
        $this->assertEquals('/test', $vhostProp->getValue($backend));
    }

    public function testRabbitMQBackendCloseBeforeConnectLeavesNoSocket(): void
    {
        // Real behaviour (not "assertTrue(true)"): close() before connect() is a
        // safe no-op AND leaves the backend genuinely disconnected — the socket
        // stays null, no broker frame is attempted. Mirrors Python's
        // test_close_when_never_connected_leaves_no_connection.
        $backend = new RabbitMQBackend();
        $sock = new \ReflectionProperty($backend, 'socket');
        $this->assertNull($sock->getValue($backend), 'a fresh backend must have no socket');
        $backend->close();
        $this->assertNull($sock->getValue($backend), 'close() before connect() must leave the socket null');
    }

    // -- Kafka default config ------------------------------------------------

    public function testKafkaBackendDefaultConfig(): void
    {
        $backend = new KafkaBackend();

        $ref = new \ReflectionClass($backend);

        $brokersProp = $ref->getProperty('brokers');
        $this->assertEquals('localhost:9092', $brokersProp->getValue($backend));

        $groupProp = $ref->getProperty('groupId');
        $this->assertEquals('tina4_consumer_group', $groupProp->getValue($backend));
    }

    public function testKafkaBackendCustomConfig(): void
    {
        $backend = new KafkaBackend([
            'brokers' => 'kafka1:9092,kafka2:9092',
            'group_id' => 'my-app',
        ]);

        $ref = new \ReflectionClass($backend);

        $brokersProp = $ref->getProperty('brokers');
        $this->assertEquals('kafka1:9092,kafka2:9092', $brokersProp->getValue($backend));

        $groupProp = $ref->getProperty('groupId');
        $this->assertEquals('my-app', $groupProp->getValue($backend));
    }

    public function testKafkaBackendCloseBeforeConnectLeavesNoSocket(): void
    {
        // Real behaviour: close() before connect() is a safe no-op AND leaves the
        // backend disconnected (socket stays null). Mirrors Python's
        // test_close_when_never_connected_leaves_no_connection.
        $backend = new KafkaBackend();
        $sock = new \ReflectionProperty($backend, 'socket');
        $this->assertNull($sock->getValue($backend), 'a fresh backend must have no socket');
        $backend->close();
        $this->assertNull($sock->getValue($backend), 'close() before connect() must leave the socket null');
    }

    public function testKafkaSizeIsAlwaysZero(): void
    {
        // Kafka has no queue-size concept; size() is documented to ALWAYS return
        // 0 regardless of topic or how much was produced. Assert the real
        // contract value (a constant, not a smoke check). Mirrors Python's
        // test_size_is_always_zero.
        $backend = new KafkaBackend();
        $this->assertSame(0, $backend->size('anything'));
        $this->assertSame(0, $backend->size(''));
    }

    public function testDeadLetterTopicNaming(): void
    {
        // deadLetter() routes to "<topic>.dead_letter". Prove the routing against
        // a REAL Kafka broker (no double): call deadLetter() on a live backend,
        // then consume the produced record straight off the "<topic>.dead_letter"
        // topic. If the message comes back from that exact topic name, the
        // routing landed where it claims to. Mirrors Python's
        // test_dead_letter_topic_naming and reuses the KafkaIntegrationTest
        // reachability + KRaft topic-creation pattern (a KRaft broker silently
        // drops a produce to a leaderless/not-yet-created topic).
        //
        // Gated on a reachable Kafka (localhost:9092, or TINA4_TEST_KAFKA_URL).
        // The skip reason carries "kafka" + "not reachable"/"not set" so the
        // #252 service gate fails CI when Kafka should be up but is not.
        $url = getenv('TINA4_TEST_KAFKA_URL') ?: 'localhost:9092';
        $broker = self::firstKafkaBroker($url);
        if (!self::kafkaReachable($broker['host'], $broker['port'])) {
            $this->markTestSkipped("kafka broker not reachable at {$broker['host']}:{$broker['port']} — skipping live dead-letter topic-naming test.");
        }

        $base = 'tina4_test_dlq_' . bin2hex(random_bytes(6));
        $deadTopic = $base . '.dead_letter';      // the exact name deadLetter() must target
        $container = getenv('TINA4_TEST_KAFKA_CONTAINER') ?: 'tina4-kafka';

        // Create the .dead_letter topic WITH a leader before producing.
        $out = [];
        $rc = 0;
        exec(sprintf(
            'docker exec %s /opt/kafka/bin/kafka-topics.sh --bootstrap-server localhost:9092 --create --topic %s --partitions 1 --replication-factor 1 --if-not-exists 2>&1',
            escapeshellarg($container),
            escapeshellarg($deadTopic)
        ), $out, $rc);
        if ($rc !== 0) {
            $this->markTestSkipped('could not create kafka topic via "docker exec ' . $container . '" (set TINA4_TEST_KAFKA_CONTAINER): ' . implode(' ', $out));
        }

        try {
            $backend = new KafkaBackend(['brokers' => $url]);
            $backend->connect();

            // The real method under test: deadLetter('<base>', ...) must produce
            // to '<base>.dead_letter' on the live broker.
            $nonce = bin2hex(random_bytes(4));
            $backend->deadLetter($base, ['id' => 'msg-1', 'error' => 'timeout', 'nonce' => $nonce]);

            // Consume back from the EXACT dead-letter topic name. If routing went
            // anywhere else, nothing surfaces here and the assertions fail.
            $msg = null;
            for ($i = 0; $i < 12; $i++) {
                $msg = $backend->dequeue($deadTopic);
                if ($msg !== null) {
                    break;
                }
                usleep(400000);
            }

            $this->assertIsArray($msg, "deadLetter('{$base}', ...) must produce to '{$deadTopic}' on the real broker.");
            $this->assertSame('timeout', $msg['error'] ?? null, 'The dead-letter payload round-trips through real Kafka.');
            $this->assertSame($nonce, $msg['nonce'] ?? null, 'The unique nonce round-trips, proving this record came from the .dead_letter topic.');
        } finally {
            if (isset($backend)) {
                $backend->close();
            }
            @exec(sprintf(
                'docker exec %s /opt/kafka/bin/kafka-topics.sh --bootstrap-server localhost:9092 --delete --topic %s 2>&1',
                escapeshellarg($container),
                escapeshellarg($deadTopic)
            ));
        }
    }

    /** @return array{host:string,port:int} first broker from a comma list. */
    private static function firstKafkaBroker(string $url): array
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

    /** True if a TCP connection to the Kafka host:port opens within ~1.5s. */
    private static function kafkaReachable(string $host, int $port): bool
    {
        $fp = @fsockopen($host, $port, $errno, $errstr, 1.5);
        if ($fp) {
            fclose($fp);
            return true;
        }
        return false;
    }

    // -- Env var tests -------------------------------------------------------

    public function testRabbitMQBackendEnvVarVhost(): void
    {
        putenv('TINA4_RABBITMQ_VHOST=/envvhost');

        $backend = new RabbitMQBackend();

        $ref = new \ReflectionClass($backend);
        $vhostProp = $ref->getProperty('vhost');
        $this->assertEquals('/envvhost', $vhostProp->getValue($backend));

        putenv('TINA4_RABBITMQ_VHOST');
    }

    public function testRabbitMQBackendEnvVarUsername(): void
    {
        putenv('TINA4_RABBITMQ_USERNAME=envuser');
        putenv('TINA4_RABBITMQ_PASSWORD=envpass');

        $backend = new RabbitMQBackend();

        $ref = new \ReflectionClass($backend);

        $userProp = $ref->getProperty('username');
        $this->assertEquals('envuser', $userProp->getValue($backend));

        $passProp = $ref->getProperty('password');
        $this->assertEquals('envpass', $passProp->getValue($backend));

        putenv('TINA4_RABBITMQ_USERNAME');
        putenv('TINA4_RABBITMQ_PASSWORD');
    }

    public function testKafkaBackendEnvVarClientId(): void
    {
        $backend = new KafkaBackend();

        $ref = new \ReflectionClass($backend);
        $clientProp = $ref->getProperty('clientId');
        $this->assertEquals('tina4-php', $clientProp->getValue($backend));
    }

    // (Interface parameter counts + return types are asserted by the
    //  consolidated testQueueBackendInterfaceContract loop above.)

    // -- MongoBackend extension guard ----------------------------------------

    /**
     * The missing-extension error is CREATED, not waited for.
     *
     * This read "if ext-mongodb IS installed, skip", so on any box that can run
     * the live Mongo queue tests it skipped green and the error a developer with
     * a bare PHP actually hits was asserted by nobody. The absence is produced
     * instead, in a REAL php subprocess whose conf.d simply omits the .ini that
     * loads ext-mongodb. See tests/MissingExtensionCase.php.
     */
    public function testMongoBackendRequiresMongodbExtension(): void
    {
        $this->assertConstructionReportsMissingExtension(
            'mongodb',
            "new \\Tina4\\Queue\\MongoBackend();",
            'The mongodb extension is required'
        );
    }

    /**
     * NEGATIVE CONTROL: with ext-mongodb present, constructing MongoBackend in
     * the same kind of child must NOT report the extension missing. Without it,
     * a subprocess broken in a way that made every construction fail would still
     * pass the case above.
     */
    public function testMongoBackendDoesNotReportTheExtensionMissingWhenItIsPresent(): void
    {
        $this->assertConstructionDoesNotReportMissingExtension(
            'mongodb',
            "new \\Tina4\\Queue\\MongoBackend();",
            'The mongodb extension is required'
        );
    }

    // -- Batch dequeue (popBatch) -------------------------------------------

    public function testPopBatchReturnsArray(): void
    {
        $queue = new \Tina4\Queue('file', [], 'batch_test');
        $queue->clear();
        $queue->push(['n' => 1]);
        $queue->push(['n' => 2]);
        $queue->push(['n' => 3]);
        $jobs = $queue->popBatch(2);
        $this->assertIsArray($jobs);
        $this->assertCount(2, $jobs);
        $queue->clear();
    }

    public function testPopBatchPartialWhenFewerAvailable(): void
    {
        $queue = new \Tina4\Queue('file', [], 'batch_partial');
        $queue->clear();
        $queue->push(['n' => 1]);
        $jobs = $queue->popBatch(10);
        $this->assertCount(1, $jobs);
        $queue->clear();
    }

    public function testPopBatchEmptyQueue(): void
    {
        $queue = new \Tina4\Queue('file', [], 'batch_empty');
        $queue->clear();
        $jobs = $queue->popBatch(5);
        $this->assertSame([], $jobs);
    }

    public function testConsumeWithBatchSize(): void
    {
        $queue = new \Tina4\Queue('file', [], 'batch_consume');
        $queue->clear();
        for ($i = 0; $i < 5; $i++) {
            $queue->push(['n' => $i]);
        }
        $batches = [];
        foreach ($queue->consume('batch_consume', null, 0, 0, 2) as $jobs) {
            $batches[] = $jobs;
        }
        $total = array_sum(array_map('count', $batches));
        $this->assertSame(5, $total);
        $this->assertTrue(is_array($batches[0]));
        $queue->clear();
    }

    public function testProcessWithBatchSize(): void
    {
        $queue = new \Tina4\Queue('file', [], 'batch_process');
        $queue->clear();
        for ($i = 0; $i < 6; $i++) {
            $queue->push(['n' => $i]);
        }
        $received = [];
        $queue->process(function($jobs) use (&$received) {
            foreach ($jobs as $job) {
                $received[] = $job['payload']['n'] ?? $job['n'] ?? null;
            }
        }, '', ['batchSize' => 3]);
        $this->assertCount(6, $received);
        $queue->clear();
    }

    // -- Config override priority -------------------------------------------

    public function testKafkaConfigOverridesEnvVars(): void
    {
        putenv('TINA4_KAFKA_BROKERS=env-broker:9092');

        $backend = new KafkaBackend(['brokers' => 'config-broker:9092']);

        $ref = new \ReflectionClass($backend);
        $brokersProp = $ref->getProperty('brokers');
        $this->assertEquals('config-broker:9092', $brokersProp->getValue($backend));

        putenv('TINA4_KAFKA_BROKERS');
    }

    public function testRabbitMQBackendEnvVarOverrideOrder(): void
    {
        putenv('TINA4_RABBITMQ_HOST=envhost');
        putenv('TINA4_RABBITMQ_PORT=15672');

        $backend = new RabbitMQBackend(['host' => 'confighost']);

        $ref = new \ReflectionClass($backend);

        $hostProp = $ref->getProperty('host');
        $this->assertEquals('confighost', $hostProp->getValue($backend));

        // Port should still come from env since not specified in config
        $portProp = $ref->getProperty('port');
        $this->assertEquals(15672, $portProp->getValue($backend));

        putenv('TINA4_RABBITMQ_HOST');
        putenv('TINA4_RABBITMQ_PORT');
    }

    // -- Kafka TLS/SASL security config (lock-in, parity with Python master) --
    //
    // KafkaBackend::securityConfig() reads SSL/SASL settings from the
    // Tina4-namespaced env var first (TINA4_KAFKA_*), then the bare
    // librdkafka-convention name (KAFKA_*); unset keys are omitted (PLAINTEXT
    // default). Mirrors tina4_python ...kafka_backend.py::_security_config and
    // its five lock-in tests.

    private const KAFKA_SECURITY_VARS = [
        'TINA4_KAFKA_SECURITY_PROTOCOL', 'KAFKA_SECURITY_PROTOCOL',
        'TINA4_KAFKA_SSL_CA_LOCATION', 'KAFKA_SSL_CA_LOCATION',
        'TINA4_KAFKA_SASL_MECHANISM', 'KAFKA_SASL_MECHANISM',
        'TINA4_KAFKA_SASL_USERNAME', 'KAFKA_SASL_USERNAME',
        'TINA4_KAFKA_SASL_PASSWORD', 'KAFKA_SASL_PASSWORD',
    ];

    private function cleanKafkaSecurityEnv(): void
    {
        foreach (self::KAFKA_SECURITY_VARS as $v) {
            putenv($v);
        }
    }

    protected function tearDown(): void
    {
        $this->cleanKafkaSecurityEnv();
        // Drop the throwaway live-Mongo db so re-runs are idempotent. setUp for
        // each live test also drops it up front; doing both ends keeps the
        // namespace clean whether a test ran, skipped, or aborted.
        $this->dropLiveMongoTestDb();
    }

    public function testKafkaSecurityConfigNoEnvIsEmpty(): void
    {
        // NEGATIVE: nothing set -> [] (PLAINTEXT default left untouched).
        $this->cleanKafkaSecurityEnv();
        $this->assertSame([], KafkaBackend::securityConfig());
    }

    public function testKafkaSecurityConfigBareKafkaNames(): void
    {
        // POSITIVE: bare KAFKA_* names work as a fallback.
        $this->cleanKafkaSecurityEnv();
        putenv('KAFKA_SECURITY_PROTOCOL=SSL');
        putenv('KAFKA_SSL_CA_LOCATION=/etc/ssl/ca.pem');

        $this->assertSame(
            ['security.protocol' => 'SSL', 'ssl.ca.location' => '/etc/ssl/ca.pem'],
            KafkaBackend::securityConfig()
        );
    }

    public function testKafkaSecurityConfigTina4NamespacedNames(): void
    {
        // POSITIVE: Tina4-namespaced env vars are honoured.
        $this->cleanKafkaSecurityEnv();
        putenv('TINA4_KAFKA_SECURITY_PROTOCOL=SASL_SSL');

        $this->assertSame(
            ['security.protocol' => 'SASL_SSL'],
            KafkaBackend::securityConfig()
        );
    }

    public function testKafkaSecurityConfigTina4TakesPrecedenceOverBare(): void
    {
        // PRECEDENCE: TINA4_KAFKA_* wins when both are set.
        $this->cleanKafkaSecurityEnv();
        putenv('KAFKA_SECURITY_PROTOCOL=SSL');
        putenv('TINA4_KAFKA_SECURITY_PROTOCOL=SASL_SSL');

        $cfg = KafkaBackend::securityConfig();
        $this->assertSame('SASL_SSL', $cfg['security.protocol']);
    }

    public function testKafkaSecurityConfigSaslKeysMapped(): void
    {
        // POSITIVE: optional SASL creds map to the rdkafka sasl.* keys (mixed
        // namespaced + bare, exercising the per-key fallback).
        $this->cleanKafkaSecurityEnv();
        putenv('TINA4_KAFKA_SASL_MECHANISM=PLAIN');
        putenv('KAFKA_SASL_USERNAME=user');
        putenv('KAFKA_SASL_PASSWORD=secret');

        $this->assertSame(
            ['sasl.mechanism' => 'PLAIN', 'sasl.username' => 'user', 'sasl.password' => 'secret'],
            KafkaBackend::securityConfig()
        );
    }

    public function testKafkaSecurityConfigAppliedToProducerAndConsumer(): void
    {
        // The resolved security config is merged into BOTH the producer and the
        // consumer client setup (parity with Python's _connect_confluent).
        $this->cleanKafkaSecurityEnv();
        putenv('TINA4_KAFKA_SECURITY_PROTOCOL=SASL_SSL');
        putenv('TINA4_KAFKA_SASL_MECHANISM=PLAIN');
        putenv('TINA4_KAFKA_SASL_USERNAME=user');
        putenv('TINA4_KAFKA_SASL_PASSWORD=secret');

        $backend = new KafkaBackend();
        $ref = new \ReflectionClass($backend);

        $producer = $ref->getProperty('producerConfig')->getValue($backend);
        $consumer = $ref->getProperty('consumerConfig')->getValue($backend);

        foreach (['security.protocol', 'sasl.mechanism', 'sasl.username', 'sasl.password'] as $key) {
            $this->assertArrayHasKey($key, $producer, "producer missing {$key}");
            $this->assertArrayHasKey($key, $consumer, "consumer missing {$key}");
        }
        $this->assertSame('SASL_SSL', $producer['security.protocol']);
        $this->assertSame('SASL_SSL', $consumer['security.protocol']);

        // Consumer also carries its group + offset settings.
        $this->assertSame('earliest', $consumer['auto.offset.reset']);
        $this->assertArrayHasKey('group.id', $consumer);
    }

    // -- MongoBackend visibility timeout (config resolution only) ------------
    //
    // These cases construct a real MongoBackend and assert how it resolves the
    // visibility timeout from config/env/default. They touch NO collection (no
    // connection, no double) so they run wherever ext-mongodb is present — they
    // are pure config-resolution checks, not mock tests. The behavioural
    // reservation/dead-letter/retry coverage that used to wire a fake collection
    // double here has been rewritten against a REAL MongoDB further down (the
    // "MongoBackend reservation/reclaim/requeue behaviour" section):
    // each one seeds real documents into a throwaway namespaced collection,
    // calls the real MongoBackend method, and reads the resulting state back out
    // of Mongo — no test doubles anywhere, per the no-mock rule.

    public function testMongoVisibilityTimeoutFromConfig(): void
    {
        if (!extension_loaded('mongodb')) {
            $this->markTestSkipped('ext-mongodb not installed — cannot construct MongoBackend');
        }
        $backend = new MongoBackend(['visibility_timeout' => 45]);
        $this->assertEquals(45.0, $backend->getVisibilityTimeout());
    }

    public function testMongoVisibilityTimeoutFromEnv(): void
    {
        if (!extension_loaded('mongodb')) {
            $this->markTestSkipped('ext-mongodb not installed — cannot construct MongoBackend');
        }
        putenv('TINA4_QUEUE_VISIBILITY_TIMEOUT=42');
        try {
            $backend = new MongoBackend();
            $this->assertEquals(42.0, $backend->getVisibilityTimeout());
        } finally {
            putenv('TINA4_QUEUE_VISIBILITY_TIMEOUT');
        }
    }

    public function testMongoVisibilityTimeoutDefaultsTo300(): void
    {
        if (!extension_loaded('mongodb')) {
            $this->markTestSkipped('ext-mongodb not installed — cannot construct MongoBackend');
        }
        putenv('TINA4_QUEUE_VISIBILITY_TIMEOUT');
        $backend = new MongoBackend();
        $this->assertEquals(300.0, $backend->getVisibilityTimeout());
    }

    // -- MongoBackend reservation/reclaim/requeue behaviour (REAL Mongo) ------
    //
    // Regression locks for the production bugs where a Mongo-backed queue could
    // strand a job (consumer dies mid-flight, reservation never reclaimed),
    // retry forever (dequeue surfaced the stale push-time attempts snapshot
    // instead of the live document attempts), or hold a fail()'d job invisible
    // for the whole visibility window (requeue left available_at in the future).
    //
    // These used to wire a fake collection double onto the adapter — a test
    // double the no-mock rule forbids, and exactly the kind of gap that let the
    // queue bugs ship. They now run against a REAL MongoDB: each seeds real docs
    // into a THROWAWAY, framework-namespaced collection (db tina4_test_queue_php,
    // collection tina4_test_queue_jobs), calls the real MongoBackend method, and
    // asserts on state read back out of Mongo. setUp()/tearDown() drop the
    // throwaway database so re-runs are idempotent.
    //
    // Skip cleanly ONLY when the mongodb client library is missing or Mongo is
    // not reachable on localhost:27017 — the skip reason contains "mongo" +
    // "not reachable"/"not installed" so the #252 TINA4_REQUIRE_SERVICES gate
    // (Tina4\Testing\RequireServicesExtension) fails CI when Mongo should be up.

    private const MONGO_TEST_DB = 'tina4_test_queue_php';
    private const MONGO_TEST_COLLECTION = 'tina4_test_queue_jobs';
    private const MONGO_TEST_TOPIC = 'tina4_test_queue_emails';

    private string $mongoTestUri = '';

    /**
     * Build a real MongoBackend pointed at the throwaway namespaced collection.
     * No doubles — this returns a backend that talks to the live Mongo.
     */
    private function makeLiveMongoBackend(float $visibilityTimeout = 300.0, int $maxRetries = 3, float $retryBackoff = 0.0): MongoBackend
    {
        return new MongoBackend([
            'uri' => $this->mongoTestUri,
            'db' => self::MONGO_TEST_DB,
            'collection' => self::MONGO_TEST_COLLECTION,
            'visibility_timeout' => $visibilityTimeout,
            'max_retries' => $maxRetries,
            'retry_backoff' => $retryBackoff,
        ]);
    }

    /** The live \MongoDB\Collection for the throwaway db (seed + read-back). */
    private function liveMongoCollection(): \MongoDB\Collection
    {
        $client = new \MongoDB\Client($this->mongoTestUri);
        return $client->selectCollection(self::MONGO_TEST_DB, self::MONGO_TEST_COLLECTION);
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

    /**
     * Gate + clean slate for the live-Mongo behaviour tests. Skips cleanly (never
     * fakes) when the client library is missing or Mongo is unreachable; otherwise
     * drops the throwaway database so each test starts pristine and re-runs are
     * idempotent.
     */
    private function setUpLiveMongoOrSkip(): void
    {
        if (!extension_loaded('mongodb') || !class_exists('MongoDB\\Client')) {
            $this->markTestSkipped('mongo client (ext-mongodb / mongodb library) not installed — skipping live Mongo queue behaviour test');
        }
        $this->mongoTestUri = getenv('TINA4_MONGO_URI') ?: 'mongodb://localhost:27017';
        if (!$this->mongoReachable($this->mongoTestUri)) {
            $this->markTestSkipped('mongo not reachable at ' . $this->mongoTestUri . ' — skipping live Mongo queue behaviour test');
        }
        // Pristine slate even if a prior run aborted before teardown.
        $this->dropLiveMongoTestDb();
    }

    /** Drop the throwaway db so re-runs are idempotent (best-effort). */
    private function dropLiveMongoTestDb(): void
    {
        if ($this->mongoTestUri === '') {
            return;
        }
        try {
            (new \MongoDB\Client($this->mongoTestUri))->dropDatabase(self::MONGO_TEST_DB);
        } catch (\Throwable $e) {
            // best-effort cleanup — never let teardown noise fail a test
        }
    }

    /** A UTCDateTime $seconds relative to now (negative = in the past). */
    private function mongoTime(float $seconds = 0.0): \MongoDB\BSON\UTCDateTime
    {
        return new \MongoDB\BSON\UTCDateTime((int) round((microtime(true) + $seconds) * 1000));
    }

    public function testMongoReclaimRequeuesUnderLimit(): void
    {
        // An expired reservation under max_retries is requeued: the real
        // reclaimExpired() flips processing -> pending, increments attempts, and
        // does NOT dead-letter or delete. Seed one real RESERVED doc whose
        // visibility window already lapsed (available_at in the past, attempts=0
        // so attempts-after-inc = 1 < max 3), then read the doc back from Mongo.
        $this->setUpLiveMongoOrSkip();
        $topic = self::MONGO_TEST_TOPIC;
        $coll = $this->liveMongoCollection();
        $coll->insertOne([
            '_id' => 'msg-under',
            'topic' => $topic,
            'status' => 'processing',
            'message' => ['id' => 'msg-under', 'payload' => ['x' => 1]],
            'attempts' => 0,
            'reserved_at' => $this->mongoTime(-600),
            'available_at' => $this->mongoTime(-60),   // visibility window lapsed
            'created_at' => $this->mongoTime(-600),
            'updated_at' => $this->mongoTime(-600),
        ]);

        $backend = $this->makeLiveMongoBackend(300.0, maxRetries: 3);
        $count = $backend->reclaimExpired($topic, 3);

        $this->assertSame(1, $count);

        // Read the REAL doc back: flipped to pending, attempts incremented to 1,
        // reserved_at cleared, available_at reset to ~now (visible again).
        $doc = $coll->findOne(['_id' => 'msg-under', 'topic' => $topic]);
        $this->assertNotNull($doc, 'reclaimed doc must still exist (under the limit, not dead-lettered)');
        $this->assertSame('pending', $doc['status']);
        $this->assertSame(1, (int) $doc['attempts']);
        $this->assertNull($doc['reserved_at']);
        $availableMs = (int) ((string) $doc['available_at']);
        $nowMs = (int) round(microtime(true) * 1000);
        $this->assertLessThanOrEqual(5000, abs($availableMs - $nowMs), 'available_at should be reset to ~now');

        // Under the limit: nothing dead-lettered, original not deleted.
        $this->assertSame(0, $coll->countDocuments(['topic' => $topic . '.dead_letter']));
        $this->assertSame(1, $coll->countDocuments(['_id' => 'msg-under', 'topic' => $topic]));

        $backend->close();
    }

    public function testMongoReclaimDeadLettersPastMaxRetries(): void
    {
        // An expired reservation AT/OVER max_retries is dead-lettered: the real
        // reclaimExpired() moves it to '<topic>.dead_letter' and deletes the
        // original. Seed a real RESERVED doc whose attempts already equals max-1
        // so attempts-after-inc = max (3) >= the limit, with an expired window.
        $this->setUpLiveMongoOrSkip();
        $topic = self::MONGO_TEST_TOPIC;
        $coll = $this->liveMongoCollection();
        $coll->insertOne([
            '_id' => 'msg-over',
            'topic' => $topic,
            'status' => 'processing',
            'message' => ['id' => 'msg-over', 'payload' => ['x' => 1]],
            'attempts' => 2,                            // after inc -> 3 == max
            'reserved_at' => $this->mongoTime(-600),
            'available_at' => $this->mongoTime(-60),
            'created_at' => $this->mongoTime(-600),
            'updated_at' => $this->mongoTime(-600),
        ]);

        $backend = $this->makeLiveMongoBackend(300.0, maxRetries: 3);
        $count = $backend->reclaimExpired($topic, 3);

        $this->assertSame(1, $count);

        // Read back: the original is gone, a dead-letter doc exists on the
        // '<topic>.dead_letter' topic carrying the live attempts + the reason.
        $this->assertSame(0, $coll->countDocuments(['_id' => 'msg-over', 'topic' => $topic]), 'original must be removed');
        $dead = $coll->findOne(['topic' => $topic . '.dead_letter']);
        $this->assertNotNull($dead, 'doc must be dead-lettered past the limit');
        $this->assertSame('dead', $dead['status']);
        $this->assertSame(3, (int) $dead['attempts']);
        $this->assertSame('msg-over', $dead['message']['id']);
        $this->assertNotEmpty($dead['message']['error'] ?? '');

        $backend->close();
    }

    public function testMongoReclaimDisabledWhenTimeoutZero(): void
    {
        // visibility_timeout <= 0 disables reclaim entirely: an expired
        // reservation is left untouched. Real Mongo — seed an expired reserved
        // doc and confirm reclaimExpired() returns 0 and the doc is unchanged.
        $this->setUpLiveMongoOrSkip();
        $topic = self::MONGO_TEST_TOPIC;
        $coll = $this->liveMongoCollection();
        $coll->insertOne([
            '_id' => 'msg-zero',
            'topic' => $topic,
            'status' => 'processing',
            'message' => ['id' => 'msg-zero', 'payload' => ['x' => 1]],
            'attempts' => 0,
            'reserved_at' => $this->mongoTime(-600),
            'available_at' => $this->mongoTime(-60),
            'created_at' => $this->mongoTime(-600),
            'updated_at' => $this->mongoTime(-600),
        ]);

        $backend = $this->makeLiveMongoBackend(0.0, maxRetries: 3);
        $this->assertSame(0, $backend->reclaimExpired($topic, 3));

        // Untouched: still processing, attempts still 0, nothing dead-lettered.
        $doc = $coll->findOne(['_id' => 'msg-zero', 'topic' => $topic]);
        $this->assertNotNull($doc);
        $this->assertSame('processing', $doc['status']);
        $this->assertSame(0, (int) $doc['attempts']);
        $this->assertSame(0, $coll->countDocuments(['topic' => $topic . '.dead_letter']));

        $backend->close();
    }

    public function testMongoDequeueAdvancesAvailableAtAndRecordsReservedAt(): void
    {
        // dequeue() claims a pending job by advancing available_at into the
        // future (now + visibility_timeout) and stamping reserved_at, so a dead
        // consumer's reservation can later be reclaimed. Seed a real pending doc
        // and read the claimed doc back from Mongo.
        $this->setUpLiveMongoOrSkip();
        $topic = self::MONGO_TEST_TOPIC;
        $coll = $this->liveMongoCollection();
        $coll->insertOne([
            '_id' => 'msg-claim',
            'topic' => $topic,
            'status' => 'pending',
            'message' => ['id' => 'msg-claim', 'payload' => ['x' => 1]],
            'attempts' => 0,
            'available_at' => $this->mongoTime(-1),     // available now
            'created_at' => $this->mongoTime(-1),
            'updated_at' => $this->mongoTime(-1),
        ]);

        $backend = $this->makeLiveMongoBackend(300.0);
        $job = $backend->dequeue($topic);
        $this->assertNotNull($job);

        // Read the claimed doc back from Mongo: now processing, reserved_at
        // stamped, available_at pushed into the future past reserved_at.
        $doc = $coll->findOne(['_id' => 'msg-claim', 'topic' => $topic]);
        $this->assertSame('processing', $doc['status']);
        $this->assertNotNull($doc['reserved_at']);
        $reservedMs = (int) ((string) $doc['reserved_at']);
        $availableMs = (int) ((string) $doc['available_at']);
        $this->assertGreaterThan($reservedMs, $availableMs, 'available_at must be pushed past reserved_at (the visibility window)');

        $backend->close();
    }

    public function testMongoDequeueSurfacesLiveAttempts(): void
    {
        // dequeue() must return the LIVE top-level document attempts (incremented
        // by reclaim/requeue), NOT the stale push-time snapshot stored inside
        // 'message'. Seed a real pending doc whose top-level attempts=2 while its
        // message snapshot still says 0, then dequeue and assert the surfaced
        // value is the live 2 (and priority likewise).
        $this->setUpLiveMongoOrSkip();
        $topic = self::MONGO_TEST_TOPIC;
        $coll = $this->liveMongoCollection();
        $coll->insertOne([
            '_id' => 'msg-live',
            'topic' => $topic,
            'status' => 'pending',
            'attempts' => 2,                            // live, document-level
            'priority' => 7,
            'message' => ['id' => 'msg-live', 'payload' => ['x' => 1], 'attempts' => 0, 'priority' => 0],
            'available_at' => $this->mongoTime(-1),
            'created_at' => $this->mongoTime(-1),
            'updated_at' => $this->mongoTime(-1),
        ]);

        $backend = $this->makeLiveMongoBackend(300.0);
        $job = $backend->dequeue($topic);

        $this->assertNotNull($job);
        $this->assertSame(2, $job['attempts'], 'dequeue must surface the live document attempts, not the push-time 0');
        $this->assertSame(7, $job['priority']);

        $backend->close();
    }

    public function testMongoRequeueResetsAvailableAtToNow(): void
    {
        // requeue() with no retry_backoff resets available_at to ~now (and clears
        // reserved_at) so a fail()'d job retries on the very next dequeue instead
        // of waiting out the visibility window. Seed a real RESERVED doc whose
        // available_at is far in the future (as a live dequeue would leave it),
        // requeue it, and read available_at back from Mongo.
        $this->setUpLiveMongoOrSkip();
        $topic = self::MONGO_TEST_TOPIC;
        $coll = $this->liveMongoCollection();
        $coll->insertOne([
            '_id' => 'msg-requeue',
            'topic' => $topic,
            'status' => 'processing',
            'message' => ['id' => 'msg-requeue', 'payload' => ['x' => 1]],
            'attempts' => 1,
            'reserved_at' => $this->mongoTime(-5),
            'available_at' => $this->mongoTime(300),    // pushed out by the claim
            'created_at' => $this->mongoTime(-10),
            'updated_at' => $this->mongoTime(-5),
        ]);

        $backend = $this->makeLiveMongoBackend(300.0);
        $backend->requeue($topic, ['id' => 'msg-requeue', 'payload' => ['x' => 1], 'attempts' => 1]);

        $doc = $coll->findOne(['_id' => 'msg-requeue', 'topic' => $topic]);
        $this->assertSame('pending', $doc['status']);
        $this->assertNull($doc['reserved_at']);
        $availableMs = (int) ((string) $doc['available_at']);
        $nowMs = (int) round(microtime(true) * 1000);
        $this->assertLessThanOrEqual(5000, abs($availableMs - $nowMs), 'available_at should be reset to ~now, not the visibility expiry');

        $backend->close();
    }

    public function testMongoRequeueRespectsRetryBackoff(): void
    {
        // requeue() with retry_backoff sets available_at to ~now + backoff: the
        // requeued job is delayed by the backoff but nowhere near the 300s
        // visibility window. Seed a real RESERVED doc, requeue with a 30s
        // backoff, and read available_at back from Mongo.
        $this->setUpLiveMongoOrSkip();
        $topic = self::MONGO_TEST_TOPIC;
        $coll = $this->liveMongoCollection();
        $coll->insertOne([
            '_id' => 'msg-backoff',
            'topic' => $topic,
            'status' => 'processing',
            'message' => ['id' => 'msg-backoff', 'payload' => ['x' => 1]],
            'attempts' => 1,
            'reserved_at' => $this->mongoTime(-5),
            'available_at' => $this->mongoTime(300),
            'created_at' => $this->mongoTime(-10),
            'updated_at' => $this->mongoTime(-5),
        ]);

        $backend = $this->makeLiveMongoBackend(300.0, maxRetries: 3, retryBackoff: 30.0);
        $backend->requeue($topic, ['id' => 'msg-backoff', 'payload' => ['x' => 1], 'attempts' => 1]);

        $doc = $coll->findOne(['_id' => 'msg-backoff', 'topic' => $topic]);
        $this->assertSame('pending', $doc['status']);
        $availableMs = (int) ((string) $doc['available_at']);
        $nowMs = (int) round(microtime(true) * 1000);
        $deltaSeconds = ($availableMs - $nowMs) / 1000.0;
        $this->assertGreaterThan(20, $deltaSeconds, 'available_at should be ~30s ahead (backoff)');
        $this->assertLessThan(60, $deltaSeconds, 'available_at must be nowhere near the 300s visibility window');

        $backend->close();
    }

    public function testMongoDeadLettersAcceptsMaxRetriesKwarg(): void
    {
        // deadLetters() accepts the $maxRetries kwarg the Queue passes (LiteBackend
        // contract / Bug D) and reads dead-letter docs off '<topic>.dead_letter',
        // surfacing the LIVE attempts. Seed a real dead-letter doc and read it back
        // via the real backend method.
        $this->setUpLiveMongoOrSkip();
        $topic = self::MONGO_TEST_TOPIC;
        $coll = $this->liveMongoCollection();
        $coll->insertOne([
            '_id' => 'dl-1',
            'topic' => $topic . '.dead_letter',
            'status' => 'dead',
            'attempts' => 3,
            'message' => ['id' => 'orig-1', 'payload' => ['x' => 1], 'attempts' => 0],
            'available_at' => $this->mongoTime(),
            'created_at' => $this->mongoTime(),
            'updated_at' => $this->mongoTime(),
        ]);

        $backend = $this->makeLiveMongoBackend(300.0, maxRetries: 3);
        $dead = $backend->deadLetters($topic, 5);   // must NOT TypeError on the kwarg

        $this->assertCount(1, $dead);
        $this->assertSame(3, $dead[0]['attempts'], 'dead-letter must surface the live attempts, not the snapshot 0');
        $this->assertSame('dead', $dead[0]['status']);

        $backend->close();
    }

    public function testMongoRetryFailedAcceptsMaxRetriesAndResetsAvailableAt(): void
    {
        // retryFailed() accepts the $maxRetries kwarg (Bug D) and re-queues a
        // dead-letter doc under the raised limit back to pending — resetting
        // available_at and clearing reserved_at (Bug B) — then drops the
        // dead-letter copy. Seed a real dead-letter doc, run retryFailed, and read
        // both topics back from Mongo.
        $this->setUpLiveMongoOrSkip();
        $topic = self::MONGO_TEST_TOPIC;
        $coll = $this->liveMongoCollection();
        $coll->insertOne([
            '_id' => 'dl-retry',
            'topic' => $topic . '.dead_letter',
            'status' => 'dead',
            'attempts' => 2,                            // under the raised limit (5)
            'message' => ['id' => 'orig-retry', 'payload' => ['x' => 1]],
            'available_at' => $this->mongoTime(),
            'created_at' => $this->mongoTime(),
            'updated_at' => $this->mongoTime(),
        ]);

        $backend = $this->makeLiveMongoBackend(300.0, maxRetries: 3);
        $count = $backend->retryFailed($topic, 5);

        $this->assertSame(1, $count);

        // The dead-letter copy is gone and the job is back as pending on the
        // live topic with available_at reset to ~now and no live reservation. The
        // real lifecycle deleted the original when it was dead-lettered, so
        // retryFailed()->requeue() re-inserts a fresh pending doc (the
        // enqueue-fallback path) which carries no reserved_at — i.e. unreserved.
        $this->assertSame(0, $coll->countDocuments(['topic' => $topic . '.dead_letter']));
        $pending = $coll->findOne(['_id' => 'orig-retry', 'topic' => $topic, 'status' => 'pending']);
        $this->assertNotNull($pending, 'the job must be re-queued onto the live topic as pending');
        $reserved = $pending['reserved_at'] ?? null;   // absent or explicitly null = unreserved
        $this->assertNull($reserved, 'a re-queued job must not carry a live reservation');
        $availableMs = (int) ((string) $pending['available_at']);
        $nowMs = (int) round(microtime(true) * 1000);
        $this->assertLessThanOrEqual(5000, abs($availableMs - $nowMs), 'requeued job available_at should be ~now');

        $backend->close();
    }

    public function testMongoQueueDeadLettersRetryFailedSignaturesMatchLite(): void
    {
        // Pure signature/contract check — runs even without ext-mongodb. The
        // Queue passes ?int $maxRetries; the Mongo backend must accept it so the
        // call can't TypeError (Bug D).
        $ref = new \ReflectionClass(MongoBackend::class);

        $this->assertTrue($ref->hasMethod('deadLetters'));
        $dl = $ref->getMethod('deadLetters');
        $this->assertSame(2, $dl->getNumberOfParameters());
        $this->assertSame('topic', $dl->getParameters()[0]->getName());
        $this->assertSame('maxRetries', $dl->getParameters()[1]->getName());
        $this->assertTrue($dl->getParameters()[1]->isOptional());

        $this->assertTrue($ref->hasMethod('retryFailed'));
        $rf = $ref->getMethod('retryFailed');
        $this->assertSame(2, $rf->getNumberOfParameters());
        $this->assertSame('topic', $rf->getParameters()[0]->getName());
        $this->assertSame('maxRetries', $rf->getParameters()[1]->getName());
        $this->assertTrue($rf->getParameters()[1]->isOptional());

        $this->assertTrue($ref->hasMethod('failed'));
        $this->assertSame(1, $ref->getMethod('failed')->getNumberOfParameters());
    }

    // -- Live RabbitMQ behaviour (real broker over raw AMQP, no mocks) --------
    //
    // The PHP RabbitMQBackend speaks AMQP 0-9-1 by hand over a raw TCP socket
    // (zero deps). These exercise the REAL broker — no doubles. They lock in the
    // size() passive-declare parity (PARITY WATCH #3 / Python's
    // test_size_on_fresh_queue_is_zero): size() must NOT do a passive declare
    // that throws when the queue is absent — it does a normal (non-passive)
    // Queue.Declare and reads the message count, so size() on a never-declared
    // queue returns 0 instead of a 404 channel error.
    //
    // Each test uses a fresh, uniquely-named durable queue and producer/reader
    // use SEPARATE connections: a queue's message_count does not reflect a
    // message still in flight on the *publishing* channel right after publish,
    // so a same-connection size() would race — the at-least-once contract is
    // verified across connections, the way a real producer/worker split runs.
    //
    // Skips cleanly (never fakes) when the broker is unreachable, with a reason
    // the #252 require-services gate recognises ("rabbitmq ... not reachable").

    private const RABBIT_HOST = 'localhost';
    private const RABBIT_PORT = 5672;

    private function rabbitReachableOrSkip(): void
    {
        $host = getenv('TINA4_RABBITMQ_HOST') ?: self::RABBIT_HOST;
        $port = (int)(getenv('TINA4_RABBITMQ_PORT') ?: self::RABBIT_PORT);
        $sock = @fsockopen($host, $port, $errno, $errstr, 1.5);
        if ($sock === false) {
            $this->markTestSkipped("rabbitmq not reachable at {$host}:{$port} — skipping live RabbitMQ queue behaviour test");
        }
        fclose($sock);
    }

    private function liveRabbitBackend(): RabbitMQBackend
    {
        $backend = new RabbitMQBackend([
            'host' => getenv('TINA4_RABBITMQ_HOST') ?: self::RABBIT_HOST,
            'port' => (int)(getenv('TINA4_RABBITMQ_PORT') ?: self::RABBIT_PORT),
            'username' => getenv('TINA4_RABBITMQ_USERNAME') ?: 'guest',
            'password' => getenv('TINA4_RABBITMQ_PASSWORD') ?: 'guest',
            'vhost' => getenv('TINA4_RABBITMQ_VHOST') ?: '/',
        ]);
        $backend->connect();
        return $backend;
    }

    public function testRabbitMQSizeOnFreshQueueIsZeroNotAPassiveDeclareError(): void
    {
        // PARITY WATCH #3: size() on a never-declared queue must return 0, NOT
        // raise a channel error from a passive declare. The raw AMQP path does a
        // normal Queue.Declare (durable, passive=false) and reads the count, so a
        // missing queue is created-then-counted at 0 rather than throwing.
        $this->rabbitReachableOrSkip();
        $topic = 'tina4_test_' . bin2hex(random_bytes(8));
        $backend = $this->liveRabbitBackend();
        try {
            $this->assertSame(0, $backend->size($topic), 'size() on a fresh queue must be 0, not a passive-declare error');
        } finally {
            // Best-effort cleanup of the throwaway durable queue.
            try { $backend->dequeue($topic); } catch (\Throwable $e) {}
            $backend->close();
        }
    }

    public function testRabbitMQFullEnqueueDequeueAcknowledgeCycle(): void
    {
        // Full lifecycle against the real broker: a fresh queue is empty (0),
        // an enqueued message is visible to a separate reader connection (1),
        // the dequeued payload + id round-trip, the delivery tag is captured,
        // acknowledge() clears it, and after ack the queue is empty again (0).
        // Mirrors Python's test_full_enqueue_dequeue_acknowledge_cycle.
        $this->rabbitReachableOrSkip();
        $topic = 'tina4_test_' . bin2hex(random_bytes(8));

        $producer = $this->liveRabbitBackend();
        $msgId = '';
        try {
            $this->assertSame(0, $producer->size($topic));
            $msgId = $producer->enqueue($topic, ['to' => 'alice@test.com', 'value' => 7]);
            $this->assertNotEmpty($msgId, 'enqueue() must return a message id');
        } finally {
            $producer->close();
        }

        $reader = $this->liveRabbitBackend();
        try {
            $this->assertSame(1, $reader->size($topic), 'a separate connection must see the enqueued message');
            $msg = $reader->dequeue($topic);
            $this->assertIsArray($msg);
            $this->assertSame('alice@test.com', $msg['to']);
            $this->assertSame(7, $msg['value']);
            $this->assertSame($msgId, $msg['id'], 'the message id must round-trip through the broker');

            // The delivery tag was captured by dequeue() and is cleared by ack().
            $tag = new \ReflectionProperty($reader, 'lastDeliveryTag');
            $this->assertNotNull($tag->getValue($reader), 'dequeue() must capture a delivery tag');
            $reader->acknowledge($topic, $msgId);
            $this->assertNull($tag->getValue($reader), 'acknowledge() must clear the delivery tag');
        } finally {
            $reader->close();
        }

        $verifier = $this->liveRabbitBackend();
        try {
            $this->assertSame(0, $verifier->size($topic), 'after ack the queue must be empty again');
        } finally {
            $verifier->close();
        }
    }

    public function testRabbitMQDeadLetterPublishesToDeadLetterQueue(): void
    {
        // deadLetter() routes to "<topic>.dead_letter" on the REAL broker: a
        // separate reader sees one message there and its payload round-trips.
        // Mirrors Python's test_dead_letter_publishes_to_dead_letter_queue.
        $this->rabbitReachableOrSkip();
        $topic = 'tina4_test_' . bin2hex(random_bytes(8));
        $deadTopic = $topic . '.dead_letter';

        $producer = $this->liveRabbitBackend();
        try {
            $producer->deadLetter($topic, ['id' => 'd1', 'error' => 'boom']);
        } finally {
            $producer->close();
        }

        $reader = $this->liveRabbitBackend();
        try {
            $this->assertSame(1, $reader->size($deadTopic), 'dead-letter queue must hold the message');
            $dead = $reader->dequeue($deadTopic);
            $this->assertIsArray($dead);
            $this->assertSame('boom', $dead['error']);
            $reader->acknowledge($deadTopic, 'd1');
            $this->assertSame(0, $reader->size($deadTopic));
        } finally {
            $reader->close();
        }
    }
}
