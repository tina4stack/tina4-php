<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;
use Tina4\QueryBuilder;
use Tina4\Database\SQLite3Adapter;

class QueryBuilderTest extends TestCase
{
    private SQLite3Adapter $db;

    protected function setUp(): void
    {
        $this->db = new SQLite3Adapter(':memory:');

        $this->db->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT,
            age INTEGER,
            active INTEGER DEFAULT 1
        )");

        $this->db->exec("CREATE TABLE orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            amount REAL,
            status TEXT DEFAULT 'pending'
        )");

        // Insert 5 user rows
        $users = [
            ['Alice',   'alice@test.com',   30, 1],
            ['Bob',     'bob@test.com',     25, 1],
            ['Charlie', 'charlie@test.com', 35, 0],
            ['Diana',   'diana@test.com',   28, 1],
            ['Eve',     'eve@test.com',     22, 0],
        ];

        foreach ($users as [$name, $email, $age, $active]) {
            $this->db->exec(
                "INSERT INTO users (name, email, age, active) VALUES (:name, :email, :age, :active)",
                [':name' => $name, ':email' => $email, ':age' => $age, ':active' => $active]
            );
        }

        // Insert a few orders for join tests
        $this->db->exec("INSERT INTO orders (user_id, amount, status) VALUES (1, 99.99, 'completed')");
        $this->db->exec("INSERT INTO orders (user_id, amount, status) VALUES (1, 49.50, 'pending')");
        $this->db->exec("INSERT INTO orders (user_id, amount, status) VALUES (2, 75.00, 'completed')");
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    // -------------------------------------------------------------------------
    // 1. from() creates a QueryBuilder
    // -------------------------------------------------------------------------

    public function testFromReturnsQueryBuilder(): void
    {
        $qb = QueryBuilder::fromTable('users');
        $this->assertInstanceOf(QueryBuilder::class, $qb);
    }

    public function testFromWithDatabase(): void
    {
        $qb = QueryBuilder::fromTable('users', $this->db);
        $this->assertInstanceOf(QueryBuilder::class, $qb);
    }

    // -------------------------------------------------------------------------
    // 2. select() sets columns
    // -------------------------------------------------------------------------

    public function testSelectSetsColumns(): void
    {
        $sql = QueryBuilder::fromTable('users')
            ->select('id', 'name')
            ->toSql();

        $this->assertSame('SELECT id, name FROM users', $sql);
    }

    public function testSelectDefaultStar(): void
    {
        $sql = QueryBuilder::fromTable('users')->toSql();
        $this->assertSame('SELECT * FROM users', $sql);
    }

    public function testSelectEmptyKeepsStar(): void
    {
        $sql = QueryBuilder::fromTable('users')->select()->toSql();
        $this->assertSame('SELECT * FROM users', $sql);
    }

    public function testSelectSingleColumn(): void
    {
        $sql = QueryBuilder::fromTable('users')->select('name')->toSql();
        $this->assertSame('SELECT name FROM users', $sql);
    }

    public function testSelectMultipleColumns(): void
    {
        $sql = QueryBuilder::fromTable('users')
            ->select('id', 'name', 'email', 'age')
            ->toSql();

        $this->assertSame('SELECT id, name, email, age FROM users', $sql);
    }

    // -------------------------------------------------------------------------
    // 3. where() adds AND conditions
    // -------------------------------------------------------------------------

    public function testWhereSingleCondition(): void
    {
        $sql = QueryBuilder::fromTable('users')
            ->where('active = ?', [1])
            ->toSql();

        $this->assertSame('SELECT * FROM users WHERE active = ?', $sql);
    }

    public function testWhereMultipleConditionsAnd(): void
    {
        $sql = QueryBuilder::fromTable('users')
            ->where('active = ?', [1])
            ->where('age > ?', [18])
            ->toSql();

        $this->assertSame('SELECT * FROM users WHERE active = ? AND age > ?', $sql);
    }

    public function testWhereNoParams(): void
    {
        $sql = QueryBuilder::fromTable('users')
            ->where('active = 1')
            ->toSql();

        $this->assertSame('SELECT * FROM users WHERE active = 1', $sql);
    }

    // -------------------------------------------------------------------------
    // 4. orWhere() adds OR conditions
    // -------------------------------------------------------------------------

    public function testOrWhere(): void
    {
        $sql = QueryBuilder::fromTable('users')
            ->where('active = ?', [1])
            ->orWhere('age > ?', [30])
            ->toSql();

        $this->assertSame('SELECT * FROM users WHERE active = ? OR age > ?', $sql);
    }

    public function testOrWhereAsFirst(): void
    {
        // When orWhere is the first condition, the OR connector is omitted (index 0)
        $sql = QueryBuilder::fromTable('users')
            ->orWhere('active = ?', [1])
            ->toSql();

        $this->assertSame('SELECT * FROM users WHERE active = ?', $sql);
    }

    public function testMixedWhereAndOrWhere(): void
    {
        $sql = QueryBuilder::fromTable('users')
            ->where('active = ?', [1])
            ->where('age > ?', [18])
            ->orWhere('name = ?', ['Admin'])
            ->toSql();

        $this->assertSame(
            'SELECT * FROM users WHERE active = ? AND age > ? OR name = ?',
            $sql
        );
    }

    // -------------------------------------------------------------------------
    // 5. join() adds INNER JOIN
    // -------------------------------------------------------------------------

    public function testJoinInner(): void
    {
        $sql = QueryBuilder::fromTable('users')
            ->join('orders', 'orders.user_id = users.id')
            ->toSql();

        $this->assertSame(
            'SELECT * FROM users INNER JOIN orders ON orders.user_id = users.id',
            $sql
        );
    }

    public function testMultipleJoins(): void
    {
        $sql = QueryBuilder::fromTable('users')
            ->join('orders', 'orders.user_id = users.id')
            ->join('products', 'products.id = orders.product_id')
            ->toSql();

        $this->assertStringContainsString('INNER JOIN orders ON orders.user_id = users.id', $sql);
        $this->assertStringContainsString('INNER JOIN products ON products.id = orders.product_id', $sql);
    }

    // -------------------------------------------------------------------------
    // 6. leftJoin() adds LEFT JOIN
    // -------------------------------------------------------------------------

    public function testLeftJoin(): void
    {
        $sql = QueryBuilder::fromTable('users')
            ->leftJoin('orders', 'orders.user_id = users.id')
            ->toSql();

        $this->assertSame(
            'SELECT * FROM users LEFT JOIN orders ON orders.user_id = users.id',
            $sql
        );
    }

    public function testMixedJoinTypes(): void
    {
        $sql = QueryBuilder::fromTable('users')
            ->join('orders', 'orders.user_id = users.id')
            ->leftJoin('profiles', 'profiles.user_id = users.id')
            ->toSql();

        $this->assertStringContainsString('INNER JOIN orders ON orders.user_id = users.id', $sql);
        $this->assertStringContainsString('LEFT JOIN profiles ON profiles.user_id = users.id', $sql);
    }

    // -------------------------------------------------------------------------
    // 7. groupBy() adds GROUP BY
    // -------------------------------------------------------------------------

    public function testGroupBy(): void
    {
        $sql = QueryBuilder::fromTable('users')
            ->select('active', 'COUNT(*) as cnt')
            ->groupBy('active')
            ->toSql();

        $this->assertSame('SELECT active, COUNT(*) as cnt FROM users GROUP BY active', $sql);
    }

    public function testGroupByMultiple(): void
    {
        $sql = QueryBuilder::fromTable('users')
            ->groupBy('active')
            ->groupBy('age')
            ->toSql();

        $this->assertSame('SELECT * FROM users GROUP BY active, age', $sql);
    }

    // -------------------------------------------------------------------------
    // 8. having() adds HAVING
    // -------------------------------------------------------------------------

    public function testHaving(): void
    {
        $sql = QueryBuilder::fromTable('users')
            ->select('active', 'COUNT(*) as cnt')
            ->groupBy('active')
            ->having('COUNT(*) > ?', [1])
            ->toSql();

        $this->assertSame(
            'SELECT active, COUNT(*) as cnt FROM users GROUP BY active HAVING COUNT(*) > ?',
            $sql
        );
    }

    public function testHavingMultiple(): void
    {
        $sql = QueryBuilder::fromTable('users')
            ->select('active', 'COUNT(*) as cnt', 'AVG(age) as avg_age')
            ->groupBy('active')
            ->having('COUNT(*) > ?', [1])
            ->having('AVG(age) > ?', [20])
            ->toSql();

        $this->assertStringContainsString('HAVING COUNT(*) > ? AND AVG(age) > ?', $sql);
    }

    // -------------------------------------------------------------------------
    // 9. orderBy() adds ORDER BY
    // -------------------------------------------------------------------------

    public function testOrderBy(): void
    {
        $sql = QueryBuilder::fromTable('users')
            ->orderBy('name ASC')
            ->toSql();

        $this->assertSame('SELECT * FROM users ORDER BY name ASC', $sql);
    }

    public function testOrderByMultiple(): void
    {
        $sql = QueryBuilder::fromTable('users')
            ->orderBy('active DESC')
            ->orderBy('name ASC')
            ->toSql();

        $this->assertSame('SELECT * FROM users ORDER BY active DESC, name ASC', $sql);
    }

    // -------------------------------------------------------------------------
    // 10. limit() sets LIMIT and OFFSET
    // -------------------------------------------------------------------------

    public function testLimitOnly(): void
    {
        // limit does not appear in toSql() — it is passed to fetch()
        // We verify the SQL is valid and limit works via get()
        $result = QueryBuilder::fromTable('users', $this->db)
            ->orderBy('id ASC')
            ->limit(2)
            ->get();

        $this->assertArrayHasKey('data', $result);
        $this->assertCount(2, $result['data']);
    }

    public function testLimitWithOffset(): void
    {
        $result = QueryBuilder::fromTable('users', $this->db)
            ->orderBy('id ASC')
            ->limit(2, 2)
            ->get();

        $this->assertArrayHasKey('data', $result);
        $this->assertCount(2, $result['data']);
        // Offset 2 skips Alice and Bob, so first result is Charlie
        $this->assertSame('Charlie', $result['data'][0]['name']);
    }

    // -------------------------------------------------------------------------
    // 11. toSql() generates correct SQL
    // -------------------------------------------------------------------------

    public function testToSqlMinimal(): void
    {
        $sql = QueryBuilder::fromTable('users')->toSql();
        $this->assertSame('SELECT * FROM users', $sql);
    }

    public function testToSqlFullQuery(): void
    {
        $sql = QueryBuilder::fromTable('users')
            ->select('name', 'age')
            ->join('orders', 'orders.user_id = users.id')
            ->where('active = ?', [1])
            ->groupBy('name')
            ->having('COUNT(*) > ?', [0])
            ->orderBy('name ASC')
            ->toSql();

        // Verify clause ordering: SELECT ... FROM ... JOIN ... WHERE ... GROUP BY ... HAVING ... ORDER BY
        $this->assertStringStartsWith('SELECT name, age FROM users', $sql);
        $this->assertStringContainsString('INNER JOIN orders ON orders.user_id = users.id', $sql);
        $this->assertStringContainsString('WHERE active = ?', $sql);
        $this->assertStringContainsString('GROUP BY name', $sql);
        $this->assertStringContainsString('HAVING COUNT(*) > ?', $sql);
        $this->assertStringContainsString('ORDER BY name ASC', $sql);

        // Verify ordering of clauses
        $joinPos    = strpos($sql, 'INNER JOIN');
        $wherePos   = strpos($sql, 'WHERE');
        $groupPos   = strpos($sql, 'GROUP BY');
        $havingPos  = strpos($sql, 'HAVING');
        $orderPos   = strpos($sql, 'ORDER BY');

        $this->assertLessThan($wherePos, $joinPos);
        $this->assertLessThan($groupPos, $wherePos);
        $this->assertLessThan($havingPos, $groupPos);
        $this->assertLessThan($orderPos, $havingPos);
    }

    // -------------------------------------------------------------------------
    // 12. Method chaining
    // -------------------------------------------------------------------------

    public function testMethodChainingReturnsSelf(): void
    {
        $qb = QueryBuilder::fromTable('users', $this->db);

        $this->assertSame($qb, $qb->select('id', 'name'));
        $this->assertSame($qb, $qb->where('id > ?', [0]));
        $this->assertSame($qb, $qb->orWhere('id < ?', [100]));
        $this->assertSame($qb, $qb->join('orders', 'orders.user_id = users.id'));
        $this->assertSame($qb, $qb->leftJoin('profiles', 'profiles.user_id = users.id'));
        $this->assertSame($qb, $qb->groupBy('name'));
        $this->assertSame($qb, $qb->having('COUNT(*) > ?', [0]));
        $this->assertSame($qb, $qb->orderBy('name ASC'));
        $this->assertSame($qb, $qb->limit(10, 5));
    }

    public function testFluentChainProducesCorrectSql(): void
    {
        $sql = QueryBuilder::fromTable('users')
            ->select('id', 'name')
            ->where('active = ?', [1])
            ->orderBy('name ASC')
            ->toSql();

        $this->assertSame(
            'SELECT id, name FROM users WHERE active = ? ORDER BY name ASC',
            $sql
        );
    }

    // -------------------------------------------------------------------------
    // 13. get() executes query
    // -------------------------------------------------------------------------

    public function testGetReturnsResults(): void
    {
        $result = QueryBuilder::fromTable('users', $this->db)
            ->select('id', 'name')
            ->orderBy('id ASC')
            ->get();

        $this->assertArrayHasKey('data', $result);
        $this->assertCount(5, $result['data']);
        $this->assertSame('Alice', $result['data'][0]['name']);
    }

    public function testGetWithWhereCondition(): void
    {
        $result = QueryBuilder::fromTable('users', $this->db)
            ->where('active = ?', [1])
            ->orderBy('name ASC')
            ->get();

        $this->assertArrayHasKey('data', $result);
        $this->assertCount(3, $result['data']);
        // Active users: Alice, Bob, Diana (sorted)
        $this->assertSame('Alice', $result['data'][0]['name']);
        $this->assertSame('Bob', $result['data'][1]['name']);
        $this->assertSame('Diana', $result['data'][2]['name']);
    }

    public function testGetWithJoin(): void
    {
        $result = QueryBuilder::fromTable('users', $this->db)
            ->select('users.name', 'orders.amount')
            ->join('orders', 'orders.user_id = users.id')
            ->orderBy('orders.amount DESC')
            ->get();

        $this->assertArrayHasKey('data', $result);
        $this->assertCount(3, $result['data']);
    }

    // -------------------------------------------------------------------------
    // 14. first() returns single row or null
    // -------------------------------------------------------------------------

    public function testFirstReturnsSingleRow(): void
    {
        $row = QueryBuilder::fromTable('users', $this->db)
            ->where('name = ?', ['Alice'])
            ->first();

        $this->assertIsArray($row);
        $this->assertSame('Alice', $row['name']);
        $this->assertSame('alice@test.com', $row['email']);
    }

    public function testFirstReturnsNullWhenNoMatch(): void
    {
        $row = QueryBuilder::fromTable('users', $this->db)
            ->where('name = ?', ['Nonexistent'])
            ->first();

        $this->assertNull($row);
    }

    public function testFirstWithOrderByReturnsCorrectRow(): void
    {
        $row = QueryBuilder::fromTable('users', $this->db)
            ->where('active = ?', [1])
            ->orderBy('age ASC')
            ->first();

        $this->assertIsArray($row);
        // Youngest active user is Bob (age 25)
        $this->assertSame('Bob', $row['name']);
    }

    // -------------------------------------------------------------------------
    // 15. count() returns integer
    // -------------------------------------------------------------------------

    public function testCountAll(): void
    {
        $count = QueryBuilder::fromTable('users', $this->db)->count();
        $this->assertSame(5, $count);
    }

    public function testCountWithWhere(): void
    {
        $count = QueryBuilder::fromTable('users', $this->db)
            ->where('active = ?', [1])
            ->count();

        $this->assertSame(3, $count);
    }

    public function testCountWithNoResults(): void
    {
        $count = QueryBuilder::fromTable('users', $this->db)
            ->where('name = ?', ['Nonexistent'])
            ->count();

        $this->assertSame(0, $count);
    }

    public function testCountDoesNotAlterColumns(): void
    {
        $qb = QueryBuilder::fromTable('users', $this->db)
            ->select('id', 'name');

        // count() temporarily replaces columns with COUNT(*), then restores
        $count = $qb->count();
        $this->assertSame(5, $count);

        // Verify original columns are restored by checking toSql()
        $sql = $qb->toSql();
        $this->assertStringContainsString('SELECT id, name', $sql);
    }

    // -------------------------------------------------------------------------
    // 16. exists() returns boolean
    // -------------------------------------------------------------------------

    public function testExistsReturnsTrue(): void
    {
        $exists = QueryBuilder::fromTable('users', $this->db)
            ->where('name = ?', ['Alice'])
            ->exists();

        $this->assertTrue($exists);
    }

    public function testExistsReturnsFalse(): void
    {
        $exists = QueryBuilder::fromTable('users', $this->db)
            ->where('name = ?', ['Nonexistent'])
            ->exists();

        $this->assertFalse($exists);
    }

    public function testExistsWithMultipleConditions(): void
    {
        $exists = QueryBuilder::fromTable('users', $this->db)
            ->where('active = ?', [1])
            ->where('age > ?', [29])
            ->exists();

        // Alice is active and age 30
        $this->assertTrue($exists);
    }

    // -------------------------------------------------------------------------
    // 17. No database throws RuntimeException
    // -------------------------------------------------------------------------

    public function testGetWithoutDbThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No database adapter provided');

        QueryBuilder::fromTable('users')->get();
    }

    public function testFirstWithoutDbThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        QueryBuilder::fromTable('users')->first();
    }

    public function testCountWithoutDbThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        QueryBuilder::fromTable('users')->count();
    }

    public function testExistsWithoutDbThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        QueryBuilder::fromTable('users')->exists();
    }

    // -------------------------------------------------------------------------
    // 18. Complex multi-clause query
    // -------------------------------------------------------------------------

    public function testComplexQuerySql(): void
    {
        $sql = QueryBuilder::fromTable('users')
            ->select('users.name', 'COUNT(orders.id) as order_count', 'SUM(orders.amount) as total')
            ->join('orders', 'orders.user_id = users.id')
            ->where('users.active = ?', [1])
            ->where('orders.status = ?', ['completed'])
            ->groupBy('users.name')
            ->having('COUNT(orders.id) > ?', [0])
            ->orderBy('total DESC')
            ->toSql();

        $expected = 'SELECT users.name, COUNT(orders.id) as order_count, SUM(orders.amount) as total'
            . ' FROM users'
            . ' INNER JOIN orders ON orders.user_id = users.id'
            . ' WHERE users.active = ? AND orders.status = ?'
            . ' GROUP BY users.name'
            . ' HAVING COUNT(orders.id) > ?'
            . ' ORDER BY total DESC';

        $this->assertSame($expected, $sql);
    }

    public function testComplexQueryExecution(): void
    {
        $result = QueryBuilder::fromTable('users', $this->db)
            ->select('users.name', 'SUM(orders.amount) as total')
            ->join('orders', 'orders.user_id = users.id')
            ->where('orders.status = ?', ['completed'])
            ->groupBy('users.name')
            ->orderBy('total DESC')
            ->get();

        $this->assertArrayHasKey('data', $result);
        $this->assertGreaterThanOrEqual(1, count($result['data']));
    }

    public function testComplexQueryWithOrWhere(): void
    {
        $result = QueryBuilder::fromTable('users', $this->db)
            ->where('age > ?', [30])
            ->orWhere('active = ?', [0])
            ->orderBy('name ASC')
            ->get();

        $this->assertArrayHasKey('data', $result);
        // Charlie (age 35, inactive), Eve (age 22, inactive), plus Charlie qualifies for both
        $names = array_column($result['data'], 'name');
        $this->assertContains('Charlie', $names);
        $this->assertContains('Eve', $names);
    }

    public function testComplexQueryWithLeftJoin(): void
    {
        // Left join should include users without orders
        $result = QueryBuilder::fromTable('users', $this->db)
            ->select('users.name', 'orders.amount')
            ->leftJoin('orders', 'orders.user_id = users.id')
            ->orderBy('users.name ASC')
            ->get();

        $this->assertArrayHasKey('data', $result);
        // All 5 users should appear (some with null order amounts)
        $names = array_column($result['data'], 'name');
        $this->assertContains('Alice', $names);
        $this->assertContains('Charlie', $names);
        $this->assertContains('Diana', $names);
        $this->assertContains('Eve', $names);
    }

    // -------------------------------------------------------------------------
    // 19. Empty results
    // -------------------------------------------------------------------------

    public function testGetReturnsEmptyData(): void
    {
        $result = QueryBuilder::fromTable('users', $this->db)
            ->where('name = ?', ['Nonexistent'])
            ->get();

        $this->assertArrayHasKey('data', $result);
        $this->assertEmpty($result['data']);
    }

    public function testEmptyTableReturnsZeroCount(): void
    {
        $this->db->exec("CREATE TABLE empty_table (id INTEGER PRIMARY KEY, value TEXT)");

        $count = QueryBuilder::fromTable('empty_table', $this->db)->count();
        $this->assertSame(0, $count);
    }

    public function testEmptyTableExistsReturnsFalse(): void
    {
        $this->db->exec("CREATE TABLE empty_table (id INTEGER PRIMARY KEY, value TEXT)");

        $exists = QueryBuilder::fromTable('empty_table', $this->db)->exists();
        $this->assertFalse($exists);
    }

    public function testEmptyTableFirstReturnsNull(): void
    {
        $this->db->exec("CREATE TABLE empty_table (id INTEGER PRIMARY KEY, value TEXT)");

        $row = QueryBuilder::fromTable('empty_table', $this->db)->first();
        $this->assertNull($row);
    }
}
