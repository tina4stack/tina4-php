<?php

/**
 * Tina4 - This is not a 4ramework.
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
        $hostProp->setAccessible(true);
        $this->assertEquals('envhost', $hostProp->getValue($backend));

        $portProp = $ref->getProperty('port');
        $portProp->setAccessible(true);
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
        $brokersProp->setAccessible(true);
        $this->assertEquals('kafka1:9092,kafka2:9092', $brokersProp->getValue($backend));

        $groupProp = $ref->getProperty('groupId');
        $groupProp->setAccessible(true);
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
        $hostProp->setAccessible(true);
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
}
