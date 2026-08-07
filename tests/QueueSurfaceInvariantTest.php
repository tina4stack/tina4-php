<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;

/**
 * queue_contract.json :: every-method-exists-on-every-backend
 *
 * RULE: every public Queue method RESOLVES and runs on every configured
 * backend. No method may be a fatal error or an undefined method on any
 * backend the framework offers.
 *
 * This is not the same rule as invariant 6. Invariant 6 says an operation a
 * backend cannot perform must THROW naming itself. Invariant 1 says the method
 * must EXIST to do the throwing. A named refusal satisfies both; a
 * "Call to undefined method" fatal satisfies neither.
 *
 * MEASURED 2026-08-03: failed(), deadLetters(), retry() and retryFailed() were
 * a FATAL \Error - "Call to undefined method Tina4\Queue\RabbitMQBackend::
 * failed()" - on rabbitmq AND kafka. The root cause was structural: the
 * QueueBackend INTERFACE never declared them, so only LiteBackend and
 * MongoBackend happened to define them while Queue::failed() called them on
 * every backend. They are now declared on the interface, which makes the type
 * system enforce this invariant for those three methods permanently.
 *
 * The brokers answer with a named refusal rather than an empty list, because
 * an empty list claims nothing has failed - a different answer to the question
 * asked (ADR-0022 decision 7).
 *
 * NO MOCKS. Every assertion drives a live MongoDB over TCP. If it is
 * unreachable the test skips, unless TINA4_REQUIRE_SERVICES is set.
 *
 * The three case names here are shared VERBATIM with the Python, Ruby and Node
 * suites, because scripts/audit-contract-fixtures.py resolves ONE fixture case
 * against EVERY framework's file.
 */
final class QueueSurfaceInvariantTest extends TestCase
{
    private string $host;
    private int $port;

    protected function setUp(): void
    {
        $this->host = getenv('TINA4_TEST_MONGO_HOST') ?: '127.0.0.1';
        $this->port = (int)(getenv('TINA4_TEST_MONGO_PORT') ?: 27017);

        putenv("TINA4_MONGO_URI=mongodb://{$this->host}:{$this->port}");
        putenv('TINA4_QUEUE_PATH=' . sys_get_temp_dir() . '/qsurf_' . bin2hex(random_bytes(5)));

        // Point the brokers at whatever the runner provides. An UNREACHABLE
        // broker is a valid input here rather than a skip: this invariant is
        // about a method resolving, and a connection failure must surface as a
        // clean exception, never as a fatal \Error. That was itself a defect -
        // a failed connect stored FALSE in the socket, so every later call did
        // fwrite(false, ...) and died with a TypeError.
        $rabbit = getenv('TINA4_TEST_RABBITMQ_HOST') ?: '127.0.0.1';
        putenv("TINA4_RABBITMQ_HOST={$rabbit}");
        putenv('TINA4_RABBITMQ_PORT=' . (getenv('TINA4_TEST_RABBITMQ_PORT') ?: '5672'));
        putenv('TINA4_RABBITMQ_USERNAME=guest');
        putenv('TINA4_RABBITMQ_PASSWORD=guest');
        $kafka = getenv('TINA4_TEST_KAFKA_HOST') ?: '127.0.0.1';
        putenv("TINA4_KAFKA_BROKERS={$kafka}:" . (getenv('TINA4_TEST_KAFKA_PORT') ?: '9092'));
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

    private function queue(string $backend): \Tina4\Queue
    {
        return new \Tina4\Queue($backend, [], 'surf_' . bin2hex(random_bytes(6)));
    }

    /**
     * @return array<string, callable>
     */
    private function methods(): array
    {
        return [
            'size'        => fn($q) => $q->size(),
            'popBatch'    => fn($q) => $q->popBatch(1),
            'popById'     => fn($q) => $q->popById('nope'),
            'failed'      => fn($q) => $q->failed(),
            'deadLetters' => fn($q) => $q->deadLetters(),
            'retry'       => fn($q) => $q->retry(null),
            'retryFailed' => fn($q) => $q->retryFailed(),
            'purge'       => fn($q) => $q->purge('completed'),
            'clear'       => fn($q) => $q->clear(),
        ];
    }

    /**
     * A method must never be ABSENT. A fatal \Error means the call site cannot
     * even reach a refusal - the upgrade path is severed rather than degraded,
     * which is the exact scenario ADR-0024 exists to prevent.
     *
     * The brokers are included deliberately: this is the case that was failing.
     */
    public function testEveryPublicQueueMethodResolvesOnEveryBackend(): void
    {
        $this->requireMongo();

        // Each method is exercised TWICE, because the two modes catch different
        // bugs. FRESH catches a method that never initialises what it uses
        // (Python's clear() reached an unconnected collection; on a shared
        // queue an earlier call had already connected, hiding it). SHARED
        // catches state a previous call left behind - which is exactly how the
        // broker socket bug surfaced here: a failed connect stored FALSE, so
        // every later call died in fwrite().
        $unresolved = [];
        foreach (['file', 'mongodb', 'rabbitmq', 'kafka'] as $backend) {
            foreach (['fresh', 'shared'] as $mode) {
                $shared = $mode === 'shared' ? $this->queue($backend) : null;
                foreach ($this->methods() as $name => $call) {
                    $queue = $shared ?? $this->queue($backend);
                    try {
                        $call($queue);
                    } catch (\Error $e) {
                        // \Error is the violation: undefined method / bad call.
                        $unresolved[] = "{$backend}::{$name} ({$mode}) — " . $e->getMessage();
                    } catch (\Throwable $e) {
                        // A RuntimeException (named refusal) or any runtime failure
                        // is NOT this invariant's concern - the method resolved.
                    }
                }
            }
        }

        $this->assertSame([], $unresolved, "methods that do not resolve:\n" . implode("\n", $unresolved));
    }

    /**
     * The broker keeps no queryable registry of failed jobs. Returning an empty
     * list would claim nothing has failed, which is a different answer to the
     * question asked - so it must refuse, naming itself and the method.
     */
    public function testABackendThatCannotAnswerAQuestionRefusesByNameInsteadOfLying(): void
    {
        foreach (['rabbitmq', 'kafka'] as $backend) {
            foreach (['failed', 'deadLetters', 'retryFailed'] as $method) {
                $queue = $this->queue($backend);
                $message = null;
                try {
                    $queue->{$method}();
                } catch (\Error $e) {
                    $this->fail("{$backend}::{$method}() is a FATAL, not a refusal: " . $e->getMessage());
                } catch (\RuntimeException $e) {
                    $message = $e->getMessage();
                }

                // Asserted OUTSIDE the catch: PHPUnit's AssertionFailedError extends
                // \RuntimeException, so a $this->fail() inside the try would be
                // swallowed by the \RuntimeException block and hide a method that
                // answered instead of refusing (the ghost). The \Error catch stays -
                // a FATAL is a genuine failure and its $this->fail() propagates (a
                // sibling catch never swallows it). assertNotNull is the real gate.
                $this->assertNotNull($message, "{$backend}::{$method}() must refuse, not answer");
                $this->assertStringContainsString($backend, (string)$message, 'the refusal must name the backend');
                $this->assertStringContainsString($method, (string)$message, 'the refusal must name the method');
            }
        }
    }

    /**
     * NEGATIVE: without this, "make every method throw a named refusal" would
     * satisfy both cases above while breaking the whole queue. A backend that
     * CAN answer must actually answer.
     */
    public function testASupportedMethodReturnsARealAnswerRatherThanARefusal(): void
    {
        $this->requireMongo();

        foreach (['file', 'mongodb'] as $backend) {
            $queue = $this->queue($backend);
            $this->assertIsInt($queue->size(), "{$backend}: size must return a real count");
            $this->assertIsArray($queue->failed(), "{$backend}: failed must return a real list");
            $this->assertIsArray($queue->deadLetters(), "{$backend}: deadLetters must return a real list");
            $this->assertIsInt($queue->clear(), "{$backend}: clear must return a real count");
        }
    }
}
