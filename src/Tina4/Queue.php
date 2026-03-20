<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Queue — File-backed job queue, zero dependencies.
 * Matches the Python tina4_python.queue implementation.
 */

namespace Tina4;

class Queue
{
    private string $backend;
    private string $basePath;
    private int $maxRetries;

    /**
     * @param string $backend  Queue backend type (currently only 'file')
     * @param array  $config   Configuration: path, maxRetries
     */
    public function __construct(string $backend = 'file', array $config = [])
    {
        $this->backend = getenv('TINA4_QUEUE_BACKEND') ?: $backend;
        $this->basePath = $config['path'] ?? (getenv('TINA4_QUEUE_PATH') ?: 'data/queue');
        $this->maxRetries = $config['maxRetries'] ?? 3;
    }

    /**
     * Push a job onto a queue.
     *
     * @param string $queue   Queue name
     * @param mixed  $payload Job data (will be JSON-encoded)
     * @param int    $delay   Delay in seconds before job becomes available
     * @return string Job ID
     */
    public function push(string $queue, mixed $payload, int $delay = 0): string
    {
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

        file_put_contents($queuePath . '/' . $id . '.json', json_encode($job, JSON_PRETTY_PRINT));
        return $id;
    }

    /**
     * Pop the next available job from the queue.
     *
     * @param string $queue Queue name
     * @return array|null Job data or null if empty
     */
    public function pop(string $queue): ?array
    {
        $queuePath = $this->queuePath($queue);
        if (!is_dir($queuePath)) {
            return null;
        }

        $files = glob($queuePath . '/*.json');
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
     * @param string   $queue   Queue name
     * @param callable $handler Function receiving the job array
     * @param array    $options Options: maxJobs (int)
     */
    public function process(string $queue, callable $handler, array $options = []): void
    {
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
                    $failedPath . '/' . $job['id'] . '.json',
                    json_encode($job, JSON_PRETTY_PRINT)
                );
            }

            $processed++;
        }
    }

    /**
     * Get the number of pending jobs in a queue.
     *
     * @param string $queue Queue name
     * @return int
     */
    public function size(string $queue): int
    {
        $queuePath = $this->queuePath($queue);
        if (!is_dir($queuePath)) {
            return 0;
        }

        $files = glob($queuePath . '/*.json');
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
     * @param string $queue Queue name
     */
    public function clear(string $queue): void
    {
        $queuePath = $this->queuePath($queue);
        if (!is_dir($queuePath)) {
            return;
        }

        $files = glob($queuePath . '/*.json');
        foreach ($files as $file) {
            unlink($file);
        }
    }

    /**
     * Get all failed jobs from a queue.
     *
     * @param string $queue Queue name
     * @return array List of failed job arrays
     */
    public function failed(string $queue): array
    {
        $failedPath = $this->queuePath($queue) . '/failed';
        if (!is_dir($failedPath)) {
            return [];
        }

        $files = glob($failedPath . '/*.json');
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
            $failedFile = $failedPath . '/' . $jobId . '.json';

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
                    $queueDir . '/' . $jobId . '.json',
                    json_encode($data, JSON_PRETTY_PRINT)
                );

                unlink($failedFile);
                return true;
            }
        }

        return false;
    }

    /**
     * Get the base path for this queue system.
     */
    public function getBasePath(): string
    {
        return $this->basePath;
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
