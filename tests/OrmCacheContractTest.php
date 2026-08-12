<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * ORM result caching contract -- feature 25 (CACHE-DEC-01).
 *
 * Shared conformance fixture:
 *   tina4-documentation/plan/v3/fixtures/ormcache_contract.json
 *
 * Proves, against a REAL SQLite database with REAL rows and REAL ORM writes (NO
 * mocks), that ORM::cached():
 *
 *   * caches within the TTL (a DIRECT db write between two reads is NOT seen),
 *     and ttl<=0 means NO-CACHE (every read hits the DB);
 *   * is busted by a save/delete/forceDelete/restore THROUGH THE ORM;
 *   * is tagged by every table it touches, so a write to a JOINed table busts it
 *     while a write to an UNRELATED table leaves it intact.
 *
 * "It cached" is proven POSITIVELY: the ONLY way the second within-TTL read can
 * be stale is that it came from the cache. The direct write is a raw
 * `$db->exec("UPDATE ...")`, which never touches the model query cache.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\SQLite3Adapter;

class CacheAuthorModel extends \Tina4\ORM
{
    public string $tableName = 'cacheauthor';
    public string $primaryKey = 'id';
}

class CacheBookModel extends \Tina4\ORM
{
    public string $tableName = 'cachebook';
    public string $primaryKey = 'id';
    public bool $softDelete = true;
}

class OrmCacheContractTest extends TestCase
{
    private SQLite3Adapter $db;

    private const BOOK_SQL = 'SELECT id, title FROM cachebook WHERE is_deleted = 0';
    private const ALL_BOOK_SQL = 'SELECT id, title FROM cachebook';
    private const JOIN_SQL =
        'SELECT b.id FROM cachebook b JOIN cacheauthor a ON a.id = b.author_id WHERE a.name = ?';

    protected function setUp(): void
    {
        $this->db = new SQLite3Adapter(':memory:');
        $this->db->exec('CREATE TABLE cacheauthor (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $this->db->exec(
            'CREATE TABLE cachebook (id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'title TEXT, author_id INTEGER, is_deleted INTEGER DEFAULT 0)'
        );
        \Tina4\ORM::bindDatabase($this->db);
        // The model query cache is process-wide (static) -- reset the tags this
        // suite uses so a prior test's entry can never be served against this
        // fresh in-memory DB.
        (new CacheBookModel($this->db))->clearCache();
        (new CacheAuthorModel($this->db))->clearCache();
    }

    protected function tearDown(): void
    {
        (new CacheBookModel($this->db))->clearCache();
        (new CacheAuthorModel($this->db))->clearCache();
        $this->db->close();
    }

    private function newBook(string $title, int $authorId): CacheBookModel
    {
        $book = new CacheBookModel($this->db);
        $book->title = $title;
        $book->author_id = $authorId;
        $book->save();
        return $book;
    }

    private function newAuthor(string $name): CacheAuthorModel
    {
        $author = new CacheAuthorModel($this->db);
        $author->name = $name;
        $author->save();
        return $author;
    }

    // ── caches within ttl; ttl=0 = no-cache ─────────────────────────────────

    public function testACachedQueryIsServedFromCacheWithinTtl(): void
    {
        $this->newBook('A', 0);

        $first = (new CacheBookModel($this->db))->cached(self::BOOK_SQL, [], 60);
        $this->assertSame('A', $first[0]->title);

        // Direct db write behind the cache's back (NOT an ORM write -> no bust).
        $this->db->exec("UPDATE cachebook SET title = 'CHANGED' WHERE id = 1");

        $second = (new CacheBookModel($this->db))->cached(self::BOOK_SQL, [], 60);
        // Stale on purpose: the only way this is 'A' is that it came from cache.
        $this->assertSame('A', $second[0]->title);
    }

    public function testTtlZeroDoesNotCache(): void
    {
        $this->newBook('A', 0);

        $first = (new CacheBookModel($this->db))->cached(self::BOOK_SQL, [], 0);
        $this->assertSame('A', $first[0]->title);

        $this->db->exec("UPDATE cachebook SET title = 'CHANGED' WHERE id = 1");

        $second = (new CacheBookModel($this->db))->cached(self::BOOK_SQL, [], 0);
        // ttl<=0 stores nothing, so this read hit the DB and sees the change.
        $this->assertSame('CHANGED', $second[0]->title);
    }

    // ── busts on every ORM write ────────────────────────────────────────────

    public function testASaveThroughTheOrmBustsTheCachedRead(): void
    {
        $this->newBook('A', 0);

        $this->assertSame('A', (new CacheBookModel($this->db))->cached(self::BOOK_SQL, [], 60)[0]->title);

        $book = (new CacheBookModel($this->db))->selectOne('SELECT * FROM cachebook WHERE id = 1');
        $book->title = 'B';
        $book->save();

        $this->assertSame('B', (new CacheBookModel($this->db))->cached(self::BOOK_SQL, [], 60)[0]->title);
    }

    public function testADeleteThroughTheOrmBustsTheCachedRead(): void
    {
        $this->newBook('A', 0);
        $this->newBook('B', 0);

        $this->assertCount(2, (new CacheBookModel($this->db))->cached(self::BOOK_SQL, [], 60));

        (new CacheBookModel($this->db))->selectOne('SELECT * FROM cachebook WHERE id = 1')->delete();

        $this->assertCount(1, (new CacheBookModel($this->db))->cached(self::BOOK_SQL, [], 60));
    }

    public function testAForceDeleteThroughTheOrmBustsTheCachedRead(): void
    {
        $this->newBook('A', 0);
        $this->newBook('B', 0);

        $this->assertCount(2, (new CacheBookModel($this->db))->cached(self::ALL_BOOK_SQL, [], 60));

        (new CacheBookModel($this->db))->selectOne('SELECT * FROM cachebook WHERE id = 1')->forceDelete();

        $this->assertCount(1, (new CacheBookModel($this->db))->cached(self::ALL_BOOK_SQL, [], 60));
    }

    public function testARestoreThroughTheOrmBustsTheCachedRead(): void
    {
        $this->newBook('A', 0);
        $book = (new CacheBookModel($this->db))->selectOne('SELECT * FROM cachebook WHERE id = 1');
        $book->delete(); // soft-delete -> is_deleted = 1

        // Cached view of ACTIVE rows is empty...
        $this->assertCount(0, (new CacheBookModel($this->db))->cached(self::BOOK_SQL, [], 60));

        $book->restore(); // ORM write -> must bust

        // ...and the restore is seen, not the stale empty result.
        $restored = (new CacheBookModel($this->db))->cached(self::BOOK_SQL, [], 60);
        $this->assertCount(1, $restored);
        $this->assertSame('A', $restored[0]->title);
    }

    // ── tagged by table (cross-table bust; unrelated left intact) ────────────

    public function testAWriteToAJoinedTableBustsTheCrossTableCachedRead(): void
    {
        $this->newAuthor('A1');
        $this->newBook('A', 1);

        $first = (new CacheBookModel($this->db))->cached(self::JOIN_SQL, ['A1'], 60);
        $this->assertCount(1, $first);

        $author = (new CacheAuthorModel($this->db))->selectOne('SELECT * FROM cacheauthor WHERE id = 1');
        $author->name = 'A2';
        $author->save(); // writes cacheauthor -> must bust the cross-table cached JOIN

        $second = (new CacheBookModel($this->db))->cached(self::JOIN_SQL, ['A1'], 60);
        $this->assertCount(0, $second);
    }

    public function testAWriteToAnUnrelatedTableLeavesTheCachedReadIntact(): void
    {
        $this->newAuthor('A1');
        $this->newBook('A', 1);

        $this->assertSame('A', (new CacheBookModel($this->db))->cached(self::BOOK_SQL, [], 60)[0]->title);

        // Change the book row directly (no ORM bust), then write an UNRELATED table.
        $this->db->exec("UPDATE cachebook SET title = 'RAW' WHERE id = 1");
        $author = (new CacheAuthorModel($this->db))->selectOne('SELECT * FROM cacheauthor WHERE id = 1');
        $author->name = 'A2';
        $author->save(); // writes cacheauthor only -> must NOT bust the cachebook query

        // The cachebook entry survived (tag-scoped bust, not a wholesale flush).
        $this->assertSame('A', (new CacheBookModel($this->db))->cached(self::BOOK_SQL, [], 60)[0]->title);
    }
}
