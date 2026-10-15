<?php

/*
 * Copyright (c) 2026 Code Infinity
 * SPDX-License-Identifier: MPL-2.0
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */


/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright (c) 2026 Code Infinity
 * License: MPL-2.0 https://mozilla.org/MPL/2.0/
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\MongoDBAdapter;

/**
 * MongoDB SQL provider — a bound parameter is a value, never part of the statement.
 *
 * The provider parses the SQL text itself, so it used to paste each bound value
 * into that text before parsing it. A value holding a quote, a keyword or a
 * comma was then read as SQL, and matched, wrote or returned something other
 * than what the statement says. Bound values now stand in the text as opaque
 * tokens and are resolved only where a literal is read.
 *
 * NO MOCKS. A real MongoDB; the adapter performs the writes, the raw driver
 * collection witnesses the documents. Each case fails against the pre-fix adapter.
 */
final class MongoSqlBoundValuesTest extends TestCase
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
            if (self::requireServices()) {
                $this->fail($reason . ' [TINA4_REQUIRE_SERVICES is set]');
            }
            $this->markTestSkipped($reason);
        }
        $this->adapter = new MongoDBAdapter(self::uriWithDb());
        $this->collection = 'bound_' . bin2hex(random_bytes(6));
        foreach ([[1, 'a'], [2, 'b'], [3, 'c']] as [$id, $name]) {
            $this->adapter->insert($this->collection, ['id' => $id, 'name' => $name, 'role' => 'user']);
        }
    }

    protected function tearDown(): void
    {
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

    /** @return list<array<string, mixed>> every document, by id, without _id — read by the raw driver */
    private function docs(): array
    {
        $cursor = $this->adapter->getDatabase()->selectCollection($this->collection)->find(
            [],
            ['sort' => ['id' => 1], 'projection' => ['_id' => 0], 'typeMap' => ['root' => 'array', 'document' => 'array']]
        );
        return array_values(iterator_to_array($cursor, false));
    }

    /** @return list<int> */
    private function ids(array $rows): array
    {
        return array_map(static fn(array $r): int => (int)$r['id'], $rows);
    }

    public function testAQuotedBoundValueInADeleteMatchesOnlyItself(): void
    {
        $this->adapter->execute("DELETE FROM {$this->collection} WHERE name = ?", ["z' OR name IS NOT NULL OR name = 'y"]);

        $this->assertSame([1, 2, 3], $this->ids($this->docs()));
    }

    public function testAQuotedBoundValueInAnUpdateSetIsStoredWholeAndWritesNoOtherField(): void
    {
        $this->adapter->execute("UPDATE {$this->collection} SET name = ? WHERE id = ?", ["n', role = 'x", 2]);

        $this->assertSame([
            ['id' => 1, 'name' => 'a', 'role' => 'user'],
            ['id' => 2, 'name' => "n', role = 'x", 'role' => 'user'],
            ['id' => 3, 'name' => 'c', 'role' => 'user'],
        ], $this->docs());
    }

    public function testABoundValueHoldingAKeywordDoesNotChangeTheUpdatesWhere(): void
    {
        $this->adapter->execute("UPDATE {$this->collection} SET name = ? WHERE id = ?", ["n' WHERE id > 0 OR id = 'y", 2]);

        $this->assertSame(['a', "n' WHERE id > 0 OR id = 'y", 'c'], array_column($this->docs(), 'name'));
    }

    public function testAQuotedBoundValueInASelectMatchesOnlyItself(): void
    {
        $result = $this->adapter->fetch("SELECT * FROM {$this->collection} WHERE name = ?", ["z' OR name IS NOT NULL OR name = 'y"]);

        $this->assertSame([], $result['data']);
        $this->assertSame(0, $result['total']);
    }

    public function testABoundValueHoldingLimitDoesNotLimitTheSelect(): void
    {
        $result = $this->adapter->fetch("SELECT * FROM {$this->collection} WHERE name != ?", ['z LIMIT 1']);

        $this->assertCount(3, $result['data']);
    }

    public function testAValueWithAQuoteOrBackslashRoundTripsThroughInsertAndSelectExactly(): void
    {
        $this->adapter->execute("INSERT INTO {$this->collection} (id, name) VALUES (?, ?)", [4, "it's"]);
        $this->adapter->execute("INSERT INTO {$this->collection} (id, name) VALUES (?, ?)", [5, 'C:\\new']);

        $this->assertSame(["it's", 'C:\\new'], array_column(array_slice($this->docs(), 3), 'name'));
        $this->assertSame([4], $this->ids($this->adapter->fetch("SELECT * FROM {$this->collection} WHERE name = ?", ["it's"])['data']));
        $this->assertSame([5], $this->ids($this->adapter->fetch("SELECT * FROM {$this->collection} WHERE name = ?", ['C:\\new'])['data']));
    }

    public function testABoundValueKeepsItsWhitespace(): void
    {
        $this->adapter->execute("INSERT INTO {$this->collection} (id, name) VALUES (?, ?)", [4, 'a  b']);

        $this->assertSame([4], $this->ids($this->adapter->fetch("SELECT * FROM {$this->collection} WHERE name = ?", ['a  b'])['data']));
    }

    public function testABoundLikeValueWithAQuoteMatchesItself(): void
    {
        $this->adapter->execute("INSERT INTO {$this->collection} (id, name) VALUES (?, ?)", [4, "it's"]);

        $this->assertSame([4], $this->ids($this->adapter->fetch("SELECT * FROM {$this->collection} WHERE name LIKE ?", ["it's"])['data']));
        $this->assertSame([1, 2, 3], $this->ids($this->adapter->fetch("SELECT * FROM {$this->collection} WHERE name NOT LIKE ? ORDER BY id", ["it's"])['data']));
    }

    public function testANamedValueNamingAnotherPlaceholderStaysItself(): void
    {
        $this->adapter->execute("UPDATE {$this->collection} SET name = :name WHERE id = :id", ['name' => ':id', 'id' => 2]);

        $this->assertSame(['a', ':id', 'c'], array_column($this->docs(), 'name'));
    }
}
