<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Race-safe getNextId() contract — feature 16 (nextid_contract.json), parity
 * with tina4-python/tests/test_nextid_contract.py.
 *
 * Two duplicate-id bugs locked out against REAL databases with REAL concurrency
 * (pcntl_fork — no mocks):
 *
 *   * NEXTID-GENERIC-TOCTOU + NEXTID-PG-FIRSTUSE: the generic sequence fallback
 *     did an UPDATE then a SEPARATE SELECT (two concurrent callers read the same
 *     post-increment value → DUPLICATE id), and the PostgreSQL first-use path let
 *     two concurrent first-callers each CREATE a separate sequence so the loser
 *     drew a duplicate from a second counter. Fixed: a single atomic
 *     UPDATE ... RETURNING, and CREATE SEQUENCE IF NOT EXISTS + always-draw-from
 *     nextval so every caller shares ONE counter.
 *
 *   * NEXTID-MONGO-BROKEN: mongoNextId() no longer swallows an error into
 *     `return 1` (which collides with an existing row) — it RAISES; the atomic
 *     findOneAndUpdate($inc) keyed by _id is monotonic and concurrency-safe.
 *
 * Real services on the .99 lab: PostgreSQL :55432 (tina4/tina4 → tina4_php),
 * MySQL :3306 (tina4/tina4 → tina4_test), MongoDB :27017.
 *
 * Mutation-proof: revert the fallback to UPDATE-then-separate-SELECT and the
 * generic-concurrency case goes RED (a duplicate id); make mongoNextId swallow
 * to `return 1` (or drop the $inc) and the monotonic case goes RED.
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;

class NextIdContractTest extends TestCase
{
    private const CONCURRENCY = 24;

    // ── connection coordinates (canonical TINA4_TEST_* convention) ──────────
    private static function pgUrl(): string
    {
        $host = getenv('TINA4_TEST_PG_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TINA4_TEST_PG_PORT') ?: 55432);
        $db   = getenv('TINA4_TEST_PG_DB') ?: 'tina4_php';
        $user = getenv('TINA4_TEST_PG_USERNAME') ?: 'tina4';
        $pass = getenv('TINA4_TEST_PG_PASSWORD') ?: 'tina4';
        return "postgres://{$user}:{$pass}@{$host}:{$port}/{$db}";
    }

    private static function mysqlUrl(): string
    {
        $host = getenv('TINA4_TEST_MYSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TINA4_TEST_MYSQL_PORT') ?: 3306);
        $db   = getenv('TINA4_TEST_MYSQL_DB') ?: 'tina4_test';
        $user = getenv('TINA4_TEST_MYSQL_USERNAME') ?: 'tina4';
        $pass = getenv('TINA4_TEST_MYSQL_PASSWORD') ?: 'tina4';
        return "mysql://{$user}:{$pass}@{$host}:{$port}/{$db}";
    }

    private static function mongoUri(): string
    {
        return getenv('TINA4_TEST_MONGO_URI') ?: 'mongodb://127.0.0.1:27017';
    }

    private static function mongoUrlWithDb(): string
    {
        $uri = self::mongoUri();
        [$scheme, $rest] = explode('://', $uri, 2);
        $query = str_contains($rest, '?') ? '?' . explode('?', $rest, 2)[1] : '';
        $host = explode('/', explode('?', $rest, 2)[0], 2)[0];
        return "{$scheme}://{$host}/tina4_nextid_php{$query}";
    }

    private static function tcpReachable(string $host, int $port): bool
    {
        $conn = @fsockopen($host, $port, $errno, $errstr, 2.0);
        if ($conn === false) {
            return false;
        }
        fclose($conn);
        return true;
    }

    private function requireFork(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork is required for the real-concurrency test');
        }
    }

    private function requirePg(): void
    {
        $host = getenv('TINA4_TEST_PG_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TINA4_TEST_PG_PORT') ?: 55432);
        if (!self::tcpReachable($host, $port)) {
            $this->markTestSkipped("no reachable postgres at {$host}:{$port} (set TINA4_TEST_PG_*)");
        }
    }

    private function requireMysql(): void
    {
        $host = getenv('TINA4_TEST_MYSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TINA4_TEST_MYSQL_PORT') ?: 3306);
        if (!self::tcpReachable($host, $port)) {
            $this->markTestSkipped("no reachable mysql at {$host}:{$port} (set TINA4_TEST_MYSQL_*)");
        }
    }

    private function requireMongo(): void
    {
        if (!extension_loaded('mongodb') || !class_exists(\MongoDB\Client::class)) {
            $this->markTestSkipped('ext-mongodb / mongodb library not available for the mongodb test');
        }
        $uri = self::mongoUri();
        $host = explode(':', explode('/', explode('://', $uri, 2)[1], 2)[0])[0];
        $port = (int) (explode(':', explode('/', explode('://', $uri, 2)[1], 2)[0] . ':27017')[1]);
        if (!self::tcpReachable($host, $port)) {
            $this->markTestSkipped("no reachable mongodb at {$uri} (set TINA4_TEST_MONGO_URI)");
        }
    }

    /**
     * Fork $n children, each producing ONE id via $childOp(), collected through
     * the filesystem. Returns ['ids' => int[], 'errors' => string[]].
     *
     * @param callable():int $childOp
     * @return array{ids: list<int>, errors: list<string>}
     */
    private function forkIds(int $n, callable $childOp): array
    {
        $outDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tina4_nextid_' . uniqid('', true);
        @mkdir($outDir);

        $pids = [];
        for ($i = 0; $i < $n; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork failed');
            }
            if ($pid === 0) {
                // Child — its own fresh connection (never inherit a DB handle across fork).
                try {
                    $id = $childOp();
                    file_put_contents($outDir . '/' . $i, (string) $id);
                } catch (\Throwable $e) {
                    file_put_contents($outDir . '/' . $i, 'ERR:' . $e->getMessage());
                }
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $ids = [];
        $errors = [];
        foreach (glob($outDir . '/*') as $file) {
            $val = trim((string) file_get_contents($file));
            if (str_starts_with($val, 'ERR:')) {
                $errors[] = $val;
            } else {
                $ids[] = (int) $val;
            }
            @unlink($file);
        }
        @rmdir($outDir);
        return ['ids' => $ids, 'errors' => $errors];
    }

    // ── PostgreSQL: public first-use + the direct generic fallback ──────────

    public function testConcurrentGetNextIdOnAFreshTableYieldsDistinctIds(): void
    {
        $this->requireFork();
        $this->requirePg();

        $table = 'nextid_fresh_' . bin2hex(random_bytes(5));
        $admin = Database::create(self::pgUrl());
        try {
            $admin->execute("DROP TABLE IF EXISTS {$table}");
            $admin->execute("DROP SEQUENCE IF EXISTS {$table}_id_seq");
            $admin->execute("CREATE TABLE {$table} (id INTEGER PRIMARY KEY, v VARCHAR(20))");
            $admin->close();
            $admin = null;

            $out = $this->forkIds(self::CONCURRENCY, static function () use ($table): int {
                $child = Database::create(self::pgUrl());
                try {
                    return $child->getNextId($table);
                } finally {
                    $child->close();
                }
            });

            $this->assertSame([], $out['errors'], 'getNextId raised under concurrency: ' . implode('; ', $out['errors']));
            $this->assertCount(self::CONCURRENCY, $out['ids']);
            $this->assertSame(
                count($out['ids']),
                count(array_unique($out['ids'])),
                'DUPLICATE ids under concurrency: ' . implode(',', $out['ids'])
            );
        } finally {
            $cleanup = Database::create(self::pgUrl());
            try {
                $cleanup->execute("DROP TABLE IF EXISTS {$table}");
                $cleanup->execute("DROP SEQUENCE IF EXISTS {$table}_id_seq");
            } catch (\Throwable) {
                // best effort
            }
            $cleanup->close();
        }
    }

    public function testConcurrentGenericSequenceNextIdYieldsDistinctIds(): void
    {
        $this->requireFork();
        $this->requirePg();

        $seq = 'gen.' . bin2hex(random_bytes(5));
        $admin = Database::create(self::pgUrl());
        try {
            // Seed the tina4_sequences row once so every worker hits the atomic
            // increment (isolating the TOCTOU, not the first-insert race).
            self::invokeSequenceNext($admin, $seq);
            $admin->close();
            $admin = null;

            $out = $this->forkIds(self::CONCURRENCY, static function () use ($seq): int {
                $child = Database::create(self::pgUrl());
                try {
                    // On postgres, sequenceNext() routes to the generic fallback.
                    return self::invokeSequenceNext($child, $seq);
                } finally {
                    $child->close();
                }
            });

            $this->assertSame([], $out['errors'], 'generic next-id raised under concurrency: ' . implode('; ', $out['errors']));
            $this->assertCount(self::CONCURRENCY, $out['ids']);
            $this->assertSame(
                count($out['ids']),
                count(array_unique($out['ids'])),
                'DUPLICATE ids from the generic fallback under concurrency: ' . implode(',', $out['ids'])
            );
        } finally {
            $cleanup = Database::create(self::pgUrl());
            try {
                $cleanup->execute('DELETE FROM tina4_sequences WHERE seq_name = ?', [$seq]);
            } catch (\Throwable) {
                // best effort
            }
            $cleanup->close();
        }
    }

    /** Call the private Database::sequenceNext() (generic path on postgres). */
    private static function invokeSequenceNext(Database $db, string $seq): int
    {
        $method = new \ReflectionMethod($db, 'sequenceNext');
        $method->setAccessible(true);
        return (int) $method->invoke($db, $seq, null, 'id', $db->getAdapter());
    }

    // ── MySQL: the tina4_sequences LAST_INSERT_ID path under real concurrency ──

    public function testConcurrentGetNextIdOnMysqlYieldsDistinctIds(): void
    {
        $this->requireFork();
        $this->requireMysql();

        $table = 'nextid_my_' . bin2hex(random_bytes(4));
        $admin = Database::create(self::mysqlUrl());
        try {
            $admin->execute("DROP TABLE IF EXISTS {$table}");
            $admin->execute("CREATE TABLE {$table} (id INTEGER PRIMARY KEY, v VARCHAR(20))");
            // Pre-create tina4_sequences + the row so workers race the increment.
            $admin->getNextId($table);
            $admin->close();
            $admin = null;

            $out = $this->forkIds(self::CONCURRENCY, static function () use ($table): int {
                $child = Database::create(self::mysqlUrl());
                try {
                    return $child->getNextId($table);
                } finally {
                    $child->close();
                }
            });

            $this->assertSame([], $out['errors'], 'getNextId raised under concurrency: ' . implode('; ', $out['errors']));
            $this->assertCount(self::CONCURRENCY, $out['ids']);
            $this->assertSame(
                count($out['ids']),
                count(array_unique($out['ids'])),
                'DUPLICATE ids under concurrency (mysql): ' . implode(',', $out['ids'])
            );
        } finally {
            $cleanup = Database::create(self::mysqlUrl());
            try {
                $cleanup->execute('DELETE FROM tina4_sequences WHERE seq_name = ?', ["{$table}.id"]);
                $cleanup->execute("DROP TABLE IF EXISTS {$table}");
            } catch (\Throwable) {
                // best effort
            }
            $cleanup->close();
        }
    }

    // ── MongoDB: the dedicated atomic findOneAndUpdate($inc) counter ────────

    public function testMongoNextIdIncrementsMonotonically(): void
    {
        $this->requireMongo();

        $db = Database::create(self::mongoUrlWithDb());
        $table = 'mono_' . bin2hex(random_bytes(5));
        try {
            $id1 = $db->getNextId($table);
            $id2 = $db->getNextId($table);
            $id3 = $db->getNextId($table);
            $this->assertGreaterThan($id1, $id2, "mongo next-id did not advance: id1={$id1} id2={$id2}");
            $this->assertGreaterThan($id2, $id3, "mongo next-id did not advance: id2={$id2} id3={$id3}");
            $this->assertCount(3, array_unique([$id1, $id2, $id3]));
        } finally {
            try {
                $db->execute("DROP TABLE {$table}");
            } catch (\Throwable) {
                // best effort
            }
            $db->close();
        }
    }

    public function testConcurrentMongoNextIdYieldsDistinctIds(): void
    {
        $this->requireFork();
        $this->requireMongo();

        $table = 'conc_' . bin2hex(random_bytes(5));
        $seed = Database::create(self::mongoUrlWithDb());
        try {
            $seed->getNextId($table); // seed the counter once
            $seed->close();
            $seed = null;

            $out = $this->forkIds(self::CONCURRENCY, static function () use ($table): int {
                // Fresh client per child — never share a mongo client across fork.
                $child = Database::create(self::mongoUrlWithDb());
                try {
                    return $child->getNextId($table);
                } finally {
                    $child->close();
                }
            });

            $this->assertSame([], $out['errors'], 'mongo getNextId raised under concurrency: ' . implode('; ', $out['errors']));
            $this->assertCount(self::CONCURRENCY, $out['ids']);
            $this->assertSame(
                count($out['ids']),
                count(array_unique($out['ids'])),
                'DUPLICATE ids under concurrency (mongo): ' . implode(',', $out['ids'])
            );
        } finally {
            $cleanup = Database::create(self::mongoUrlWithDb());
            try {
                $cleanup->execute("DROP TABLE {$table}");
            } catch (\Throwable) {
                // best effort
            }
            $cleanup->close();
        }
    }
}
