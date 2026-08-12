<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tina4\ORM;
use Tina4\Database\Database;

/**
 * Feature 23 - ORM scopes: the shared conformance contract, parity with
 * tina4-python/tests/test_scopes_contract.py.
 *
 * SCOPE-DEC-01 (OWNER-DECISIONS.md Batch 4): fix PHP's scope global-registry
 * collision -- `$_scopes` was declared once on the abstract ORM base and never
 * redeclared per subclass, so PHP's static-property inheritance resolved every
 * subclass's `static::$_scopes` to the SAME shared array, keyed only by scope
 * name. Two models registering a scope with the same name collided (the last
 * registration won for BOTH). Fixed by keying the shared array by the calling
 * class: `static::$_scopes[static::class][$name]`.
 *
 * SCOPE-DEC-02: scopes stay TERMINAL LISTS (no compose/rebind/global-scope --
 * the ledger did not separately ratify it).
 *
 * NO MOCKS: real SQLite AND the lab's real PostgreSQL (:55432, tina4/tina4).
 * Positive AND negative throughout. Under TINA4_REQUIRE_SERVICES a postgres
 * skip is a hard failure.
 */

/** Two models sharing the SAME scope name with DIFFERENT filters -- the collision case. */
class ScopeUserPhp extends ORM
{
    public string $tableName = 'scope_users';
    public ?int $id = null;
    public ?string $name = null;
    public ?int $active = 0;
}

class ScopeProductPhp extends ORM
{
    public string $tableName = 'scope_products';
    public ?int $id = null;
    public ?string $name = null;
    public ?int $discontinued = 0;
}

/** Soft-delete model: proves a scope respects the soft-delete filter. */
class ScopeArticlePhp extends ORM
{
    public string $tableName = 'scope_articles';
    public bool $softDelete = true;
    public ?int $id = null;
    public ?string $name = null;
    public ?string $category = null;
}

/** Plain model with more rows than any single page -- proves limit/offset pushdown. */
class ScopeWidgetPhp extends ORM
{
    public string $tableName = 'scope_widgets';
    public ?int $id = null;
    public ?string $name = null;
}

final class ScopesContractTest extends TestCase
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

    /** @param array<int, string> $tables */
    private function fresh(Database $db, array $tables): void
    {
        ORM::bindDatabase($db);
        foreach ($tables as $t) {
            try {
                $db->execute("DROP TABLE IF EXISTS {$t}");
            } catch (\Throwable) {
            }
        }
    }

    // ── SCOPE-DEC-01: two models, SAME scope name, DIFFERENT filters -- no collision ─
    #[DataProvider('engines')]
    public function testTwoModelsSameScopeNameReturnDifferentRows(string $engine): void
    {
        $db = $this->engineDb($engine);
        $this->fresh($db, ['scope_users', 'scope_products']);
        (new ScopeUserPhp($db))->createTable();
        (new ScopeProductPhp($db))->createTable();

        (new ScopeUserPhp($db, ['name' => 'Alice', 'active' => 1]))->save();
        (new ScopeUserPhp($db, ['name' => 'Bob', 'active' => 0]))->save();
        (new ScopeUserPhp($db, ['name' => 'Carol', 'active' => 1]))->save();

        (new ScopeProductPhp($db, ['name' => 'Widget', 'discontinued' => 0]))->save();
        (new ScopeProductPhp($db, ['name' => 'Gadget', 'discontinued' => 1]))->save();
        (new ScopeProductPhp($db, ['name' => 'Gizmo', 'discontinued' => 0]))->save();

        // SAME scope name ("active") registered on TWO different models with
        // DIFFERENT filters -- the exact SCOPE-PHP-COLLISION scenario from the
        // feature doc. The second registration must never overwrite or leak
        // into the first model's filter.
        (new ScopeUserPhp($db))->scope('active', 'active = ?', [1]);
        (new ScopeProductPhp($db))->scope('active', 'discontinued = ?', [0]);

        $users = ScopeUserPhp::active();
        $products = ScopeProductPhp::active();

        $userNames = array_map(static fn($u) => $u->name, $users);
        $productNames = array_map(static fn($p) => $p->name, $products);
        sort($userNames);
        sort($productNames);

        $this->assertSame(['Alice', 'Carol'], $userNames, 'ScopeUserPhp::active() collided');
        $this->assertSame(['Gizmo', 'Widget'], $productNames, 'ScopeProductPhp::active() collided');
    }

    // ── SCOPE-DEC-02: a scope respects the soft-delete filter (via where()) ───
    #[DataProvider('engines')]
    public function testScopeExcludesASoftDeletedRow(string $engine): void
    {
        $db = $this->engineDb($engine);
        $this->fresh($db, ['scope_articles']);
        (new ScopeArticlePhp($db))->createTable();

        $one = (new ScopeArticlePhp($db, ['name' => 'One', 'category' => 'news']))->save();
        (new ScopeArticlePhp($db, ['name' => 'Two', 'category' => 'news']))->save();
        (new ScopeArticlePhp($db, ['name' => 'Three', 'category' => 'news']))->save();

        (new ScopeArticlePhp($db))->scope('news', 'category = ?', ['news']);
        $this->assertCount(3, ScopeArticlePhp::news());

        $this->assertNotFalse($one);
        $this->assertTrue($one->delete());

        $visible = ScopeArticlePhp::news();
        $this->assertCount(2, $visible);
        $names = array_map(static fn($a) => $a->name, $visible);
        $this->assertNotContains('One', $names);

        // Negative: the row is still PHYSICALLY present (raw, unfiltered).
        $row = $db->fetchOne('SELECT COUNT(*) AS c FROM scope_articles');
        $this->assertSame(3, (int) $row['c']);
    }

    // ── SCOPE-DEC-02: a scope pushes limit/offset to the database ─────────────
    #[DataProvider('engines')]
    public function testScopeHonoursLimitAndOffset(string $engine): void
    {
        $db = $this->engineDb($engine);
        $this->fresh($db, ['scope_widgets']);
        (new ScopeWidgetPhp($db))->createTable();

        for ($i = 0; $i < 15; $i++) {
            (new ScopeWidgetPhp($db, ['name' => "w{$i}"]))->save();
        }

        (new ScopeWidgetPhp($db))->scope('everything', '1=1');

        // Negative: an explicit smaller limit is honoured exactly (proves the
        // argument reaches the DB rather than being silently discarded).
        $small = ScopeWidgetPhp::everything(3);
        $this->assertCount(3, $small);

        // Two pages of the SAME scope, from the SAME 15-row set, are DISJOINT --
        // proves offset reaches the database, not a client-side no-op.
        $page1 = ScopeWidgetPhp::everything(5, 0);
        $page2 = ScopeWidgetPhp::everything(5, 5);
        $this->assertCount(5, $page1);
        $this->assertCount(5, $page2);
        $ids1 = array_map(static fn($w) => $w->id, $page1);
        $ids2 = array_map(static fn($w) => $w->id, $page2);
        $this->assertEmpty(array_intersect($ids1, $ids2), 'pages overlap');
    }
}
