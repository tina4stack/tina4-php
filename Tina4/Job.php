<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Job — Wraps a queue job with lifecycle methods.
 */

namespace Tina4;

/**
 * Job — Wraps a queue job with lifecycle methods.
 */
class Job
{
    public string $id;
    public mixed $payload;
    public string $status;
    public int $attempts;
    public ?string $error;
    private Queue $queue;
    private string $topic;

    public function __construct(array $data, Queue $queue, string $topic)
    {
        $this->id = $data['id'];
        $this->payload = $data['payload'];
        $this->status = $data['status'] ?? 'reserved';
        $this->attempts = $data['attempts'] ?? 0;
        $this->error = $data['error'] ?? null;
        $this->queue = $queue;
        $this->topic = $topic;
    }

    /**
     * Mark this job as completed (removes it from the queue).
     */
    public function complete(): void
    {
        $this->status = 'completed';
    }

    /**
     * Mark this job as failed with a reason.
     */
    public function fail(string $reason = ''): void
    {
        $this->status = 'failed';
        $this->error = $reason;
        $this->attempts++;

        $this->queue->writeFailed($this->topic, [
            'id' => $this->id,
            'payload' => $this->payload,
            'status' => 'failed',
            'attempts' => $this->attempts,
            'error' => $reason,
        ]
        );
    }

    /**
     * Reject this job with a reason. Alias for fail().
     */
    public function reject(string $reason = ''): void
    {
        $this->fail($reason);
    }

    /**
     * Re-queue this job with an optional delay.
     */
    public function retry(int $delaySeconds = 0): void
    {
        $this->queue->retry($this->id, $delaySeconds);
    }

    /**
     * Get the raw job data as a flat list of values.
     */
    public function toArray(): array
    {
        return [$this->id, $this->topic, $this->payload, $this->attempts, $this->error];
    }

    /**
     * Get the job as an associative array (hash).
     */
    public function toHash(): array
    {
        return [
            'id' => $this->id,
            'topic' => $this->topic,
            'payload' => $this->payload,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'error' => $this->error,
        ];
    }

    /**
     * Get the job as a JSON string.
     */
    public function toJson(): string
    {
        return json_encode($this->toHash(), JSON_PRETTY_PRINT);
    }
}
