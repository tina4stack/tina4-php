<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Database\DatabaseResult;

class DatabaseResultTest extends TestCase
{
    // -- Constructor & properties ------------------------------------------------

    public function testConstructorDefaults(): void
    {
        $result = new DatabaseResult();

        $this->assertSame([], $result->records);
        $this->assertSame([], $result->columns);
        $this->assertSame(0, $result->count);
        $this->assertSame(100, $result->limit);
        $this->assertSame(0, $result->offset);
    }

    public function testConstructorWithValues(): void
    {
        $records = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ];
        $columns = ['id', 'name'];

        $result = new DatabaseResult(
            records: $records,
            columns: $columns,
            count: 50,
            limit: 10,
            offset: 20,
        );

        $this->assertSame($records, $result->records);
        $this->assertSame($columns, $result->columns);
        $this->assertSame(50, $result->count);
        $this->assertSame(10, $result->limit);
        $this->assertSame(20, $result->offset);
    }

    // -- toJson() ---------------------------------------------------------------

    public function testToJsonReturnsValidJson(): void
    {
        $records = [['id' => 1, 'name' => 'Alice']];
        $result = new DatabaseResult(records: $records);

        $json = $result->toJson();

        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertCount(1, $decoded);
        $this->assertSame(1, $decoded[0]['id']);
        $this->assertSame('Alice', $decoded[0]['name']);
    }

    public function testToJsonEmptyRecords(): void
    {
        $result = new DatabaseResult();

        $json = $result->toJson();
        $decoded = json_decode($json, true);

        $this->assertSame([], $decoded);
    }

    // -- toCsv() ----------------------------------------------------------------

    public function testToCsvReturnsHeaderRow(): void
    {
        $records = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ];
        $result = new DatabaseResult(records: $records, columns: ['id', 'name']);

        $csv = $result->toCsv();
        $lines = array_filter(explode("\n", trim($csv)));

        $this->assertCount(3, $lines); // header + 2 rows
        $this->assertStringContainsString('id', $lines[0]);
        $this->assertStringContainsString('name', $lines[0]);
    }

    public function testToCsvDerivesHeadersFromRecords(): void
    {
        $records = [['foo' => 'bar', 'baz' => 'qux']];
        $result = new DatabaseResult(records: $records);

        $csv = $result->toCsv();
        $lines = array_filter(explode("\n", trim($csv)));

        $this->assertCount(2, $lines); // header + 1 row
        $this->assertStringContainsString('foo', $lines[0]);
        $this->assertStringContainsString('baz', $lines[0]);
    }

    public function testToCsvEmptyResult(): void
    {
        $result = new DatabaseResult();

        $csv = $result->toCsv();

        $this->assertSame('', $csv);
    }

    // -- toArray() --------------------------------------------------------------

    public function testToArrayReturnsRecords(): void
    {
        $records = [['id' => 1], ['id' => 2]];
        $result = new DatabaseResult(records: $records);

        $this->assertSame($records, $result->toArray());
    }

    public function testToArrayEmpty(): void
    {
        $result = new DatabaseResult();
        $this->assertSame([], $result->toArray());
    }

    // -- toPaginate() -----------------------------------------------------------

    public function testToPaginateReturnsCorrectStructure(): void
    {
        $records = [['id' => 1]];
        $result = new DatabaseResult(
            records: $records,
            count: 100,
            limit: 25,
            offset: 50,
        );

        $paginate = $result->toPaginate();

        $this->assertArrayHasKey('records', $paginate);
        $this->assertArrayHasKey('count', $paginate);
        $this->assertArrayHasKey('limit', $paginate);
        $this->assertArrayHasKey('offset', $paginate);
        $this->assertSame($records, $paginate['records']);
        $this->assertSame(100, $paginate['count']);
        $this->assertSame(25, $paginate['limit']);
        $this->assertSame(50, $paginate['offset']);
    }

    // -- Iterator (foreach) -----------------------------------------------------

    public function testIterableWithForeach(): void
    {
        $records = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
            ['id' => 3, 'name' => 'Charlie'],
        ];
        $result = new DatabaseResult(records: $records, count: 3);

        $collected = [];
        foreach ($result as $key => $row) {
            $collected[$key] = $row;
        }

        $this->assertCount(3, $collected);
        $this->assertSame('Alice', $collected[0]['name']);
        $this->assertSame('Charlie', $collected[2]['name']);
    }

    public function testIterableEmptyResult(): void
    {
        $result = new DatabaseResult();

        $collected = [];
        foreach ($result as $row) {
            $collected[] = $row;
        }

        $this->assertEmpty($collected);
    }

    public function testIterableCanRewind(): void
    {
        $records = [['id' => 1], ['id' => 2]];
        $result = new DatabaseResult(records: $records, count: 2);

        // First pass
        $first = [];
        foreach ($result as $row) {
            $first[] = $row;
        }
        // Second pass (tests rewind)
        $second = [];
        foreach ($result as $row) {
            $second[] = $row;
        }

        $this->assertSame($first, $second);
    }

    // -- Countable --------------------------------------------------------------

    public function testCountReturnsCount(): void
    {
        $result = new DatabaseResult(
            records: [['id' => 1]],
            count: 42,
        );

        $this->assertSame(42, count($result));
    }

    public function testCountEmptyResult(): void
    {
        $result = new DatabaseResult();
        $this->assertSame(0, count($result));
    }

    public function testCountReflectsTotalNotPageSize(): void
    {
        // count is the total rows, not the number of records on this page
        $result = new DatabaseResult(
            records: [['id' => 1], ['id' => 2]],
            count: 500,
            limit: 2,
            offset: 0,
        );

        $this->assertSame(500, count($result));
        $this->assertCount(2, $result->records);
    }

    // -- ArrayAccess ------------------------------------------------------------

    public function testArrayAccessOffsetGet(): void
    {
        $records = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ];
        $result = new DatabaseResult(records: $records, count: 2);

        $this->assertSame('Alice', $result[0]['name']);
        $this->assertSame('Bob', $result[1]['name']);
    }

    public function testArrayAccessOffsetExists(): void
    {
        $result = new DatabaseResult(records: [['id' => 1]], count: 1);

        $this->assertTrue(isset($result[0]));
        $this->assertFalse(isset($result[1]));
    }

    public function testArrayAccessOffsetSet(): void
    {
        $result = new DatabaseResult(records: [['id' => 1]], count: 1);

        $result[0] = ['id' => 99];
        $this->assertSame(99, $result[0]['id']);

        // Append with null offset
        $result[] = ['id' => 100];
        $this->assertSame(100, $result[1]['id']);
    }

    public function testArrayAccessOffsetUnset(): void
    {
        $result = new DatabaseResult(records: [['id' => 1], ['id' => 2]], count: 2);

        unset($result[0]);
        $this->assertFalse(isset($result[0]));
    }

    public function testArrayAccessOutOfBoundsReturnsNull(): void
    {
        $result = new DatabaseResult(records: [['id' => 1]], count: 1);

        $this->assertNull($result[999]);
    }

    // -- JsonSerializable -------------------------------------------------------

    public function testJsonSerializableWithJsonEncode(): void
    {
        $records = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ];
        $result = new DatabaseResult(records: $records, count: 2);

        $json = json_encode($result);
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);
        $this->assertSame(1, $decoded[0]['id']);
        $this->assertSame('Bob', $decoded[1]['name']);
    }

    public function testJsonSerializableEmpty(): void
    {
        $result = new DatabaseResult();

        $json = json_encode($result);
        $decoded = json_decode($json, true);

        $this->assertSame([], $decoded);
    }

    // -- Empty result handling --------------------------------------------------

    public function testEmptyResultAllMethods(): void
    {
        $result = new DatabaseResult();

        $this->assertSame([], $result->toArray());
        $this->assertSame('', $result->toCsv());
        $this->assertSame('[]', $result->toJson());
        $this->assertSame(0, count($result));
        $this->assertNull($result[0]);
        $this->assertFalse(isset($result[0]));

        $paginate = $result->toPaginate();
        $this->assertSame([], $paginate['records']);
        $this->assertSame(0, $paginate['count']);
    }

    // -- columnInfo() fallback --------------------------------------------------

    public function testColumnInfoWithNullAdapterReturnsFromColumns(): void
    {
        $result = new DatabaseResult(
            records: [],
            columns: ['id', 'name', 'email'],
        );

        $info = $result->columnInfo();

        $this->assertCount(3, $info);
        $this->assertSame('id', $info[0]['name']);
        $this->assertSame('name', $info[1]['name']);
        $this->assertSame('email', $info[2]['name']);

        // All fallback entries have UNKNOWN type
        foreach ($info as $col) {
            $this->assertSame('UNKNOWN', $col['type']);
            $this->assertNull($col['size']);
            $this->assertNull($col['decimals']);
            $this->assertTrue($col['nullable']);
            $this->assertFalse($col['primary_key']);
        }
    }

    public function testColumnInfoFallbackDerivesFromRecordKeys(): void
    {
        $records = [
            ['first_name' => 'Alice', 'age' => 30],
        ];
        // No columns provided, no adapter — should derive from record keys
        $result = new DatabaseResult(records: $records);

        $info = $result->columnInfo();

        $this->assertCount(2, $info);
        $this->assertSame('first_name', $info[0]['name']);
        $this->assertSame('age', $info[1]['name']);
    }

    public function testColumnInfoEmptyResult(): void
    {
        $result = new DatabaseResult();

        $info = $result->columnInfo();

        $this->assertSame([], $info);
    }

    public function testColumnInfoIsCached(): void
    {
        $result = new DatabaseResult(columns: ['a', 'b']);

        $first = $result->columnInfo();
        $second = $result->columnInfo();

        $this->assertSame($first, $second);
    }
}
