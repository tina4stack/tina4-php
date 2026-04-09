<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * LiteBackend — File-based queue backend (zero dependencies).
 *
 * Jobs are stored as JSON files on the local filesystem.
 * Failed jobs are moved to a 'failed/' subdirectory under each topic path.
 *
 * Environment variables:
 *   TINA4_QUEUE_PATH — Base path for queue files (default: data/queue)
 */

namespace Tina4\Queue;

class LiteBackend implements QueueBackend
{
    private string $basePath;
    private int $maxRetries;

    /**
     * @param string $basePath   Base filesystem path for queue directories
     * @param int    $maxRetries Maximum retry attempts before a job is considered dead
     */
    public function __construct(string $basePath = 'data/queue', int $maxRetries = 3)
    {
        $this->basePath = $basePath;
        $this->maxRetries = $maxRetries;
    }

    // -------------------------------------------------------------------------
    // QueueBackend interface
    // -------------------------------------------------------------------------

    /**
     * Push a message onto a topic/queue.
     *
     * @param string $topic   The queue/topic name
     * @param array  $message Message data — must contain 'id', 'payload'.
     *                        Optional keys: 'delay_seconds', 'priority'.
     * @return string Message ID
     */
    public function enqueue(string $topic, array $message): string
    {
        $queuePath = $this->queuePath($topic);
        $this->ensureDir($queuePath);

        $id = $message['id'] ?? $this->generateId();
        $delaySeconds = (int)($message['delay_seconds'] ?? 0);
        $delayUntil = $delaySeconds > 0 ? sprintf('%.6f', microtime(true) + $delaySeconds) : null;

        $job = [
            'id'          => $id,
            'payload'     => $message['payload'] ?? $message,
            'status'      => 'pending',
            'created_at'  => $this->now(),
            'attempts'    => 0,
            'delay_until' => $delayUntil,
            'priority'    => $message['priority'] ?? 0,
            'error'       => null,
        ];

        file_put_contents($queuePath . '/' . $id . '.queue-data', json_encode($job, JSON_PRETTY_PRINT));
        return $id;
    }

    /**
     * Pop the next available (pending, non-delayed) message from a topic.
     *
     * @param string $topic The queue/topic name
     * @return array|null Job data or null if empty
     */
    public function dequeue(string $topic): ?array
    {
        $queuePath = $this->queuePath($topic);
        if (!is_dir($queuePath)) {
            return null;
        }

        $files = glob($queuePath . '/*.queue-data');
        if (empty($files)) {
            return null;
        }

        $candidates = [];
        $nowMicro = microtime(true);

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data === null || $data['status'] !== 'pending') {
                continue;
            }

            if (!empty($data['delay_until']) && (float)$data['delay_until'] > $nowMicro) {
                continue;
            }

            $candidates[] = ['file' => $file, 'data' => $data];
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, fn($a, $b) => strcmp($a['data']['created_at'], $b['data']['created_at']));

        $chosen = $candidates[0];
        $job = $chosen['data'];
        $job['topic'] = $topic;

        unlink($chosen['file']);

        return $job;
    }

    /**
     * Pop up to $count pending jobs at once. Returns a partial batch if fewer available.
     *
     * @param string $topic Queue/topic name
     * @param int    $count Maximum number of jobs to return
     * @return array<int, array> Array of job arrays (may be shorter than $count)
     */
    public function dequeueBatch(string $topic, int $count): array
    {
        $queuePath = $this->queuePath($topic);
        if (!is_dir($queuePath)) {
            return [];
        }

        $files = glob($queuePath . '/*.queue-data');
        if (empty($files)) {
            return [];
        }

        $candidates = [];
        $nowMicro = microtime(true);

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data === null || $data['status'] !== 'pending') {
                continue;
            }
            if (!empty($data['delay_until']) && (float)$data['delay_until'] > $nowMicro) {
                continue;
            }
            $candidates[] = ['file' => $file, 'data' => $data];
        }

        if (empty($candidates)) {
            return [];
        }

        usort($candidates, fn($a, $b) => strcmp($a['data']['created_at'], $b['data']['created_at']));

        $jobs = [];
        foreach ($candidates as $candidate) {
            if (count($jobs) >= $count) {
                break;
            }
            if (@unlink($candidate['file'])) {
                $job = $candidate['data'];
                $job['topic'] = $topic;
                $jobs[] = $job;
            }
        }

        return $jobs;
    }

    /**
     * Acknowledge a message — no-op for file backend (message is removed on dequeue).
     *
     * @param string $topic     The queue/topic name
     * @param string $messageId The message ID to acknowledge
     */
    public function acknowledge(string $topic, string $messageId): void
    {
        // File backend removes the file on dequeue — no further action needed.
    }

    /**
     * Re-queue a message for retry.
     *
     * @param string $topic   The queue/topic name
     * @param array  $message The message data
     */
    public function requeue(string $topic, array $message): void
    {
        $queuePath = $this->queuePath($topic);
        $this->ensureDir($queuePath);

        $message['status'] = 'pending';
        file_put_contents(
            $queuePath . '/' . $message['id'] . '.queue-data',
            json_encode($message, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Send a message to the dead letter (failed) queue.
     *
     * @param string $topic   The original queue/topic name
     * @param array  $message The message data
     */
    public function deadLetter(string $topic, array $message): void
    {
        $failedPath = $this->queuePath($topic) . '/failed';
        $this->ensureDir($failedPath);

        $message['status'] = 'failed';
        file_put_contents(
            $failedPath . '/' . $message['id'] . '.queue-data',
            json_encode($message, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Get the number of pending messages in a queue.
     *
     * @param string $topic The queue/topic name
     * @return int
     */
    public function size(string $topic): int
    {
        return $this->count($topic, 'pending');
    }

    /**
     * Close — no-op for file backend.
     */
    public function close(): void
    {
        // No persistent connection to close.
    }

    // -------------------------------------------------------------------------
    // Extended methods (extras beyond the interface)
    // -------------------------------------------------------------------------

    /**
     * Count messages by status.
     *
     * @param string $topic  The queue/topic name
     * @param string $status Job status: 'pending', 'failed', or other
     * @return int
     */
    public function count(string $topic, string $status = 'pending'): int
    {
        if ($status === 'failed') {
            $failedPath = $this->queuePath($topic) . '/failed';
            if (!is_dir($failedPath)) {
                return 0;
            }

            $n = 0;
            foreach (glob($failedPath . '/*.queue-data') as $file) {
                $data = json_decode(file_get_contents($file), true);
                if ($data !== null && ($data['status'] ?? '') === 'failed') {
                    $n++;
                }
            }
            return $n;
        }

        $queuePath = $this->queuePath($topic);
        if (!is_dir($queuePath)) {
            return 0;
        }

        $n = 0;
        foreach (glob($queuePath . '/*.queue-data') as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data !== null && ($data['status'] ?? '') === $status) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Clear all pending jobs from a topic queue.
     *
     * @param string $topic The queue/topic name
     */
    public function clear(string $topic): void
    {
        $queuePath = $this->queuePath($topic);
        if (!is_dir($queuePath)) {
            return;
        }

        foreach (glob($queuePath . '/*.queue-data') as $file) {
            unlink($file);
        }
    }

    /**
     * Get all failed jobs for a topic.
     *
     * @param string $topic The queue/topic name
     * @return array List of failed job arrays
     */
    public function failed(string $topic): array
    {
        $failedPath = $this->queuePath($topic) . '/failed';
        if (!is_dir($failedPath)) {
            return [];
        }

        $jobs = [];
        foreach (glob($failedPath . '/*.queue-data') as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data !== null) {
                $jobs[] = $data;
            }
        }
        return $jobs;
    }

    /**
     * Retry a specific failed job by ID.
     *
     * When $topic is null, all topic subdirectories under basePath are searched.
     *
     * @param string      $jobId        Job ID to retry
     * @param string|null $topic        Topic name, or null to search all topics
     * @param int         $delaySeconds Delay before the retried job becomes available
     * @return bool True if job was found and re-queued
     */
    public function retry(string $jobId, ?string $topic = null, int $delaySeconds = 0): bool
    {
        if ($topic !== null) {
            $queueDirs = [$this->queuePath($topic)];
        } else {
            if (!is_dir($this->basePath)) {
                return false;
            }
            $queueDirs = array_filter(glob($this->basePath . '/*'), 'is_dir');
        }

        foreach ($queueDirs as $queueDir) {
            $failedPath = $queueDir . '/failed';
            $failedFile = $failedPath . '/' . $jobId . '.queue-data';

            if (!file_exists($failedFile)) {
                continue;
            }

            $data = json_decode(file_get_contents($failedFile), true);
            if ($data === null) {
                return false;
            }

            if (($data['attempts'] ?? 0) >= $this->maxRetries) {
                return false;
            }

            $data['status'] = 'pending';
            $data['error'] = null;
            $data['attempts'] = ($data['attempts'] ?? 0) + 1;

            if ($delaySeconds > 0) {
                $data['delay_until'] = sprintf('%.6f', microtime(true) + $delaySeconds);
            }

            $this->ensureDir($queueDir);
            file_put_contents($queueDir . '/' . $jobId . '.queue-data', json_encode($data, JSON_PRETTY_PRINT));
            unlink($failedFile);

            return true;
        }

        return false;
    }

    /**
     * Get dead letter jobs — failed jobs that have exceeded max retries.
     *
     * @param string $topic The queue/topic name
     * @return array List of dead job arrays
     */
    public function deadLetters(string $topic): array
    {
        $failedPath = $this->queuePath($topic) . '/failed';
        if (!is_dir($failedPath)) {
            return [];
        }

        $jobs = [];
        foreach (glob($failedPath . '/*.queue-data') as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data !== null && ($data['attempts'] ?? 0) >= $this->maxRetries) {
                $data['status'] = 'dead';
                $jobs[] = $data;
            }
        }
        return $jobs;
    }

    /**
     * Purge jobs by status.
     *
     * @param string $status Status to purge: 'completed', 'failed', 'dead', 'pending'
     * @param string $topic  The queue/topic name
     * @return int Number of jobs purged
     */
    public function purge(string $status, string $topic): int
    {
        $count = 0;

        if ($status === 'dead') {
            $failedPath = $this->queuePath($topic) . '/failed';
            if (!is_dir($failedPath)) {
                return 0;
            }
            foreach (glob($failedPath . '/*.queue-data') as $file) {
                $data = json_decode(file_get_contents($file), true);
                if ($data !== null && ($data['attempts'] ?? 0) >= $this->maxRetries) {
                    unlink($file);
                    $count++;
                }
            }
        } elseif ($status === 'failed') {
            $failedPath = $this->queuePath($topic) . '/failed';
            if (!is_dir($failedPath)) {
                return 0;
            }
            foreach (glob($failedPath . '/*.queue-data') as $file) {
                $data = json_decode(file_get_contents($file), true);
                if ($data !== null && ($data['attempts'] ?? 0) < $this->maxRetries) {
                    unlink($file);
                    $count++;
                }
            }
        } else {
            $queuePath = $this->queuePath($topic);
            if (!is_dir($queuePath)) {
                return 0;
            }
            foreach (glob($queuePath . '/*.queue-data') as $file) {
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
     * Re-queue all failed jobs (under max retries) back to pending.
     *
     * @param string $topic The queue/topic name
     * @return int Number of jobs re-queued
     */
    public function retryFailed(string $topic): int
    {
        $queuePath = $this->queuePath($topic);
        $failedPath = $queuePath . '/failed';

        if (!is_dir($failedPath)) {
            return 0;
        }

        $count = 0;
        foreach (glob($failedPath . '/*.queue-data') as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data === null || ($data['attempts'] ?? 0) >= $this->maxRetries) {
                continue;
            }

            $data['status'] = 'pending';
            $data['error'] = null;

            $this->ensureDir($queuePath);
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
     * Pop a specific job by ID from the pending queue.
     *
     * @param string $topic The queue/topic name
     * @param string $id    Job ID to find
     * @return array|null Job data or null if not found
     */
    public function popById(string $topic, string $id): ?array
    {
        $queuePath = $this->queuePath($topic);
        if (!is_dir($queuePath)) {
            return null;
        }

        foreach (glob($queuePath . '/*.queue-data') as $file) {
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
     * Write a failed job record directly (used by process() error handler).
     *
     * @param string $topic  The queue/topic name
     * @param array  $jobData The job data with 'status' = 'failed'
     */
    public function writeFailed(string $topic, array $jobData): void
    {
        $failedPath = $this->queuePath($topic) . '/failed';
        $this->ensureDir($failedPath);
        file_put_contents(
            $failedPath . '/' . $jobData['id'] . '.queue-data',
            json_encode($jobData, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Get the base filesystem path.
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function queuePath(string $queue): string
    {
        return $this->basePath . '/' . $queue;
    }

    private function ensureDir(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(8)) . '-' . dechex((int)(microtime(true) * 1000));
    }

    private function now(): string
    {
        return sprintf('%.6f', microtime(true));
    }
}
