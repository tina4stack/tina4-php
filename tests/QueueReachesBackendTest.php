<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;

/**
 * queue_contract.json :: operations-reach-the-configured-backend
 *
 * RULE: every operation acts on the CONFIGURED backend. No method may silently
 * read or write the local file store when another backend is selected.
 *
 * MEASURED 2026-08-03 on mongodb, before the fix: PHP's clear() and purge()
 * called $this->liteBackend unconditionally, so clearing a mongodb-backed queue
 * emptied a local directory and left every real job in place; and popById()
 * returned null on every external backend with the comment "External backends
 * don't support ID-based pop natively" - which mongodb does.
 *
 * This is the worst failure class: the call appears to succeed and operates on
 * the wrong data, so nothing surfaces it.
 *
 * NO MOCKS. Live MongoDB over TCP; skips unless TINA4_REQUIRE_SERVICES is set.
 *
 * The three case names here are shared VERBATIM with the Python, Ruby and Node
 * suites.
 */
final class QueueReachesBackendTest extends TestCase
{
    private string $host;
    private int $port;

    protected function setUp(): void
    {
        $this->host = getenv('TINA4_TEST_MONGO_HOST') ?: '127.0.0.1';
        $this->port = (int)(getenv('TINA4_TEST_MONGO_PORT') ?: 27017);
        putenv("TINA4_MONGO_URI=mongodb://{$this->host}:{$this->port}");
        putenv('TINA4_QUEUE_PATH=' . sys_get_temp_dir() . '/qreach_' . bin2hex(random_bytes(5)));
    }

    private function requireMongo(): void
    {
        if (!extension_loaded('mongodb')) {
            $this->skipOrFail('ext-mongodb is not loaded');
        }
        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, 2);
        if ($socket === false) {
            $this->skipOrFail("MongoDB is not reachable at {$this->host}:{$this->port}");
        }
        fclose($socket);
    }

    private function skipOrFail(string $why): void
    {
        if (getenv('TINA4_REQUIRE_SERVICES')) {
            $this->fail("TINA4_REQUIRE_SERVICES is set but {$why}");
        }
        $this->markTestSkipped($why);
    }

    private function mongoQueue(): \Tina4\Queue
    {
        return new \Tina4\Queue('mongodb', [], 'reach_' . bin2hex(random_bytes(6)));
    }

    /**
     * If clear() hits the file store, the mongodb jobs survive and size stays 2.
     */
    public function testClearActsOnTheConfiguredBackendNotTheLocalFileStore(): void
    {
        $this->requireMongo();

        $queue = $this->mongoQueue();
        $queue->push(['m' => 'a'], 0, 0);
        $queue->push(['m' => 'b'], 0, 0);
        $this->assertSame(2, $queue->size(), 'the pushes must reach mongodb first, or this proves nothing');

        $queue->clear();

        $this->assertSame(0, $queue->size(), 'clear() must empty the CONFIGURED backend');
    }

    /**
     * The job is in mongodb and we ask for it by its own id. Getting nothing
     * back means the call went somewhere else.
     */
    public function testPopByIdClaimsTheJobFromTheConfiguredBackend(): void
    {
        $this->requireMongo();

        $queue = $this->mongoQueue();
        $id = $queue->push(['m' => 'byid'], 0, 0);

        $this->assertNotNull($queue->popById($id), 'popById must claim the job from the configured backend');
    }

    /**
     * A broker cannot address one message by id. It must say so, naming itself
     * and the operation - never quietly answer from a local directory.
     */
    public function testAnOperationTheBackendCannotPerformRefusesInsteadOfSilentlyUsingTheFileStore(): void
    {
        foreach (['rabbitmq', 'kafka'] as $backend) {
            $queue = new \Tina4\Queue($backend, [], 'reach_' . bin2hex(random_bytes(6)));
            $message = null;
            try {
                $queue->popById('whatever');
            } catch (\RuntimeException $e) {
                $message = $e->getMessage();
            }

            // Asserted OUTSIDE the catch: PHPUnit's AssertionFailedError extends
            // \RuntimeException, so a $this->fail() inside the try would be caught
            // here and hide a popById() that quietly answered from local disk (the
            // ghost). assertNotNull makes this a real gate.
            $this->assertNotNull($message, "{$backend}::popById() must refuse, not answer from local disk");
            $this->assertStringContainsString($backend, (string)$message, 'the refusal must name the backend');
            $this->assertStringContainsString('popById', (string)$message, 'the refusal must name the operation');
        }
    }
}
