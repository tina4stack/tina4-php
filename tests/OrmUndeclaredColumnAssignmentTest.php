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
 *
 * ADR-0069 G2 — a model that declares fields writes only those fields. A value
 * assigned to an undeclared property is not a column of the model and is never
 * written.
 *
 * Before this, save() dropped such a value with no trace, even when the property
 * named one of the table's real columns: a caller who set
 * `$company->loginUsername` on a model that declared only `$name` got a
 * successful save, a NULL column, and nothing in the log.
 *
 * Locked in here (the G2 rule itself is unchanged - see
 * IdentifierAllowListContractTest::testOrmSaveWritesOnlyDeclaredFields):
 *   - an undeclared property the caller set (plain assignment, fill(), or a
 *     change after a load) -> still not written, and save() logs a WARNING
 *     naming the property, once per model class and property per process
 *   - an undeclared value read back by any read path (a column the model does
 *     not declare, a join extra) and saved untouched -> no warning
 *   - a model that declares no fields -> the column is written, no warning
 *
 * NOT a mock: a real SQLite3Adapter, real DDL, real save()/reload round-trips,
 * and the real file log sink.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tina4\Database\SQLite3Adapter;
use Tina4\Log;
use Tina4\ORM;

// ── Test Models ──────────────────────────────────────────────────────────────

/** Declares only $name: login_username is a real column it does not declare. */
class UndeclaredColumnCompany extends ORM
{
    public string $tableName  = 'undeclared_company';
    public string $primaryKey = 'id';

    public ?int    $id   = null;
    public ?string $name = null;
}

/** Eager-loads its companies: the related rows are hydrated by the include path. */
class UndeclaredColumnOwner extends ORM
{
    public string $tableName  = 'undeclared_owner';
    public string $primaryKey = 'id';

    public ?int    $id   = null;
    public ?string $name = null;

    public array $hasMany = ['companies' => 'UndeclaredColumnCompany.owner_id'];
}

/** Declares nothing: its dynamic properties resolve against the real columns. */
class UndeclaredColumnCompanyUntyped extends ORM
{
    public string $tableName  = 'undeclared_company';
    public string $primaryKey = 'id';
}

class OrmUndeclaredColumnAssignmentTest extends TestCase
{
    private SQLite3Adapter $db;
    private string $logDir;

    protected function setUp(): void
    {
        $this->db = new SQLite3Adapter(':memory:');
        $this->db->exec(
            'CREATE TABLE undeclared_company (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                name           TEXT,
                login_username TEXT,
                owner_id       INTEGER
            )'
        );
        $this->db->exec('CREATE TABLE undeclared_owner (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        ORM::bindDatabase($this->db);

        $this->logDir = sys_get_temp_dir() . '/tina4_undeclared_' . uniqid();
        mkdir($this->logDir, 0755, true);
        Log::reset();
        putenv('TINA4_LOG_OUTPUT=file');
        putenv('TINA4_LOG_DIR=' . $this->logDir);
        Log::configure(level: 'warning');
        $this->resetWarned();
    }

    protected function tearDown(): void
    {
        $this->db->close();
        Log::reset();
        putenv('TINA4_LOG_OUTPUT');
        putenv('TINA4_LOG_DIR');
        $this->resetWarned();
        array_map('unlink', glob($this->logDir . '/*') ?: []);
        @rmdir($this->logDir);
    }

    /** Reset the private per-process warn-once ledger — real state, no mock. */
    private function resetWarned(): void
    {
        if (property_exists(ORM::class, 'undeclaredWarned')) {
            (new \ReflectionProperty(ORM::class, 'undeclaredWarned'))->setValue(null, []);
        }
    }

    /** @return array<int, string> WARNING lines about undeclared columns. */
    private function warnings(): array
    {
        $path = $this->logDir . '/tina4.log';
        $lines = is_file($path) ? explode("\n", (string) file_get_contents($path)) : [];
        return array_values(array_filter(
            $lines,
            static fn (string $l): bool => str_contains($l, 'WARNING') && str_contains($l, 'did not write')
        ));
    }

    private function assertWarnedOnce(string $property): void
    {
        $warnings = $this->warnings();
        $this->assertCount(1, $warnings, implode("\n", $warnings));
        $this->assertStringContainsString("'{$property}'", $warnings[0]);
        $this->assertStringContainsString(UndeclaredColumnCompany::class, $warnings[0]);
    }

    private function seed(string $login): int
    {
        $this->db->exec(
            "INSERT INTO undeclared_company (name, login_username) VALUES ('seed', ?)",
            [$login]
        );
        return (int) $this->db->fetchOne('SELECT MAX(id) AS id FROM undeclared_company')['id'];
    }

    private function login(int $id): mixed
    {
        return $this->db->fetchOne('SELECT login_username FROM undeclared_company WHERE id = ?', [$id])['login_username'];
    }

    // ── Warned: a caller's value for a real column the model does not declare ──

    public function testAssignedUndeclaredRealColumnIsDroppedWithAWarning(): void
    {
        $c = new UndeclaredColumnCompany();
        $c->name = 'Acme';
        $c->loginUsername = 'alice';

        $this->assertNotFalse($c->save(), 'save failed: ' . var_export($c->getError(), true));
        $this->assertNull($this->login((int) $c->id), 'G2: an undeclared property is not written');
        $this->assertWarnedOnce('loginUsername');
    }

    public function testFilledUndeclaredRealColumnWarns(): void
    {
        $c = new UndeclaredColumnCompany();
        $c->fill(['name' => 'Acme', 'login_username' => 'alice']);

        $this->assertNotFalse($c->save(), 'save failed: ' . var_export($c->getError(), true));
        $this->assertWarnedOnce('loginUsername');
    }

    public function testChangingAnUndeclaredRealColumnAfterLoadWarns(): void
    {
        $id = $this->seed('carol');
        $c = new UndeclaredColumnCompany();
        $this->assertTrue($c->load('id = ?', [$id]));
        $c->loginUsername = 'carol2';

        $this->assertNotFalse($c->save(), 'save failed: ' . var_export($c->getError(), true));
        $this->assertSame('carol', $this->login($id));
        $this->assertWarnedOnce('loginUsername');
    }

    public function testAssigningNullToALoadedUndeclaredRealColumnWarns(): void
    {
        $id = $this->seed('nina');
        $c = UndeclaredColumnCompany::findById($id);
        $c->loginUsername = null;

        $this->assertNotFalse($c->save(), 'save failed: ' . var_export($c->getError(), true));
        $this->assertSame('nina', $this->login($id));
        $this->assertWarnedOnce('loginUsername');
    }

    public function testWarnsOncePerModelPropertyAcrossSaves(): void
    {
        foreach (['a', 'b', 'c'] as $login) {
            $c = new UndeclaredColumnCompany();
            $c->name = 'Acme';
            $c->loginUsername = $login;
            $this->assertNotFalse($c->save(), 'save failed: ' . var_export($c->getError(), true));
        }
        $this->assertWarnedOnce('loginUsername');
    }

    // ── Silent: values the model did not take from the caller ───────────────

    public function testLoadThenSaveUntouchedDoesNotWarn(): void
    {
        $id = $this->seed('bob');
        $c = new UndeclaredColumnCompany();
        $this->assertTrue($c->load('id = ?', [$id]));
        $c->name = 'renamed';

        $this->assertNotFalse($c->save(), 'save failed: ' . var_export($c->getError(), true));
        $this->assertSame('bob', $this->login($id));
        $this->assertSame([], $this->warnings());
    }

    public function testFindByIdThenSaveUntouchedDoesNotWarn(): void
    {
        $id = $this->seed('hank');
        $c = UndeclaredColumnCompany::findById($id);
        $c->name = 'renamed';

        $this->assertNotFalse($c->save(), 'save failed: ' . var_export($c->getError(), true));
        $this->assertSame([], $this->warnings());
    }

    public function testRowsFromWhereSaveUntouchedWithoutWarning(): void
    {
        $this->seed('row');
        foreach ((new UndeclaredColumnCompany())->where('1 = 1') as $c) {
            $c->name = 'renamed';
            $this->assertNotFalse($c->save(), 'save failed: ' . var_export($c->getError(), true));
        }
        $this->assertSame([], $this->warnings());
    }

    /**
     * Every path that reads rows into models must record the undeclared columns
     * as read, or saving a row it returns untouched warns about a value the
     * caller never assigned.
     *
     * @return array<string, array{callable(): iterable<ORM>}>
     */
    public static function hydratingPaths(): array
    {
        return [
            'find'        => [fn () => UndeclaredColumnCompany::find(['name' => 'seed'])],
            'all'         => [fn () => (new UndeclaredColumnCompany())->all()],
            'select'      => [fn () => (new UndeclaredColumnCompany())->select('SELECT * FROM undeclared_company')],
            'selectOne'   => [fn () => [(new UndeclaredColumnCompany())->selectOne('SELECT * FROM undeclared_company')]],
            'withTrashed' => [fn () => (new UndeclaredColumnCompany())->withTrashed()],
            'include'     => [fn () => UndeclaredColumnOwner::findById(1, ['companies'])->companies],
        ];
    }

    #[DataProvider('hydratingPaths')]
    public function testRowsFromEveryReadPathSaveUntouchedWithoutWarning(callable $read): void
    {
        $this->db->exec("INSERT INTO undeclared_owner (name) VALUES ('owner')");
        $id = $this->seed('row');
        $this->db->exec('UPDATE undeclared_company SET owner_id = 1 WHERE id = ?', [$id]);

        $models = $read();
        $this->assertNotEmpty($models);
        foreach ($models as $c) {
            $c->name = 'renamed';
            $this->assertNotFalse($c->save(), 'save failed: ' . var_export($c->getError(), true));
        }
        $this->assertSame('row', $this->login($id));
        $this->assertSame([], $this->warnings());
    }

    public function testReassigningTheLoadedValueDoesNotWarn(): void
    {
        $id = $this->seed('gail');
        $c = new UndeclaredColumnCompany();
        $c->load('id = ?', [$id]);
        $c->loginUsername = 'gail';

        $this->assertNotFalse($c->save(), 'save failed: ' . var_export($c->getError(), true));
        $this->assertSame([], $this->warnings());
    }

    public function testAssignedUndeclaredPropertyNamingNoColumnAlsoWarns(): void
    {
        $c = new UndeclaredColumnCompany();
        $c->name = 'Acme';
        $c->companyCount = 3;

        $this->assertNotFalse($c->save(), 'save failed: ' . var_export($c->getError(), true));
        $this->assertWarnedOnce('companyCount');
    }

    public function testJoinExtraReadBackDoesNotWarn(): void
    {
        $this->seed('row');
        $c = (new UndeclaredColumnCompany())->selectOne(
            'SELECT c.*, 3 AS company_count FROM undeclared_company c'
        );
        $c->name = 'renamed';

        $this->assertNotFalse($c->save(), 'save failed: ' . var_export($c->getError(), true));
        $this->assertSame([], $this->warnings());
    }

    public function testModelDeclaringNoFieldsWritesTheColumnWithoutWarning(): void
    {
        $c = new UndeclaredColumnCompanyUntyped();
        $c->name = 'Acme';
        $c->loginUsername = 'eve';

        $this->assertNotFalse($c->save(), 'save failed: ' . var_export($c->getError(), true));
        $this->assertSame('eve', $this->login((int) $c->id));
        $this->assertSame([], $this->warnings());
    }
}
