<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\SQLite3Adapter;
use Tina4\ORM;

/**
 * JSON-document column model. An `array`-typed property is the idiomatic PHP
 * equivalent of Python's JSONField: the dict/list is json_encode()d on write
 * and json_decode()d back on read. `?array` columns accept SQL NULL.
 */
class JsonDoc extends ORM
{
    public string $tableName = 'json_doc';
    public string $primaryKey = 'id';
    public array $fieldMapping = [];
    public int $id = 0;
    public string $name = '';
    public ?array $payload = null;
    public ?array $tags = null;
}

/**
 * Model with a non-nullable array default — locks in that PHP's value-type
 * array semantics never alias one default across instances (Python needs an
 * explicit deepcopy for the same guarantee).
 */
class JsonDefaultDoc extends ORM
{
    public string $tableName = 'json_default_doc';
    public string $primaryKey = 'id';
    public array $fieldMapping = [];
    public int $id = 0;
    public array $meta = [];
}

/**
 * NO MOCKS: every test runs against a REAL in-memory SQLite database,
 * exercising the full path createTable -> save (serialize) -> fetch ->
 * fill (parse). The engine-aware DDL type and native-JSON read normalisation
 * (PostgreSQL JSONB / MySQL JSON) are additionally covered by the gated
 * cross-engine suite in CI; the string-backed path is fully exercised here.
 */
class OrmJsonFieldTest extends TestCase
{
    private SQLite3Adapter $db;

    protected function setUp(): void
    {
        $this->db = new SQLite3Adapter(':memory:');
        \Tina4\ORM::bindDatabase($this->db);
        (new JsonDoc())->createTable();
        (new JsonDefaultDoc())->createTable();
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    // --- Round trip ---

    public function testDictRoundTripsAsArray(): void
    {
        $doc = new JsonDoc();
        $doc->name = 'click';
        $doc->payload = ['x' => 1, 'nested' => ['a' => [1, 2, 3]]];
        $this->assertSame($doc, $doc->save());

        $got = (new JsonDoc())->selectOne('SELECT * FROM json_doc WHERE id = ?', [$doc->id]);
        $this->assertIsArray($got->payload);                 // not a JSON string
        $this->assertSame(['x' => 1, 'nested' => ['a' => [1, 2, 3]]], $got->payload);
        $this->assertSame([1, 2, 3], $got->payload['nested']['a']);
    }

    public function testListRoundTripsAsList(): void
    {
        $doc = new JsonDoc();
        $doc->name = 'tagged';
        $doc->tags = ['a', 'b', 'c'];
        $doc->save();

        $got = (new JsonDoc())->selectOne('SELECT * FROM json_doc WHERE id = ?', [$doc->id]);
        $this->assertSame(['a', 'b', 'c'], $got->tags);
    }

    public function testNullRoundTripsAsNull(): void
    {
        $doc = new JsonDoc();
        $doc->name = 'empty';
        $doc->save();

        $got = (new JsonDoc())->selectOne('SELECT * FROM json_doc WHERE id = ?', [$doc->id]);
        $this->assertNull($got->payload);
        $this->assertNull($got->tags);
    }

    public function testUpdateReplacesJson(): void
    {
        $doc = new JsonDoc();
        $doc->name = 'v';
        $doc->payload = ['v' => 1];
        $doc->save();

        $doc->payload = ['v' => 2, 'changed' => true];
        $doc->save();

        $got = (new JsonDoc())->selectOne('SELECT * FROM json_doc WHERE id = ?', [$doc->id]);
        $this->assertSame(['v' => 2, 'changed' => true], $got->payload);
    }

    public function testUnicodeAndSpecialCharsSurvive(): void
    {
        $body = ['emoji' => 'cafe ☕', 'quote' => 'he said "hi"', 'slash' => 'a/b\\c'];
        $doc = new JsonDoc();
        $doc->name = 'u';
        $doc->payload = $body;
        $doc->save();

        $got = (new JsonDoc())->selectOne('SELECT * FROM json_doc WHERE id = ?', [$doc->id]);
        $this->assertSame($body, $got->payload);
    }

    // --- Engine-aware DDL ---

    public function testSqliteMapsToText(): void
    {
        $result = $this->db->fetch(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='json_doc'"
        );
        $ddl = strtoupper($result['data'][0]['sql']);
        // Two JSON columns, both TEXT on SQLite (no native JSON type).
        $this->assertStringContainsString('PAYLOAD TEXT', $ddl);
        $this->assertStringContainsString('TAGS TEXT', $ddl);
    }

    // --- Validation / hydration ---

    public function testJsonStringIsParsedOnRead(): void
    {
        // A raw JSON string in the column (as a text-backed engine returns) is
        // decoded to a PHP array by fill() on hydration.
        $this->db->exec(
            'INSERT INTO json_doc (name, payload) VALUES (?, ?)',
            ['raw', '{"from": "string"}']
        );
        $got = (new JsonDoc())->selectOne('SELECT * FROM json_doc WHERE name = ?', ['raw']);
        $this->assertSame(['from' => 'string'], $got->payload);
    }

    public function testNonSerializableValueFailsLoud(): void
    {
        // NAN is not JSON-serialisable (Inf/NaN cannot be encoded) -> save()
        // fails loud (returns false), rolls back, and records the cause.
        // Nothing is persisted.
        $doc = new JsonDoc();
        $doc->name = 'bad';
        $doc->payload = ['n' => NAN];
        $this->assertFalse($doc->save());
        $this->assertNotNull($doc->getError());
        $this->assertNull((new JsonDoc())->selectOne('SELECT * FROM json_doc WHERE name = ?', ['bad']));
    }

    public function testScalarRejectedByType(): void
    {
        // PHP's type system enforces the JSON contract at the boundary: a scalar
        // cannot be assigned to an `array`-typed column (stronger than Python's
        // runtime validate() — the language rejects it before save()).
        $doc = new JsonDoc();
        $this->expectException(\TypeError::class);
        $doc->payload = 42;
    }

    // --- Default isolation ---

    public function testMutableDefaultNotSharedBetweenInstances(): void
    {
        $a = new JsonDefaultDoc();
        $b = new JsonDefaultDoc();
        $a->meta['only_a'] = true;
        $this->assertSame([], $b->meta, 'instances must not alias the same default array');
    }
}
