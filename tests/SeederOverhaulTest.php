<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * SEEDING FULL OVERHAUL — lock-in regression guards.
 *
 * Mirrors the Python master's tests/test_seeder_overhaul.py:
 *   - error handling: a constraint-violating row returns {seeded, failed} with
 *     failed >= 1, does NOT crash, and does NOT silently report all-success;
 *     with strict=true it RAISES.
 *   - idempotency: seedTable(..., clear=true) run twice yields the same row
 *     count (no duplicates).
 *   - reproducibility: same seed → identical generated data across two runs.
 *   - FK ordering: a batch seeding two FK-related models in the "wrong" declared
 *     order still inserts parents before children (no FK violation).
 *
 * Engine-agnostic SQLite (no server). SQLite enforces FKs (PRAGMA
 * foreign_keys=ON in the adapter), so the FK-ordering test uses real
 * `REFERENCES seed_author(id)` columns.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Database\DatabaseAdapter;
use Tina4\FakeData;
use Tina4\SeedSummary;

// ── FK-ordering ORM models ──────────────────────────────────────────

/** Parent table. */
class SeedAuthor extends \Tina4\ORM
{
    public string $tableName = 'seed_author';
    public string $primaryKey = 'id';
    public int $id = 0;
    public string $name = '';
}

/**
 * Child table — declares a foreign key onto SeedAuthor. The FK column is
 * an int so getFieldDefinitions() flags it correctly, and the migration
 * below creates a real REFERENCES constraint so SQLite enforces it.
 */
class SeedBook extends \Tina4\ORM
{
    public string $tableName = 'seed_book';
    public string $primaryKey = 'id';
    public array $foreignKeys = ['author_id' => 'SeedAuthor'];
    public int $id = 0;
    public string $title = '';
    public int $authorId = 0;
}

class SeederOverhaulTest extends TestCase
{
    private static ?DatabaseAdapter $savedGlobalDb = null;
    private Database $db;

    protected function setUp(): void
    {
        self::$savedGlobalDb = \Tina4\ORM::getGlobalDb();
        $this->resetBindings();

        $this->db = Database::create('sqlite::memory:');
        \Tina4\ORM::bindDatabase($this->db);
    }

    protected function tearDown(): void
    {
        $this->resetBindings();
        if (self::$savedGlobalDb !== null) {
            \Tina4\ORM::bindDatabase(self::$savedGlobalDb);
        }
    }

    private function resetBindings(): void
    {
        $ref = new \ReflectionClass(\Tina4\ORM::class);
        $ref->getProperty('_globalDb')->setValue(null, null);
        $ref->getProperty('_namedDbs')->setValue(null, []);
    }

    // ── Test fixtures ───────────────────────────────────────────────

    /** A table with a NOT NULL email so a null email row violates a constraint. */
    private function createUsersTable(): void
    {
        $this->db->execute(
            'CREATE TABLE seed_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                email TEXT NOT NULL
            )'
        );
    }

    /** A table with a UNIQUE code so a duplicate seed value violates a constraint. */
    private function createCodesTable(): void
    {
        $this->db->execute(
            'CREATE TABLE seed_codes (
                id INTEGER PRIMARY KEY,
                code TEXT NOT NULL
            )'
        );
    }

    private function createFkTables(): void
    {
        $this->db->execute(
            'CREATE TABLE seed_author (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT
            )'
        );
        $this->db->execute(
            'CREATE TABLE seed_book (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT,
                author_id INTEGER NOT NULL REFERENCES seed_author(id)
            )'
        );
    }

    private function rowCount(string $table): int
    {
        $rows = $this->db->query("SELECT COUNT(*) AS c FROM {$table}");
        return (int) ($rows[0]['c'] ?? 0);
    }

    // ── P1 — error handling ─────────────────────────────────────────

    public function testSummaryShape(): void
    {
        $this->createUsersTable();
        $fake = new FakeData(1);
        $summary = FakeData::seedTable($this->db, 'seed_users', 5, [
            'name' => fn() => $fake->name(),
            'email' => fn() => $fake->email(),
        ]);

        $this->assertInstanceOf(SeedSummary::class, $summary);
        $this->assertSame(5, $summary->seeded);
        $this->assertSame(0, $summary->failed);
        $this->assertSame([], $summary->errors);

        // Array-access + count()/string back-compat (the value behaves as the
        // seeded count, mirroring Python's int-subclass).
        $this->assertSame(5, $summary['seeded']);
        $this->assertSame(0, $summary['failed']);
        $this->assertSame(5, count($summary));
        $this->assertSame('5', (string) $summary);
        $this->assertSame(
            ['seeded' => 5, 'failed' => 0, 'errors' => []],
            $summary->toArray()
        );
    }

    public function testConstraintViolationDoesNotCrashAndIsNotSilent(): void
    {
        $this->createUsersTable();

        // email is NOT NULL but we generate null for it on every row → every
        // INSERT violates the constraint. The old behaviour was either a crash
        // (Py/Node) or a silent all-success (PHP/Ruby). Now: visible failures.
        $summary = FakeData::seedTable($this->db, 'seed_users', 4, [
            'name' => fn() => 'Someone',
            'email' => null, // static null → constraint violation
        ]);

        // Did NOT crash, and is NOT silently all-success.
        $this->assertSame(0, $summary->seeded);
        $this->assertGreaterThanOrEqual(1, $summary->failed);
        $this->assertSame(4, $summary->failed);
        $this->assertCount(4, $summary->errors);
        $this->assertArrayHasKey('row', $summary->errors[0]);
        $this->assertArrayHasKey('message', $summary->errors[0]);
        $this->assertSame(0, $summary->errors[0]['row']);

        // No rows landed in the table.
        $this->assertSame(0, $this->rowCount('seed_users'));
    }

    public function testStrictReraisesOnFirstFailure(): void
    {
        $this->createUsersTable();

        $this->expectException(\Throwable::class);
        FakeData::seedTable($this->db, 'seed_users', 4, [
            'name' => fn() => 'Someone',
            'email' => null,
        ], [], false, null, true); // strict = true
    }

    public function testPartialSuccessCounts(): void
    {
        $this->createUsersTable();

        // Every other row has a null email → half succeed, half fail.
        $i = 0;
        $summary = FakeData::seedTable($this->db, 'seed_users', 6, [
            'name' => fn() => 'Someone',
            'email' => function () use (&$i) {
                $val = ($i % 2 === 0) ? 'ok@example.com' : null;
                $i++;
                return $val;
            },
        ]);

        $this->assertSame(3, $summary->seeded);
        $this->assertSame(3, $summary->failed);
        $this->assertSame(3, $this->rowCount('seed_users'));
    }

    public function testSeedOrmFalsySaveCountsAsFailure(): void
    {
        // seed_codes.code is UNIQUE; override `code` to a constant so every row
        // is a fresh INSERT (auto-increment PK) that collides on the UNIQUE
        // column from the 2nd row on. ORM::save() returns false (it rolls back
        // internally) — the seeder must count that as a failure, never as
        // silent success.
        $this->db->execute(
            'CREATE TABLE seed_codes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code TEXT NOT NULL UNIQUE
            )'
        );

        $summary = FakeData::seedOrm(SeedCode::class, 3, [
            'code' => 'SAME',          // duplicate UNIQUE value → 2nd+ fail
        ]);

        $this->assertInstanceOf(SeedSummary::class, $summary);
        $this->assertSame(1, $summary->seeded);
        $this->assertSame(2, $summary->failed);
        $this->assertSame(1, $this->rowCount('seed_codes'));
    }

    // ── P2 — idempotency ────────────────────────────────────────────

    public function testClearAvoidsDuplicatesOnRerun(): void
    {
        $this->createUsersTable();

        $field = [
            'name' => fn() => 'Someone',
            'email' => fn() => 'a@example.com',
        ];

        $first = FakeData::seedTable($this->db, 'seed_users', 5, $field, [], true);
        $this->assertSame(5, $first->seeded);
        $this->assertSame(5, $this->rowCount('seed_users'));

        // Re-run with clear=true — must wipe then re-seed → still 5, no dupes.
        $second = FakeData::seedTable($this->db, 'seed_users', 5, $field, [], true);
        $this->assertSame(5, $second->seeded);
        $this->assertSame(5, $this->rowCount('seed_users'));
    }

    public function testClearPreventsUniquePkViolation(): void
    {
        $this->createCodesTable();

        $field = ['id' => fn() => 1, 'code' => fn() => 'X'];

        // First run inserts a single row at id=1 (the rest collide and fail).
        $first = FakeData::seedTable($this->db, 'seed_codes', 1, $field);
        $this->assertSame(1, $first->seeded);
        $this->assertSame(1, $this->rowCount('seed_codes'));

        // Without clear, the second run would collide on PK=1 (fail). With
        // clear=true the prior row is deleted first, so it succeeds again.
        $second = FakeData::seedTable($this->db, 'seed_codes', 1, $field, [], true);
        $this->assertSame(1, $second->seeded);
        $this->assertSame(0, $second->failed);
        $this->assertSame(1, $this->rowCount('seed_codes'));
    }

    // ── P3 — reproducibility ────────────────────────────────────────

    public function testSameSeedIdenticalData(): void
    {
        $fake1 = new FakeData(12345);
        $run1 = [];
        for ($i = 0; $i < 10; $i++) {
            $run1[] = [$fake1->name(), $fake1->email(), $fake1->integer(1, 1000)];
        }

        $fake2 = new FakeData(12345);
        $run2 = [];
        for ($i = 0; $i < 10; $i++) {
            $run2[] = [$fake2->name(), $fake2->email(), $fake2->integer(1, 1000)];
        }

        $this->assertSame($run1, $run2);
    }

    public function testSeedOrmSeedParamReproducible(): void
    {
        // Two separate in-memory DBs, same seed → identical generated rows.
        $dbA = Database::create('sqlite::memory:');
        $dbB = Database::create('sqlite::memory:');

        $ddl = 'CREATE TABLE seed_person (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT,
                    email TEXT,
                    age INTEGER
                )';
        $dbA->execute($ddl);
        $dbB->execute($ddl);

        // Run A
        \Tina4\ORM::bindDatabase($dbA);
        $sumA = FakeData::seedOrm(SeedPerson::class, 8, [], false, 999);
        $this->assertSame(8, $sumA->seeded);
        $rowsA = $dbA->query('SELECT name, email, age FROM seed_person ORDER BY id');

        // Run B
        \Tina4\ORM::bindDatabase($dbB);
        $sumB = FakeData::seedOrm(SeedPerson::class, 8, [], false, 999);
        $this->assertSame(8, $sumB->seeded);
        $rowsB = $dbB->query('SELECT name, email, age FROM seed_person ORDER BY id');

        $this->assertSame($rowsA, $rowsB);
    }

    // ── P4a — FK ordering ───────────────────────────────────────────

    public function testWrongDeclaredOrderStillSeedsParentsFirst(): void
    {
        $this->createFkTables();

        // Pass the models in the WRONG order (child before parent). The topo
        // sort must reorder so SeedAuthor seeds before SeedBook, and the FK
        // pooling must point each book at a REAL author id — 0 failures, 0
        // orphans, no FK violation.
        $results = FakeData::seedModels(
            [SeedBook::class, SeedAuthor::class],
            5,
            [],
            false,
            42
        );

        $this->assertArrayHasKey('SeedAuthor', $results);
        $this->assertArrayHasKey('SeedBook', $results);
        $this->assertSame(5, $results['SeedAuthor']->seeded);
        $this->assertSame(0, $results['SeedAuthor']->failed);
        $this->assertSame(5, $results['SeedBook']->seeded);
        $this->assertSame(0, $results['SeedBook']->failed, 'children should reference real parents — no FK violations');

        $this->assertSame(5, $this->rowCount('seed_author'));
        $this->assertSame(5, $this->rowCount('seed_book'));

        // Every book.author_id references a real author (no orphans).
        $orphans = $this->db->query(
            'SELECT COUNT(*) AS c FROM seed_book b
             LEFT JOIN seed_author a ON b.author_id = a.id
             WHERE a.id IS NULL'
        );
        $this->assertSame(0, (int) ($orphans[0]['c'] ?? -1));
    }

    public function testClearRunsInReverseTopoOrder(): void
    {
        $this->createFkTables();

        // Seed once.
        FakeData::seedModels([SeedAuthor::class, SeedBook::class], 4, [], false, 7);
        $this->assertSame(4, $this->rowCount('seed_author'));
        $this->assertSame(4, $this->rowCount('seed_book'));

        // Re-seed with clear=true. Children (seed_book) must truncate BEFORE
        // parents (seed_author) — otherwise the FK constraint would block the
        // parent delete. After the run, counts are still 4 (no dupes, no
        // violation).
        $results = FakeData::seedModels([SeedAuthor::class, SeedBook::class], 4, [], true, 7);
        $this->assertSame(4, $results['SeedAuthor']->seeded);
        $this->assertSame(4, $results['SeedBook']->seeded);
        $this->assertSame(0, $results['SeedBook']->failed);
        $this->assertSame(4, $this->rowCount('seed_author'));
        $this->assertSame(4, $this->rowCount('seed_book'));
    }
}

// ── Extra ORM models referenced by the tests above ──────────────────

/** Natural-PK model used to prove a falsy save() is counted as a failure. */
class SeedCode extends \Tina4\ORM
{
    public string $tableName = 'seed_codes';
    public string $primaryKey = 'id';
    public int $id = 0;
    public string $code = '';
}

/** Plain model used by the reproducibility test. */
class SeedPerson extends \Tina4\ORM
{
    public string $tableName = 'seed_person';
    public string $primaryKey = 'id';
    public int $id = 0;
    public string $name = '';
    public string $email = '';
    public int $age = 0;
}
