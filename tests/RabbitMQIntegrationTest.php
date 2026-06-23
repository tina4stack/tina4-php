<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Live RabbitMQ integration test — exercises the REAL broker (no mocks).
 *
 * Locks in the AMQP 0-9-1 handshake fix: RabbitMQBackend::sendConnectionTuneOk()
 * must NEGOTIATE Connection.TuneOk from the broker's Connection.Tune rather than
 * hardcoding channel-max=0. A hardcoded channel-max of 0 means "no limit", which
 * RabbitMQ treats as exceeding its proposed channel-max (2047) and so it closes
 * the socket right after Connection.Open — surfacing as "Failed to read AMQP
 * frame header" at the Connection.OpenOk read. If connect() completes and a full
 * enqueue -> size -> dequeue -> acknowledge -> size cycle works on a fresh queue,
 * the handshake negotiated correctly.
 *
 * Gated on TINA4_TEST_RABBITMQ_URL (e.g. amqp://guest:guest@localhost:5672).
 * Skips cleanly when the env var is unset or the broker is unreachable — it never
 * fakes the broker (NO mock testing: a queue test must hit a real RabbitMQ).
 */

use PHPUnit\Framework\TestCase;
use Tina4\Queue\RabbitMQBackend;

class RabbitMQIntegrationTest extends TestCase
{
    /** @var array{host:string,port:int,username:string,password:string,vhost:string}|null */
    private static ?array $config = null;

    /** @var string Unique queue name for this run (cleaned up in tearDown). */
    private string $queue = '';

    /**
     * Parse amqp://[user:pass@]host[:port][/vhost] into a RabbitMQBackend config.
     */
    private static function parseAmqpUrl(string $url): array
    {
        $rest = preg_replace('#^amqps?://#', '', $url);

        $username = 'guest';
        $password = 'guest';
        $vhost = '/';

        $atPos = strpos($rest, '@');
        if ($atPos !== false) {
            $creds = substr($rest, 0, $atPos);
            $rest = substr($rest, $atPos + 1);
            $colon = strpos($creds, ':');
            if ($colon !== false) {
                $username = substr($creds, 0, $colon);
                $password = substr($creds, $colon + 1);
            } else {
                $username = $creds;
            }
        }

        $hostport = $rest;
        $slash = strpos($rest, '/');
        if ($slash !== false) {
            $hostport = substr($rest, 0, $slash);
            $vh = substr($rest, $slash + 1);
            if ($vh !== '') {
                $vhost = ($vh[0] === '/') ? $vh : '/' . $vh;
            }
        }

        $host = $hostport;
        $port = 5672;
        $portColon = strpos($hostport, ':');
        if ($portColon !== false) {
            $host = substr($hostport, 0, $portColon);
            $port = (int)substr($hostport, $portColon + 1);
        }

        return [
            'host' => $host ?: 'localhost',
            'port' => $port ?: 5672,
            'username' => $username,
            'password' => $password,
            'vhost' => $vhost,
        ];
    }

    private static function brokerReachable(array $config): bool
    {
        $fp = @fsockopen($config['host'], $config['port'], $errno, $errstr, 1.5);
        if ($fp) {
            fclose($fp);
            return true;
        }
        return false;
    }

    private function backend(): RabbitMQBackend
    {
        return new RabbitMQBackend(self::$config);
    }

    protected function setUp(): void
    {
        $url = getenv('TINA4_TEST_RABBITMQ_URL');
        if ($url === false || $url === '') {
            $this->markTestSkipped('Set TINA4_TEST_RABBITMQ_URL (e.g. amqp://guest:guest@localhost:5672) to run the live RabbitMQ integration test.');
        }

        self::$config = self::parseAmqpUrl($url);

        if (!self::brokerReachable(self::$config)) {
            $this->markTestSkipped('RabbitMQ broker unreachable at ' . self::$config['host'] . ':' . self::$config['port'] . '.');
        }

        $this->queue = 'tina4_test_' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if ($this->queue === '' || self::$config === null) {
            return;
        }
        // Best-effort drain + purge so a re-run starts from a clean broker.
        try {
            $backend = $this->backend();
            $backend->connect();
            // Drain any leftover messages (dequeue+ack) until the queue is empty.
            for ($i = 0; $i < 100; $i++) {
                $msg = $backend->dequeue($this->queue);
                if ($msg === null) {
                    break;
                }
                $backend->acknowledge($this->queue, (string)($msg['id'] ?? ''));
            }
            $backend->close();
        } catch (\Throwable $e) {
            // Cleanup is best-effort — never fail the test on a teardown hiccup.
        }
    }

    /**
     * The full lifecycle: connect() (real handshake), size()==0 on a fresh queue,
     * enqueue, size()==1, dequeue() returns the message, acknowledge(), size()==0.
     *
     * size() is read on a fresh connection each time because the backend caches a
     * declared queue within a connection (it returns 0 for an already-declared
     * queue rather than re-declaring to read the live count) — the broker's true
     * count is what we assert.
     */
    public function testFullEnqueueDequeueAcknowledgeCycle(): void
    {
        // connect() must complete the AMQP handshake (the bug threw here).
        $backend = $this->backend();
        $backend->connect();
        $this->assertSame(0, $backend->size($this->queue), 'A fresh queue must be empty.');

        $payload = ['task' => 'integration', 'value' => 12345];
        $id = $backend->enqueue($this->queue, $payload);
        $this->assertNotEmpty($id, 'enqueue() must return a message id.');
        $backend->close();

        // Fresh connection -> true broker count after enqueue.
        $reader = $this->backend();
        $reader->connect();
        $this->assertSame(1, $reader->size($this->queue), 'Queue must hold exactly one message after enqueue.');

        $message = $reader->dequeue($this->queue);
        $this->assertIsArray($message, 'dequeue() must return the enqueued message.');
        $this->assertSame('integration', $message['task'] ?? null);
        $this->assertSame(12345, $message['value'] ?? null);
        $this->assertSame($id, $message['id'] ?? null, 'The id must round-trip.');

        $reader->acknowledge($this->queue, $id);
        $reader->close();

        // Fresh connection -> the acknowledged message is gone.
        $verifier = $this->backend();
        $verifier->connect();
        $this->assertSame(0, $verifier->size($this->queue), 'Queue must be empty after acknowledge.');
        $verifier->close();
    }
}
