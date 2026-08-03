<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Queue Backend Interface — contract for all queue backends.
 */

namespace Tina4\Queue;

interface QueueBackend
{
    /**
     * Push a message onto a topic/queue.
     *
     * @param string $topic   The queue/topic name
     * @param array  $message Message data (will be JSON-encoded)
     * @return string Message ID
     */
    public function enqueue(string $topic, array $message): string;

    /**
     * Pop a message from a topic/queue.
     *
     * @param string $topic The queue/topic name
     * @return array|null Message data or null if empty
     */
    public function dequeue(string $topic): ?array;

    /**
     * Acknowledge a message has been processed.
     *
     * @param string $topic     The queue/topic name
     * @param string $messageId The message ID to acknowledge
     */
    public function acknowledge(string $topic, string $messageId): void;

    /**
     * Re-queue a message for retry.
     *
     * @param string $topic   The queue/topic name
     * @param array  $message The message data
     */
    public function requeue(string $topic, array $message): void;

    /**
     * Send a message to the dead letter queue.
     *
     * @param string $topic   The original queue/topic name
     * @param array  $message The message data
     */
    public function deadLetter(string $topic, array $message): void;

    /**
     * Get the number of messages in a queue.
     *
     * @param string $topic The queue/topic name
     * @return int
     */
    public function size(string $topic): int;

    /**
     * List the jobs that failed but have retries left.
     *
     * Declared here because Queue::failed() calls it on EVERY backend. It used
     * to be absent from this interface while only LiteBackend and MongoBackend
     * happened to define it, so the call was a FATAL "undefined method" on
     * rabbitmq and kafka. A backend that keeps no such registry must throw a
     * refusal naming itself, never a fatal and never a misleading empty list.
     *
     * @param string $topic The queue/topic name
     * @return array<int, array<string, mixed>>
     * @throws \RuntimeException When the backend cannot answer the question
     */
    public function failed(string $topic): array;

    /**
     * List the jobs that exhausted their retries.
     *
     * @param string $topic The queue/topic name
     * @param int|null $maxRetries Attempt limit; null uses the queue's own
     * @return array<int, array<string, mixed>>
     * @throws \RuntimeException When the backend cannot answer the question
     */
    public function deadLetters(string $topic, ?int $maxRetries = null): array;

    /**
     * Re-queue every failed job still under the retry limit. Returns the count.
     *
     * @param string $topic The queue/topic name
     * @param int|null $maxRetries Attempt limit; null uses the queue's own
     * @return int
     * @throws \RuntimeException When the backend cannot perform the operation
     */
    public function retryFailed(string $topic, ?int $maxRetries = null): int;

    /**
     * Close the connection and clean up resources.
     */
    public function close(): void;
}
