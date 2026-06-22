<?php

/**
 * Tina4 - The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * DocStore - pymongo-style SQLite document store (JSON1 fallback).
 *
 * Locks the everyday CRUD + filter subset, the built-in ObjectId, value
 * round-trip (ObjectId/DateTime), cursor semantics, and the serverless
 * selection (SQLite when no Mongo configured). Mirrors the Python master
 * spec tina4-python/tests/test_docstore.py.
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Cursor;
use Tina4\DocStoreCodec;
use Tina4\InvalidId;
use Tina4\ObjectId;
use Tina4\SqliteCollection;
use Tina4\SqliteDatabase;

use function Tina4\docStoreMongoUri;
use function Tina4\getCollection;
use function Tina4\isServerless;
use function Tina4\resetDefaultStore;

class DocStoreTest extends TestCase
{
    private SqliteDatabase $db;
    private SqliteCollection $orders;

    protected function setUp(): void
    {
        $this->db = new SqliteDatabase(':memory:');
        $this->orders = $this->db->getCollection('orders');
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    // -- ObjectId --------------------------------------------------------

    public function testObjectIdGeneratesUnique24Hex(): void
    {
        $a = new ObjectId();
        $b = new ObjectId();
        $this->assertFalse($a->equals($b));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}$/', (string)$a);
    }

    public function testObjectIdRoundtripFromHex(): void
    {
        $a = new ObjectId();
        $this->assertTrue((new ObjectId((string)$a))->equals($a));
        $this->assertSame((string)$a, (string)(new ObjectId((string)$a)));
    }

    public function testObjectIdIsValid(): void
    {
        $this->assertTrue(ObjectId::isValid((string)(new ObjectId())));
        $this->assertFalse(ObjectId::isValid('nope'));
        $this->assertFalse(ObjectId::isValid(str_repeat('zz', 12)));
    }

    public function testObjectIdInvalidRaises(): void
    {
        $this->expectException(InvalidId::class);
        new ObjectId('too-short');
    }

    public function testObjectIdGenerationTimeIsUtcDateTime(): void
    {
        $gt = (new ObjectId())->generationTime();
        $this->assertInstanceOf(\DateTimeInterface::class, $gt);
        $this->assertSame('UTC', $gt->getTimezone()->getName());
    }

    // -- insert + find ----------------------------------------------------

    public function testInsertOneReturnsObjectId(): void
    {
        $res = $this->orders->insertOne(['customer_id' => 1, 'total' => 9.99]);
        $this->assertInstanceOf(ObjectId::class, $res->insertedId);
        $got = $this->orders->findOne(['_id' => $res->insertedId]);
        $this->assertSame(1, $got['customer_id']);
        $this->assertSame(9.99, $got['total']);
        // _id rehydrated to ObjectId
        $this->assertInstanceOf(ObjectId::class, $got['_id']);
        $this->assertTrue($got['_id']->equals($res->insertedId));
    }

    public function testInsertMany(): void
    {
        $docs = [];
        for ($i = 0; $i < 5; $i++) {
            $docs[] = ['n' => $i];
        }
        $ids = $this->orders->insertMany($docs)->insertedIds;
        $this->assertCount(5, $ids);
        $this->assertSame(5, $this->orders->countDocuments([]));
    }

    public function testCustomIdPreserved(): void
    {
        $this->orders->insertOne(['_id' => 'ORD-1', 'x' => 1]);
        $this->assertSame(1, $this->orders->findOne(['_id' => 'ORD-1'])['x']);
    }

    // -- filter operators (pushed to SQL via json_extract) -----------------

    private function seedFilters(): void
    {
        $this->orders->insertMany([
            ['customer_id' => 1, 'status' => 'new', 'total' => 10, 'tags' => ['a']],
            ['customer_id' => 2, 'status' => 'shipped', 'total' => 50],
            ['customer_id' => 3, 'status' => 'shipped', 'total' => 99, 'vip' => true],
        ]);
    }

    public function testEquality(): void
    {
        $this->seedFilters();
        $this->assertSame(2, $this->orders->countDocuments(['status' => 'shipped']));
    }

    public function testIn(): void
    {
        $this->seedFilters();
        $got = $this->orders->find(['customer_id' => ['$in' => [1, 3]]])->toList();
        $ids = array_map(fn ($d) => $d['customer_id'], $got);
        sort($ids);
        $this->assertSame([1, 3], $ids);
    }

    public function testComparisons(): void
    {
        $this->seedFilters();
        $this->assertSame(2, $this->orders->countDocuments(['total' => ['$gt' => 10]]));
        $this->assertSame(2, $this->orders->countDocuments(['total' => ['$gte' => 10, '$lt' => 99]]));
        $this->assertSame(1, $this->orders->countDocuments(['total' => ['$lte' => 10]]));
    }

    public function testNe(): void
    {
        $this->seedFilters();
        $this->assertSame(1, $this->orders->countDocuments(['status' => ['$ne' => 'shipped']]));
    }

    public function testExists(): void
    {
        $this->seedFilters();
        $this->assertSame(1, $this->orders->countDocuments(['vip' => ['$exists' => true]]));
        $this->assertSame(2, $this->orders->countDocuments(['vip' => ['$exists' => false]]));
    }

    public function testRegex(): void
    {
        $this->seedFilters();
        $this->assertSame(2, $this->orders->countDocuments(['status' => ['$regex' => '^ship']]));
    }

    public function testOr(): void
    {
        $this->seedFilters();
        $got = $this->orders->find(['$or' => [
            ['customer_id' => 1],
            ['total' => ['$gt' => 90]],
        ]])->toList();
        $ids = array_map(fn ($d) => $d['customer_id'], $got);
        sort($ids);
        $this->assertSame([1, 3], $ids);
    }

    public function testPushdownUsesJsonExtract(): void
    {
        [$where, $params] = DocStoreCodec::compileFilter(['customer_id' => ['$in' => [1, 2]]]);
        $this->assertStringContainsString('json_extract(doc', $where);
        $this->assertStringContainsString('IN (?,?)', $where);
        $this->assertSame([1, 2], $params);
    }

    // -- cursor: sort / limit / skip / projection --------------------------

    private function seedCursor(): void
    {
        $docs = [];
        for ($i = 0; $i < 10; $i++) {
            $docs[] = ['n' => $i, 'grp' => 'g'];
        }
        $this->orders->insertMany($docs);
    }

    public function testSortDesc(): void
    {
        $this->seedCursor();
        $got = $this->orders->find(['grp' => 'g'])->sort('n', -1)->toList();
        $ns = array_map(fn ($d) => $d['n'], $got);
        $this->assertSame(range(9, 0), $ns);
    }

    public function testLimitSkip(): void
    {
        $this->seedCursor();
        $got = $this->orders->find(['grp' => 'g'])->sort('n', 1)->skip(2)->limit(3)->toList();
        $this->assertSame([2, 3, 4], array_map(fn ($d) => $d['n'], $got));
    }

    public function testProjectionInclude(): void
    {
        $this->seedCursor();
        $d = $this->orders->findOne(['n' => 5], ['n' => 1, '_id' => 0]);
        $this->assertSame(['n' => 5], $d);
    }

    public function testProjectionExclude(): void
    {
        $this->seedCursor();
        $d = $this->orders->findOne(['n' => 5], ['grp' => 0]);
        $this->assertArrayNotHasKey('grp', $d);
        $this->assertSame(5, $d['n']);
        $this->assertArrayHasKey('_id', $d);
    }

    // -- datetime round-trip + sortability ---------------------------------

    public function testDatetimeRoundtripAndSort(): void
    {
        $utc = new \DateTimeZone('UTC');
        $base = new \DateTimeImmutable('2024-01-01T00:00:00', $utc);
        $this->orders->insertMany([
            ['k' => 'b', 'created_at' => new \DateTimeImmutable('2024-03-01T00:00:00', $utc)],
            ['k' => 'a', 'created_at' => new \DateTimeImmutable('2024-01-01T00:00:00', $utc)],
            ['k' => 'c', 'created_at' => new \DateTimeImmutable('2024-06-01T00:00:00', $utc)],
        ]);
        $got = $this->orders->find([])->sort('created_at', -1)->toList();
        $this->assertSame(['c', 'b', 'a'], array_map(fn ($d) => $d['k'], $got));
        $this->assertInstanceOf(\DateTimeInterface::class, $got[0]['created_at']);
        $this->assertSame(3, $this->orders->countDocuments(['created_at' => ['$gte' => $base]]));
    }

    // -- updates ------------------------------------------------------------

    public function testSetIncUnset(): void
    {
        $oid = $this->orders->insertOne(['status' => 'new', 'count' => 1, 'tmp' => 'x'])->insertedId;
        $this->orders->updateOne(['_id' => $oid], ['$set' => ['status' => 'shipped']]);
        $this->orders->updateOne(['_id' => $oid], ['$inc' => ['count' => 5]]);
        $this->orders->updateOne(['_id' => $oid], ['$unset' => ['tmp' => '']]);
        $d = $this->orders->findOne(['_id' => $oid]);
        $this->assertSame('shipped', $d['status']);
        $this->assertSame(6, $d['count']);
        $this->assertArrayNotHasKey('tmp', $d);
    }

    public function testUpdateMany(): void
    {
        $this->orders->insertMany([['s' => 'a'], ['s' => 'a'], ['s' => 'b']]);
        $res = $this->orders->updateMany(['s' => 'a'], ['$set' => ['s' => 'z']]);
        $this->assertSame(2, $res->matchedCount);
        $this->assertSame(2, $res->modifiedCount);
        $this->assertSame(2, $this->orders->countDocuments(['s' => 'z']));
    }

    public function testReplaceOneKeepsId(): void
    {
        $oid = $this->orders->insertOne(['a' => 1])->insertedId;
        $this->orders->replaceOne(['_id' => $oid], ['b' => 2]);
        $d = $this->orders->findOne(['_id' => $oid]);
        $this->assertSame(2, $d['b']);
        $this->assertArrayNotHasKey('a', $d);
        $this->assertTrue($d['_id']->equals($oid));
    }

    public function testUpsert(): void
    {
        $res = $this->orders->updateOne(['sku' => 'X1'], ['$set' => ['qty' => 3]], upsert: true);
        $this->assertNotNull($res->upsertedId);
        $this->assertSame(3, $this->orders->findOne(['sku' => 'X1'])['qty']);
    }

    public function testNestedSet(): void
    {
        $oid = $this->orders->insertOne(['a' => ['b' => 1]])->insertedId;
        $this->orders->updateOne(['_id' => $oid], ['$set' => ['a.c' => 2]]);
        $d = $this->orders->findOne(['_id' => $oid]);
        $this->assertSame(['b' => 1, 'c' => 2], $d['a']);
    }

    // -- deletes + nested filter -------------------------------------------

    public function testDeleteOneAndMany(): void
    {
        $this->orders->insertMany([['s' => 'a'], ['s' => 'a'], ['s' => 'b']]);
        $this->assertSame(1, $this->orders->deleteOne(['s' => 'a'])->deletedCount);
        $this->assertSame(1, $this->orders->deleteMany(['s' => 'a'])->deletedCount);
        $this->assertSame(1, $this->orders->countDocuments([]));
    }

    public function testNestedDottedFilter(): void
    {
        $this->orders->insertOne(['addr' => ['city' => 'Cape Town']]);
        $this->orders->insertOne(['addr' => ['city' => 'Joburg']]);
        $this->assertSame(1, $this->orders->countDocuments(['addr.city' => 'Cape Town']));
    }

    // -- db attribute + item-style access ----------------------------------

    public function testDbCollectionReuse(): void
    {
        $this->db->getCollection('a')->insertOne(['x' => 1]);
        $this->assertSame(1, $this->db->getCollection('a')->countDocuments([]));
        // same backing collection instance is returned
        $this->assertSame($this->db->getCollection('a'), $this->db->getCollection('a'));
    }

    // -- serverless selection ----------------------------------------------

    public function testServerlessWhenNoMongo(): void
    {
        $hadUri = getenv('TINA4_MONGO_URI');
        $hadUrl = getenv('TINA4_SESSION_MONGO_URL');
        putenv('TINA4_MONGO_URI');
        putenv('TINA4_SESSION_MONGO_URL');
        try {
            $this->assertTrue(isServerless());
        } finally {
            if ($hadUri !== false) {
                putenv('TINA4_MONGO_URI=' . $hadUri);
            }
            if ($hadUrl !== false) {
                putenv('TINA4_SESSION_MONGO_URL=' . $hadUrl);
            }
        }
    }

    public function testGetCollectionIsSqliteWhenServerless(): void
    {
        $hadUri = getenv('TINA4_MONGO_URI');
        $hadUrl = getenv('TINA4_SESSION_MONGO_URL');
        $hadPath = getenv('TINA4_DOC_STORE_PATH');
        $tmp = sys_get_temp_dir() . '/tina4_ds_' . uniqid('', true) . '.db';
        putenv('TINA4_MONGO_URI');
        putenv('TINA4_SESSION_MONGO_URL');
        putenv('TINA4_DOC_STORE_PATH=' . $tmp);
        resetDefaultStore();
        try {
            $col = getCollection('widgets');
            $this->assertInstanceOf(SqliteCollection::class, $col);
            $col->insertOne(['name' => 'bolt']);
            $this->assertSame('bolt', $col->findOne(['name' => 'bolt'])['name']);
        } finally {
            resetDefaultStore();
            if ($hadUri !== false) {
                putenv('TINA4_MONGO_URI=' . $hadUri);
            }
            if ($hadUrl !== false) {
                putenv('TINA4_SESSION_MONGO_URL=' . $hadUrl);
            }
            if ($hadPath !== false) {
                putenv('TINA4_DOC_STORE_PATH=' . $hadPath);
            } else {
                putenv('TINA4_DOC_STORE_PATH');
            }
            @unlink($tmp);
        }
    }

    // Parity fix: canonical TINA4_SESSION_MONGO_URI, with TINA4_SESSION_MONGO_URL
    // accepted as a legacy alias (PHP/Python historically used _URL).
    public function testSessionMongoUriAndUrlBothResolve(): void
    {
        $hadAppUri = getenv('TINA4_MONGO_URI');
        $hadUri = getenv('TINA4_SESSION_MONGO_URI');
        $hadUrl = getenv('TINA4_SESSION_MONGO_URL');
        putenv('TINA4_MONGO_URI');
        putenv('TINA4_SESSION_MONGO_URI');
        putenv('TINA4_SESSION_MONGO_URL');
        try {
            putenv('TINA4_SESSION_MONGO_URI=mongodb://uri-host/db');
            $this->assertSame('mongodb://uri-host/db', docStoreMongoUri());

            putenv('TINA4_SESSION_MONGO_URI');
            putenv('TINA4_SESSION_MONGO_URL=mongodb://url-host/db');
            $this->assertSame('mongodb://url-host/db', docStoreMongoUri()); // legacy alias

            putenv('TINA4_SESSION_MONGO_URI=mongodb://uri-host/db');
            $this->assertSame('mongodb://uri-host/db', docStoreMongoUri()); // canonical wins

            putenv('TINA4_MONGO_URI=mongodb://app-host/db');
            $this->assertSame('mongodb://app-host/db', docStoreMongoUri()); // app-wide wins
        } finally {
            putenv($hadAppUri !== false ? 'TINA4_MONGO_URI=' . $hadAppUri : 'TINA4_MONGO_URI');
            putenv($hadUri !== false ? 'TINA4_SESSION_MONGO_URI=' . $hadUri : 'TINA4_SESSION_MONGO_URI');
            putenv($hadUrl !== false ? 'TINA4_SESSION_MONGO_URL=' . $hadUrl : 'TINA4_SESSION_MONGO_URL');
        }
    }
}
