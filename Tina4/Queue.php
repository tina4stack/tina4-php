<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Queue — Unified job queue with pluggable backends, zero dependencies.
 *
 * Switching from file to RabbitMQ or Kafka is a .env change — no code change needed.
 *
 * Supported backends:
 *   - 'sqlite'   — SQLite database (default)
 *   - 'rabbitmq' — RabbitMQ via raw TCP sockets (AMQP 0-9-1)
 *   - 'kafka'    — Kafka via raw TCP sockets
 *   - 'mongodb'  — MongoDB via ext-mongodb (also accepts 'mongo')
 *
 * Environment variables:
 *   TINA4_QUEUE_BACKEND — 'file', 'rabbitmq', 'kafka', or 'mongodb'
 *   TINA4_QUEUE_URL     — connection URL for rabbitmq/kafka
 *
 * Usage:
 *   // Auto-detect from env (default: file)
 *   $queue = new Queue(topic: 'tasks');
 *
 *   // Explicit backend
 *   $queue = new Queue(topic: 'tasks', backend: 'rabbitmq');
 *
 *   // Legacy usage (still works — uses file backend)
 *   $queue = new Queue('file', ['path' => 'data/queue']);
 */

namespace Tina4;

use Tina4\Queue\QueueBackend;
use Tina4\Queue\LiteBackend;
use Tina4\Queue\RabbitMQBackend;
use Tina4\Queue\KafkaBackend;
use Tina4\Queue\MongoBackend;

class Queue
{
    private string $backend;
    private string $basePath;
    private int $maxRetries;
    private int $retryBackoff;
    private float $visibilityTimeout;
    private string $topic;

    /** @var LiteBackend File-based queue backend */
    private LiteBackend $liteBackend;

    /** @var QueueBackend|null External queue backend (rabbitmq, kafka) */
    private ?QueueBackend $externalBackend = null;

    /**
     * Unified Queue constructor.
     *
     * @param string $backend    Queue backend type: 'file', 'rabbitmq', 'kafka'
     * @param array  $config     Configuration: path, maxRetries, retryBackoff, and backend-specific options
     * @param string $topic      Default topic/queue name
     */
    public function __construct(string $backend = 'file', array $config = [], string $topic = 'default')
    {
        $this->backend = getenv('TINA4_QUEUE_BACKEND') ?: $backend;
        $this->basePath = $config['path'] ?? (getenv('TINA4_QUEUE_PATH') ?: 'data/queue');
        $this->maxRetries = $config['maxRetries'] ?? 3;
        // Seconds to delay a failed job's automatic re-enqueue (parity with
        // Python's retry_backoff). 0 = retry on the next pop()/consume().
        $this->retryBackoff = (int)($config['retryBackoff'] ?? 0);
        // Reservation/visibility timeout (seconds). A popped job is reserved for
        // this long; if the consumer dies before complete()/fail() the next
        // pop() reclaims it (at-least-once delivery). Falls back to
        // TINA4_QUEUE_VISIBILITY_TIMEOUT, else 300 (5 min). <= 0 disables reclaim.
        // File + MongoDB backends only — RabbitMQ/Kafka delegate visibility to
        // the broker.
        $this->visibilityTimeout = $this->resolveVisibilityTimeout($config);
        $this->topic = $topic;

        // Always initialise the lite (file) backend
        $this->liteBackend = new LiteBackend($this->basePath, $this->maxRetries, $this->retryBackoff, $this->visibilityTimeout);

        // Initialize external backends
        if ($this->backend === 'rabbitmq') {
            // Broker manages visibility/redelivery (unacked messages requeue on
            // channel close) — the framework timeout is accepted but not used.
            $resolvedConfig = $this->resolveRabbitMQConfig($config);
            $this->externalBackend = new RabbitMQBackend($resolvedConfig);
        } elseif ($this->backend === 'kafka') {
            // Consumer-group offsets manage redelivery — framework timeout N/A.
            $resolvedConfig = $this->resolveKafkaConfig($config);
            $this->externalBackend = new KafkaBackend($resolvedConfig);
        } elseif ($this->backend === 'mongodb' || $this->backend === 'mongo') {
            $resolvedConfig = $this->resolveMongoConfig($config);
            $resolvedConfig['visibility_timeout'] = $this->visibilityTimeout;
            $resolvedConfig['max_retries'] = $this->maxRetries;
            $this->externalBackend = new MongoBackend($resolvedConfig);
        }
    }

    /**
     * Get the external queue backend instance (if using rabbitmq or kafka).
     *
     * @return QueueBackend|null
     */
    private function getExternalBackend(): ?QueueBackend
    {
        return $this->externalBackend;
    }

    /**
     * Push a job onto the queue.
     *
     * @param mixed $payload       Job data
     * @param int   $delay         Delay in seconds before job becomes available
     * @param int   $priority      Job priority (higher = processed first)
     * @return string Job ID
     */
    public function push(mixed $payload, int $priority = 0, int $delay = 0): string
    {
        // Delegate to external backend if configured
        if ($this->externalBackend !== null) {
            $message = [
                'id' => $this->generateId(),
                'payload' => $payload,
                'topic' => $this->topic,
            ];
            return $this->externalBackend->enqueue($this->topic, $message);
        }

        $message = [
            'id'            => $this->generateId(),
            'payload'       => $payload,
            'priority'      => $priority,
            'delay_seconds' => $delay,
        ];
        return $this->liteBackend->enqueue($this->topic, $message);
    }

    /**
     * Pop the next available job from the queue.
     *
     * @return array|null Job data or null if empty
     */
    public function pop(): ?array
    {
        // Delegate to external backend if configured
        if ($this->externalBackend !== null) {
            return $this->externalBackend->dequeue($this->topic);
        }

        return $this->liteBackend->dequeue($this->topic);
    }

    /**
     * Pop up to $count jobs at once. Returns a partial batch if fewer available.
     *
     * @param int $count Maximum number of jobs to return.
     * @return array<int, array> Array of job arrays (may be shorter than $count).
     */
    public function popBatch(int $count): array
    {
        $backend = $this->externalBackend ?? $this->liteBackend;
        if (method_exists($backend, 'dequeueBatch')) {
            return $backend->dequeueBatch($this->topic, $count);
        }
        // Fallback for external backends
        $jobs = [];
        for ($i = 0; $i < $count; $i++) {
            $job = $backend->dequeue($this->topic);
            if ($job === null) break;
            $jobs[] = $job;
        }
        return $jobs;
    }

    /**
     * Process jobs from a queue using a handler callback.
     *
     * Accepts two calling styles:
     *   $queue->process($handler)                        — uses constructor topic
     *   $queue->process($handler, 'queue_name')          — explicit queue override
     *   $queue->process('queue_name', $handler)           — legacy (queue first)
     *   $queue->process('queue_name', $handler, $options) — legacy with options
     *
     * @param callable|string $handlerOrQueue Handler or queue name (legacy)
     * @param callable|string|array $queueOrHandlerOrOptions Queue name, handler, or options
     * @param array    $options Options: maxJobs (int)
     */
    public function process(callable|string $handlerOrQueue, callable|string|array $queueOrHandlerOrOptions = '', array $options = []): void
    {
        // Detect calling style
        if (is_string($handlerOrQueue) && is_callable($queueOrHandlerOrOptions)) {
            // Legacy: process('queue_name', $handler, $options)
            $queue = $handlerOrQueue;
            $handler = $queueOrHandlerOrOptions;
        } elseif (is_callable($handlerOrQueue) && is_string($queueOrHandlerOrOptions)) {
            // Unified: process($handler, 'queue_name')
            $handler = $handlerOrQueue;
            $queue = $queueOrHandlerOrOptions ?: $this->topic;
        } elseif (is_callable($handlerOrQueue) && is_array($queueOrHandlerOrOptions)) {
            // Unified: process($handler, $options)
            $handler = $handlerOrQueue;
            $queue = $this->topic;
            $options = $queueOrHandlerOrOptions;
        } elseif (is_callable($handlerOrQueue)) {
            // Simple: process($handler)
            $handler = $handlerOrQueue;
            $queue = $this->topic;
        } else {
            $queue = $handlerOrQueue;
            $handler = $queueOrHandlerOrOptions;
        }
        $queue = $queue ?: $this->topic;
        $maxJobs = $options['maxJobs'] ?? null;
        $batchSize = (int)($options['batchSize'] ?? 1);
        $processed = 0;

        if ($batchSize > 1) {
            while ($maxJobs === null || $processed < $maxJobs) {
                $remaining = $maxJobs !== null ? min($batchSize, $maxJobs - $processed) : $batchSize;
                $jobs = $this->popBatch($remaining);
                if (empty($jobs)) {
                    break;
                }
                try {
                    $handler($jobs);
                } catch (\Throwable $e) {
                    // Route each job through the auto retry → dead-letter lifecycle.
                    foreach ($jobs as $jobData) {
                        $this->failJob($queue, $jobData, $e->getMessage());
                    }
                }
                $processed += count($jobs);
            }
        } else {
            while ($maxJobs === null || $processed < $maxJobs) {
                $job = $this->externalBackend !== null
                    ? $this->externalBackend->dequeue($queue)
                    : $this->liteBackend->dequeue($queue);
                if ($job === null) {
                    break;
                }

                try {
                    $handler($job);
                } catch (\Throwable $e) {
                    // Auto retry → dead-letter: re-enqueue while retries remain,
                    // otherwise move to the dead-letter store.
                    $this->failJob($queue, $job, $e->getMessage());
                }

                $processed++;
            }
        }
    }

    /**
     * Get the number of jobs in the queue filtered by status.
     *
     * @param string $status Job status to count ('pending', 'failed')
     * @return int
     */
    public function size(string $status = 'pending'): int
    {
        if ($this->externalBackend !== null) {
            return $this->externalBackend->size($this->topic);
        }

        return $this->liteBackend->count($this->topic, $status);
    }

    /**
     * Clear all pending jobs from the queue topic.
     *
     * @return int Number of jobs cleared
     */
    public function clear(): int
    {
        $count = $this->liteBackend->count($this->topic, 'pending');
        $this->liteBackend->clear($this->topic);
        return $count;
    }

    /**
     * Record a failed attempt for a job and apply the auto retry → dead-letter
     * lifecycle (called by Job::fail() and the process() error handler).
     *
     * Increments attempts + stores the error; re-enqueues to pending while
     * retries remain (after retryBackoff), otherwise moves to the dead-letter
     * store once attempts >= maxRetries.
     *
     * @internal Used by Job and process() — not a primary public Queue verb
     */
    public function failJob(string $topic, array $jobData, string $error = ''): void
    {
        // External brokers manage retries/dead-lettering themselves.
        if ($this->externalBackend !== null) {
            return;
        }
        $this->liteBackend->failJob($topic, $jobData, $error);
    }

    /**
     * Acknowledge a completed job (called by Job::complete()). Clears the
     * reservation record so a dead-consumer reclaim never re-delivers a job that
     * was actually finished.
     *
     * @internal Used by Job — not a primary public Queue verb
     */
    public function completeJob(string $topic, string $jobId): void
    {
        if ($this->externalBackend !== null) {
            // External brokers ack via their own protocol (handled by the broker).
            $this->externalBackend->acknowledge($topic, $jobId);
            return;
        }
        $this->liteBackend->acknowledge($topic, $jobId);
    }

    /**
     * Explicitly re-queue a job (called by Job::retry()). Always re-enqueues
     * regardless of the retry limit — a manual override, distinct from failJob().
     *
     * @internal Used by Job — not a primary public Queue verb
     */
    public function retryJob(string $topic, array $jobData, int $delaySeconds = 0): void
    {
        if ($this->externalBackend !== null) {
            return;
        }
        $this->liteBackend->retryJob($topic, $jobData, $delaySeconds);
    }

    /**
     * Get all failed jobs from the queue topic.
     *
     * @return array<int, array> List of failed job arrays
     */
    public function failed(): array
    {
        return $this->liteBackend->failed($this->topic);
    }

    /**
     * Retry a failed job by moving it back to the pending queue.
     *
     * @param string $jobId        Job ID
     * @param int    $delaySeconds Delay in seconds before the retried job becomes available
     * @return bool True if job was found and re-queued
     */
    public function retry(?string $jobId = null, int $delaySeconds = 0): bool
    {
        if ($jobId === null) {
            // Retry all dead-letter jobs
            $dead = $this->deadLetters();
            if (empty($dead)) return false;
            $retried = false;
            foreach ($dead as $job) {
                if ($this->liteBackend->retry($job['id'], null, $delaySeconds)) {
                    $retried = true;
                }
            }
            return $retried;
        }
        // Pass null so LiteBackend searches all topic subdirectories — the caller
        // doesn't know which topic the job lives in, only the ID.
        return $this->liteBackend->retry($jobId, null, $delaySeconds);
    }

    /**
     * Get dead letter jobs — failed jobs that exceeded max retries.
     *
     * @param int|null $maxRetries Override the queue's default max retries threshold
     * @return array<int, array> List of dead letter job arrays
     */
    public function deadLetters(?int $maxRetries = null): array
    {
        return $this->liteBackend->deadLetters($this->topic, $maxRetries ?? $this->maxRetries);
    }

    /**
     * Delete messages by status (completed, failed, dead).
     *
     * @param string $status Status to purge: 'completed', 'failed', 'dead', 'pending'
     * @return int Number of jobs purged
     */
    public function purge(string $status, ?int $maxRetries = null): int
    {
        return $this->liteBackend->purge($status, $this->topic, $maxRetries ?? $this->maxRetries);
    }

    /**
     * Re-queue failed jobs that haven't exceeded max retries back to pending.
     *
     * @param int|null $maxRetries Override the queue's default max retries threshold
     * @return int Number of jobs re-queued
     */
    public function retryFailed(?int $maxRetries = null): int
    {
        return $this->liteBackend->retryFailed($this->topic, $maxRetries ?? $this->maxRetries);
    }

    /**
     * Produce a message onto a named topic. Use when you need to push to a
     * topic other than the one set in the constructor.
     *
     * @param string $topic        Topic/queue name
     * @param mixed  $payload      Job data
     * @param int    $priority     Priority (higher = dequeued first, default 0)
     * @param int    $delaySeconds Delay in seconds before job becomes available
     * @return string Job ID
     */
    public function produce(string $topic, mixed $payload, int $priority = 0, int $delaySeconds = 0): string
    {
        if ($this->externalBackend !== null) {
            $message = [
                'id'      => $this->generateId(),
                'payload' => $payload,
                'topic'   => $topic,
            ];
            return $this->externalBackend->enqueue($topic, $message);
        }

        $message = [
            'id'            => $this->generateId(),
            'payload'       => $payload,
            'priority'      => $priority,
            'delay_seconds' => $delaySeconds,
        ];
        return $this->liteBackend->enqueue($topic, $message);
    }

    /**
     * Consume jobs from a topic using a generator (yield pattern).
     *
     * Usage:
     *   foreach ($queue->consume('emails') as $job) {
     *       processEmail($job);
     *   }
     *
     *   // Consume a specific job by ID:
     *   foreach ($queue->consume('emails', 'job-id-123') as $job) {
     *       processEmail($job);
     *   }
     *
     * @param string      $topic Topic/queue name (defaults to constructor topic)
     * @param string|null $id    Optional job ID — only yield this specific job
     * @return \Generator<array>
     */
    /**
     * Consume jobs from a topic using a long-running generator.
     *
     * Polls the queue continuously. When empty, sleeps for $pollInterval
     * seconds before polling again. No external while-loop or sleep needed.
     *
     * @param string $topic        Queue topic (defaults to constructor topic)
     * @param ?string $id          Optional job ID — single yield, no polling
     * @param float $pollInterval  Seconds to sleep when queue is empty (default 1.0)
     * @param int $batchSize       When > 1, yield arrays of jobs instead of single Job objects
     */
    public function consume(string $topic = '', ?string $id = null, float $pollInterval = 1.0, int $iterations = 0, int $batchSize = 1): \Generator
    {
        $topic = $topic ?: $this->topic;

        if ($id !== null) {
            // Consume a specific job by ID — single yield, no polling
            $data = $this->popById($topic, $id);
            if ($data !== null) {
                yield new Job($data, $this, $topic);
            }
            return;
        }

        // iterations = max jobs consumed (0 = unlimited). Matches Python semantics.
        // pollInterval=0 → single-pass drain (returns when empty)
        // pollInterval>0 → long-running poll (sleeps when empty)
        $consumed = 0;
        if ($batchSize > 1) {
            while (true) {
                $jobs = $this->popBatch($batchSize);
                if (empty($jobs)) {
                    if ($pollInterval <= 0) {
                        break;
                    }
                    usleep((int)($pollInterval * 1_000_000));
                    continue;
                }
                yield $jobs;
                $consumed += count($jobs);
                if ($iterations > 0 && $consumed >= $iterations) {
                    break;
                }
            }
        } else {
            while (true) {
                $data = $this->externalBackend !== null
                    ? $this->externalBackend->dequeue($topic)
                    : $this->liteBackend->dequeue($topic);
                if ($data === null) {
                    if ($pollInterval <= 0) {
                        break;
                    }
                    usleep((int)($pollInterval * 1_000_000));
                    continue;
                }
                yield new Job($data, $this, $topic);
                $consumed++;
                if ($iterations > 0 && $consumed >= $iterations) {
                    break;
                }
            }
        }
    }

    /**
     * Pop a specific job by ID from the queue.
     *
     * @param string $queue Queue/topic name
     * @param string $id    Job ID to find
     * @return array|null Job data or null if not found
     */
    public function popById(string $id): ?array
    {
        if ($this->externalBackend !== null) {
            // External backends don't support ID-based pop natively
            return null;
        }

        return $this->liteBackend->popById($this->topic, $id);
    }

    /**
     * Get the base path for this queue system.
     */
    private function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * Get the topic name.
     */
    public function getTopic(): string
    {
        return $this->topic;
    }

    /**
     * Resolve RabbitMQ config from environment variables and TINA4_QUEUE_URL.
     */
    private function resolveRabbitMQConfig(array $config): array
    {
        $url = getenv('TINA4_QUEUE_URL');
        if ($url) {
            $parsed = $this->parseAmqpUrl($url);
            return array_merge($parsed, $config);
        }
        return $config;
    }

    /**
     * Resolve MongoDB config from environment variables and TINA4_QUEUE_URL.
     */
    private function resolveMongoConfig(array $config): array
    {
        $url = getenv('TINA4_QUEUE_URL');
        if ($url) {
            $config['uri'] = $url;
        }
        return $config;
    }

    /**
     * Resolve the reservation/visibility timeout (seconds): explicit config
     * value wins, else env TINA4_QUEUE_VISIBILITY_TIMEOUT, else 300 (5 min).
     */
    private function resolveVisibilityTimeout(array $config): float
    {
        if (array_key_exists('visibilityTimeout', $config)) {
            return (float)$config['visibilityTimeout'];
        }
        $env = getenv('TINA4_QUEUE_VISIBILITY_TIMEOUT');
        if ($env !== false && $env !== '') {
            return (float)$env;
        }
        return 300.0;
    }

    /**
     * Get the resolved reservation/visibility timeout in seconds.
     */
    public function getVisibilityTimeout(): float
    {
        return $this->visibilityTimeout;
    }

    /**
     * Resolve Kafka config from environment variables and TINA4_QUEUE_URL.
     */
    private function resolveKafkaConfig(array $config): array
    {
        $url = getenv('TINA4_QUEUE_URL');
        if ($url) {
            $config['brokers'] = str_replace('kafka://', '', $url);
        }
        $brokers = getenv('TINA4_KAFKA_BROKERS');
        if ($brokers) {
            $config['brokers'] = $brokers;
        }
        return $config;
    }

    /**
     * Parse an AMQP URL into config array.
     */
    private function parseAmqpUrl(string $url): array
    {
        $config = [];
        $url = str_replace(['amqp://', 'amqps://'], '', $url);

        if (str_contains($url, '@')) {
            [$creds, $rest] = explode('@', $url, 2);
            if (str_contains($creds, ':')) {
                [$config['username'], $config['password']] = explode(':', $creds, 2);
            } else {
                $config['username'] = $creds;
            }
        } else {
            $rest = $url;
        }

        if (str_contains($rest, '/')) {
            [$hostport, $vhost] = explode('/', $rest, 2);
            if ($vhost) {
                $config['vhost'] = str_starts_with($vhost, '/') ? $vhost : '/' . $vhost;
            }
        } else {
            $hostport = $rest;
        }

        if (str_contains($hostport, ':')) {
            [$config['host'], $port] = explode(':', $hostport, 2);
            $config['port'] = (int)$port;
        } elseif ($hostport) {
            $config['host'] = $hostport;
        }

        return $config;
    }

    /**
     * Generate a unique job ID.
     */
    private function generateId(): string
    {
        return bin2hex(random_bytes(8)) . '-' . dechex((int)(microtime(true) * 1000));
    }
}
