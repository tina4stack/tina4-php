<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Seeder + fake-data cross-engine contract — feature 28 (seeder_contract.json),
 * parity with tina4-python/tests/test_seeder_contract.py.
 *
 * SEED-DEC-01 (OWNER-DECISIONS.md Batch 4, feature 028-seeder-fake-data.md):
 *   - SEED-PHP-BACKTICK: FakeData::seedTable() quoted identifiers with
 *     BACKTICKS (MySQL/SQLite only), so every INSERT raised a syntax error on
 *     PostgreSQL/Firebird (double-quote) and MSSQL (brackets) — the dev-admin
 *     POST /__dev/api/seed endpoint delegates to seedTable, so dashboard
 *     seeding was BROKEN on those three engines. Fixed by routing seedTable
 *     through the PARAMETERIZED Database::insert() adapter path (the same one
 *     seedOrm already used via ORM::save()), deleting the raw-SQL
 *     backtick-building code entirely.
 *   - SEED-TABLE-SEED-INERT: seedTable's $seed argument was a silent no-op
 *     (it has no generators of its own — $fieldMap callables are opaque).
 *     OWNER-DECISIONS.md ratified REMOVAL (same principle as the no-op
 *     ForeignKeyField on_delete): $seed now THROWS if non-null, and
 *     determinism is achieved by the caller building their own seeded
 *     FakeData and closing over it in $fieldMap — exactly the pattern
 *     seedOrm/seedModels already use internally.
 *
 * seed_table_inserts_on_every_engine is the ONLY case that must run on all
 * three non-SQLite engines — it is the direct regression guard for
 * SEED-PHP-BACKTICK. The other cases are seeder-LOGIC properties (RNG
 * determinism, FK topo-order, failure counting) that are engine-agnostic once
 * routed through the already adapter-contract-proven insert()/ORM::save()
 * paths, so they run on SQLite + PostgreSQL for a real-engine sanity check
 * without re-proving the adapter contracts a second time.
 *
 * Mutation-proof (exercised manually during release verification, restored
 * after): reinstate the backtick-quoted raw SQL in seedTable() —
 * seed_table_inserts_on_every_engine goes RED on PostgreSQL/MSSQL/Firebird.
 */

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\FakeData;
use Tina4\ORM;

// ── ORM fixtures (file scope — PHP ORM subclasses can't be nested) ─────────

class SeederContractPerson extends ORM
{
    public string $tableName = 'seedercontract_person';
    public string $primaryKey = 'id';
    public int $id = 0;
    public string $name = '';
    public string $email = '';
    public int $age = 0;
}

class SeederContractAuthor extends ORM
{
    public string $tableName = 'seedercontract_author';
    public string $primaryKey = 'id';
    public int $id = 0;
    public string $name = '';
}

class SeederContractBook extends ORM
{
    public string $tableName = 'seedercontract_book';
    public string $primaryKey = 'id';
    public array $foreignKeys = ['author_id' => 'SeederContractAuthor'];
    public int $id = 0;
    public string $title = '';
    public int $authorId = 0;
}

class SeederContractTest extends TestCase
{
    private static ?ORM $savedGlobalDb = null;

    protected function setUp(): void
    {
        self::$savedGlobalDb = ORM::getGlobalDb();
        $this->resetBindings();
    }

    protected function tearDown(): void
    {
        $this->resetBindings();
        if (self::$savedGlobalDb !== null) {
            ORM::bindDatabase(self::$savedGlobalDb);
        }
    }

    private function resetBindings(): void
    {
        $ref = new \ReflectionClass(ORM::class);
        $ref->getProperty('_globalDb')->setValue(null, null);
        $ref->getProperty('_namedDbs')->setValue(null, []);
    }

    // ── real-service coordinates (the canonical TINA4_TEST_* convention) ───

    private static function pgUrl(): string
    {
        $host = getenv('TINA4_TEST_PG_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TINA4_TEST_PG_PORT') ?: 55432);
        $db   = getenv('TINA4_TEST_PG_DB') ?: 'tina4_php';
        $user = getenv('TINA4_TEST_PG_USERNAME') ?: 'tina4';
        $pass = getenv('TINA4_TEST_PG_PASSWORD') ?: 'tina4';
        return "postgres://{$user}:{$pass}@{$host}:{$port}/{$db}";
    }

    private static function mssqlUrl(): string
    {
        $host = getenv('TINA4_TEST_MSSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TINA4_TEST_MSSQL_PORT') ?: 1433);
        $db   = getenv('TINA4_TEST_MSSQL_DB') ?: 'tina4_test';
        return "mssql://{$host}:{$port}/{$db}";
    }

    private static function mssqlUser(): string
    {
        return getenv('TINA4_TEST_MSSQL_USERNAME') ?: 'sa';
    }

    private static function mssqlPass(): string
    {
        $p = getenv('TINA4_TEST_MSSQL_PASSWORD');
        return ($p === false || $p === '') ? 'TinaSQL123!Secure' : $p;
    }

    private static function firebirdUrl(): string
    {
        return getenv('TINA4_TEST_FIREBIRD_URL') ?: '';
    }

    private static function tcpReachable(string $host, int $port): bool
    {
        $conn = @fsockopen($host, $port, $errno, $errstr, 2.0);
        if ($conn === false) {
            return false;
        }
        fclose($conn);
        return true;
    }

    private function skipUnlessPgReachable(): void
    {
        $host = getenv('TINA4_TEST_PG_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TINA4_TEST_PG_PORT') ?: 55432);
        if (!self::tcpReachable($host, $port)) {
            $this->markTestSkipped("no reachable postgres at {$host}:{$port} (set TINA4_TEST_PG_*)");
        }
    }

    private function skipUnlessMssqlReachable(): void
    {
        if (!function_exists('sqlsrv_connect') && !in_array('dblib', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped(
                'MSSQL client not installed — neither ext-sqlsrv nor ext-pdo_dblib (FreeTDS) is available'
            );
        }
        $host = getenv('TINA4_TEST_MSSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TINA4_TEST_MSSQL_PORT') ?: 1433);
        if (!self::tcpReachable($host, $port)) {
            $this->markTestSkipped("MSSQL not reachable at {$host}:{$port} (set TINA4_TEST_MSSQL_*)");
        }
    }

    private function skipUnlessFirebirdConfigured(): string
    {
        $url = self::firebirdUrl();
        if ($url === '') {
            $this->markTestSkipped('TINA4_TEST_FIREBIRD_URL not set (needs a live Firebird)');
        }
        return $url;
    }

    private function sqliteDb(string $path): Database
    {
        return Database::create('sqlite:///' . $path);
    }

    private function pgDb(): Database
    {
        return Database::create(self::pgUrl());
    }

    private function mssqlDb(): Database
    {
        return Database::create(self::mssqlUrl(), username: self::mssqlUser(), password: self::mssqlPass());
    }

    private function firebirdDb(string $url): Database
    {
        return Database::create($url);
    }

    private function dropAll(Database $db, string ...$statements): void
    {
        foreach ($statements as $sql) {
            try {
                $db->execute($sql);
            } catch (\Throwable) {
                // best effort — tolerant of "does not exist" on a fresh DB
            }
        }
    }

    // ── seed_table_inserts_on_every_engine ──────────────────────────────
    // Catches SEED-PHP-BACKTICK: creates the table with each engine's real
    // DDL, seeds 5 rows through seedTable (raw-SQL INSERT path — no ORM),
    // and reads every row back on the SAME engine.

    private function assertSeedTableRoundtrips(Database $db, string $table, array $setup, array $drop): void
    {
        $this->dropAll($db, ...$drop);
        try {
            foreach ($setup as $sql) {
                $db->execute($sql);
            }
            $fake = new FakeData(1);
            $summary = FakeData::seedTable($db, $table, 5, [
                'name' => fn() => $fake->name(),
                'score' => fn() => $fake->integer(1, 100),
            ]);
            $this->assertSame(5, $summary->seeded, "{$table}: seeded={$summary->seeded} errors=" . json_encode($summary->errors));
            $this->assertSame(0, $summary->failed);
            $rows = $db->fetch("SELECT name, score FROM {$table}", [], 100)->records;
            $this->assertCount(5, $rows);
            foreach ($rows as $row) {
                $this->assertNotEmpty($row['name']);
                $this->assertGreaterThanOrEqual(1, (int) $row['score']);
                $this->assertLessThanOrEqual(100, (int) $row['score']);
            }
        } finally {
            $this->dropAll($db, ...$drop);
            $db->close();
        }
    }

    public function testSeedTableInsertsOnEveryEngineSqlite(): void
    {
        $table = 'contract_sqlite';
        $tmp = sys_get_temp_dir() . '/tina4_seeder_contract_' . uniqid() . '.db';
        $this->assertSeedTableRoundtrips(
            $this->sqliteDb($tmp),
            $table,
            ["CREATE TABLE {$table} (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, score INTEGER)"],
            ["DROP TABLE {$table}"],
        );
        @unlink($tmp);
    }

    public function testSeedTableInsertsOnEveryEnginePostgresql(): void
    {
        $this->skipUnlessPgReachable();
        $table = 'contract_pg';
        $this->assertSeedTableRoundtrips(
            $this->pgDb(),
            $table,
            ["CREATE TABLE {$table} (id SERIAL PRIMARY KEY, name VARCHAR(100), score INTEGER)"],
            ["DROP TABLE {$table}"],
        );
    }

    public function testSeedTableInsertsOnEveryEngineMssql(): void
    {
        $this->skipUnlessMssqlReachable();
        $table = 'contract_mssql';
        $this->assertSeedTableRoundtrips(
            $this->mssqlDb(),
            $table,
            ["CREATE TABLE {$table} (id INTEGER IDENTITY(1,1) PRIMARY KEY, name VARCHAR(100), score INTEGER)"],
            ["DROP TABLE {$table}"],
        );
    }

    public function testSeedTableInsertsOnEveryEngineFirebird(): void
    {
        $url = $this->skipUnlessFirebirdConfigured();
        $table = 'contract_fb';
        // Firebird has no AUTOINCREMENT — the real idiom is a generator + a
        // BEFORE INSERT trigger (same pattern FirebirdProviderContractTest
        // uses), so `id` is assigned without seedTable needing to know it.
        $this->assertSeedTableRoundtrips(
            $this->firebirdDb($url),
            $table,
            [
                "CREATE TABLE {$table} (id INTEGER NOT NULL PRIMARY KEY, name VARCHAR(100), score INTEGER)",
                "CREATE GENERATOR gen_{$table}_id",
                "CREATE TRIGGER {$table}_bi FOR {$table} ACTIVE BEFORE INSERT POSITION 0 "
                . "AS BEGIN IF (NEW.id IS NULL) THEN NEW.id = GEN_ID(gen_{$table}_id, 1); END",
            ],
            ["DROP TRIGGER {$table}_bi", "DROP TABLE {$table}", "DROP GENERATOR gen_{$table}_id"],
        );
    }

    // ── seeded_run_reproduces_identical_rows ────────────────────────────
    // seedOrm/seedModels: their OWN $seed is deterministic (unchanged).
    // seedTable: no longer HAS a $seed (removed) — the documented
    // replacement is a caller-seeded FakeData closed over in $fieldMap.

    public function testSeededRunReproducesIdenticalRowsSeedOrm(): void
    {
        $run = function (string $path): array {
            $db = Database::create('sqlite:///' . $path);
            ORM::bindDatabase($db);
            $db->execute(
                'CREATE TABLE seedercontract_person (id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'name TEXT, email TEXT, age INTEGER)'
            );
            FakeData::seedOrm(SeederContractPerson::class, 6, [], false, 4242);
            $rows = $db->fetch('SELECT name, email, age FROM seedercontract_person ORDER BY id', [], 1000)->records;
            $db->close();
            return $rows;
        };

        $tmpA = sys_get_temp_dir() . '/tina4_seedorm_a_' . uniqid() . '.db';
        $tmpB = sys_get_temp_dir() . '/tina4_seedorm_b_' . uniqid() . '.db';
        $a = $run($tmpA);
        $b = $run($tmpB);
        @unlink($tmpA);
        @unlink($tmpB);

        $this->assertSame($a, $b);
        $this->assertCount(6, $a);
    }

    public function testSeededRunReproducesIdenticalRowsSeedTable(): void
    {
        // Replacement pattern for the removed seedTable($seed): the caller
        // builds its OWN seeded FakeData and closes over it in $fieldMap.
        $run = function (string $path): array {
            $db = Database::create('sqlite:///' . $path);
            $db->execute(
                'CREATE TABLE contract_seedtable_raw (id INTEGER PRIMARY KEY AUTOINCREMENT, '
                . 'name TEXT, score INTEGER)'
            );
            $fake = new FakeData(777);
            FakeData::seedTable($db, 'contract_seedtable_raw', 6, [
                'name' => fn() => $fake->name(),
                'score' => fn() => $fake->integer(1, 1000),
            ]);
            $rows = $db->fetch('SELECT name, score FROM contract_seedtable_raw ORDER BY id', [], 1000)->records;
            $db->close();
            return $rows;
        };

        $tmpA = sys_get_temp_dir() . '/tina4_seedtable_a_' . uniqid() . '.db';
        $tmpB = sys_get_temp_dir() . '/tina4_seedtable_b_' . uniqid() . '.db';
        $a = $run($tmpA);
        $b = $run($tmpB);
        @unlink($tmpA);
        @unlink($tmpB);

        $this->assertSame($a, $b);
        $this->assertCount(6, $a);
    }

    public function testSeededRunReproducesIdenticalRowsSeedTableSeedParamThrows(): void
    {
        // SEED-TABLE-SEED-INERT fix, mutation witness: seedTable's OWN $seed
        // is gone — passing a real value now THROWS instead of silently
        // doing nothing.
        $tmp = sys_get_temp_dir() . '/tina4_seedtable_throws_' . uniqid() . '.db';
        $db = Database::create('sqlite:///' . $tmp);
        $db->execute('CREATE TABLE contract_seedtable_throws (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');

        $this->expectException(\InvalidArgumentException::class);
        try {
            FakeData::seedTable($db, 'contract_seedtable_throws', 1, ['name' => fn() => 'x'], [], false, 99);
        } finally {
            $db->close();
            @unlink($tmp);
        }
    }

    public function testSeededRunReproducesIdenticalRowsPostgresql(): void
    {
        $this->skipUnlessPgReachable();

        $run = function (string $table): array {
            $db = $this->pgDb();
            $this->dropAll($db, "DROP TABLE {$table}");
            $db->execute("CREATE TABLE {$table} (id SERIAL PRIMARY KEY, name VARCHAR(100), score INTEGER)");
            $fake = new FakeData(555);
            FakeData::seedTable($db, $table, 5, [
                'name' => fn() => $fake->name(),
                'score' => fn() => $fake->integer(1, 100),
            ]);
            $rows = $db->fetch("SELECT name, score FROM {$table} ORDER BY id", [], 100)->records;
            $this->dropAll($db, "DROP TABLE {$table}");
            $db->close();
            return $rows;
        };

        $a = $run('contract_repro_pg_a');
        $b = $run('contract_repro_pg_b');
        $this->assertSame($a, $b);
        $this->assertCount(5, $a);
    }

    // ── seed_models_orders_parents_before_children ──────────────────────

    public function testSeedModelsOrdersParentsBeforeChildrenSqlite(): void
    {
        $tmp = sys_get_temp_dir() . '/tina4_seedmodels_' . uniqid() . '.db';
        $db = Database::create('sqlite:///' . $tmp);
        ORM::bindDatabase($db);
        $db->execute('CREATE TABLE seedercontract_author (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $db->execute(
            'CREATE TABLE seedercontract_book (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, '
            . 'author_id INTEGER NOT NULL REFERENCES seedercontract_author(id))'
        );

        // Children declared BEFORE parents on purpose — topo-sort must fix it.
        $results = FakeData::seedModels([SeederContractBook::class, SeederContractAuthor::class], 5, [], false, 3);

        $this->assertSame(5, $results['SeederContractAuthor']->seeded);
        $this->assertSame(0, $results['SeederContractAuthor']->failed);
        $this->assertSame(5, $results['SeederContractBook']->seeded);
        $this->assertSame(0, $results['SeederContractBook']->failed, 'children should reference real parents — no FK violations');

        $orphans = $db->fetch(
            'SELECT * FROM seedercontract_book b LEFT JOIN seedercontract_author a ON b.author_id = a.id '
            . 'WHERE a.id IS NULL',
            [],
            100,
        );
        $this->assertSame(0, $orphans->count);
        $db->close();
        @unlink($tmp);
    }

    public function testSeedModelsOrdersParentsBeforeChildrenPostgresql(): void
    {
        $this->skipUnlessPgReachable();
        $db = $this->pgDb();
        ORM::bindDatabase($db);
        $this->dropAll($db, 'DROP TABLE seedercontract_book', 'DROP TABLE seedercontract_author');
        $db->execute('CREATE TABLE seedercontract_author (id SERIAL PRIMARY KEY, name VARCHAR(100))');
        $db->execute(
            'CREATE TABLE seedercontract_book (id SERIAL PRIMARY KEY, title VARCHAR(100), '
            . 'author_id INTEGER NOT NULL REFERENCES seedercontract_author(id))'
        );

        try {
            $results = FakeData::seedModels([SeederContractBook::class, SeederContractAuthor::class], 5, [], false, 9);

            $this->assertSame(5, $results['SeederContractAuthor']->seeded);
            $this->assertSame(0, $results['SeederContractAuthor']->failed);
            $this->assertSame(5, $results['SeederContractBook']->seeded);
            $this->assertSame(0, $results['SeederContractBook']->failed);

            $orphans = $db->fetch(
                'SELECT * FROM seedercontract_book b LEFT JOIN seedercontract_author a ON b.author_id = a.id '
                . 'WHERE a.id IS NULL',
                [],
                100,
            );
            $this->assertSame(0, $orphans->count);
        } finally {
            $this->dropAll($db, 'DROP TABLE seedercontract_book', 'DROP TABLE seedercontract_author');
            $db->close();
        }
    }

    // ── failures_are_counted_not_silent ──────────────────────────────────

    public function testFailuresAreCountedNotSilentSqlite(): void
    {
        $tmp = sys_get_temp_dir() . '/tina4_fail_' . uniqid() . '.db';
        $db = Database::create('sqlite:///' . $tmp);
        $db->execute(
            'CREATE TABLE contract_fail (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT NOT NULL)'
        );

        // email is NOT NULL but every generated value is null -> every INSERT
        // violates the constraint — never silent, never a crash.
        $summary = FakeData::seedTable($db, 'contract_fail', 4, [
            'name' => fn() => 'someone',
            'email' => null,
        ]);

        $this->assertSame(0, $summary->seeded);
        $this->assertSame(4, $summary->failed);
        $this->assertCount(4, $summary->errors);
        $this->assertSame(0, $db->fetch('SELECT * FROM contract_fail', [], 100)->count);
        $db->close();
        @unlink($tmp);
    }

    public function testFailuresAreCountedNotSilentStrictReraises(): void
    {
        $tmp = sys_get_temp_dir() . '/tina4_fail_strict_' . uniqid() . '.db';
        $db = Database::create('sqlite:///' . $tmp);
        $db->execute('CREATE TABLE contract_fail_strict (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL)');

        $this->expectException(\Throwable::class);
        try {
            FakeData::seedTable($db, 'contract_fail_strict', 3, ['email' => null], [], false, null, true);
        } finally {
            $db->close();
            @unlink($tmp);
        }
    }

    public function testFailuresAreCountedNotSilentPostgresql(): void
    {
        $this->skipUnlessPgReachable();
        $db = $this->pgDb();
        $table = 'contract_fail_pg';
        $this->dropAll($db, "DROP TABLE {$table}");
        $db->execute("CREATE TABLE {$table} (id SERIAL PRIMARY KEY, email VARCHAR(100) NOT NULL)");

        try {
            $summary = FakeData::seedTable($db, $table, 4, ['email' => null]);
            $this->assertSame(0, $summary->seeded);
            $this->assertSame(4, $summary->failed);
            $this->assertSame(0, $db->fetch("SELECT * FROM {$table}", [], 100)->count);
        } finally {
            $this->dropAll($db, "DROP TABLE {$table}");
            $db->close();
        }
    }

    // ── generator_vocabulary_present ─────────────────────────────────────
    // SEED-VOCAB-PARITY: this exact generator set (idiomatic spelling per
    // language) exists in all four — Python/PHP/Ruby/Node.

    private const GENERATOR_VOCABULARY = [
        'name', 'firstName', 'lastName', 'email', 'phone', 'address', 'city',
        'country', 'zipCode', 'company', 'jobTitle', 'sentence', 'paragraph',
        'text', 'word', 'integer', 'numeric', 'boolean', 'date', 'datetime',
        'uuid', 'url', 'colorHex', 'currency', 'ipAddress', 'creditCard',
        'choice', 'forField',
    ];

    public function testGeneratorVocabularyPresent(): void
    {
        $fake = new FakeData(1);
        foreach (self::GENERATOR_VOCABULARY as $generatorName) {
            $this->assertTrue(method_exists($fake, $generatorName), "FakeData missing generator: {$generatorName}");
        }

        $this->assertNotEmpty($fake->name());
        $this->assertNotEmpty($fake->firstName());
        $this->assertNotEmpty($fake->lastName());
        $this->assertStringContainsString('@', $fake->email());
        $this->assertNotEmpty($fake->phone());
        $this->assertNotEmpty($fake->address());
        $this->assertNotEmpty($fake->city());
        $this->assertNotEmpty($fake->country());
        $this->assertNotEmpty($fake->zipCode());
        $this->assertNotEmpty($fake->company());
        $this->assertNotEmpty($fake->jobTitle());
        $this->assertNotEmpty($fake->sentence());
        $this->assertNotEmpty($fake->paragraph());
        $this->assertNotEmpty($fake->text());
        $this->assertNotEmpty($fake->word());
        $this->assertIsInt($fake->integer(1, 10));
        $this->assertIsFloat($fake->numeric(0, 10));
        $this->assertIsBool($fake->boolean());
        $this->assertNotEmpty($fake->date());
        $this->assertNotEmpty($fake->datetime());
        $this->assertNotEmpty($fake->uuid());
        $this->assertStringStartsWith('https://', $fake->url());
        $this->assertStringStartsWith('#', $fake->colorHex());
        $this->assertSame(3, strlen($fake->currency()));
        $this->assertSame(3, substr_count($fake->ipAddress(), '.'));
        $this->assertNotEmpty($fake->creditCard());
        $this->assertContains($fake->choice([1, 2, 3]), [1, 2, 3]);
        $this->assertNotNull($fake->forField(['type' => 'string']));
    }
}
