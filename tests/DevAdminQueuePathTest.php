<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;

/**
 * Regression: the dev-admin queue panel LISTED a different store from the one it
 * COUNTED, and the two disagreed in both directions.
 *
 * Three defects of one shape, all pinned here (ported from tina4-nodejs b07a9d9):
 *
 *   1. THE DIRECTORY. DevAdmin hardcoded `getcwd() . '/data/queue'` for both the
 *      job list and the topic list, while Queue::size() counts against the
 *      Queue's basePath, which honours TINA4_QUEUE_PATH. Move the store off the
 *      working directory - any container with a volume - and the panel listed
 *      one directory and counted another.
 *
 *   2. THE SET. A RESERVED job was counted by stats.reserved and never listed. A
 *      failed-but-retryable job - which lives in the PENDING dir with status
 *      "pending" - was listed TWICE: once by the directory scan and once by
 *      Queue::failed(), which re-reads those same files. One job, two rows, two
 *      contradictory statuses. This one bites in the DEFAULT configuration, with
 *      no TINA4_QUEUE_PATH set at all.
 *
 *   3. MAXRETRIES. Dead letters were listed via Queue::deadLetters(), which
 *      filters on the DEV ADMIN's own maxRetries (3). A job dead-lettered by an
 *      app configured maxRetries=1 was counted by stats.failed and never appeared
 *      in the list.
 *
 * The contract this locks in: every job appears EXACTLY ONCE, in the bucket its
 * own stat counts it in, so sum(pending, completed, failed, reserved) equals the
 * number of listed jobs, and each ?status= filter returns exactly what its stat
 * counts.
 *
 * NO MOCKS. Two real `php -S` servers on real ports, real job files on disk in a
 * real file-backed queue store, driven over real HTTP. The stats come from the
 * real Queue class, not from anything this test writes.
 */
class DevAdminQueuePathTest extends TestCase
{
    /** Server whose app pins TINA4_QUEUE_PATH away from the working directory. */
    private static ?TestServer $pinnedServer = null;
    /** Server with NO TINA4_QUEUE_PATH at all - the default configuration. */
    private static ?TestServer $defaultServer = null;

    /** Document root (and cwd) of the pinned-path server. */
    private static string $pinnedRoot = '';
    /** The real store the pinned-path app reads and writes - NOT under the docroot. */
    private static string $pinnedStore = '';
    /** Document root (and cwd) of the default-configuration server. */
    private static string $defaultRoot = '';

    private const TOPIC = 'emails';

    public static function setUpBeforeClass(): void
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';

        // A clean-room app: framework only, dev admin registered, nothing else.
        // App::register() gates DevAdmin::register() on TINA4_DEBUG; calling it
        // directly is the same registration, without needing a whole app boot.
        $index = <<<PHP
        <?php
        require_once '{$autoload}';
        \\Tina4\\DevAdmin::register();
        \$result = \\Tina4\\Router::dispatch(new \\Tina4\\Request(), new \\Tina4\\Response());
        echo \$result->getBody();
        PHP;

        $suffix = getmypid() . '_' . bin2hex(random_bytes(4));
        self::$pinnedRoot  = sys_get_temp_dir() . '/tina4_devqueue_app_' . $suffix;
        self::$pinnedStore = sys_get_temp_dir() . '/tina4_devqueue_store_' . $suffix;
        self::$defaultRoot = sys_get_temp_dir() . '/tina4_devqueue_default_' . $suffix;

        foreach ([self::$pinnedRoot, self::$defaultRoot] as $root) {
            @mkdir($root, 0777, true);
            file_put_contents($root . '/index.php', $index);
        }

        // ── Scaffold A: the REAL store, pinned by TINA4_QUEUE_PATH ────────────
        $topicDir = self::$pinnedStore . '/' . self::TOPIC;
        self::writeJob($topicDir, 'pending-one');
        self::writeJob($topicDir, 'pending-two');
        // Failed-but-retryable: the auto-retry lifecycle puts it back in the
        // PENDING dir with status "pending" and attempts > 0. Queue::failed()
        // re-reads it, which is what listed it twice.
        self::writeJob($topicDir, 'retrying-job', ['attempts' => 1, 'error' => 'boom']);
        self::writeJob($topicDir . '/reserved', 'reserved-job', ['status' => 'reserved', 'attempts' => 1]);
        // Two dead letters: one past the dev admin's own maxRetries of 3, one
        // dead-lettered by an app configured maxRetries=1. Both are counted by
        // size('failed'); only the first survived deadLetters()' filter.
        self::writeJob($topicDir . '/failed', 'dead-max-retries', ['status' => 'dead', 'attempts' => 5]);
        self::writeJob($topicDir . '/failed', 'dead-low-retries', ['status' => 'dead', 'attempts' => 1]);
        // A second REAL topic, so the topics endpoint has something to get wrong.
        self::writeJob(self::$pinnedStore . '/reports', 'report-one');

        // ── Scaffold A: STALE junk at the legacy getcwd()/data/queue path ─────
        // A previous local run left this behind. The app does not read it and
        // Queue::size() does not count it, so the panel must not list it.
        self::writeJob(self::$pinnedRoot . '/data/queue/' . self::TOPIC, 'stale-legacy');
        self::writeJob(self::$pinnedRoot . '/data/queue/legacy-only', 'stale-other');

        // ── Scaffold B: the DEFAULT configuration, store under the docroot ────
        $defaultTopicDir = self::$defaultRoot . '/data/queue/default';
        self::writeJob($defaultTopicDir, 'plain-pending');
        self::writeJob($defaultTopicDir, 'retry-pending', ['attempts' => 1, 'error' => 'boom']);
        self::writeJob($defaultTopicDir . '/reserved', 'held-job', ['status' => 'reserved', 'attempts' => 1]);
        self::writeJob($defaultTopicDir . '/failed', 'dead-one', ['status' => 'dead', 'attempts' => 1]);

        $pinnedEnv = getenv();
        $pinnedEnv['TINA4_QUEUE_PATH'] = self::$pinnedStore;
        unset($pinnedEnv['TINA4_QUEUE_BACKEND']);
        self::$pinnedServer = TestServer::start(self::$pinnedRoot . '/index.php', $pinnedEnv);

        $defaultEnv = getenv();
        unset($defaultEnv['TINA4_QUEUE_PATH'], $defaultEnv['TINA4_QUEUE_BACKEND']);
        self::$defaultServer = TestServer::start(self::$defaultRoot . '/index.php', $defaultEnv);
    }

    public static function tearDownAfterClass(): void
    {
        self::$pinnedServer?->stop();
        self::$defaultServer?->stop();
        foreach ([self::$pinnedRoot, self::$pinnedStore, self::$defaultRoot] as $dir) {
            self::removeTree($dir);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Defect 1 — THE DIRECTORY
    // ─────────────────────────────────────────────────────────────────────────

    /** CONTROL: the fixture really is split across two directories on disk. */
    public function testTheFixtureReallyHasTwoSeparateStoresOnDisk(): void
    {
        $this->assertFileExists(
            self::$pinnedStore . '/' . self::TOPIC . '/pending-one.queue-data',
            'the real store must hold the real jobs'
        );
        $this->assertFileExists(
            self::$pinnedRoot . '/data/queue/' . self::TOPIC . '/stale-legacy.queue-data',
            'the legacy cwd path must hold the stale job, or this test proves nothing'
        );
        $this->assertNotSame(
            realpath(self::$pinnedStore),
            realpath(self::$pinnedRoot . '/data/queue'),
            'the two stores must be different directories'
        );
    }

    public function testStatsCountTheStorePinnedByTina4QueuePath(): void
    {
        $stats = $this->queue(self::$pinnedServer, self::TOPIC)['stats'];

        $this->assertSame(3, $stats['pending'], 'two fresh + one retrying job live in the real store');
        $this->assertSame(0, $stats['completed'], 'the file backend deletes on acknowledge, so completed is always 0');
        $this->assertSame(2, $stats['failed'], 'both dead letters are counted regardless of attempts');
        $this->assertSame(1, $stats['reserved'], 'one reservation record');
    }

    public function testTheJobListReadsTheStorePinnedByTina4QueuePath(): void
    {
        $ids = $this->jobIds($this->queue(self::$pinnedServer, self::TOPIC));

        $this->assertContains('pending-one', $ids);
        $this->assertContains('pending-two', $ids);
        $this->assertContains('retrying-job', $ids);
    }

    public function testAStaleJobAtTheLegacyCwdPathIsNotListed(): void
    {
        $ids = $this->jobIds($this->queue(self::$pinnedServer, self::TOPIC));

        $this->assertNotContains(
            'stale-legacy',
            $ids,
            'the panel listed getcwd()/data/queue while the stats counted TINA4_QUEUE_PATH'
        );
    }

    public function testTopicsComeFromTheStorePinnedByTina4QueuePath(): void
    {
        $topics = $this->fetchJson(self::$pinnedServer, '/__dev/api/queue/topics')['topics'];

        $this->assertContains(self::TOPIC, $topics);
        $this->assertContains('reports', $topics, 'a real topic in the real store must be listed');
        $this->assertNotContains('legacy-only', $topics, 'a topic that exists only in the stale legacy store must not be');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Defect 2 — THE SET (every job listed exactly once, in its own bucket)
    // ─────────────────────────────────────────────────────────────────────────

    public function testAReservedJobIsListedAndNotOnlyCounted(): void
    {
        $jobs = $this->queue(self::$pinnedServer, self::TOPIC)['jobs'];
        $reserved = $this->jobsWithStatus($jobs, 'reserved');

        $this->assertCount(1, $reserved, 'stats.reserved counted it, so the list must show it');
        $this->assertSame('reserved-job', $reserved[0]['id']);
    }

    public function testAFailedButRetryableJobIsListedExactlyOnce(): void
    {
        $ids = $this->jobIds($this->queue(self::$pinnedServer, self::TOPIC));

        $this->assertSame(
            1,
            count(array_keys($ids, 'retrying-job', true)),
            'the directory scan and Queue::failed() both read this same file'
        );
    }

    public function testNoJobIsListedTwice(): void
    {
        $ids = $this->jobIds($this->queue(self::$pinnedServer, self::TOPIC));

        $this->assertSame(array_values(array_unique($ids)), $ids, 'duplicate rows in the job list: ' . implode(',', $ids));
    }

    public function testTheStatsSumToTheNumberOfListedJobs(): void
    {
        $payload = $this->queue(self::$pinnedServer, self::TOPIC);
        $stats = $payload['stats'];
        $sum = $stats['pending'] + $stats['completed'] + $stats['failed'] + $stats['reserved'];

        $this->assertGreaterThan(0, $sum, 'CONTROL: the store must actually hold jobs');
        $this->assertCount($sum, $payload['jobs'], 'the list must describe the same set the stats count');
    }

    public function testEveryRealJobIsListedExactlyOnce(): void
    {
        $ids = $this->jobIds($this->queue(self::$pinnedServer, self::TOPIC));
        sort($ids);

        $this->assertSame(
            ['dead-low-retries', 'dead-max-retries', 'pending-one', 'pending-two', 'reserved-job', 'retrying-job'],
            $ids
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Defect 3 — MAXRETRIES
    // ─────────────────────────────────────────────────────────────────────────

    public function testADeadLetterBelowTheDevAdminsOwnMaxRetriesIsStillListed(): void
    {
        $jobs = $this->queue(self::$pinnedServer, self::TOPIC)['jobs'];
        $ids = $this->jobIds(['jobs' => $jobs]);

        $this->assertContains('dead-max-retries', $ids, 'CONTROL: the dead letter past maxRetries was always listed');
        $this->assertContains(
            'dead-low-retries',
            $ids,
            'dead-lettered by an app with maxRetries=1, counted by stats.failed, filtered out of the list'
        );
    }

    public function testTheDeadLetterEndpointAgreesWithTheFailedStat(): void
    {
        $payload = $this->fetchJson(self::$pinnedServer, '/__dev/api/queue/dead-letters?topic=' . self::TOPIC);
        $ids = array_column($payload['jobs'], 'id');
        sort($ids);

        $this->assertSame(['dead-low-retries', 'dead-max-retries'], $ids);
        $this->assertSame(
            $this->queue(self::$pinnedServer, self::TOPIC)['stats']['failed'],
            $payload['count'],
            "the panel's Dead Letters tab showed 0 next to a stats tab reporting failed: 1"
        );
    }

    public function testTheDeadLetterEndpointReadsTheStorePinnedByTina4QueuePath(): void
    {
        $payload = $this->fetchJson(self::$pinnedServer, '/__dev/api/queue/dead-letters?topic=' . self::TOPIC);

        $this->assertNotContains('stale-legacy', array_column($payload['jobs'], 'id'));
    }

    public function testDeadLettersAreListedAsDeadLetterAndNotAsPending(): void
    {
        $jobs = $this->queue(self::$pinnedServer, self::TOPIC)['jobs'];
        $dead = $this->jobsWithStatus($jobs, 'dead_letter');
        $deadIds = array_column($dead, 'id');
        sort($deadIds);

        $this->assertSame(['dead-low-retries', 'dead-max-retries'], $deadIds);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Each ?status= filter returns exactly what its stat counts
    // ─────────────────────────────────────────────────────────────────────────

    public function testStatusPendingReturnsExactlyWhatThePendingStatCounts(): void
    {
        $payload = $this->queue(self::$pinnedServer, self::TOPIC, 'pending');

        $this->assertCount($payload['stats']['pending'], $payload['jobs']);
        foreach ($payload['jobs'] as $job) {
            $this->assertSame('pending', $job['status']);
        }
    }

    public function testStatusReservedReturnsExactlyWhatTheReservedStatCounts(): void
    {
        $payload = $this->queue(self::$pinnedServer, self::TOPIC, 'reserved');

        $this->assertCount($payload['stats']['reserved'], $payload['jobs']);
        $this->assertSame('reserved-job', $payload['jobs'][0]['id']);
    }

    public function testStatusFailedReturnsExactlyWhatTheFailedStatCounts(): void
    {
        $payload = $this->queue(self::$pinnedServer, self::TOPIC, 'failed');

        $this->assertCount($payload['stats']['failed'], $payload['jobs']);
    }

    public function testStatusDeadReturnsTheSameDeadLetterStoreAsStatusFailed(): void
    {
        $dead = $this->jobIds($this->queue(self::$pinnedServer, self::TOPIC, 'dead'));
        $failed = $this->jobIds($this->queue(self::$pinnedServer, self::TOPIC, 'failed'));
        sort($dead);
        sort($failed);

        $this->assertSame(['dead-low-retries', 'dead-max-retries'], $dead);
        $this->assertSame($failed, $dead, "'failed' and 'dead' name the same store, so size() counts them the same");
    }

    public function testStatusCompletedReturnsExactlyWhatTheCompletedStatCounts(): void
    {
        $payload = $this->queue(self::$pinnedServer, self::TOPIC, 'completed');

        $this->assertCount($payload['stats']['completed'], $payload['jobs']);
    }

    public function testAnUnknownTopicListsNothingAndCountsNothing(): void
    {
        $payload = $this->queue(self::$pinnedServer, 'no-such-topic');

        $this->assertSame([], $payload['jobs']);
        $this->assertSame(0, array_sum($payload['stats']));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The DEFAULT configuration — no TINA4_QUEUE_PATH at all
    // ─────────────────────────────────────────────────────────────────────────

    public function testDefaultConfigurationStatsCountTheStoreUnderTheWorkingDirectory(): void
    {
        $stats = $this->queue(self::$defaultServer, 'default')['stats'];

        $this->assertSame(2, $stats['pending'], 'CONTROL: the default data/queue store must resolve, or nothing below discriminates');
        $this->assertSame(1, $stats['reserved']);
        $this->assertSame(1, $stats['failed']);
        $this->assertSame(0, $stats['completed']);
    }

    public function testDefaultConfigurationListsEveryJobExactlyOnce(): void
    {
        $ids = $this->jobIds($this->queue(self::$defaultServer, 'default'));
        sort($ids);

        $this->assertSame(['dead-one', 'held-job', 'plain-pending', 'retry-pending'], $ids);
    }

    public function testDefaultConfigurationDoesNotListTheRetryingJobTwice(): void
    {
        $ids = $this->jobIds($this->queue(self::$defaultServer, 'default'));

        $this->assertSame(
            1,
            count(array_keys($ids, 'retry-pending', true)),
            'the directory scan and Queue::failed() read the same file, with contradictory statuses'
        );
    }

    public function testDefaultConfigurationStatsSumToTheNumberOfListedJobs(): void
    {
        $payload = $this->queue(self::$defaultServer, 'default');
        $sum = array_sum($payload['stats']);

        $this->assertGreaterThan(0, $sum, 'CONTROL: the default store must actually hold jobs');
        $this->assertCount($sum, $payload['jobs']);
    }

    public function testDefaultConfigurationListsTheReservedJob(): void
    {
        $jobs = $this->queue(self::$defaultServer, 'default')['jobs'];

        $this->assertSame(['held-job'], array_column($this->jobsWithStatus($jobs, 'reserved'), 'id'));
    }

    public function testDefaultConfigurationListsADeadLetterBelowTheDevAdminsMaxRetries(): void
    {
        $jobs = $this->queue(self::$defaultServer, 'default')['jobs'];

        $this->assertSame(['dead-one'], array_column($this->jobsWithStatus($jobs, 'dead_letter'), 'id'));
    }

    public function testDefaultConfigurationTopicsComeFromTheWorkingDirectoryStore(): void
    {
        $topics = $this->fetchJson(self::$defaultServer, '/__dev/api/queue/topics')['topics'];

        $this->assertContains('default', $topics);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** GET /__dev/api/queue for a topic, optionally filtered by status. */
    private function queue(TestServer $server, string $topic, ?string $status = null): array
    {
        $path = '/__dev/api/queue?topic=' . rawurlencode($topic);
        if ($status !== null) {
            $path .= '&status=' . rawurlencode($status);
        }
        $payload = $this->fetchJson($server, $path);

        $this->assertArrayNotHasKey('error', $payload, 'the queue endpoint reported: ' . ($payload['error'] ?? ''));
        $this->assertIsArray($payload['jobs']);
        $this->assertIsArray($payload['stats']);

        return $payload;
    }

    /** A real HTTP GET against a real server, decoded. */
    private function fetchJson(TestServer $server, string $path): array
    {
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'ignore_errors' => true,
            'timeout' => 15,
        ]]);
        $raw = @file_get_contents($server->base() . $path, false, $context);
        $this->assertIsString($raw, 'no response from ' . $path . ' — server log: ' . $server->log());

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, $path . ' did not return JSON: ' . $raw);

        return $decoded;
    }

    /** @return string[] job ids in listed order */
    private function jobIds(array $payload): array
    {
        return array_map(static fn (array $job): string => (string)($job['id'] ?? ''), $payload['jobs']);
    }

    /** @return array<int, array> the listed jobs carrying a given status */
    private function jobsWithStatus(array $jobs, string $status): array
    {
        return array_values(array_filter($jobs, static fn (array $job): bool => ($job['status'] ?? '') === $status));
    }

    /** Write a real *.queue-data record in the LiteBackend's own on-disk shape. */
    private static function writeJob(string $dir, string $id, array $overrides = []): void
    {
        @mkdir($dir, 0777, true);
        $job = array_merge([
            'id'          => $id,
            'payload'     => ['job' => $id],
            'status'      => 'pending',
            'created_at'  => '2026-08-05 10:00:00.000000',
            'attempts'    => 0,
            'delay_until' => null,
            'priority'    => 0,
            'error'       => null,
        ], $overrides);

        file_put_contents($dir . '/' . $id . '.queue-data', json_encode($job, JSON_PRETTY_PRINT));
    }

    private static function removeTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
