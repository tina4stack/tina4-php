<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Lock-in regression tests for the v3.13.39 ORM "fail loud, never silent" pass.
 *
 * These contracts guard the ORM / QueryBuilder failure paths against silent
 * drift. The principle (the same one Database::execute() already follows by
 * RAISING): a failure must never vanish silently — save()/create() return the
 * documented false, get()/toMongo() refuse to truncate or to emit a surprise
 * sink, AND the real cause stays recoverable. If any of these behaviours
 * regresses to the old silent form, one of these named tests goes red.
 *
 * Mirrors tina4-python/tests/test_orm_contracts.py (the Python master), adapted
 * to PHP — plus two PHP-outlier lock-ins (createTable string-PK + the
 * class-name-derived defaultForeignKey) that have no Python counterpart.
 *
 * Engine-agnostic: in-memory SQLite, so it runs with no DB server.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\SQLite3Adapter;
use Tina4\ORM;
use Tina4\QueryBuilder;

// ── Test Models ──────────────────────────────────────────────────────────────

/**
 * A user with a required `name`. The DB column is NOT NULL, and validate() is
 * overridden to require it in the model too — so we can exercise BOTH the
 * validation path (model rejects) and the DB-error path (driver rejects) in
 * isolation.
 */
class ContractUser extends ORM
{
    public string $tableName  = 'cusers';
    public string $primaryKey = 'id';
    public bool   $autoMap    = true;

    public ?int    $id    = null;
    public ?string $name  = null;
    public ?string $email = null;

    public function validate(): array
    {
        $errors = [];
        if ($this->name === null || $this->name === '') {
            $errors[] = 'name is required';
        }
        return $errors;
    }
}

/**
 * Same as ContractUser but with the DEFAULT (no-op) validate() — used for the
 * DB-error path, where the model passes validation but the driver still rejects
 * the write (NOT NULL violation the validator does not catch).
 */
class ContractUserNoValidate extends ORM
{
    public string $tableName  = 'cusers';
    public string $primaryKey = 'id';
    public bool   $autoMap    = true;

    public ?int    $id    = null;
    public ?string $name  = null;
    public ?string $email = null;
}

/**
 * A widget — a plain table used for QueryBuilder no-limit tests.
 */
class ContractWidget extends ORM
{
    public string $tableName  = 'cwidgets';
    public string $primaryKey = 'id';
    public bool   $autoMap    = true;

    public ?int    $id    = null;
    public ?string $label = null;
}

/**
 * Points at a table that is never created — every save() hits a driver error
 * ("no such table"), exercising create()'s failure propagation through the
 * DB-error path (construction + validate both succeed).
 */
class ContractGhost extends ORM
{
    public string $tableName  = 'cghost_missing';
    public string $primaryKey = 'id';
    public bool   $autoMap    = true;

    public ?int    $id    = null;
    public ?string $label = null;
}

/**
 * A model with a DECLARED string primary key (`code`). createTable() must NOT
 * coerce this into an autoincrement INTEGER — it must emit the declared string
 * type + PRIMARY KEY and no AUTOINCREMENT.
 */
class ContractStringPkModel extends ORM
{
    public string $tableName  = 'cstring_pk';
    public string $primaryKey = 'code';
    public bool   $autoMap    = true;

    public ?string $code = null;
    public ?string $name = null;
}

class OrmContractsTest extends TestCase
{
    private SQLite3Adapter $db;

    protected function setUp(): void
    {
        $this->db = new SQLite3Adapter(':memory:');
        // cusers.name is NOT NULL → the driver-level constraint for the DB-error path.
        $this->db->exec(
            'CREATE TABLE cusers (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT)'
        );
        $this->db->exec(
            'CREATE TABLE cwidgets (id INTEGER PRIMARY KEY AUTOINCREMENT, label TEXT)'
        );
        ORM::bindDatabase($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    // ── 1. save() loud on a DB error ─────────────────────────────────────────

    /**
     * Baseline: a valid save() returns $this (fluent) and clears getError() to
     * null.
     */
    public function testValidSaveReturnsSelfAndClearsError(): void
    {
        $user = new ContractUser($this->db, ['name' => 'Alice']);

        $result = $user->save();

        $this->assertInstanceOf(
            ContractUser::class,
            $result,
            'A valid save() must return the fluent model instance.'
        );
        $this->assertSame($user, $result, 'save() returns $this on success.');
        $this->assertNull($user->getError(), 'getError() is cleared on a successful save().');
        $this->assertNull($user->lastError, 'lastError is cleared on a successful save().');
        $this->assertSame(1, $user->count(), 'The row really landed.');
    }

    /**
     * A genuine driver error (NOT NULL violation that validation does not catch)
     * must fail loud: save() returns false, the real cause is recoverable via
     * getError()/lastError, and nothing is committed (row count unchanged).
     *
     * ContractUserNoValidate uses the default no-op validate(), so the model
     * passes validation but the NOT NULL `name` column makes the driver — not
     * the validator — reject the write. This isolates the DB-error path from the
     * validation path.
     */
    public function testSaveIsLoudAndRecoverableOnDbError(): void
    {
        $before = (new ContractUserNoValidate($this->db))->count();

        $bad = new ContractUserNoValidate($this->db);
        $bad->name = null; // NOT NULL column left null — driver rejects on INSERT

        $result = $bad->save();

        $this->assertFalse($result, 'save() must return false (loud) on a driver error.');
        $this->assertNotNull($bad->getError(), 'The real cause must be recoverable via getError().');
        $this->assertNotSame('', $bad->getError(), 'getError() must be non-empty.');
        $this->assertNotNull($bad->lastError, 'The cause is also recorded on lastError.');
        $this->assertSame($bad->getError(), $bad->lastError, 'getError() and lastError agree.');
        $this->assertSame($bad->getError(), $bad->error(), 'error() aliases getError().');
        $this->assertSame(
            $before,
            (new ContractUserNoValidate($this->db))->count(),
            'A failed save() must roll back — nothing committed.'
        );
    }

    // ── 2. save() enforces validate() BEFORE any DB write ────────────────────

    /**
     * A model failing validate() must not reach the driver: save() returns
     * false, getError() is non-empty, and NO row is written (count unchanged) —
     * proving validate() ran before any DB write.
     */
    public function testSaveEnforcesValidationBeforeWrite(): void
    {
        $before = (new ContractUser($this->db))->count();

        $invalid = new ContractUser($this->db, ['name' => 'temp']);
        $invalid->name = null; // now violates the model's validate() rule

        // Sanity: validate() itself reports the error.
        $this->assertNotEmpty(
            $invalid->validate(),
            'validate() should report the missing required field.'
        );

        $result = $invalid->save();

        $this->assertFalse($result, 'save() must return false when validate() fails.');
        $this->assertNotNull($invalid->getError(), 'The validation cause is recoverable.');
        $this->assertStringContainsStringIgnoringCase(
            'required',
            (string) $invalid->getError(),
            'getError() carries the validation message.'
        );
        $this->assertSame(
            $before,
            (new ContractUser($this->db))->count(),
            'A validation failure must write NOTHING — validate() ran before any DB write.'
        );
    }

    // ── 3. create() propagates failure ───────────────────────────────────────

    /**
     * create() on a model whose save() fails (missing table) returns false (not
     * an instance) — a failed insert can never masquerade as a success.
     */
    public function testCreateReturnsFalseWhenSaveFails(): void
    {
        $result = ContractGhost::create(['label' => 'x']);

        $this->assertFalse(
            $result,
            'create() must propagate save()\'s failure as false, never a possibly-unsaved instance.'
        );
    }

    /**
     * The happy path is unchanged: a valid create() returns the saved instance
     * with its PK assigned.
     */
    public function testCreateReturnsInstanceOnSuccess(): void
    {
        $user = ContractUser::create(['name' => 'Valid']);

        $this->assertInstanceOf(ContractUser::class, $user);
        $this->assertNotNull($user->getPrimaryKeyValue(), 'The PK was backfilled on insert.');
        $this->assertSame(1, $user->count());
    }

    // ── 4. QueryBuilder get() has no silent cap ──────────────────────────────

    /**
     * No silent default LIMIT 100. Insert 150 rows, get() with no ->limit() must
     * return all 150 — not a silently-truncated 100.
     */
    public function testGetReturnsAllRowsWithNoLimit(): void
    {
        for ($i = 0; $i < 150; $i++) {
            $this->db->exec('INSERT INTO cwidgets (label) VALUES (:label)', [':label' => "w{$i}"]);
        }

        $result = QueryBuilder::fromTable('cwidgets', $this->db)->get();

        $this->assertArrayHasKey('data', $result);
        $this->assertCount(
            150,
            $result['data'],
            'get() with no ->limit() must return ALL rows — no silent LIMIT 100.'
        );
    }

    /**
     * An explicit ->limit(n) still caps the result — only the silent default was
     * removed.
     */
    public function testExplicitLimitIsStillHonoured(): void
    {
        for ($i = 0; $i < 150; $i++) {
            $this->db->exec('INSERT INTO cwidgets (label) VALUES (:label)', [':label' => "w{$i}"]);
        }

        $result = QueryBuilder::fromTable('cwidgets', $this->db)->limit(10)->get();

        $this->assertCount(10, $result['data'], 'An explicit ->limit(10) still caps at 10.');
    }

    /**
     * toSql() must never inject a LIMIT clause on its own.
     */
    public function testToSqlInjectsNoDefaultLimit(): void
    {
        $sql = QueryBuilder::fromTable('cwidgets', $this->db)->toSql();

        $this->assertStringNotContainsStringIgnoringCase(
            'LIMIT',
            $sql,
            'toSql() must not inject a default LIMIT.'
        );
    }

    // ── 5. toMongo() throws on an unparseable WHERE ──────────────────────────

    /**
     * An unparseable WHERE must raise a clear InvalidArgumentException naming the
     * clause — never get silently wrapped into a {'$where': <raw JS>} sink.
     */
    public function testToMongoThrowsOnUnparseableWhere(): void
    {
        $qb = QueryBuilder::fromTable('cwidgets', $this->db)->where('label = label OR 1=1');

        try {
            $qb->toMongo();
            $this->fail('toMongo() must raise on an unparseable WHERE clause.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString(
                'label = label OR 1=1',
                $e->getMessage(),
                'The error must name the offending clause so the caller can fix it.'
            );
        }
    }

    /**
     * A parseable "field = ?" still translates to the expected filter — only the
     * silent fallback was removed.
     */
    public function testToMongoTranslatesParseableCondition(): void
    {
        $mongo = QueryBuilder::fromTable('cwidgets', $this->db)
            ->where('label = ?', ['x'])
            ->toMongo();

        $this->assertSame(['label' => 'x'], $mongo['filter']);
    }

    // ── 6. createTable() honours a DECLARED string primary key ───────────────

    /**
     * A model with a string PK must NOT get "INTEGER ... AUTOINCREMENT" forced.
     * The generated DDL must give the PK its declared string type with PRIMARY
     * KEY and no AUTOINCREMENT — coercing "GC-100" to an autoincrement integer 0
     * would be silent data corruption.
     */
    public function testCreateTableHonoursDeclaredStringPrimaryKey(): void
    {
        $model = new ContractStringPkModel($this->db);

        $this->assertTrue($model->createTable(), 'createTable() should succeed on SQLite.');
        $this->assertTrue($this->db->tableExists('cstring_pk'), 'the table must really exist.');

        // Inspect the real CREATE TABLE statement SQLite stored.
        $row = $this->db->fetchOne(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'cstring_pk'"
        );
        $this->assertNotNull($row, 'sqlite_master must record the created table.');
        $ddl = $row['sql'];

        // The PK column `code` must be a string type with PRIMARY KEY...
        $this->assertMatchesRegularExpression(
            '/\bcode\s+VARCHAR\(255\)\s+PRIMARY KEY/i',
            $ddl,
            'A declared string PK must keep its string type + PRIMARY KEY: ' . $ddl
        );
        // ...and must NOT have been forced to an autoincrement INTEGER.
        $this->assertStringNotContainsStringIgnoringCase(
            'AUTOINCREMENT',
            $ddl,
            'A declared string PK must NOT be forced to an AUTOINCREMENT integer: ' . $ddl
        );
        $this->assertStringNotContainsStringIgnoringCase(
            'code INTEGER',
            $ddl,
            'The string PK column must not be declared INTEGER: ' . $ddl
        );
    }

    // ── 7. defaultForeignKey() derives from the class name ───────────────────

    /**
     * defaultForeignKey() returns '<shortclassname-lowercased>_id' — proving the
     * FK default derives from the CLASS NAME, not a naive rtrim('s') of the table
     * name (which broke "people" → "people_id", "status" → "statu_id", and
     * disagreed with the auto-wired has-many key).
     */
    public function testDefaultForeignKeyDerivesFromClassName(): void
    {
        // For a class named "User" → "user_id" (NOT "users_id" from the table).
        $this->assertSame('contractuser_id', ORM::defaultForeignKey(ContractUser::class));
        $this->assertSame('contractuser_id', ORM::defaultForeignKey(new ContractUser($this->db)));

        // The short class name is used (namespace/path stripped), lowercased.
        $this->assertSame('contractstringpkmodel_id', ORM::defaultForeignKey(ContractStringPkModel::class));
    }
}
