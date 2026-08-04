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
 *
 * ARRAY-COMPATIBLE ON PURPOSE. Queue::pop(), popBatch() and popById() used to
 * hand back the backend's raw array, so the lifecycle this class exists for was
 * reachable from consume() and unreachable from pop() — `$queue->pop()->fail()`
 * was a fatal in PHP while the identical line worked in Python, Ruby and Node
 * (ADR-0024: the same concept must be the same shape in all four). Those three
 * methods now return a Job, and Job implements ArrayAccess so every existing
 * `$job['id']` / `$job['payload']` caller keeps working unchanged.
 *
 * Reads see the CANONICAL field first (so `$job['attempts']` reflects a fail()
 * that already happened) and fall back to whatever else the backend stored
 * (created_at, delay_until, …). Writes are refused: a job is a claim on a
 * message, not a bag you edit — use complete()/fail()/retry() to change it.
 */
class Job implements \ArrayAccess, \JsonSerializable
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
    /**
     * The backend record exactly as stored, so array reads of fields Job does
     * not model (created_at, delay_until, …) keep resolving after pop() started
     * returning a Job instead of this array.
     *
     * @var array<string, mixed>
     */
    private array $raw;

    /**
     * @param array|Job $data  The backend record, or an existing Job to re-wrap
     * @param Queue     $queue The queue this job's lifecycle calls route through
     * @param string    $topic Topic/queue the job belongs to
     */
    public function __construct(array|Job $data, Queue $queue, string $topic)
    {
        // A Job is accepted, not just an array, because the ONLY way to reach
        // the lifecycle before pop() returned a Job was to re-wrap by hand -
        // `new Job($queue->pop(), $queue, $topic)` - and the shipped example
        // taught exactly that. Refusing it would break every app that copied
        // the workaround, on an upgrade whose entire point was to remove the
        // need for it. Re-wrapping a Job is now a copy.
        $data = $data instanceof self ? $data->toHash() + $data->raw : $data;
        $this->id = $data['id'];
        $this->payload = $data['payload'] ?? ($data['data'] ?? null);
        $this->status = $data['status'] ?? 'reserved';
        $this->attempts = $data['attempts'] ?? 0;
        $this->error = $data['error'] ?? null;
        $this->priority = (int)($data['priority'] ?? 0);
        $this->queue = $queue;
        $this->topic = $topic;
        $this->raw = $data;
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

    /**
     * Serialize to the same shape toJson() and toHash() produce.
     *
     * Without this, json_encode() of a popped job would emit only the PUBLIC
     * properties and silently drop nothing today but drift the moment a field
     * turns private — and it would not match toJson(). One shape, one spelling.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toHash();
    }

    /**
     * @param mixed $offset Field name
     */
    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->toHash())
            || array_key_exists($offset, $this->raw);
    }

    /**
     * Read a field the way the raw array read before pop() returned a Job.
     *
     * Canonical fields win over the stored record so a read after fail() sees
     * the NEW attempts/status/error rather than the values the backend wrote
     * when the job was claimed. An unknown key returns null, exactly as reading
     * a missing key off the old array did (PHP would warn on the array; here it
     * is simply absent).
     *
     * @param mixed $offset Field name
     */
    public function offsetGet(mixed $offset): mixed
    {
        $canonical = $this->toHash();
        if (array_key_exists($offset, $canonical)) {
            return $canonical[$offset];
        }
        return $this->raw[$offset] ?? null;
    }

    /**
     * Refused: a job is a claim on a message, not a bag.
     *
     * Silently accepting `$job['attempts'] = 9` would let a caller believe it
     * had changed the queue when it had changed one in-memory object; the
     * backend would never hear about it. complete()/fail()/retry() are the ways
     * a job changes.
     *
     * @param mixed $offset Field name
     * @param mixed $value  Ignored
     * @throws \LogicException Always
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException(
            'A Tina4\Job is read-only: $job[' . var_export($offset, true) . '] cannot be assigned. '
            . 'Writing it would change this object and never reach the queue. '
            . 'Use $job->complete(), $job->fail($reason) or $job->retry($delaySeconds) instead.'
        );
    }

    /**
     * Refused, for the same reason as offsetSet().
     *
     * @param mixed $offset Field name
     * @throws \LogicException Always
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException(
            'A Tina4\Job is read-only: $job[' . var_export($offset, true) . '] cannot be unset. '
            . 'Use $job->complete(), $job->fail($reason) or $job->retry($delaySeconds) instead.'
        );
    }
}
