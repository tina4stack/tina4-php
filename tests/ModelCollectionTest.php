<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * ModelCollection — ORM read queries carry the query total (ADR-0064).
 *
 * Real SQLite, real ORM writes, no mocks. This mirrors the Python reference
 * (tests/test_orm_model_collection.py) case-for-case, PLUS the PHP-specific
 * array-compatibility the object-return demands: where/select/find(filter)/all/
 * withTrashed return an array-compatible ModelCollection that also exposes
 * getTotalRecords() and the same seven-key toPaginate() envelope as
 * DatabaseResult.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\DatabaseResult;
use Tina4\Database\SQLite3Adapter;
use Tina4\ModelCollection;
use Tina4\ORM;

/**
 * Product model for the collection tests — no soft delete.
 */
class CollProduct extends ORM
{
    public string $tableName = 'coll_product';
    public string $primaryKey = 'id';
}

/**
 * Note model with soft delete enabled.
 */
class CollNote extends ORM
{
    public string $tableName = 'coll_note';
    public string $primaryKey = 'id';
    public bool $softDelete = true;
}

class ModelCollectionTest extends TestCase
{
    private SQLite3Adapter $db;

    protected function setUp(): void
    {
        $this->db = new SQLite3Adapter(':memory:');
        $this->db->exec(
            'CREATE TABLE coll_product (id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'name TEXT NOT NULL, category TEXT, price NUMERIC)'
        );
        $this->db->exec(
            'CREATE TABLE coll_note (id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'body TEXT, is_deleted INTEGER DEFAULT 0)'
        );
        ORM::bindDatabase($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    /**
     * Seed real rows through the ORM write path.
     */
    private function seed(int $books = 250, int $music = 7): void
    {
        for ($i = 0; $i < $books; $i++) {
            (new CollProduct($this->db, ['name' => "book{$i}", 'category' => 'books', 'price' => $i]))->save();
        }
        for ($i = 0; $i < $music; $i++) {
            (new CollProduct($this->db, ['name' => "song{$i}", 'category' => 'music', 'price' => $i]))->save();
        }
    }

    // ── the core promise: page is capped, total is the whole filtered set ──

    public function testWhereTotalIsOutsidePagination(): void
    {
        $this->seed();
        $rows = (new CollProduct($this->db))->where('category = ?', ['books'], 20, 40);

        $this->assertInstanceOf(ModelCollection::class, $rows);
        $this->assertCount(20, $rows, 'the page is capped at the limit');
        $this->assertSame(250, $rows->getTotalRecords(), 'the total is the whole matching set');
    }

    public function testAllCarriesTableTotal(): void
    {
        $this->seed();
        $rows = (new CollProduct($this->db))->all(10);

        $this->assertInstanceOf(ModelCollection::class, $rows);
        $this->assertCount(10, $rows);
        $this->assertSame(257, $rows->getTotalRecords(), '250 books + 7 music');
    }

    public function testSelectCarriesTotal(): void
    {
        $this->seed();
        $rows = (new CollProduct($this->db))->select(
            'SELECT * FROM coll_product WHERE category = ?',
            ['music'],
            5
        );

        $this->assertInstanceOf(ModelCollection::class, $rows);
        $this->assertCount(5, $rows);
        $this->assertSame(7, $rows->getTotalRecords());
    }

    public function testFindFilterFormCarriesTotal(): void
    {
        $this->seed();
        $rows = CollProduct::find(['category' => 'books'], 10);

        $this->assertInstanceOf(ModelCollection::class, $rows);
        $this->assertCount(10, $rows);
        $this->assertSame(250, $rows->getTotalRecords());
    }

    public function testFindPkFormStillReturnsSingle(): void
    {
        $this->seed();
        $one = CollProduct::find(1);

        $this->assertNotNull($one);
        $this->assertNotInstanceOf(ModelCollection::class, $one, 'a PK lookup is a single model');
        $this->assertInstanceOf(CollProduct::class, $one);
        $this->assertEquals(1, $one->id);
    }

    // ── every returning method carries the total ───────────────────────────

    public function testEveryReturningMethodCarriesTheTotal(): void
    {
        $this->seed();
        $product = new CollProduct($this->db);

        $this->assertSame(250, $product->where('category = ?', ['books'], 20)->getTotalRecords());
        $this->assertSame(
            7,
            $product->select('SELECT * FROM coll_product WHERE category = ?', ['music'], 5)->getTotalRecords()
        );
        $this->assertSame(250, CollProduct::find(['category' => 'books'], 10)->getTotalRecords());
        $this->assertSame(257, $product->all(10)->getTotalRecords());
        $this->assertSame(257, $product->withTrashed('1=1', [], 10)->getTotalRecords());
    }

    // ── toPaginate() — the uniform seven-key envelope ──────────────────────

    public function testToPaginateEnvelopeMatchesDatabaseResult(): void
    {
        $this->seed();
        $rows = (new CollProduct($this->db))->where('category = ?', ['books'], 20, 40);
        $page = $rows->toPaginate();

        $this->assertSame(
            ['records', 'total', 'page', 'per_page', 'total_pages', 'limit', 'offset'],
            array_keys($page),
            'exactly the seven keys, in the canonical order'
        );
        $this->assertSame(250, $page['total']);
        $this->assertSame(20, $page['per_page']);
        $this->assertSame(3, $page['page'], 'offset 40 / 20 + 1');
        $this->assertSame(13, $page['total_pages'], 'ceil(250 / 20)');
        $this->assertSame(20, $page['limit']);
        $this->assertSame(40, $page['offset']);
        $this->assertCount(20, $page['records']);

        // records are assoc arrays (dicts), identical shape to db.fetch(...).toPaginate()
        $rawArr = $this->db->fetch('SELECT * FROM coll_product WHERE category = ?', ['books'], 20, 40);
        $raw = (new DatabaseResult(
            records: $rawArr['data'],
            count: (int) $rawArr['total'],
            limit: 20,
            offset: 40,
        ))->toPaginate();

        $this->assertSame($raw['total'], $page['total']);
        $this->assertSame($raw['total_pages'], $page['total_pages']);
        $this->assertSame($raw['page'], $page['page']);
        $this->assertSame($raw['per_page'], $page['per_page']);
        $this->assertSame($raw['offset'], $page['offset']);
        $this->assertIsArray($page['records'][0]);

        $pageKeys = array_keys($page['records'][0]);
        $rawKeys = array_keys($raw['records'][0]);
        sort($pageKeys);
        sort($rawKeys);
        $this->assertSame($rawKeys, $pageKeys, 'record dict has the same columns as the raw fetch');
    }

    // ── edge cases ─────────────────────────────────────────────────────────

    public function testEmptyPageStillReportsTotal(): void
    {
        $this->seed();
        // Offset past the end: no rows on this page, but the total still stands.
        $rows = (new CollProduct($this->db))->where('category = ?', ['books'], 20, 1000);

        $this->assertCount(0, $rows);
        $this->assertSame(250, $rows->getTotalRecords());
        $this->assertSame(250, $rows->toPaginate()['total']);
    }

    public function testZeroMatchesTotalIsZero(): void
    {
        $this->seed();
        $rows = (new CollProduct($this->db))->where('category = ?', ['nothing']);

        $this->assertCount(0, $rows);
        $this->assertSame([], $rows->toArray());
        $this->assertSame(0, $rows->getTotalRecords());
    }

    public function testSoftDeleteExcludedFromLiveTotalIncludedInTrashed(): void
    {
        for ($i = 0; $i < 5; $i++) {
            (new CollNote($this->db, ['body' => "n{$i}"]))->save();
        }
        CollNote::find(1)->delete();   // soft-delete one

        $live = (new CollNote($this->db))->where('1=1');
        $this->assertSame(4, $live->getTotalRecords(), 'soft-deleted row excluded from the live total');

        $trashed = (new CollNote($this->db))->withTrashed('1=1');
        $this->assertSame(5, $trashed->getTotalRecords(), 'soft-deleted row included by withTrashed');
    }

    // ── PHP array-compatibility (nothing existing breaks) ──────────────────

    public function testArrayCompatibility(): void
    {
        $this->seed(3, 0);
        $rows = (new CollProduct($this->db))->where('category = ?', ['books']);

        // count()
        $this->assertCount(3, $rows);
        $this->assertSame(3, count($rows));

        // index -> model instance
        $this->assertInstanceOf(CollProduct::class, $rows[0]);
        $this->assertSame('books', $rows[0]->category);

        // foreach -> model instances
        $seen = 0;
        foreach ($rows as $r) {
            $this->assertInstanceOf(CollProduct::class, $r);
            $this->assertSame('books', $r->category);
            $seen++;
        }
        $this->assertSame(3, $seen);

        // json_encode -> the array of model dicts
        $decoded = json_decode(json_encode($rows), true);
        $this->assertIsArray($decoded);
        $this->assertCount(3, $decoded);
        $this->assertSame('books', $decoded[0]['category']);
        $this->assertArrayHasKey('id', $decoded[0]);

        // toArray() -> a bare array usable by native array_map
        $bare = $rows->toArray();
        $this->assertIsArray($bare);
        $names = array_map(static fn ($m) => $m->name, $bare);
        $this->assertSame(['book0', 'book1', 'book2'], $names);
    }

    public function testResponseAutoSerialisesCollectionToJsonArray(): void
    {
        // The documented `return $response(Model::all())` path must still yield a
        // JSON array of dicts now that all() returns a ModelCollection object.
        $this->seed(4, 0);
        $response = new \Tina4\Response(true);
        $response->json((new CollProduct($this->db))->all(4));

        $decoded = json_decode($response->getBody(), true);
        $this->assertIsArray($decoded);
        $this->assertCount(4, $decoded);
        $this->assertArrayHasKey('name', $decoded[0]);
        $this->assertArrayHasKey('category', $decoded[0]);
    }

    public function testWithTrashedIsAModelCollection(): void
    {
        $this->seed(3, 0);
        $rows = (new CollProduct($this->db))->withTrashed('1=1');
        $this->assertInstanceOf(ModelCollection::class, $rows);
        $this->assertCount(3, $rows);
        $this->assertSame(3, $rows->getTotalRecords());
    }
}
