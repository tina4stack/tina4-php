<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tina4\ORM;
use Tina4\Database\Database;

/**
 * Feature 20 - Soft delete: the shared conformance contract, parity with
 * tina4-python/tests/test_softdelete_contract.py.
 *
 * Proves the soft-delete BEHAVIOUR against a REAL database, NO MOCKS. Every case
 * runs on real SQLite AND the lab's real PostgreSQL (:55432, tina4/tina4 by
 * default) so row presence/absence is asserted by querying the real table, not a
 * double: a soft-deleted row is COUNT=1 in the raw table but ABSENT from the
 * finders; a force-deleted row is COUNT=0.
 *
 * Case names are shared verbatim across the four frameworks and gated by
 * scripts/audit-contract-fixtures.py. Under TINA4_REQUIRE_SERVICES a postgres
 * skip is upgraded to a failure by RequireServicesExtension.
 *
 * SOFTDEL-DEC-01 (adds the previously-UNTESTED PHP restore()/withTrashed() cases)
 * + SOFTDEL-DEC-02 (createTable() injects is_deleted): delete() FLAGS not
 * removes; the finders exclude it; withTrashed() includes it; restore()
 * un-deletes; forceDelete() ALWAYS hard-removes (the regression that catches the
 * PHP-class undefined-$whereParams throw-instead-of-delete bug); createTable()
 * INJECTS the is_deleted column for a soft-delete model that never declared it.
 */

/** Soft-delete model that DECLARES is_deleted (behaviour independent of injection). */
class SdItem extends ORM
{
    public string $tableName = 'sd_item';
    public bool $softDelete = true;
    public ?int $id = null;
    public ?string $title = null;
    public int $is_deleted = 0;
}

/** Soft-delete model with NO is_deleted declared -- createTable() must INJECT it. */
class SdAuto extends ORM
{
    public string $tableName = 'sd_auto';
    public bool $softDelete = true;
    public ?int $id = null;
    public ?string $title = null;
}

final class SoftDeleteContractTest extends TestCase
{
    /** @return array<string, array<int, string>> */
    public static function engines(): array
    {
        return [
            'sqlite'   => ['sqlite'],
            'postgres' => ['postgres'],
        ];
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

    private function drop(Database $db, string ...$tables): void
    {
        foreach ($tables as $t) {
            try {
                $db->execute("DROP TABLE IF EXISTS {$t}");
            } catch (\Throwable) {
                // best effort
            }
        }
    }

    /** Raw COUNT(*) with NO soft-delete filter -- sees flagged rows too. */
    private function rawCount(Database $db, string $table): int
    {
        $row = $db->fetchOne("SELECT COUNT(*) AS c FROM {$table}");
        return (int) ($row['c'] ?? $row['C'] ?? 0);
    }

    private function flagValue(Database $db, string $table, mixed $id): int
    {
        $row = $db->fetchOne("SELECT is_deleted FROM {$table} WHERE id = ?", [$id]);
        return (int) ($row['is_deleted'] ?? $row['IS_DELETED'] ?? -1);
    }

    // ── delete() FLAGS, does not remove ──────────────────────────────────────

    #[DataProvider('engines')]
    public function testDeleteFlagsTheRowInsteadOfRemovingIt(string $engine): void
    {
        $db = $this->engineDb($engine);
        ORM::bindDatabase($db);
        $this->drop($db, 'sd_item');
        $this->assertTrue((new SdItem($db))->createTable());
        try {
            $row = new SdItem($db, ['title' => 'keep-me']);
            $this->assertNotFalse($row->save());
            $this->assertTrue($row->delete());
            // The row is STILL in the raw table, with the flag set to 1.
            $this->assertSame(1, $this->rawCount($db, 'sd_item'));
            $this->assertSame(1, $this->flagValue($db, 'sd_item', $row->id));
        } finally {
            $this->drop($db, 'sd_item');
        }
    }

    #[DataProvider('engines')]
    public function testASoftDeletedRowIsExcludedFromTheDefaultFinder(string $engine): void
    {
        $db = $this->engineDb($engine);
        ORM::bindDatabase($db);
        $this->drop($db, 'sd_item');
        $this->assertTrue((new SdItem($db))->createTable());
        try {
            $row = new SdItem($db, ['title' => 'hide-me']);
            $row->save();
            $this->assertCount(1, (new SdItem($db))->all());
            $this->assertSame(1, (new SdItem($db))->count());
            $row->delete();
            // Present in the raw table (negative control) ...
            $this->assertSame(1, $this->rawCount($db, 'sd_item'));
            // ... but excluded from every high-level finder.
            $this->assertCount(0, (new SdItem($db))->all());
            $this->assertSame(0, (new SdItem($db))->count());
            $this->assertNull((new SdItem($db))->findById($row->id));
            $this->assertCount(0, (new SdItem($db))->where('id = ?', [$row->id]));
        } finally {
            $this->drop($db, 'sd_item');
        }
    }

    // ── withTrashed() includes; restore() un-deletes (SOFTDEL-PHP-RESTORE-UNTESTED) ──

    #[DataProvider('engines')]
    public function testWithTrashedReturnsTheSoftDeletedRow(string $engine): void
    {
        $db = $this->engineDb($engine);
        ORM::bindDatabase($db);
        $this->drop($db, 'sd_item');
        $this->assertTrue((new SdItem($db))->createTable());
        try {
            $row = new SdItem($db, ['title' => 'trashed']);
            $row->save();
            $row->delete();
            $this->assertCount(0, (new SdItem($db))->all());          // excluded by default
            $trashed = (new SdItem($db))->withTrashed();
            $this->assertCount(1, $trashed);                          // included with_trashed
            $this->assertSame($row->id, $trashed[0]->id);
        } finally {
            $this->drop($db, 'sd_item');
        }
    }

    #[DataProvider('engines')]
    public function testRestoreUndeletesTheRowSoItReappearsInTheFinder(string $engine): void
    {
        $db = $this->engineDb($engine);
        ORM::bindDatabase($db);
        $this->drop($db, 'sd_item');
        $this->assertTrue((new SdItem($db))->createTable());
        try {
            $row = new SdItem($db, ['title' => 'comeback']);
            $row->save();
            $row->delete();
            $this->assertSame(0, (new SdItem($db))->count());
            $this->assertTrue($row->restore());
            // Restored: the finder sees it again and the flag is cleared.
            $this->assertSame(1, (new SdItem($db))->count());
            $this->assertNotNull((new SdItem($db))->findById($row->id));
            $this->assertSame(0, $this->flagValue($db, 'sd_item', $row->id));
        } finally {
            $this->drop($db, 'sd_item');
        }
    }

    // ── forceDelete() ALWAYS hard-removes (the PHP-class regression) ──────────

    #[DataProvider('engines')]
    public function testForceDeleteHardRemovesTheRowEvenFromWithTrashed(string $engine): void
    {
        $db = $this->engineDb($engine);
        ORM::bindDatabase($db);
        $this->drop($db, 'sd_item');
        $this->assertTrue((new SdItem($db))->createTable());
        try {
            $row = new SdItem($db, ['title' => 'gone']);
            $row->save();
            $this->assertSame(1, $this->rawCount($db, 'sd_item'));
            $this->assertTrue($row->forceDelete());
            // Physically removed: gone from the raw table AND from withTrashed().
            $this->assertSame(0, $this->rawCount($db, 'sd_item'));
            $this->assertCount(0, (new SdItem($db))->withTrashed());
        } finally {
            $this->drop($db, 'sd_item');
        }
    }

    // ── createTable() INJECTS the column (SOFTDEL-CREATETABLE-COLUMN) ─────────

    #[DataProvider('engines')]
    public function testCreateTableInjectsAUsableIsDeletedColumnForASoftDeleteModel(string $engine): void
    {
        $db = $this->engineDb($engine);
        ORM::bindDatabase($db);
        $this->drop($db, 'sd_auto');
        // The model declares NO is_deleted; createTable() must inject it.
        $this->assertTrue((new SdAuto($db))->createTable());
        try {
            $cols = array_map(
                static fn (array $c): string => strtolower((string) $c['name']),
                $db->getColumns('sd_auto')
            );
            $this->assertContains('is_deleted', $cols);       // injected

            // The injected column is real and usable: a full round-trip works.
            $row = new SdAuto($db, ['title' => 'auto']);
            $row->save();
            $this->assertTrue($row->delete());                // soft-flag on injected col
            $this->assertSame(1, $this->rawCount($db, 'sd_auto'));   // row still present
            $this->assertCount(0, (new SdAuto($db))->all());        // excluded from finder
            $this->assertCount(1, (new SdAuto($db))->withTrashed()); // visible with_trashed
            $this->assertTrue($row->restore());
            $this->assertCount(1, (new SdAuto($db))->all());        // reappears
            $this->assertTrue($row->forceDelete());
            $this->assertSame(0, $this->rawCount($db, 'sd_auto'));   // hard-removed
        } finally {
            $this->drop($db, 'sd_auto');
        }
    }
}
