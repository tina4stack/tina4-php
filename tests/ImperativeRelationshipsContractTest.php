<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tina4\ORM;
use Tina4\Database\Database;

/**
 * Feature 22 - Imperative ORM relationships: the shared conformance contract,
 * parity with tina4-python/tests/test_imperative_relationships_contract.py.
 *
 * IMPREL-DEC-01: imperative relationships are a PER-LANGUAGE IDIOM (PHP keeps its
 * distinct instance-method API hasOne/hasMany/belongsTo). IMPREL-DEC-02:
 * de-duplicate PHP's parallel private hasOneMethod/hasManyMethod/belongsToMethod
 * onto the ONE public implementation and give the public imperative methods a
 * real test (they had none). NO MOCKS: real SQLite AND the lab's real PostgreSQL
 * (:55432, tina4/tina4). Positive AND negative throughout. Under
 * TINA4_REQUIRE_SERVICES a postgres skip is a hard failure.
 */

/** Parent model. */
class ImpRelAuthorPhp extends ORM
{
    public string $tableName = 'imp_rel_author';
    public ?int $id = null;
    public ?string $name = null;
}

/** Child: FK auto-wire to the parent (declares hasMany 'posts' on the parent). */
class ImpRelPostPhp extends ORM
{
    public string $tableName = 'imp_rel_post';
    public ?int $id = null;
    public ?string $title = null;
    public ?int $author_id = null;
    /** @var array<string, array{model: string, related_name: string}> */
    public array $foreignKeys = ['author_id' => ['model' => ImpRelAuthorPhp::class, 'related_name' => 'posts']];
}

final class ImperativeRelationshipsContractTest extends TestCase
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
        foreach (['imp_rel_post', 'imp_rel_author'] as $t) {
            try {
                $db->execute("DROP TABLE IF EXISTS {$t}");
            } catch (\Throwable) {
            }
        }
        (new ImpRelAuthorPhp($db))->createTable();
        (new ImpRelPostPhp($db))->createTable();
    }

    // ── IMPREL-DEC-02: an imperatively-loaded relation SERIALIZES ────────────
    #[DataProvider('engines')]
    public function testImperativeRelationSerializes(string $engine): void
    {
        $db = $this->engineDb($engine);
        $this->fresh($db);

        $author = (new ImpRelAuthorPhp($db, ['name' => 'a']))->save();
        for ($i = 0; $i < 3; $i++) {
            (new ImpRelPostPhp($db, ['title' => "p{$i}", 'author_id' => $author->id]))->save();
        }

        // Imperative load: PUBLIC instance method taking the related CLASS + fk.
        $rel = $author->hasMany(ImpRelPostPhp::class, 'author_id');
        $this->assertCount(3, $rel);

        // Serialization with include= contains the relation with the same rows.
        $d = $author->toDict(['posts']);
        $this->assertArrayHasKey('posts', $d);
        $this->assertCount(3, $d['posts']);
        $titles = array_map(static fn($p) => $p['title'], $d['posts']);
        sort($titles);
        $this->assertSame(['p0', 'p1', 'p2'], $titles);

        // Negative: WITHOUT include the relation is not serialized (fields only).
        $this->assertArrayNotHasKey('posts', $author->toDict());
    }

    // ── IMPREL-PY-CAP parity: imperative and lazy return the SAME row count ───
    public function testImperativeAndLazySameRowCount(): void
    {
        // SQLite only: paging is engine-independent. 150 > the old imperative cap
        // of 100 (the public hasMany used to single-page via where()'s default).
        $db = $this->engineDb('sqlite');
        $this->fresh($db);

        $author = (new ImpRelAuthorPhp($db, ['name' => 'a']))->save();
        $rows = [];
        for ($i = 0; $i < 150; $i++) {
            $rows[] = ['title' => "p{$i}", 'author_id' => $author->id];
        }
        $db->insert('imp_rel_post', $rows);

        $imperative = $author->hasMany(ImpRelPostPhp::class, 'author_id');
        $lazy = (new ImpRelAuthorPhp($db))->findById($author->id)->posts;

        $this->assertCount(150, $imperative, 'imperative capped');
        $this->assertCount(150, $lazy, 'lazy capped');
        $this->assertSame(count($imperative), count($lazy));

        // Negative: an EXPLICIT limit still pages (the default is genuinely
        // uncapped, not just a larger fixed cap).
        $this->assertCount(100, $author->hasMany(ImpRelPostPhp::class, 'author_id', 100));
    }

    // ── IMPREL-DEC-01: the imperative surface behaves the same (per-language) ─
    #[DataProvider('engines')]
    public function testImperativeSurfaceBehavesSameAllFour(string $engine): void
    {
        $db = $this->engineDb($engine);
        $this->fresh($db);

        $author = (new ImpRelAuthorPhp($db, ['name' => 'a']))->save();
        (new ImpRelPostPhp($db, ['title' => 'p0', 'author_id' => $author->id]))->save();
        (new ImpRelPostPhp($db, ['title' => 'p1', 'author_id' => $author->id]))->save();
        $child = (new ImpRelPostPhp($db))->where('title = ?', ['p0'])[0];

        // has_one -> a single related child
        $one = $author->hasOne(ImpRelPostPhp::class, 'author_id');
        $this->assertNotNull($one);
        $this->assertContains($one->title, ['p0', 'p1']);

        // has_many -> all the children
        $many = $author->hasMany(ImpRelPostPhp::class, 'author_id');
        $titles = array_map(static fn($p) => $p->title, $many);
        sort($titles);
        $this->assertSame(['p0', 'p1'], $titles);

        // belongs_to -> the parent
        $parent = $child->belongsTo(ImpRelAuthorPhp::class, 'author_id');
        $this->assertNotNull($parent);
        $this->assertSame('a', $parent->name);

        // Negative: belongs_to whose FK resolves to no parent returns null
        // (read-side-only: no DB FK constraint, so a dangling FK inserts fine).
        $orphan = (new ImpRelPostPhp($db, ['title' => 'orphan', 'author_id' => 999999]))->save();
        $this->assertNull($orphan->belongsTo(ImpRelAuthorPhp::class, 'author_id'));
    }
}
