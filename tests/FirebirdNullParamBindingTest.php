<?php

/**
 * Firebird: a bound PHP null must not be handed to ibase_execute()/fbird_execute()
 * — the driver fails building the XSQLDA ("Incorrect values within SQLDA
 * structure — empty pointer to data at SQLVAR index N"), position/count
 * dependent, which broke every parameterised INSERT/UPDATE with a NULL column
 * (and therefore ORM save() on any row leaving a nullable column null).
 *
 * FirebirdAdapter::rewriteNullParamsToLiterals() rewrites each null parameter to
 * a literal NULL in the SQL and drops it from the bound set, so no null is ever
 * bound (NULL semantics preserved). It is pure string logic — no server needed.
 *
 * Regression test for issue #123.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\FirebirdAdapter;

class FirebirdNullParamBindingTest extends TestCase
{
    /** @param array<int, mixed> $params @return array{0:string,1:array} */
    private function rewrite(string $sql, array $params): array
    {
        $m = new \ReflectionMethod(FirebirdAdapter::class, 'rewriteNullParamsToLiterals');
        $m->setAccessible(true);
        return $m->invoke(null, $sql, $params);
    }

    public function testNullParamsBecomeLiteralNullAndAreDropped(): void
    {
        [$sql, $params] = $this->rewrite(
            'insert into t (a, b, c, d) values (?, ?, ?, ?)',
            [1, null, 'x', null]
        );
        $this->assertSame('insert into t (a, b, c, d) values (?, NULL, ?, NULL)', $sql);
        $this->assertSame([1, 'x'], array_values($params));
    }

    public function testQuestionMarkInsideStringLiteralIsNotAParameter(): void
    {
        [$sql, $params] = $this->rewrite(
            "update t set note = 'is it? yes', a = ? where b = ?",
            [null, 5]
        );
        $this->assertSame("update t set note = 'is it? yes', a = NULL where b = ?", $sql);
        $this->assertSame([5], array_values($params));
    }

    public function testNoNullParamsLeavesStatementUnchanged(): void
    {
        $in = ['x', 5, 'y'];
        [$sql, $params] = $this->rewrite('insert into t (a, b, c) values (?, ?, ?)', $in);
        $this->assertSame('insert into t (a, b, c) values (?, ?, ?)', $sql);
        $this->assertSame($in, array_values($params));
    }

    public function testAllNullParamsProduceNoBoundValues(): void
    {
        [$sql, $params] = $this->rewrite('insert into t (a, b) values (?, ?)', [null, null]);
        $this->assertSame('insert into t (a, b) values (NULL, NULL)', $sql);
        $this->assertSame([], $params);
    }
}
