<?php

/**
 * Tina4 - This is not a 4ramework.
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
use Tina4\Queue\RabbitMQBackend;
use Tina4\Queue\KafkaBackend;
use Tina4\Queue\MongoBackend;

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

        $failedPath = $this->queue->getBasePath() . '/' . $this->topic . '/failed';
        if (!is_dir($failedPath)) {
            mkdir($failedPath, 0755, true);
        }

        $jobData = [
            'id' => $this->id,
            'payload' => $this->payload,
            'status' => 'failed',
            'attempts' => $this->attempts,
            'error' => $reason,
        ];

        file_put_contents(
            $failedPath . '/' . $this->id . '.queue-data',
            json_encode($jobData, JSON_PRETTY_PRINT)
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
     * Get the raw job data as array.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'payload' => $this->payload,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'error' => $this->error,
        ];
    }
}

class Queue
{
    private string $backend;
    private string $basePath;
    private int $maxRetries;
    private string $topic;

    /** @var QueueBackend|null External queue backend (rabbitmq, kafka) */
    private ?QueueBackend $externalBackend = null;

    /**
     * Unified Queue constructor.
     *
     * @param string $backend    Queue backend type: 'file', 'rabbitmq', 'kafka'
     * @param array  $config     Configuration: path, maxRetries, and backend-specific options
     * @param string $topic      Default topic/queue name
     */
    public function __construct(string $backend = 'file', array $config = [], string $topic = 'default')
    {
        $this->backend = getenv('TINA4_QUEUE_BACKEND') ?: $backend;
        $this->basePath = $config['path'] ?? (getenv('TINA4_QUEUE_PATH') ?: 'data/queue');
        $this->maxRetries = $config['maxRetries'] ?? 3;
        $this->topic = $topic;

        // Initialize external backends
        if ($this->backend === 'rabbitmq') {
            $resolvedConfig = $this->resolveRabbitMQConfig($config);
            $this->externalBackend = new RabbitMQBackend($resolvedConfig);
        } elseif ($this->backend === 'kafka') {
            $resolvedConfig = $this->resolveKafkaConfig($config);
            $this->externalBackend = new KafkaBackend($resolvedConfig);
        } elseif ($this->backend === 'mongodb' || $this->backend === 'mongo') {
            $resolvedConfig = $this->resolveMongoConfig($config);
            $this->externalBackend = new MongoBackend($resolvedConfig);
        }
    }

    /**
     * Get the external queue backend instance (if using rabbitmq or kafka).
     *
     * @return QueueBackend|null
     */
    public function getExternalBackend(): ?QueueBackend
    {
        return $this->externalBackend;
    }

    /**
     * Push a job onto a queue.
     *
     * Accepts two calling styles:
     *   $queue->push($payload)                          — uses constructor topic
     *   $queue->push($payload, 'queue_name')            — explicit queue override
     *   $queue->push('queue_name', $payload)             — legacy (queue first)
     *   $queue->push('queue_name', $payload, $delay)     — legacy with delay
     *
     * @param mixed       $payloadOrQueue Job data or queue name (string for legacy)
     * @param mixed       $queueOrPayload Queue name or payload (legacy)
     * @param int         $delay          Delay in seconds before job becomes available
     * @return string Job ID
     */
    public function push(mixed $payloadOrQueue, mixed $queueOrPayload = '', int $delay = 0): string
    {
        // Detect calling style:
        // 1. push('queue_name', $payload) — legacy: first arg is string queue name
        // 2. push($payload, 'queue_name') — unified: payload first, optional queue override
        // 3. push($payload) — unified: uses constructor topic
        if (is_string($payloadOrQueue) && $queueOrPayload !== '') {
            // Legacy style: push('queue_name', $payload, $delay)
            $queue = $payloadOrQueue;
            $payload = $queueOrPayload;
        } elseif (!is_string($payloadOrQueue) && is_string($queueOrPayload) && $queueOrPayload !== '') {
            // Unified with override: push($payload, 'queue_name')
            $payload = $payloadOrQueue;
            $queue = $queueOrPayload;
        } else {
            // Unified style: push($payload) — use topic
            $payload = $payloadOrQueue;
            $queue = $this->topic;
        }

        // Delegate to external backend if configured
        if ($this->externalBackend !== null) {
            $message = [
                'id' => $this->generateId(),
                'payload' => $payload,
                'topic' => $queue,
            ];
            return $this->externalBackend->enqueue($queue, $message);
        }

        $queuePath = $this->queuePath($queue);
        $this->ensureDir($queuePath);

        $id = $this->generateId();
        $now = $this->now();
        $delayUntil = $delay > 0 ? sprintf('%.6f', microtime(true) + $delay) : null;

        $job = [
            'id' => $id,
            'payload' => $payload,
            'status' => 'pending',
            'created_at' => $now,
            'attempts' => 0,
            'delay_until' => $delayUntil,
            'error' => null,
        ];

        file_put_contents($queuePath . '/' . $id . '.queue-data', json_encode($job, JSON_PRETTY_PRINT));
        return $id;
    }

    /**
     * Pop the next available job from the queue.
     *
     * @param string $queue Queue name (defaults to topic set in constructor)
     * @return array|null Job data or null if empty
     */
    public function pop(string $queue = ''): ?array
    {
        $queue = $queue ?: $this->topic;

        // Delegate to external backend if configured
        if ($this->externalBackend !== null) {
            return $this->externalBackend->dequeue($queue);
        }

        $queuePath = $this->queuePath($queue);
        if (!is_dir($queuePath)) {
            return null;
        }

        $files = glob($queuePath . '/*.queue-data');
        if (empty($files)) {
            return null;
        }

        // Load all pending jobs, sort by created_at
        $candidates = [];
        $nowMicro = microtime(true);

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data === null || $data['status'] !== 'pending') {
                continue;
            }

            // Check delay
            if (!empty($data['delay_until'])) {
                $delayTime = (float)$data['delay_until'];
                if ($delayTime > $nowMicro) {
                    continue;
                }
            }

            $candidates[] = ['file' => $file, 'data' => $data];
        }

        if (empty($candidates)) {
            return null;
        }

        // Sort by created_at ascending
        usort($candidates, function ($a, $b) {
            return strcmp($a['data']['created_at'], $b['data']['created_at']);
        });

        $chosen = $candidates[0];
        $job = $chosen['data'];

        // Mark as reserved and remove from pending
        unlink($chosen['file']);

        return $job;
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
        $processed = 0;

        while ($maxJobs === null || $processed < $maxJobs) {
            $job = $this->pop($queue);
            if ($job === null) {
                break;
            }

            try {
                $handler($job);
            } catch (\Throwable $e) {
                // Mark as failed
                $job['status'] = 'failed';
                $job['error'] = $e->getMessage();
                $job['attempts'] = ($job['attempts'] ?? 0) + 1;

                $failedPath = $this->queuePath($queue) . '/failed';
                $this->ensureDir($failedPath);
                file_put_contents(
                    $failedPath . '/' . $job['id'] . '.queue-data',
                    json_encode($job, JSON_PRETTY_PRINT)
                );
            }

            $processed++;
        }
    }

    /**
     * Get the number of pending jobs in a queue.
     *
     * @param string $queue Queue name (defaults to topic set in constructor)
     * @return int
     */
    public function size(string $queue = ''): int
    {
        $queue = $queue ?: $this->topic;

        if ($this->externalBackend !== null) {
            return $this->externalBackend->size($queue);
        }

        $queuePath = $this->queuePath($queue);
        if (!is_dir($queuePath)) {
            return 0;
        }

        $files = glob($queuePath . '/*.queue-data');
        $count = 0;

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data !== null && $data['status'] === 'pending') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Clear all pending jobs from a queue.
     *
     * @param string $queue Queue name (defaults to topic set in constructor)
     */
    public function clear(string $queue = ''): void
    {
        $queue = $queue ?: $this->topic;
        $queuePath = $this->queuePath($queue);
        if (!is_dir($queuePath)) {
            return;
        }

        $files = glob($queuePath . '/*.queue-data');
        foreach ($files as $file) {
            unlink($file);
        }
    }

    /**
     * Get all failed jobs from a queue.
     *
     * @param string $queue Queue name (defaults to topic set in constructor)
     * @return array List of failed job arrays
     */
    public function failed(string $queue = ''): array
    {
        $queue = $queue ?: $this->topic;
        $failedPath = $this->queuePath($queue) . '/failed';
        if (!is_dir($failedPath)) {
            return [];
        }

        $files = glob($failedPath . '/*.queue-data');
        $jobs = [];

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data !== null) {
                $jobs[] = $data;
            }
        }

        return $jobs;
    }

    /**
     * Retry a failed job by moving it back to the pending queue.
     *
     * @param string $jobId Job ID
     * @return bool True if job was found and re-queued
     */
    public function retry(string $jobId): bool
    {
        // Search all queue directories for the failed job
        $basePath = $this->basePath;
        if (!is_dir($basePath)) {
            return false;
        }

        $queues = array_filter(glob($basePath . '/*'), 'is_dir');

        foreach ($queues as $queueDir) {
            $failedPath = $queueDir . '/failed';
            $failedFile = $failedPath . '/' . $jobId . '.queue-data';

            if (file_exists($failedFile)) {
                $data = json_decode(file_get_contents($failedFile), true);
                if ($data === null) {
                    return false;
                }

                // Check max retries
                if (($data['attempts'] ?? 0) >= $this->maxRetries) {
                    return false;
                }

                // Reset to pending and move back
                $data['status'] = 'pending';
                $data['error'] = null;

                file_put_contents(
                    $queueDir . '/' . $jobId . '.queue-data',
                    json_encode($data, JSON_PRETTY_PRINT)
                );

                unlink($failedFile);
                return true;
            }
        }

        return false;
    }

    /**
     * Get dead letter jobs — failed jobs that exceeded max retries.
     *
     * @param string $queue Queue name (defaults to topic set in constructor)
     * @return array List of dead letter job arrays
     */
    public function deadLetters(string $queue = ''): array
    {
        $queue = $queue ?: $this->topic;
        $failedPath = $this->queuePath($queue) . '/failed';
        if (!is_dir($failedPath)) {
            return [];
        }

        $files = glob($failedPath . '/*.queue-data');
        $jobs = [];

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data !== null && ($data['attempts'] ?? 0) >= $this->maxRetries) {
                $data['status'] = 'dead';
                $jobs[] = $data;
            }
        }

        return $jobs;
    }

    /**
     * Delete messages by status (completed, failed, dead).
     *
     * @param string $status Status to purge: 'completed', 'failed', 'dead', 'pending'
     * @param string $queue  Queue name (defaults to topic set in constructor)
     * @return int Number of jobs purged
     */
    public function purge(string $status, string $queue = ''): int
    {
        $queue = $queue ?: $this->topic;
        $count = 0;

        if ($status === 'dead') {
            $failedPath = $this->queuePath($queue) . '/failed';
            if (!is_dir($failedPath)) {
                return 0;
            }

            $files = glob($failedPath . '/*.queue-data');
            foreach ($files as $file) {
                $data = json_decode(file_get_contents($file), true);
                if ($data !== null && ($data['attempts'] ?? 0) >= $this->maxRetries) {
                    unlink($file);
                    $count++;
                }
            }
        } elseif ($status === 'failed') {
            $failedPath = $this->queuePath($queue) . '/failed';
            if (!is_dir($failedPath)) {
                return 0;
            }

            $files = glob($failedPath . '/*.queue-data');
            foreach ($files as $file) {
                $data = json_decode(file_get_contents($file), true);
                if ($data !== null && ($data['attempts'] ?? 0) < $this->maxRetries) {
                    unlink($file);
                    $count++;
                }
            }
        } else {
            // completed, pending, or other statuses — scan main queue directory
            $queuePath = $this->queuePath($queue);
            if (!is_dir($queuePath)) {
                return 0;
            }

            $files = glob($queuePath . '/*.queue-data');
            foreach ($files as $file) {
                $data = json_decode(file_get_contents($file), true);
                if ($data !== null && ($data['status'] ?? '') === $status) {
                    unlink($file);
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Re-queue failed jobs that haven't exceeded max retries back to pending.
     *
     * @param string $queue Queue name (defaults to topic set in constructor)
     * @return int Number of jobs re-queued
     */
    public function retryFailed(string $queue = ''): int
    {
        $queue = $queue ?: $this->topic;
        $failedPath = $this->queuePath($queue) . '/failed';
        if (!is_dir($failedPath)) {
            return 0;
        }

        $queuePath = $this->queuePath($queue);
        $count = 0;
        $files = glob($failedPath . '/*.queue-data');

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data === null) {
                continue;
            }

            // Only retry if under max retries (not dead)
            if (($data['attempts'] ?? 0) >= $this->maxRetries) {
                continue;
            }

            $data['status'] = 'pending';
            $data['error'] = null;

            file_put_contents(
                $queuePath . '/' . $data['id'] . '.queue-data',
                json_encode($data, JSON_PRETTY_PRINT)
            );

            unlink($file);
            $count++;
        }

        return $count;
    }

    /**
     * Produce a message onto a topic. Convenience wrapper around push().
     *
     * @param string $topic   Topic/queue name
     * @param mixed  $payload Job data
     * @param int    $delay   Delay in seconds before job becomes available
     * @return string Job ID
     */
    public function produce(string $topic, mixed $payload, int $delay = 0): string
    {
        return $this->push($payload, $topic, $delay);
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
    public function consume(string $topic = '', ?string $id = null): \Generator
    {
        $topic = $topic ?: $this->topic;

        if ($id !== null) {
            // Consume a specific job by ID
            $data = $this->popById($topic, $id);
            if ($data !== null) {
                yield new Job($data, $this, $topic);
            }
            return;
        }

        // Yield all available jobs
        while (($data = $this->pop($topic)) !== null) {
            yield new Job($data, $this, $topic);
        }
    }

    /**
     * Pop a specific job by ID from the queue.
     *
     * @param string $queue Queue/topic name
     * @param string $id    Job ID to find
     * @return array|null Job data or null if not found
     */
    public function popById(string $queue, string $id): ?array
    {
        $queue = $queue ?: $this->topic;

        if ($this->externalBackend !== null) {
            // External backends don't support ID-based pop natively
            return null;
        }

        $queuePath = $this->queuePath($queue);
        if (!is_dir($queuePath)) {
            return null;
        }

        $files = glob($queuePath . '/*.queue-data');
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data === null || $data['status'] !== 'pending') {
                continue;
            }
            if ($data['id'] === $id) {
                unlink($file);
                return $data;
            }
        }

        return null;
    }

    /**
     * Get the base path for this queue system.
     */
    public function getBasePath(): string
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
     * Build the filesystem path for a named queue.
     */
    private function queuePath(string $queue): string
    {
        return $this->basePath . '/' . $queue;
    }

    /**
     * Ensure a directory exists.
     */
    private function ensureDir(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    /**
     * Generate a unique job ID.
     */
    private function generateId(): string
    {
        return bin2hex(random_bytes(8)) . '-' . dechex((int)(microtime(true) * 1000));
    }

    /**
     * Current timestamp with microsecond precision.
     */
    private function now(): string
    {
        return sprintf('%.6f', microtime(true));
    }
}
