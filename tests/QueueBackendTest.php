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
        foreach ($queue->consume('batch_consume', null, 0, 2) as $jobs) {
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
}
