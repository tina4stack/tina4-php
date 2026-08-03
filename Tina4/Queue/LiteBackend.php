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
    private int $retryBackoff;
    private float $visibilityTimeout;

    /**
     * @param string $basePath          Base filesystem path for queue directories
     * @param int    $maxRetries        Maximum retry attempts before a job is dead-lettered
     * @param int    $retryBackoff      Seconds to delay a job's automatic re-enqueue on fail()
     *                                  (0 = retry on the very next pop()/consume() iteration)
     * @param float  $visibilityTimeout Reservation/visibility timeout (seconds). When a job is
     *                                  popped it is held in reserved/ with available_at = now +
     *                                  visibilityTimeout. If the consumer dies before
     *                                  complete()/fail() (crash, OOM, k8s eviction) the next
     *                                  dequeue() reclaims it once the window expires — incrementing
     *                                  attempts and re-enqueuing, or dead-lettering past
     *                                  maxRetries. <= 0 disables the reclaim (a reservation then
     *                                  lasts until the consumer acks — the old at-most-once
     *                                  behaviour).
     */
    public function __construct(string $basePath = 'data/queue', int $maxRetries = 3, int $retryBackoff = 0, float $visibilityTimeout = 300.0)
    {
        $this->basePath = $basePath;
        $this->maxRetries = $maxRetries;
        $this->retryBackoff = $retryBackoff;
        $this->visibilityTimeout = $visibilityTimeout;
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
     * Gather every pending, non-delayed job for a topic, ordered by the
     * dequeue policy: highest priority first, ties broken oldest-first
     * (created_at ASC). The stored priority and created_at fields are the
     * ordering keys — not the filename — so pop()/consume() honour priority
     * instead of being pure FIFO.
     *
     * @param string $topic Queue/topic name
     * @return array<int, array{file: string, data: array}> Ordered candidates
     */
    private function availableCandidates(string $topic): array
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
            if ($data === null || ($data['status'] ?? '') !== 'pending') {
                continue;
            }
            if (!empty($data['delay_until']) && (float)$data['delay_until'] > $nowMicro) {
                continue; // still delayed
            }
            $candidates[] = ['file' => $file, 'data' => $data];
        }

        // priority DESC, then created_at ASC (oldest first). created_at is a
        // zero-padded microtime string, so string compare == chronological.
        usort($candidates, function ($a, $b) {
            $pa = (int)($a['data']['priority'] ?? 0);
            $pb = (int)($b['data']['priority'] ?? 0);
            if ($pa !== $pb) {
                return $pb <=> $pa; // higher priority first
            }
            return strcmp((string)($a['data']['created_at'] ?? ''), (string)($b['data']['created_at'] ?? ''));
        });

        return $candidates;
    }

    /**
     * Pop the next available (pending, non-delayed) message from a topic.
     * Returns the highest-priority job first; ties broken oldest-first.
     *
     * @param string $topic The queue/topic name
     * @return array|null Job data or null if empty
     */
    public function dequeue(string $topic): ?array
    {
        // First return any reservations whose consumer died mid-flight.
        $this->reclaimExpired($topic);

        foreach ($this->availableCandidates($topic) as $candidate) {
            // Write the reservation BEFORE claiming the pending file, so a crash
            // between claim and reserve can never strand the job. Only the worker
            // that wins the unlink owns — and returns — it.
            $this->writeReserved($topic, $candidate['data']);
            // Claim the job by deleting the file. If another worker beat us
            // to it, skip to the next candidate.
            if (@unlink($candidate['file'])) {
                $job = $candidate['data'];
                $job['topic'] = $topic;
                return $job;
            }
        }

        return null;
    }

    /**
     * Pop up to $count pending jobs at once, highest priority first.
     * Returns a partial batch if fewer available.
     *
     * @param string $topic Queue/topic name
     * @param int    $count Maximum number of jobs to return
     * @return array<int, array> Array of job arrays (may be shorter than $count)
     */
    public function dequeueBatch(string $topic, int $count): array
    {
        // First return any reservations whose consumer died mid-flight.
        $this->reclaimExpired($topic);

        $jobs = [];
        foreach ($this->availableCandidates($topic) as $candidate) {
            if (count($jobs) >= $count) {
                break;
            }
            // Write the reservation BEFORE claiming the pending file (see dequeue()).
            $this->writeReserved($topic, $candidate['data']);
            if (@unlink($candidate['file'])) {
                $job = $candidate['data'];
                $job['topic'] = $topic;
                $jobs[] = $job;
            }
        }

        return $jobs;
    }

    /**
     * Path to the reserved/ subdir for a topic.
     */
    private function reservedPath(string $topic): string
    {
        return $this->queuePath($topic) . '/reserved';
    }

    /**
     * Persist a reservation record so a dead consumer's job is reclaimable.
     *
     * Stores reserved_at + available_at = now + visibilityTimeout. The next
     * dequeue() reclaims this job once available_at has passed (see
     * reclaimExpired()). acknowledge()/failJob()/retryJob() delete the record.
     *
     * @param string $topic   The queue/topic name
     * @param array  $jobData The pending job's data
     */
    public function writeReserved(string $topic, array $jobData): void
    {
        $reservedPath = $this->reservedPath($topic);
        $this->ensureDir($reservedPath);

        $vt = $this->visibilityTimeout > 0 ? $this->visibilityTimeout : 0;
        $now = microtime(true);

        $record = [
            'id'           => $jobData['id'],
            'payload'      => $jobData['payload'] ?? ($jobData['data'] ?? null),
            'status'       => 'reserved',
            'priority'     => $jobData['priority'] ?? 0,
            'attempts'     => (int)($jobData['attempts'] ?? 0),
            'error'        => $jobData['error'] ?? null,
            'topic'        => $jobData['topic'] ?? $topic,
            'created_at'   => $jobData['created_at'] ?? $this->now(),
            'reserved_at'  => sprintf('%.6f', $now),
            'available_at' => sprintf('%.6f', $vt > 0 ? $now + $vt : $now),
        ];

        file_put_contents(
            $reservedPath . '/' . $record['id'] . '.queue-data',
            json_encode($record, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Return expired reservations to the queue (at-least-once delivery).
     *
     * A reserved job whose available_at <= now means its consumer never
     * acknowledged in time (crash / OOM / pod eviction). Increment attempts and
     * either re-enqueue it (so the next dequeue picks it up) or dead-letter it
     * once it has hit maxRetries. Disabled when visibilityTimeout <= 0.
     *
     * @param string $topic The queue/topic name
     */
    public function reclaimExpired(string $topic): void
    {
        if ($this->visibilityTimeout <= 0) {
            return;
        }

        $reservedPath = $this->reservedPath($topic);
        if (!is_dir($reservedPath)) {
            return;
        }

        $now = microtime(true);
        foreach (glob($reservedPath . '/*.queue-data') as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data === null) {
                continue;
            }
            if ((float)($data['available_at'] ?? 0) > $now) {
                continue; // reservation still valid
            }
            // Atomically claim the expired reservation by deleting its file.
            if (!@unlink($file)) {
                continue; // another worker reclaimed it first
            }

            $data['attempts'] = (int)($data['attempts'] ?? 0) + 1;
            $data['error'] = 'reservation timed out — consumer did not acknowledge within the visibility timeout';
            $topicName = $data['topic'] ?? $topic;

            if ($data['attempts'] >= $this->maxRetries) {
                $this->deadLetter($topicName, $data);
            } else {
                $this->requeue($topicName, $data, 0);
            }
        }
    }

    /**
     * Delete a job's reservation record (best-effort).
     *
     * @param string $topic     The queue/topic name
     * @param string $messageId The job ID whose reservation should be cleared
     */
    public function clearReservation(string $topic, string $messageId): void
    {
        @unlink($this->reservedPath($topic) . '/' . $messageId . '.queue-data');
    }

    /**
     * Acknowledge a message — the pending file was claimed on dequeue and a
     * reservation record written; acknowledge() is terminal, so drop the
     * reservation. The job is done.
     *
     * @param string $topic     The queue/topic name
     * @param string $messageId The message ID to acknowledge
     */
    public function acknowledge(string $topic, string $messageId): void
    {
        $this->clearReservation($topic, $messageId);
    }

    /**
     * Re-queue a message for retry (writes it back to the pending queue).
     *
     * Re-enqueued jobs get a fresh created_at so that within a priority tier
     * they sort behind jobs that have not yet been attempted. The attempts
     * count already reflects the latest failure.
     *
     * @param string $topic        The queue/topic name
     * @param array  $message      The message data
     * @param int    $delaySeconds Seconds to delay availability (0 = immediate)
     */
    public function requeue(string $topic, array $message, int $delaySeconds = 0): void
    {
        $queuePath = $this->queuePath($topic);
        $this->ensureDir($queuePath);

        $message['status'] = 'pending';
        $message['created_at'] = $this->now();
        $message['delay_until'] = $delaySeconds > 0
            ? sprintf('%.6f', microtime(true) + $delaySeconds)
            : null;

        file_put_contents(
            $queuePath . '/' . $message['id'] . '.queue-data',
            json_encode($message, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Send a message to the dead letter (failed) queue. Terminal until a
     * manual retryFailed()/retry() revives it.
     *
     * @param string $topic   The original queue/topic name
     * @param array  $message The message data
     */
    public function deadLetter(string $topic, array $message): void
    {
        $failedPath = $this->queuePath($topic) . '/failed';
        $this->ensureDir($failedPath);

        $message['status'] = 'dead';
        $message['failed_at'] = $this->now();
        file_put_contents(
            $failedPath . '/' . $message['id'] . '.queue-data',
            json_encode($message, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Record a failed attempt for a job (the automatic fail() lifecycle).
     *
     * Increments attempts and stores the error. If the job still has retries
     * left (attempts < maxRetries) it is automatically re-enqueued to the
     * pending queue — so the next pop()/consume() iteration picks it up again
     * — after the configured retryBackoff delay. Once it has been attempted
     * maxRetries times (attempts >= maxRetries) it is moved to the dead-letter
     * store, where deadLetters() returns it.
     *
     * @param string $topic   The queue/topic name
     * @param array  $jobData Job data (must contain id, payload/priority/attempts)
     * @param string $error   Failure reason
     * @return array The job data with the incremented attempts + error applied
     */
    public function failJob(string $topic, array $jobData, string $error = ''): array
    {
        // Clear the reservation — the consumer acknowledged (with a failure).
        $this->clearReservation($topic, (string)($jobData['id'] ?? ''));

        $jobData['attempts'] = (int)($jobData['attempts'] ?? 0) + 1;
        $jobData['error'] = $error;

        if ($jobData['attempts'] < $this->maxRetries) {
            $this->requeue($topic, $jobData, $this->retryBackoff);
        } else {
            $this->deadLetter($topic, $jobData);
        }

        return $jobData;
    }

    /**
     * Explicit re-queue requested by the caller (Job::retry()).
     *
     * Always re-enqueues regardless of the retry limit — this is a manual
     * override, distinct from the automatic failJob() path. Increments
     * attempts and clears the error.
     *
     * @param string $topic        The queue/topic name
     * @param array  $jobData      Job data
     * @param int    $delaySeconds Seconds to delay availability
     * @return array The job data with the incremented attempts applied
     */
    public function retryJob(string $topic, array $jobData, int $delaySeconds = 0): array
    {
        // Clear the reservation — the consumer acknowledged (with a manual retry).
        $this->clearReservation($topic, (string)($jobData['id'] ?? ''));

        $jobData['attempts'] = (int)($jobData['attempts'] ?? 0) + 1;
        $jobData['error'] = null;
        $this->requeue($topic, $jobData, $delaySeconds);

        return $jobData;
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
     * Statuses that live in the failed/ (dead-letter) directory rather than
     * as pending files in the queue directory.
     */
    private const DEAD_STATES = ['failed', 'dead', 'dead_letter'];

    /**
     * Count messages by status.
     *
     * For a dead state ('failed', 'dead', 'dead_letter') every file in the
     * dead-letter dir is counted regardless of its exact stored status string.
     * For any other status, the pending queue dir is scanned and filtered.
     *
     * @param string $topic  The queue/topic name
     * @param string $status Job status: 'pending', 'failed', 'dead', etc.
     * @return int
     */
    public function count(string $topic, string $status = 'pending'): int
    {
        if ($status === 'reserved') {
            $scanDir = $this->reservedPath($topic);
        } elseif (in_array($status, self::DEAD_STATES, true)) {
            $scanDir = $this->queuePath($topic) . '/failed';
        } else {
            $scanDir = $this->queuePath($topic);
        }

        if (!is_dir($scanDir)) {
            return 0;
        }

        $n = 0;
        foreach (glob($scanDir . '/*.queue-data') as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data === null) {
                continue;
            }
            if ($status === 'reserved' || in_array($status, self::DEAD_STATES, true)) {
                // Every file in reserved/ (or failed/) matches the requested
                // status — count them all.
                $n++;
            } elseif (($data['status'] ?? '') === $status) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Clear all pending jobs from a topic queue.
     *
     * @param string $topic The queue/topic name
     * @return int How many jobs were removed
     */
    public function clear(string $topic): int
    {
        $removed = 0;
        foreach ([$this->queuePath($topic), $this->reservedPath($topic)] as $scanDir) {
            if (!is_dir($scanDir)) {
                continue;
            }
            foreach (glob($scanDir . '/*.queue-data') as $file) {
                unlink($file);
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * Get jobs that have failed at least once but are still being retried.
     *
     * Under the auto-retry lifecycle a failed-but-retryable job lives in the
     * PENDING queue (not the dead-letter dir), so this scans the queue dir for
     * pending jobs with 0 < attempts < maxRetries. Dead-lettered jobs are
     * returned by deadLetters() instead.
     *
     * @param string $topic The queue/topic name
     * @return array List of failed (retrying) job arrays
     */
    public function failed(string $topic): array
    {
        $queuePath = $this->queuePath($topic);
        if (!is_dir($queuePath)) {
            return [];
        }

        $jobs = [];
        $files = glob($queuePath . '/*.queue-data');
        sort($files);
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data === null) {
                continue;
            }
            $attempts = (int)($data['attempts'] ?? 0);
            if ($attempts > 0 && $attempts < $this->maxRetries) {
                $jobs[] = $data;
            }
        }
        return $jobs;
    }

    /**
     * Revive a specific dead-letter job by ID back to the pending queue.
     *
     * This is a manual override (Queue::retry($jobId) / Job::retry()) — it
     * always revives a dead-letter regardless of attempt count, incrementing
     * attempts. When $topic is null, all topic subdirectories under basePath
     * are searched. Returns false only if no dead-letter with that id exists.
     *
     * @param string      $jobId        Job ID to retry
     * @param string|null $topic        Topic name, or null to search all topics
     * @param int         $delaySeconds Delay before the revived job becomes available
     * @return bool True if a dead-letter was found and re-queued
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
            $failedFile = $queueDir . '/failed/' . $jobId . '.queue-data';

            if (!file_exists($failedFile)) {
                continue;
            }

            $data = json_decode(file_get_contents($failedFile), true);
            if ($data === null) {
                return false;
            }

            // Manual revive — always re-queue, regardless of the retry limit.
            $data['attempts'] = (int)($data['attempts'] ?? 0) + 1;
            $topicName = $data['topic'] ?? basename($queueDir);
            $this->requeue($topicName, $data, $delaySeconds);
            unlink($failedFile);

            return true;
        }

        return false;
    }

    /**
     * Get dead letter jobs — failed jobs that have exhausted their retries.
     *
     * @param string   $topic      The queue/topic name
     * @param int|null $maxRetries Override the threshold (defaults to the backend's)
     * @return array List of dead job arrays
     */
    public function deadLetters(string $topic, ?int $maxRetries = null): array
    {
        $limit = $maxRetries ?? $this->maxRetries;
        $failedPath = $this->queuePath($topic) . '/failed';
        if (!is_dir($failedPath)) {
            return [];
        }

        $jobs = [];
        $files = glob($failedPath . '/*.queue-data');
        sort($files);
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data !== null && (int)($data['attempts'] ?? 0) >= $limit) {
                $data['status'] = 'dead';
                $jobs[] = $data;
            }
        }
        return $jobs;
    }

    /**
     * Purge jobs by status.
     *
     * For a dead state ('failed', 'dead', 'dead_letter') every file in the
     * dead-letter dir is removed. For any other status, the pending queue dir
     * is scanned and filtered by the stored status.
     *
     * @param string   $status     Status to purge: 'completed', 'failed', 'dead', 'pending'
     * @param string   $topic      The queue/topic name
     * @param int|null $maxRetries Unused for the scan-all dead-letter behaviour; kept for API parity
     * @return int Number of jobs purged
     */
    public function purge(string $status, string $topic, ?int $maxRetries = null): int
    {
        $scanDir = in_array($status, self::DEAD_STATES, true)
            ? $this->queuePath($topic) . '/failed'
            : $this->queuePath($topic);

        if (!is_dir($scanDir)) {
            return 0;
        }

        $count = 0;
        foreach (glob($scanDir . '/*.queue-data') as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data === null) {
                continue;
            }
            if (in_array($status, self::DEAD_STATES, true) || ($data['status'] ?? '') === $status) {
                if (@unlink($file)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Re-queue dead-letter jobs that are under the (possibly raised) limit
     * back to pending. A fresh created_at/available_at is assigned.
     *
     * @param string   $topic      The queue/topic name
     * @param int|null $maxRetries Override the threshold (defaults to the backend's)
     * @return int Number of jobs re-queued
     */
    public function retryFailed(string $topic, ?int $maxRetries = null): int
    {
        $limit = $maxRetries ?? $this->maxRetries;
        $queuePath = $this->queuePath($topic);
        $failedPath = $queuePath . '/failed';

        if (!is_dir($failedPath)) {
            return 0;
        }

        $count = 0;
        foreach (glob($failedPath . '/*.queue-data') as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data === null || (int)($data['attempts'] ?? 0) >= $limit) {
                continue;
            }

            $this->requeue($topic, $data, 0);
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
                // Reserve (so a dead consumer's job is reclaimable) then claim
                // the pending file — mirrors dequeue().
                $this->writeReserved($topic, $data);
                unlink($file);
                $data['topic'] = $topic;
                return $data;
            }
        }

        return null;
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
