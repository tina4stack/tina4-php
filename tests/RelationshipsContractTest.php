<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tina4\ORM;
use Tina4\Database\Database;
use Tina4\Database\SQLite3Adapter;

/**
 * Feature 21 - Declarative ORM relationships: the shared conformance contract,
 * parity with tina4-python/tests/test_relationships_contract.py.
 *
 * REL-DEC-01 (read-side-only cascade) + REL-DEC-02 (soft-delete filter on
 * traversal, bounded eager IN + explicit lazy paging, PHP static eagerLoad). NO
 * MOCKS: real SQLite AND the lab's real PostgreSQL (:55432, tina4/tina4). Row
 * presence/absence is asserted by querying the real table; "one query per
 * relation" is proven with a REAL executed-statement counter that instruments
 * the real adapter's fetch (observation, not a mock). Under
 * TINA4_REQUIRE_SERVICES a postgres skip is a hard failure.
 */

/** Parent model. */
class RelAuthorPhp extends ORM
{
    public string $tableName = 'rel_author';
    public ?int $id = null;
    public ?string $name = null;
}

/** Child model: soft-delete + FK auto-wire to the parent (read-side-only). */
class RelPostPhp extends ORM
{
    public string $tableName = 'rel_post';
    public bool $softDelete = true;
    public ?int $id = null;
    public ?string $title = null;
    public int $is_deleted = 0;
    public ?int $author_id = null;
    /** @var array<string, array{model: string, related_name: string}> */
    public array $foreignKeys = ['author_id' => ['model' => RelAuthorPhp::class, 'related_name' => 'posts']];
}

/**
 * A REAL SQLite adapter that counts fetches touching a watched table. Every
 * counted call still runs the REAL query via parent::fetch -- this instruments
 * the real connection; it is not a mock.
 */
class RelCountingSqlite extends SQLite3Adapter
{
    public int $childFetches = 0;
    public string $watch = '';

    public function fetch(string $sql, array $params = [], int $limit = 100, int $offset = 0): array
    {
        if ($this->watch !== '' && stripos($sql, $this->watch) !== false) {
            $this->childFetches++;
        }
        return parent::fetch($sql, $params, $limit, $offset);
    }
}

final class RelationshipsContractTest extends TestCase
{
    /** @return array<string, array<int, string>> */
    public static function engines(): array
    {
        return ['sqlite' => ['sqlite'], 'postgres' => ['postgres']];
    }

    private function engineDb(string $engine): Database
    {
        if ($engine === 'postgres') {
            $h = getenv('TINA4_TEST_PG_HOST') ?: '127.0.0.1';
            $p = (int) (getenv('TINA4_TEST_PG_PORT') ?: 55432);
            $c = @fsockopen($h, $p, $e, $s, 2.0);
            if (!$c) {
                $this->markTestSkipped("postgres unreachable at {$h}:{$p} (set TINA4_TEST_PG_*)");
            }
            fclose($c);
            $db = getenv('TINA4_TEST_PG_DB') ?: 'tina4_php';
            $u = getenv('TINA4_TEST_PG_USERNAME') ?: 'tina4';
            $pw = getenv('TINA4_TEST_PG_PASSWORD') ?: 'tina4';
            return Database::create("postgres://{$u}:{$pw}@{$h}:{$p}/{$db}");
        }
        return Database::create('sqlite::memory:');
    }

    private function fresh(Database $db): void
    {
        ORM::bindDatabase($db);
        foreach (['rel_post', 'rel_author'] as $t) {
            try {
                $db->execute("DROP TABLE IF EXISTS {$t}");
            } catch (\Throwable) {
            }
        }
        (new RelAuthorPhp($db))->createTable();
        (new RelPostPhp($db))->createTable();
    }

    private function rawCount(Database $db, string $table, string $where, array $params): int
    {
        $row = $db->fetchOne("SELECT COUNT(*) AS c FROM {$table} WHERE {$where}", $params);
        return (int) ($row['c'] ?? $row['C'] ?? 0);
    }

    // ── REL-EAGER: exactly ONE query per relation (no N+1) ───────────────────
    public function testEagerIncludeRunsOneQueryPerRelation(): void
    {
        $db = new RelCountingSqlite(':memory:');
        ORM::bindDatabase($db);
        (new RelAuthorPhp($db))->createTable();
        (new RelPostPhp($db))->createTable();

        for ($a = 0; $a < 3; $a++) {
            $author = (new RelAuthorPhp($db, ['name' => "a{$a}"]))->save();
            for ($p = 0; $p < 2; $p++) {
                (new RelPostPhp($db, ['title' => "a{$a}-p{$p}", 'author_id' => $author->id]))->save();
            }
        }

        $authors = (new RelAuthorPhp($db))->all();
        $db->watch = 'rel_post';
        $db->childFetches = 0;
        // REL-PHP-EAGERLOAD-STATIC: call the PUBLIC static eagerLoad with NO $db
        // arg -- it must resolve the DB via the static path (resolveDb), not the
        // instance getDb() which fatals in a static context.
        // all() returns a ModelCollection (ADR-0064); eagerLoad() takes an array.
        // The models are the same object references, so relations cached by
        // eagerLoad are visible when iterating $authors afterwards.
        RelAuthorPhp::eagerLoad($authors->toArray(), ['posts']);
        $total = 0;
        foreach ($authors as $author) {
            $total += count($author->posts);
        }

        $this->assertSame(6, $total);
        // ONE query for the relation across all 3 authors -- not one per author.
        $this->assertSame(1, $db->childFetches, "expected 1 relation query, got {$db->childFetches} (N+1)");
    }

    // ── REL-SOFTDELETE: a soft-deleted child is excluded from traversal ──────
    #[DataProvider('engines')]
    public function testSoftDeletedChildExcludedFromLazyTraversal(string $engine): void
    {
        $db = $this->engineDb($engine);
        $this->fresh($db);

        $author = (new RelAuthorPhp($db, ['name' => 'a']))->save();
        (new RelPostPhp($db, ['title' => 'keep', 'author_id' => $author->id]))->save();
        $gone = (new RelPostPhp($db, ['title' => 'gone', 'author_id' => $author->id]))->save();
        $gone->delete(); // soft delete -> is_deleted = 1

        $this->assertSame(2, $this->rawCount($db, 'rel_post', '1=1', []));
        $fresh = (new RelAuthorPhp($db))->findById($author->id);
        $titles = array_map(static fn($p) => $p->title, $fresh->posts);
        $this->assertSame(['keep'], $titles);
    }

    #[DataProvider('engines')]
    public function testSoftDeletedChildExcludedFromEagerTraversal(string $engine): void
    {
        $db = $this->engineDb($engine);
        $this->fresh($db);

        $author = (new RelAuthorPhp($db, ['name' => 'a']))->save();
        (new RelPostPhp($db, ['title' => 'keep', 'author_id' => $author->id]))->save();
        $gone = (new RelPostPhp($db, ['title' => 'gone', 'author_id' => $author->id]))->save();
        $gone->delete();

        $loaded = (new RelAuthorPhp($db))->where('1=1', [], 100, 0, ['posts']);
        $this->assertCount(1, $loaded);
        $titles = array_map(static fn($p) => $p->title, $loaded[0]->posts);
        $this->assertSame(['keep'], $titles);
    }

    // ── REL-EAGER-UNBOUNDED: > one page of children is fully reachable ───────
    public function testLargeChildSetIsFullyReachableNotTruncated(): void
    {
        // SQLite only: proves the PAGING loop returns the tail (was a silent
        // 100-row cap via where()'s default limit). Engine-independent paging.
        $db = $this->engineDb('sqlite');
        $this->fresh($db);

        $author = (new RelAuthorPhp($db, ['name' => 'a']))->save();
        $rows = [];
        for ($i = 0; $i < 1001; $i++) {
            $rows[] = ['title' => "p{$i}", 'author_id' => $author->id, 'is_deleted' => 0];
        }
        $db->insert('rel_post', $rows);

        $fresh = (new RelAuthorPhp($db))->findById($author->id);
        $this->assertCount(1001, $fresh->posts, 'tail lost');
    }

    // ── REL-DEC-02: lazy attribute access returns the related rows ───────────
    #[DataProvider('engines')]
    public function testLazyAttributeAccessReturnsRelatedRows(string $engine): void
    {
        $db = $this->engineDb($engine);
        $this->fresh($db);

        $author = (new RelAuthorPhp($db, ['name' => 'a']))->save();
        (new RelPostPhp($db, ['title' => 'p0', 'author_id' => $author->id]))->save();
        (new RelPostPhp($db, ['title' => 'p1', 'author_id' => $author->id]))->save();

        // has_many lazy read
        $fresh = (new RelAuthorPhp($db))->findById($author->id);
        $titles = array_map(static fn($p) => $p->title, $fresh->posts);
        sort($titles);
        $this->assertSame(['p0', 'p1'], $titles);

        // belongs_to lazy read from the other side
        $post = (new RelPostPhp($db))->where('title = ?', ['p0'])[0];
        $this->assertNotNull($post->author);
        $this->assertSame('a', $post->author->name);
    }

    // ── REL-DEC-01: read-side-only -- deleting a parent leaves children ──────
    #[DataProvider('engines')]
    public function testDeletingAParentLeavesChildrenInTheDb(string $engine): void
    {
        $db = $this->engineDb($engine);
        $this->fresh($db);

        $author = (new RelAuthorPhp($db, ['name' => 'a']))->save();
        (new RelPostPhp($db, ['title' => 'p0', 'author_id' => $author->id]))->save();
        (new RelPostPhp($db, ['title' => 'p1', 'author_id' => $author->id]))->save();

        $author->forceDelete(); // hard delete the parent

        // No DB-level ON DELETE cascade: the children rows survive.
        $this->assertSame(0, $this->rawCount($db, 'rel_author', 'id = ?', [$author->id]));
        $this->assertSame(2, $this->rawCount($db, 'rel_post', 'author_id = ?', [$author->id]));
    }
}
