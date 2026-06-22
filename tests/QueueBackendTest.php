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
    // -- Interface exists and has correct methods ----------------------------

    public function testQueueBackendInterfaceExists(): void
    {
        $this->assertTrue(interface_exists(QueueBackend::class));
    }

    public function testQueueBackendHasEnqueueMethod(): void
    {
        $ref = new \ReflectionClass(QueueBackend::class);
        $this->assertTrue($ref->hasMethod('enqueue'));
        $method = $ref->getMethod('enqueue');
        $this->assertEquals(2, $method->getNumberOfParameters());
    }

    public function testQueueBackendHasDequeueMethod(): void
    {
        $ref = new \ReflectionClass(QueueBackend::class);
        $this->assertTrue($ref->hasMethod('dequeue'));
    }

    public function testQueueBackendHasAcknowledgeMethod(): void
    {
        $ref = new \ReflectionClass(QueueBackend::class);
        $this->assertTrue($ref->hasMethod('acknowledge'));
    }

    public function testQueueBackendHasRequeueMethod(): void
    {
        $ref = new \ReflectionClass(QueueBackend::class);
        $this->assertTrue($ref->hasMethod('requeue'));
    }

    public function testQueueBackendHasDeadLetterMethod(): void
    {
        $ref = new \ReflectionClass(QueueBackend::class);
        $this->assertTrue($ref->hasMethod('deadLetter'));
    }

    public function testQueueBackendHasSizeMethod(): void
    {
        $ref = new \ReflectionClass(QueueBackend::class);
        $this->assertTrue($ref->hasMethod('size'));
    }

    public function testQueueBackendHasCloseMethod(): void
    {
        $ref = new \ReflectionClass(QueueBackend::class);
        $this->assertTrue($ref->hasMethod('close'));
    }

    // -- RabbitMQBackend implements QueueBackend -----------------------------

    public function testRabbitMQBackendImplementsQueueBackend(): void
    {
        $ref = new \ReflectionClass(RabbitMQBackend::class);
        $this->assertTrue($ref->implementsInterface(QueueBackend::class));
    }

    public function testRabbitMQBackendConstructorAcceptsConfigArray(): void
    {
        $backend = new RabbitMQBackend([
            'host' => '10.0.0.1',
            'port' => 5673,
            'username' => 'admin',
            'password' => 'secret',
            'vhost' => '/test',
        ]);
        $this->assertInstanceOf(RabbitMQBackend::class, $backend);
    }

    public function testRabbitMQBackendConstructorEmptyConfig(): void
    {
        $backend = new RabbitMQBackend();
        $this->assertInstanceOf(RabbitMQBackend::class, $backend);
    }

    // -- KafkaBackend implements QueueBackend --------------------------------

    public function testKafkaBackendImplementsQueueBackend(): void
    {
        $ref = new \ReflectionClass(KafkaBackend::class);
        $this->assertTrue($ref->implementsInterface(QueueBackend::class));
    }

    public function testKafkaBackendConstructorAcceptsConfigArray(): void
    {
        $backend = new KafkaBackend([
            'brokers' => 'broker1:9092,broker2:9092',
            'group_id' => 'my_group',
        ]);
        $this->assertInstanceOf(KafkaBackend::class, $backend);
    }

    public function testKafkaBackendConstructorEmptyConfig(): void
    {
        $backend = new KafkaBackend();
        $this->assertInstanceOf(KafkaBackend::class, $backend);
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

    // -- MongoBackend implements QueueBackend ---------------------------------

    public function testMongoBackendImplementsInterface(): void
    {
        $ref = new \ReflectionClass(MongoBackend::class);
        $this->assertTrue($ref->implementsInterface(QueueBackend::class));
    }

    public function testMongoBackendHasRequiredMethods(): void
    {
        $this->assertTrue(method_exists(MongoBackend::class, 'enqueue'));
        $this->assertTrue(method_exists(MongoBackend::class, 'dequeue'));
        $this->assertTrue(method_exists(MongoBackend::class, 'acknowledge'));
        $this->assertTrue(method_exists(MongoBackend::class, 'requeue'));
        $this->assertTrue(method_exists(MongoBackend::class, 'deadLetter'));
        $this->assertTrue(method_exists(MongoBackend::class, 'size'));
        $this->assertTrue(method_exists(MongoBackend::class, 'close'));
    }

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

    public function testRabbitMQBackendHasConnectMethod(): void
    {
        $this->assertTrue(method_exists(RabbitMQBackend::class, 'connect'));
    }

    public function testRabbitMQBackendCloseWithoutConnect(): void
    {
        $backend = new RabbitMQBackend();
        $backend->close(); // Should not throw
        $this->assertTrue(true);
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

    public function testKafkaBackendHasConnectMethod(): void
    {
        $this->assertTrue(method_exists(KafkaBackend::class, 'connect'));
    }

    public function testKafkaBackendCloseWithoutConnect(): void
    {
        $backend = new KafkaBackend();
        $backend->close(); // Should not throw
        $this->assertTrue(true);
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

    // -- Interface parameter counts -----------------------------------------

    public function testQueueBackendEnqueueParameterCount(): void
    {
        $ref = new \ReflectionClass(QueueBackend::class);
        $method = $ref->getMethod('enqueue');
        $this->assertEquals(2, $method->getNumberOfParameters());
        $params = $method->getParameters();
        $this->assertEquals('topic', $params[0]->getName());
        $this->assertEquals('message', $params[1]->getName());
    }

    public function testQueueBackendDequeueParameterCount(): void
    {
        $ref = new \ReflectionClass(QueueBackend::class);
        $method = $ref->getMethod('dequeue');
        $this->assertEquals(1, $method->getNumberOfParameters());
        $this->assertEquals('topic', $method->getParameters()[0]->getName());
    }

    public function testQueueBackendAcknowledgeParameterCount(): void
    {
        $ref = new \ReflectionClass(QueueBackend::class);
        $method = $ref->getMethod('acknowledge');
        $this->assertEquals(2, $method->getNumberOfParameters());
    }

    public function testQueueBackendRequeueParameterCount(): void
    {
        $ref = new \ReflectionClass(QueueBackend::class);
        $method = $ref->getMethod('requeue');
        $this->assertEquals(2, $method->getNumberOfParameters());
    }

    public function testQueueBackendDeadLetterParameterCount(): void
    {
        $ref = new \ReflectionClass(QueueBackend::class);
        $method = $ref->getMethod('deadLetter');
        $this->assertEquals(2, $method->getNumberOfParameters());
    }

    public function testQueueBackendSizeParameterCount(): void
    {
        $ref = new \ReflectionClass(QueueBackend::class);
        $method = $ref->getMethod('size');
        $this->assertEquals(1, $method->getNumberOfParameters());
    }

    public function testQueueBackendCloseParameterCount(): void
    {
        $ref = new \ReflectionClass(QueueBackend::class);
        $method = $ref->getMethod('close');
        $this->assertEquals(0, $method->getNumberOfParameters());
    }

    // -- Return types -------------------------------------------------------

    public function testQueueBackendEnqueueReturnsString(): void
    {
        $ref = new \ReflectionClass(QueueBackend::class);
        $method = $ref->getMethod('enqueue');
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('string', $returnType->getName());
    }

    public function testQueueBackendDequeueReturnsNullableArray(): void
    {
        $ref = new \ReflectionClass(QueueBackend::class);
        $method = $ref->getMethod('dequeue');
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertTrue($returnType->allowsNull());
    }

    public function testQueueBackendSizeReturnsInt(): void
    {
        $ref = new \ReflectionClass(QueueBackend::class);
        $method = $ref->getMethod('size');
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('int', $returnType->getName());
    }

    public function testQueueBackendCloseReturnsVoid(): void
    {
        $ref = new \ReflectionClass(QueueBackend::class);
        $method = $ref->getMethod('close');
        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertEquals('void', $returnType->getName());
    }

    // -- MongoBackend config -------------------------------------------------

    public function testMongoBackendHasConnectMethod(): void
    {
        $this->assertTrue(method_exists(MongoBackend::class, 'connect') || method_exists(MongoBackend::class, 'enqueue'));
        // MongoBackend connects lazily; verify enqueue exists
        $this->assertTrue(method_exists(MongoBackend::class, 'enqueue'));
    }

    public function testMongoBackendRequiresMongodbExtension(): void
    {
        if (extension_loaded('mongodb')) {
            $this->markTestSkipped('ext-mongodb is installed — cannot test missing extension error');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('mongodb');
        new MongoBackend();
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

    // -- MongoBackend visibility timeout / reservation reclaim ---------------
    //
    // Regression lock for the production bug where a consumer that dies before
    // acknowledging left the message 'processing' forever — never re-delivered,
    // never dead-lettered. dequeue() now advances available_at = now +
    // visibility_timeout and stamps reserved_at; reclaimExpired() flips an
    // expired reservation back to pending (attempts++) or dead-letters it past
    // max_retries. ext-mongodb references real BSON/Operation classes, so the
    // behavioural cases skip when the driver is not installed (mirroring the
    // Python mongo mock cases, which run wherever pymongo is present).

    private function requireMongoOrSkip(): void
    {
        if (!extension_loaded('mongodb') || !class_exists('MongoDB\\BSON\\UTCDateTime')) {
            $this->markTestSkipped('ext-mongodb / mongodb library not installed — skipping Mongo reclaim behaviour test');
        }
    }

    private function makeMongoBackendWithCollection(object $collection, float $visibilityTimeout = 300.0, int $maxRetries = 3, float $retryBackoff = 0.0): MongoBackend
    {
        $backend = new MongoBackend([
            'visibility_timeout' => $visibilityTimeout,
            'max_retries' => $maxRetries,
            'retry_backoff' => $retryBackoff,
        ]);
        $ref = new \ReflectionClass($backend);
        $prop = $ref->getProperty('collection');
        $prop->setAccessible(true);
        $prop->setValue($backend, $collection);
        return $backend;
    }

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

    public function testMongoDequeueAdvancesAvailableAtAndRecordsReservedAt(): void
    {
        $this->requireMongoOrSkip();
        $collection = new FakeMongoCollection();
        // dequeue's reclaim pass runs first (no expired reservations), then the
        // claim returns this doc.
        $collection->queueFindOneAndUpdate(null);                       // reclaim: none expired
        $collection->queueFindOneAndUpdate(['_id' => 'msg-1', 'message' => ['payload' => ['x' => 1]], 'status' => 'processing']);
        $backend = $this->makeMongoBackendWithCollection($collection, 300.0);

        $backend->dequeue('emails');

        // The CLAIM update (the second findOneAndUpdate call) must advance
        // available_at and stamp reserved_at.
        $claim = $collection->findOneAndUpdateCalls[1];
        $set = $claim['update']['$set'];
        $this->assertSame('processing', $set['status']);
        $this->assertArrayHasKey('reserved_at', $set);
        $this->assertArrayHasKey('available_at', $set);
        // available_at is pushed into the future relative to the reservation stamp.
        $this->assertGreaterThan(
            $set['reserved_at']->toDateTime()->getTimestamp(),
            $set['available_at']->toDateTime()->getTimestamp()
        );
        // The claim predicate gates on pending + available_at <= now.
        $this->assertSame('pending', $claim['filter']['status']);
        $this->assertArrayHasKey('available_at', $claim['filter']);
    }

    public function testMongoReclaimRequeuesUnderLimit(): void
    {
        $this->requireMongoOrSkip();
        $collection = new FakeMongoCollection();
        // One expired reservation (attempts after inc = 1, below max 3), then none.
        $collection->queueFindOneAndUpdate(['_id' => 'msg-1', 'message' => ['payload' => ['x' => 1]], 'attempts' => 1]);
        $collection->queueFindOneAndUpdate(null);
        $backend = $this->makeMongoBackendWithCollection($collection, 300.0, maxRetries: 3);

        $count = $backend->reclaimExpired('emails', 3);
        $this->assertEquals(1, $count);

        // The reclaim flips processing -> pending and increments attempts.
        $first = $collection->findOneAndUpdateCalls[0];
        $this->assertSame('pending', $first['update']['$set']['status']);
        $this->assertEquals(1, $first['update']['$inc']['attempts']);
        // Under the limit: not dead-lettered, not deleted.
        $this->assertCount(0, $collection->insertOneCalls);
        $this->assertCount(0, $collection->deleteOneCalls);
    }

    public function testMongoReclaimDeadLettersPastMaxRetries(): void
    {
        $this->requireMongoOrSkip();
        $collection = new FakeMongoCollection();
        // The reclaimed doc's attempts (after inc) has hit the limit -> dead-letter.
        $collection->queueFindOneAndUpdate(['_id' => 'msg-1', 'message' => ['payload' => ['x' => 1]], 'attempts' => 3]);
        $collection->queueFindOneAndUpdate(null);
        $backend = $this->makeMongoBackendWithCollection($collection, 300.0, maxRetries: 3);

        $count = $backend->reclaimExpired('emails', 3);
        $this->assertEquals(1, $count);
        // Moved to the dead-letter topic and the original removed.
        $this->assertCount(1, $collection->insertOneCalls);
        $this->assertSame('emails.dead_letter', $collection->insertOneCalls[0]['topic']);
        $this->assertCount(1, $collection->deleteOneCalls);
    }

    public function testMongoReclaimDisabledWhenTimeoutZero(): void
    {
        if (!extension_loaded('mongodb')) {
            $this->markTestSkipped('ext-mongodb not installed — cannot construct MongoBackend');
        }
        $collection = new FakeMongoCollection();
        $backend = $this->makeMongoBackendWithCollection($collection, 0.0);
        $this->assertEquals(0, $backend->reclaimExpired('emails', 3));
        $this->assertCount(0, $collection->findOneAndUpdateCalls);
    }

    // -- Bug B: Mongo requeue resets available_at + clears reserved_at --------
    //
    // dequeue() pushes available_at out to now + visibility_timeout. requeue()
    // (the reject/requeue path) previously left available_at in the future, so a
    // fail()'d/retried job was invisible for the whole visibility window (300s
    // default) instead of retrying on the next pop. Now it resets available_at
    // to now (or now + retryBackoff) and clears reserved_at.

    public function testMongoRequeueResetsAvailableAtToNow(): void
    {
        $this->requireMongoOrSkip();
        $collection = new FakeMongoCollection();
        $collection->queueUpdateOne(1); // existing doc updated back to pending
        $backend = $this->makeMongoBackendWithCollection($collection, 300.0);

        $backend->requeue('emails', ['id' => 'msg-1', 'payload' => ['x' => 1], 'attempts' => 1]);

        $this->assertCount(1, $collection->updateOneCalls);
        $set = $collection->updateOneCalls[0]['update']['$set'];
        $this->assertSame('pending', $set['status']);
        $this->assertArrayHasKey('available_at', $set);
        $this->assertNull($set['reserved_at']);
        // retryBackoff = 0 -> available_at is "now", not the visibility-window
        // future. Allow a small clock skew window (within ~5s of now).
        $now = (int) round(microtime(true) * 1000);
        $availableMs = (int) ((string) $set['available_at']);
        $this->assertLessThanOrEqual(5000, abs($availableMs - $now), 'available_at should be ~now, not the visibility expiry');
    }

    public function testMongoRequeueRespectsRetryBackoff(): void
    {
        $this->requireMongoOrSkip();
        $collection = new FakeMongoCollection();
        $collection->queueUpdateOne(1);
        // retryBackoff = 30s — the requeued job should be delayed by ~30s, but
        // still far below the 300s visibility window.
        $backend = $this->makeMongoBackendWithCollection($collection, 300.0, maxRetries: 3, retryBackoff: 30.0);

        $backend->requeue('emails', ['id' => 'msg-1', 'payload' => ['x' => 1], 'attempts' => 1]);

        $set = $collection->updateOneCalls[0]['update']['$set'];
        $now = (int) round(microtime(true) * 1000);
        $availableMs = (int) ((string) $set['available_at']);
        $deltaSeconds = ($availableMs - $now) / 1000.0;
        // ~30s ahead (backoff), nowhere near the 300s visibility window.
        $this->assertGreaterThan(20, $deltaSeconds);
        $this->assertLessThan(60, $deltaSeconds);
    }

    // -- Bug C: Mongo dequeue surfaces the LIVE doc-level attempts -----------
    //
    // The document has a top-level attempts (incremented by reclaim/reject) AND
    // a push-time snapshot inside the stored 'message'. dequeue() previously
    // returned the snapshot (always 0) so fail()'s attempts >= max_retries check
    // never tripped and a job retried forever. dequeue now surfaces the live
    // top-level attempts (and priority).

    public function testMongoDequeueSurfacesLiveAttempts(): void
    {
        $this->requireMongoOrSkip();
        $collection = new FakeMongoCollection();
        $collection->queueFindOneAndUpdate(null); // reclaim: none expired
        // Top-level attempts=2 (live), but the snapshot inside 'message' is the
        // stale push-time 0.
        $collection->queueFindOneAndUpdate([
            '_id' => 'msg-1',
            'attempts' => 2,
            'priority' => 7,
            'message' => ['payload' => ['x' => 1], 'attempts' => 0, 'priority' => 0],
            'status' => 'processing',
        ]);
        $backend = $this->makeMongoBackendWithCollection($collection, 300.0);

        $job = $backend->dequeue('emails');

        // The consumer sees the LIVE attempts/priority, not the push-time 0.
        $this->assertSame(2, $job['attempts']);
        $this->assertSame(7, $job['priority']);
    }

    // -- Bug D: Mongo adapter dead-letter inspection + retry contract --------
    //
    // The Queue passes a max_retries kwarg to deadLetters()/retryFailed(); the
    // Mongo backend must accept it (matching the LiteBackend signature) so those
    // calls don't blow up on MongoDB. retryFailed() also resets available_at.

    public function testMongoDeadLettersAcceptsMaxRetriesKwarg(): void
    {
        $this->requireMongoOrSkip();
        $collection = new FakeMongoCollection();
        $collection->queueFind([
            ['_id' => 'd1', 'attempts' => 3, 'message' => ['payload' => ['x' => 1], 'attempts' => 0]],
        ]);
        $backend = $this->makeMongoBackendWithCollection($collection, 300.0, maxRetries: 3);

        // Must NOT throw a TypeError on the kwarg, and must query the
        // dead_letter topic with the LIVE attempts surfaced.
        $dead = $backend->deadLetters('emails', 5);

        $this->assertSame('emails.dead_letter', $collection->findCalls[0]['filter']['topic']);
        $this->assertCount(1, $dead);
        $this->assertSame(3, $dead[0]['attempts']); // live, not snapshot 0
        $this->assertSame('dead', $dead[0]['status']);
    }

    public function testMongoRetryFailedAcceptsMaxRetriesAndResetsAvailableAt(): void
    {
        $this->requireMongoOrSkip();
        $collection = new FakeMongoCollection();
        // One dead-letter under the (raised) limit -> requeued.
        $collection->queueFind([
            ['_id' => 'd1', 'attempts' => 2, 'message' => ['id' => 'd1', 'payload' => ['x' => 1]]],
        ]);
        $collection->queueUpdateOne(1); // requeue() updates the doc back to pending
        $backend = $this->makeMongoBackendWithCollection($collection, 300.0, maxRetries: 3);

        $count = $backend->retryFailed('emails', 5);

        $this->assertSame(1, $count);
        // Queried the dead_letter topic gated below the (raised) limit.
        $this->assertSame('emails.dead_letter', $collection->findCalls[0]['filter']['topic']);
        // requeue() ran and reset available_at + cleared reserved_at (Bug B reason).
        $this->assertCount(1, $collection->updateOneCalls);
        $set = $collection->updateOneCalls[0]['update']['$set'];
        $this->assertSame('pending', $set['status']);
        $this->assertArrayHasKey('available_at', $set);
        $this->assertNull($set['reserved_at']);
        // The dead-letter copy was removed.
        $this->assertCount(1, $collection->deleteOneCalls);
        $this->assertSame('emails.dead_letter', $collection->deleteOneCalls[0]['topic']);
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
}

/**
 * Minimal stand-in for \MongoDB\Collection that records calls and returns
 * scripted results. Returned docs are wrapped as objects so MongoBackend's
 * (array)$result cast and ['message'] access work like the real driver.
 */
class FakeMongoCollection
{
    public array $findOneAndUpdateCalls = [];
    public array $insertOneCalls = [];
    public array $deleteOneCalls = [];
    public array $updateOneCalls = [];
    public array $findCalls = [];

    /** @var array<int, mixed> Scripted findOneAndUpdate return values (FIFO). */
    private array $scriptedFindOneAndUpdate = [];

    /** @var array<int, mixed> Scripted updateOne modifiedCount values (FIFO). */
    private array $scriptedUpdateOne = [];

    /** @var array<int, array> Scripted find() result sets (FIFO). */
    private array $scriptedFind = [];

    public function queueFindOneAndUpdate(?array $doc): void
    {
        $this->scriptedFindOneAndUpdate[] = $doc;
    }

    public function queueUpdateOne(int $modifiedCount): void
    {
        $this->scriptedUpdateOne[] = $modifiedCount;
    }

    public function queueFind(array $docs): void
    {
        $this->scriptedFind[] = $docs;
    }

    public function findOneAndUpdate(array $filter, array $update, array $options = []): ?object
    {
        $this->findOneAndUpdateCalls[] = ['filter' => $filter, 'update' => $update, 'options' => $options];
        $doc = array_shift($this->scriptedFindOneAndUpdate);
        return $doc === null ? null : (object)$doc;
    }

    public function updateOne(array $filter, array $update, array $options = []): object
    {
        $this->updateOneCalls[] = ['filter' => $filter, 'update' => $update, 'options' => $options];
        $modified = array_shift($this->scriptedUpdateOne);
        $modified = $modified ?? 1;
        return new class($modified) {
            public function __construct(private int $modified) {}
            public function getModifiedCount(): int { return $this->modified; }
        };
    }

    public function find(array $filter = [], array $options = []): array
    {
        $this->findCalls[] = ['filter' => $filter, 'options' => $options];
        $docs = array_shift($this->scriptedFind) ?? [];
        return array_map(fn($d) => (object)$d, $docs);
    }

    public function insertOne(array $document): void
    {
        $this->insertOneCalls[] = $document;
    }

    public function deleteOne(array $filter): void
    {
        $this->deleteOneCalls[] = $filter;
    }
}
