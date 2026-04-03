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
     * Close the connection and clean up resources.
     */
    public function close(): void;
}
