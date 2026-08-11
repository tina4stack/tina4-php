<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\MongoDBAdapter;

/**
 * MongoDB SQL provider — fail-closed WHERE + mass-delete data-loss guard (feature 14).
 *
 * Shared contract: plan/v3/fixtures/mongosql_contract.json (MONGO-DEC-01). This
 * is the PHP half; Python/Ruby/Node carry the same case names against the same
 * real MongoDB.
 *
 * WHY THIS FILE EXISTS
 *   The MongoDB SQL provider translates a SQL WHERE into a Mongo filter with a
 *   hand-rolled regex parser. Before MONGO-DEC-01 an UNPARSEABLE / UNSUPPORTED
 *   WHERE silently degraded to an EMPTY filter, so a DELETE/UPDATE then reached
 *   deleteMany([]) / updateMany([]) and matched EVERY document -- a silent mass
 *   wipe -- and NO functional test in any framework exercised the parse/CRUD
 *   path.
 *
 *   The guard is fail-closed: an unparseable WHERE THROWS (never match-all), and
 *   a DELETE/UPDATE with NO WHERE clause is REFUSED (truncate() is the explicit
 *   whole-collection spelling). This proves it against a REAL MongoDB.
 *
 * NO MOCKS. A real MongoDB over a real socket; real documents seeded and read
 * back. The witness is a real side effect: after the guard fires, the collection
 * count is UNCHANGED. Mutation-proved: disable the guard and the unparseable
 * delete wipes the collection, turning "count unchanged" red.
 */
final class MongoSqlFailClosedTest extends TestCase
{
    private const SENTINEL_HOST = '127.0.0.1';
    private const SENTINEL_PORT = 27017;
    private const DB_NAME = 'tina4_mongosql_php';

    private ?MongoDBAdapter $adapter = null;
    private string $collection = '';

    private static function mongoUri(): string
    {
        return getenv('TINA4_TEST_MONGO_URI')
            ?: 'mongodb://' . self::SENTINEL_HOST . ':' . self::SENTINEL_PORT;
    }

    /** A real connect + ping, not a bare port probe. */
    private static function mongoReachable(): bool
    {
        if (!extension_loaded('mongodb') || !class_exists(\MongoDB\Client::class)) {
            return false;
        }
        try {
            $client = new \MongoDB\Client(self::mongoUri(), ['serverSelectionTimeoutMS' => 3000]);
            $client->selectDatabase('admin')->command(['ping' => 1]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** The connection URI with a dedicated database appended. */
    private static function uriWithDb(): string
    {
        $uri = self::mongoUri();
        [$scheme, $rest] = explode('://', $uri, 2);
        $query = str_contains($rest, '?') ? '?' . explode('?', $rest, 2)[1] : '';
        $host = explode('/', explode('?', $rest, 2)[0], 2)[0];
        return "{$scheme}://{$host}/" . self::DB_NAME . $query;
    }

    private static function requireServices(): bool
    {
        return in_array(strtolower(trim((string)getenv('TINA4_REQUIRE_SERVICES'))), ['1', 'true', 'yes', 'on'], true);
    }

    protected function setUp(): void
    {
        if (!self::mongoReachable()) {
            $reason = 'no reachable MongoDB at ' . self::mongoUri() . ' (set TINA4_TEST_MONGO_URI)';
            // Under the require-services gate a real-service SKIP is a hard
            // FAILURE: "a run with skips is NOT verification".
            if (self::requireServices()) {
                $this->fail($reason . ' [TINA4_REQUIRE_SERVICES is set]');
            }
            $this->markTestSkipped($reason);
        }
        $this->adapter = new MongoDBAdapter(self::uriWithDb());
        $this->collection = 'widgets_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        // Reap: drop the collection and close the client so nothing leaks on the lab.
        if ($this->adapter !== null) {
            try {
                $this->adapter->execute("DROP TABLE {$this->collection}");
            } catch (\Throwable) {
                // best effort
            }
            $this->adapter->close();
            $this->adapter = null;
        }
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function seed(array $rows): void
    {
        foreach ($rows as $row) {
            $this->adapter->insert($this->collection, $row);
        }
    }

    private function documentCount(): int
    {
        return count($this->adapter->query("SELECT * FROM {$this->collection}"));
    }

    /** @return list<string> sorted status values of every document */
    private function statuses(): array
    {
        $rows = $this->adapter->query("SELECT * FROM {$this->collection}");
        $out = array_map(static fn(array $r): string => (string)($r['status'] ?? ''), $rows);
        sort($out);
        return $out;
    }

    // ── Guard 1: an unparseable / unsupported WHERE fails closed ─────────────

    public function testAnUnparseableWhereDeleteRaisesAndDeletesNothing(): void
    {
        $this->seed([
            ['id' => 1, 'status' => 'keep'],
            ['id' => 2, 'status' => 'keep'],
            ['id' => 3, 'status' => 'gone'],
        ]);
        $this->assertSame(3, $this->documentCount());

        // UPPER(status) is a function on the column -- unsupported by the regex
        // parser. Before the fix it degraded to [] and deleteMany([]) wiped all 3.
        $threw = false;
        try {
            $this->adapter->execute("DELETE FROM {$this->collection} WHERE UPPER(status) = 'GONE'");
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'unparseable WHERE delete must raise');

        // The witness: nothing was deleted.
        $this->assertSame(3, $this->documentCount());
    }

    public function testAPartiallyUnparseableWhereDeleteRaisesAndDeletesNothing(): void
    {
        // A COMPOUND WHERE where one AND-part is valid and one is unsupported. If
        // the parser silently DROPPED the unsupported part it would leave
        // ['id' => 1] -- a NON-empty but WRONG filter that the empty-filter guard
        // waves through -- and delete id=1 regardless of its status. Only the
        // fail-closed parse catches this: the whole statement must raise.
        $this->seed([
            ['id' => 1, 'status' => 'keep'],
            ['id' => 2, 'status' => 'gone'],
        ]);

        $threw = false;
        try {
            $this->adapter->execute("DELETE FROM {$this->collection} WHERE id = 1 AND UPPER(status) = 'GONE'");
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'a partially unparseable WHERE delete must raise');

        // Neither document was touched.
        $this->assertSame(2, $this->documentCount());
        $this->assertSame(['gone', 'keep'], $this->statuses());
    }

    public function testAnUnparseableWhereUpdateRaisesAndChangesNothing(): void
    {
        $this->seed([
            ['id' => 1, 'status' => 'keep'],
            ['id' => 2, 'status' => 'keep'],
        ]);

        $threw = false;
        try {
            $this->adapter->execute("UPDATE {$this->collection} SET status = 'wiped' WHERE UPPER(status) = 'KEEP'");
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'unparseable WHERE update must raise');

        $this->assertSame(['keep', 'keep'], $this->statuses());
    }

    // ── Guard 2: a DELETE/UPDATE with NO WHERE is refused (mass-write guard) ──

    public function testANoWhereDeleteIsRejectedAndDeletesNothing(): void
    {
        $this->seed([
            ['id' => 1, 'status' => 'keep'],
            ['id' => 2, 'status' => 'keep'],
            ['id' => 3, 'status' => 'keep'],
        ]);
        $this->assertSame(3, $this->documentCount());

        $threw = false;
        try {
            $this->adapter->execute("DELETE FROM {$this->collection}");
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'a filterless delete must be refused');

        // A filterless delete must never empty the collection.
        $this->assertSame(3, $this->documentCount());
    }

    public function testANoWhereUpdateIsRejectedAndChangesNothing(): void
    {
        $this->seed([
            ['id' => 1, 'status' => 'keep'],
            ['id' => 2, 'status' => 'keep'],
        ]);

        $threw = false;
        try {
            $this->adapter->execute("UPDATE {$this->collection} SET status = 'wiped'");
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'a filterless update must be refused');

        $this->assertSame(['keep', 'keep'], $this->statuses());
    }

    // ── Positive: a real WHERE scopes the write to only the matching docs ────

    public function testAValidWhereDeleteRemovesOnlyMatchingDocs(): void
    {
        $this->seed([
            ['id' => 1, 'status' => 'keep'],
            ['id' => 2, 'status' => 'gone'],
            ['id' => 3, 'status' => 'keep'],
            ['id' => 4, 'status' => 'gone'],
        ]);
        $this->assertSame(4, $this->documentCount());

        $this->adapter->execute("DELETE FROM {$this->collection} WHERE status = ?", ['gone']);

        // Exactly the two matches are gone; the two "keep" rows remain.
        $this->assertSame(2, $this->documentCount());
        $this->assertSame(['keep', 'keep'], $this->statuses());
    }

    public function testAValidWhereUpdateChangesOnlyMatchingDocs(): void
    {
        $this->seed([
            ['id' => 1, 'status' => 'keep'],
            ['id' => 2, 'status' => 'keep'],
            ['id' => 3, 'status' => 'keep'],
        ]);

        $this->adapter->execute("UPDATE {$this->collection} SET status = ? WHERE id = ?", ['changed', 2]);

        // Only id=2 changed.
        $this->assertSame(['changed', 'keep', 'keep'], $this->statuses());
    }
}
