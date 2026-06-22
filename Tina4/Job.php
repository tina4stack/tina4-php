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
    /** Topic/queue this job belongs to. Publicly readable (docs use $job->topic). */
    public string $topic;
    /** Job priority (higher = dequeued first). Carried through re-enqueue. */
    public int $priority;
    private Queue $queue;

    public function __construct(array $data, Queue $queue, string $topic)
    {
        $this->id = $data['id'];
        $this->payload = $data['payload'] ?? ($data['data'] ?? null);
        $this->status = $data['status'] ?? 'reserved';
        $this->attempts = $data['attempts'] ?? 0;
        $this->error = $data['error'] ?? null;
        $this->priority = (int)($data['priority'] ?? 0);
        $this->queue = $queue;
        $this->topic = $topic;
    }

    /**
     * Mark this job as completed. Terminal — the pending file was claimed on
     * pop and a reservation record written; complete() is terminal, so drop the
     * reservation. The job is done.
     */
    public function complete(): void
    {
        $this->status = 'completed';
        $this->queue->completeJob($this->topic, $this->id);
    }

    /**
     * Record a failed attempt.
     *
     * Increments attempts and stores the error. If the job still has retries
     * left (attempts < maxRetries) it is automatically re-enqueued to the
     * pending queue, so the next pop()/consume() picks it up again (after the
     * queue's retryBackoff delay, if any). Once it has been attempted
     * maxRetries times it is moved to the dead-letter store, where
     * Queue::deadLetters() returns it. No manual Queue::retryFailed() needed.
     */
    public function fail(string $reason = ''): void
    {
        $this->status = 'failed';
        $this->error = $reason;
        $this->queue->failJob($this->topic, $this->snapshot(), $reason);
        // Reflect the post-fail attempt count on this in-memory instance.
        $this->attempts++;
    }

    /**
     * Reject this job with a reason. Alias for fail().
     */
    public function reject(string $reason = ''): void
    {
        $this->fail($reason);
    }

    /**
     * Explicitly re-queue this job with an optional delay. Always re-enqueues
     * regardless of the retry limit — a manual override, distinct from fail().
     */
    public function retry(int $delaySeconds = 0): void
    {
        $this->queue->retryJob($this->topic, $this->snapshot(), $delaySeconds);
        $this->attempts++;
    }

    /**
     * Build the persisted job-data snapshot for the backend lifecycle calls.
     */
    private function snapshot(): array
    {
        return [
            'id'       => $this->id,
            'payload'  => $this->payload,
            'priority' => $this->priority,
            'attempts' => $this->attempts,
            'error'    => $this->error,
            'topic'    => $this->topic,
        ];
    }

    /**
     * Get the raw job data as a flat list of values.
     */
    public function toArray(): array
    {
        return [$this->id, $this->topic, $this->payload, $this->priority, $this->attempts];
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
            'priority' => $this->priority,
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
