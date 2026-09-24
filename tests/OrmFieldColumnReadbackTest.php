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
 * ORM field column read-back: a property whose DB column has another name
 * round-trips on every read path.
 *
 * Parity with tina4-python tests/test_orm_field_column_readback.py. Python's
 * bug was a `Field(column=)` that was written to its column but hydrated onto a
 * stray attribute. PHP has no per-field column option: `$fieldMapping`
 * (property => column) is the one resolver, read through getDbColumn() and
 * reversed in fill(). These cases pin that it round-trips on find(pk), all(),
 * where(), find([filter]), ORDER BY, count(), load(), update, toDict(), a
 * mapped primary key, and a relationship foreign key.
 *
 * Real databases, no mocks: SQLite, PostgreSQL, MySQL, MSSQL and Firebird
 * (Firebird folds unquoted identifiers to upper case).
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\ORM;

class ColRbPerson extends ORM
{
    public string $tableName = 'colrp_person';
    public array $fieldMapping = ['name' => 'full_name'];
    public $id;
    public $name;
}

class ColRbMixed extends ORM
{
    public string $tableName = 'colrp_mixed';
    public array $fieldMapping = ['name' => 'full_name', 'email' => 'email_address'];
    public $id;
    public $name;
    public $email;
}

class ColRbPlain extends ORM
{
    public string $tableName = 'colrp_plain';
    public $id;
    public $name;
}

class ColRbKeyed extends ORM
{
    public string $tableName = 'colrp_keyed';
    public string $primaryKey = 'id';
    public array $fieldMapping = ['id' => 'person_id', 'name' => 'full_name'];
    public $id;
    public $name;
}

class ColRbNamedKey extends ORM
{
    public string $tableName = 'colrp_named_key';
    public string $primaryKey = 'person_id';
    public $person_id;
    public $name;
}

class ColRbOwner extends ORM
{
    public string $tableName = 'colrp_owner';
    public array $fieldMapping = ['name' => 'owner_name'];
    public $id;
    public $name;
}

class ColRbPet extends ORM
{
    public string $tableName = 'colrp_pet';
    public array $fieldMapping = ['id' => 'pet_id', 'name' => 'pet_name', 'ownerId' => 'owner_ref'];
    public $id;
    public $name;
    public $ownerId;
}

class OrmFieldColumnReadbackTest extends TestCase
{
    private const TABLES = [
        'colrp_person' => ['id', 'full_name VARCHAR(100)'],
        'colrp_mixed' => ['id', 'full_name VARCHAR(100), email_address VARCHAR(100)'],
        'colrp_plain' => ['id', 'name VARCHAR(100)'],
        'colrp_keyed' => ['person_id', 'full_name VARCHAR(100)'],
        'colrp_named_key' => ['person_id', 'name VARCHAR(100)'],
        'colrp_owner' => ['id', 'owner_name VARCHAR(100)'],
        'colrp_pet' => ['pet_id', 'pet_name VARCHAR(100), owner_ref INTEGER'],
    ];

    private ?Database $db = null;
    private string $engine = '';

    public static function engines(): array
    {
        return [
            'sqlite' => ['sqlite'],
            'postgres' => ['postgres'],
            'mysql' => ['mysql'],
            'mssql' => ['mssql'],
            'firebird' => ['firebird'],
        ];
    }

    private static function reachable(string $host, int $port): bool
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, 3);
        if ($socket === false) {
            return false;
        }
        fclose($socket);
        return true;
    }

    private function open(string $engine): Database
    {
        $this->engine = $engine;
        if ($engine === 'sqlite') {
            return Database::create('sqlite::memory:');
        }
        if ($engine === 'firebird') {
            if (!function_exists('ibase_connect') && !function_exists('fbird_connect')) {
                $this->markTestSkipped('[needs:firebird] firebird client not installed: ext-interbase');
            }
            $url = getenv('TINA4_TEST_FIREBIRD_URL');
            if (!$url) {
                $this->markTestSkipped('[needs:firebird] firebird not set: TINA4_TEST_FIREBIRD_URL (needs a live Firebird)');
            }
            return Database::create($url);
        }
        $coordinates = [
            'postgres' => ['PG', 55432, 'tina4_php', 'tina4', 'tina4', 'postgres'],
            'mysql' => ['MYSQL', 3306, 'tina4_test', 'tina4', 'tina4', 'mysql'],
            'mssql' => ['MSSQL', 1433, 'tina4_test', 'sa', 'TinaSQL123!Secure', 'mssql'],
        ][$engine];
        [$prefix, $defaultPort, $defaultDb, $defaultUser, $defaultPassword, $scheme] = $coordinates;
        $host = getenv("TINA4_TEST_{$prefix}_HOST") ?: '127.0.0.1';
        $port = (int) (getenv("TINA4_TEST_{$prefix}_PORT") ?: $defaultPort);
        if (!self::reachable($host, $port)) {
            $this->markTestSkipped("[needs:{$engine}] {$engine} unreachable at {$host}:{$port}");
        }
        $database = getenv("TINA4_TEST_{$prefix}_DB") ?: $defaultDb;
        $user = getenv("TINA4_TEST_{$prefix}_USERNAME") ?: $defaultUser;
        $password = getenv("TINA4_TEST_{$prefix}_PASSWORD") ?: $defaultPassword;
        return new Database("{$scheme}://{$host}:{$port}/{$database}", null, $user, $password);
    }

    private function keyColumn(string $key): string
    {
        return match ($this->engine) {
            'sqlite' => "{$key} INTEGER PRIMARY KEY AUTOINCREMENT",
            'postgres' => "{$key} SERIAL PRIMARY KEY",
            'mysql' => "{$key} INT AUTO_INCREMENT PRIMARY KEY",
            'mssql' => "{$key} INT IDENTITY(1,1) PRIMARY KEY",
            'firebird' => "{$key} INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY",
        };
    }

    private function dropTables(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            try {
                if ($this->db->tableExists($table)) {
                    $this->db->execute("DROP TABLE {$table}");
                    $this->db->commit();
                }
            } catch (\Throwable) {
            }
        }
    }

    private function useEngine(string $engine, string ...$tables): void
    {
        $this->db = $this->open($engine);
        ORM::bindDatabase($this->db);
        $this->dropTables();
        foreach ($tables as $table) {
            [$key, $columns] = self::TABLES[$table];
            $this->db->execute("CREATE TABLE {$table} ({$this->keyColumn($key)}, {$columns})");
            $this->db->commit();
        }
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->dropTables();
            try {
                $this->db->close();
            } catch (\Throwable) {
            }
            $this->db = null;
        }
    }

    /** Raw row with lower-cased keys: asserts WHERE a value landed, not the driver's key casing. */
    private function rawRow(string $sql, array $params = []): array
    {
        $row = $this->db->fetchOne($sql, $params);
        $this->assertNotNull($row, "no row for: {$sql}");
        return array_change_key_case((array) $row, CASE_LOWER);
    }

    private function assertNoStrayColumnProperty(ORM $model, string $column): void
    {
        $this->assertArrayNotHasKey(
            $column,
            $model->getData(),
            "hydration left a stray '{$column}' key: the DB column must map back onto its property"
        );
    }

    private static function names(iterable $models): array
    {
        $names = [];
        foreach ($models as $model) {
            $names[] = $model->name;
        }
        return $names;
    }

    #[DataProvider('engines')]
    public function testFieldColumnWriteLandsInTheDeclaredColumn(string $engine): void
    {
        $this->useEngine($engine, 'colrp_person');
        $this->assertNotFalse(ColRbPerson::create(['name' => 'Ada']));
        $this->assertSame('Ada', $this->rawRow('SELECT full_name FROM colrp_person')['full_name']);
    }

    #[DataProvider('engines')]
    public function testFieldColumnReadsBackThroughFindByPrimaryKey(string $engine): void
    {
        $this->useEngine($engine, 'colrp_person');
        $saved = ColRbPerson::create(['name' => 'Ada']);
        $found = ColRbPerson::find($saved->id);
        $this->assertNotNull($found);
        $this->assertSame('Ada', $found->name);
        $this->assertNoStrayColumnProperty($found, 'full_name');
    }

    #[DataProvider('engines')]
    public function testFieldColumnReadsBackThroughAll(string $engine): void
    {
        $this->useEngine($engine, 'colrp_person');
        ColRbPerson::create(['name' => 'Ada']);
        ColRbPerson::create(['name' => 'Grace']);
        $people = (new ColRbPerson())->all();
        $names = self::names($people);
        sort($names);
        $this->assertSame(['Ada', 'Grace'], $names);
        foreach ($people as $person) {
            $this->assertNoStrayColumnProperty($person, 'full_name');
        }
    }

    #[DataProvider('engines')]
    public function testFieldColumnReadsBackThroughWhere(string $engine): void
    {
        $this->useEngine($engine, 'colrp_person');
        ColRbPerson::create(['name' => 'Ada']);
        ColRbPerson::create(['name' => 'Grace']);
        $rows = (new ColRbPerson())->where('full_name = ?', ['Grace']);
        $this->assertSame(['Grace'], self::names($rows));
        $this->assertNoStrayColumnProperty($rows[0], 'full_name');
    }

    #[DataProvider('engines')]
    public function testFieldColumnFiltersThroughFindByAttributeName(string $engine): void
    {
        $this->useEngine($engine, 'colrp_person');
        ColRbPerson::create(['name' => 'Ada']);
        ColRbPerson::create(['name' => 'Grace']);
        $this->assertSame(['Ada'], self::names(ColRbPerson::find(['name' => 'Ada'])));
    }

    #[DataProvider('engines')]
    public function testFieldColumnSortsAndReadsBackInOrder(string $engine): void
    {
        $this->useEngine($engine, 'colrp_person');
        foreach (['Grace', 'Ada', 'Linus'] as $name) {
            ColRbPerson::create(['name' => $name]);
        }
        // orderBy is SQL (SQL-first ORM), so it names the column; every row it
        // returns must still hydrate onto the property, in the database's order.
        $this->assertSame(['Linus', 'Grace', 'Ada'], self::names((new ColRbPerson())->all(100, 0, null, 'full_name DESC')));
        $this->assertSame(['Ada', 'Grace', 'Linus'], self::names(ColRbPerson::find([], 100, 0, 'full_name ASC')));
    }

    #[DataProvider('engines')]
    public function testFieldColumnCountsAndLoads(string $engine): void
    {
        $this->useEngine($engine, 'colrp_person');
        $saved = ColRbPerson::create(['name' => 'Ada']);
        $this->assertSame(1, (new ColRbPerson())->count('full_name = ?', ['Ada']));
        $fresh = new ColRbPerson();
        $fresh->id = $saved->id;
        $this->assertTrue($fresh->load());
        $this->assertSame('Ada', $fresh->name);
    }

    #[DataProvider('engines')]
    public function testFieldColumnUpdatesTheDeclaredColumn(string $engine): void
    {
        $this->useEngine($engine, 'colrp_person');
        $person = ColRbPerson::create(['name' => 'Ada']);
        $person->name = 'Ada Lovelace';
        $this->assertNotFalse($person->save());
        $this->assertSame('Ada Lovelace', $this->rawRow('SELECT full_name FROM colrp_person')['full_name']);
        $this->assertSame('Ada Lovelace', ColRbPerson::find($person->id)->name);
    }

    #[DataProvider('engines')]
    public function testFieldColumnToDictUsesTheAttributeName(string $engine): void
    {
        $this->useEngine($engine, 'colrp_person');
        $saved = ColRbPerson::create(['name' => 'Ada']);
        $found = ColRbPerson::find($saved->id);
        // Property-keyed view. (toDict() in its default 'snake' case emits the DB
        // COLUMN names by PHP's documented contract; 'camel' is the property view.)
        $this->assertEquals(['id' => $saved->id, 'name' => 'Ada'], $found->getData());
        $this->assertEquals(['id' => $saved->id, 'name' => 'Ada'], $found->toDict(null, 'camel'));
    }

    #[DataProvider('engines')]
    public function testGetDbColumnResolvesFieldColumn(string $engine): void
    {
        $this->assertSame('full_name', (new ColRbPerson())->getDbColumn('name'));
        $this->assertSame('email_address', (new ColRbMixed())->getDbColumn('email'));
        $this->assertSame('name', (new ColRbPlain())->getDbColumn('name'));
    }

    #[DataProvider('engines')]
    public function testFieldMappingAndFieldColumnRoundTripTogether(string $engine): void
    {
        $this->useEngine($engine, 'colrp_mixed');
        $saved = ColRbMixed::create(['name' => 'Ada', 'email' => 'ada@example.com']);
        $this->assertSame(
            ['full_name' => 'Ada', 'email_address' => 'ada@example.com'],
            $this->rawRow('SELECT full_name, email_address FROM colrp_mixed')
        );
        $found = ColRbMixed::find($saved->id);
        $this->assertSame(['Ada', 'ada@example.com'], [$found->name, $found->email]);
        $this->assertNoStrayColumnProperty($found, 'full_name');
        $this->assertNoStrayColumnProperty($found, 'email_address');
        $this->assertCount(1, ColRbMixed::find(['name' => 'Ada']));
        $this->assertCount(1, ColRbMixed::find(['email' => 'ada@example.com']));
        $this->assertSame(['Ada'], self::names((new ColRbMixed())->all()));
    }

    #[DataProvider('engines')]
    public function testPlainFieldRoundTripsUnchanged(string $engine): void
    {
        $this->useEngine($engine, 'colrp_plain');
        $saved = ColRbPlain::create(['name' => 'Ada']);
        $this->assertSame('Ada', $this->rawRow('SELECT name FROM colrp_plain')['name']);
        $this->assertSame('Ada', ColRbPlain::find($saved->id)->name);
        $this->assertSame(['Ada'], self::names(ColRbPlain::find(['name' => 'Ada'])));
        $this->assertSame(['Ada'], self::names((new ColRbPlain())->all()));
    }

    #[DataProvider('engines')]
    public function testUndeclaredSelectColumnStillLandsAsAnExtraAttribute(string $engine): void
    {
        $this->useEngine($engine, 'colrp_person');
        ColRbPerson::create(['name' => 'Ada']);
        $person = (new ColRbPerson())->select('SELECT id, full_name, 7 AS extra_value FROM colrp_person')[0];
        $this->assertSame('Ada', $person->name);
        $row = array_change_key_case($person->getData(), CASE_LOWER);
        $extra = $row['extra_value'] ?? $row['extravalue'] ?? null;
        $this->assertEquals(7, $extra);
    }

    #[DataProvider('engines')]
    public function testForeignKeyFieldColumnLoadsLazyAndEager(string $engine): void
    {
        $this->useEngine($engine, 'colrp_owner', 'colrp_pet');
        $owner = ColRbOwner::create(['name' => 'Ada']);
        ColRbPet::create(['name' => 'Rex', 'ownerId' => $owner->id]);
        ColRbPet::create(['name' => 'Tom', 'ownerId' => $owner->id]);
        $this->assertEquals($owner->id, $this->rawRow('SELECT owner_ref FROM colrp_pet WHERE pet_name = ?', ['Rex'])['owner_ref']);

        // PHP relationship foreign keys name the COLUMN.
        $pets = self::names(ColRbOwner::find($owner->id)->hasMany(ColRbPet::class, 'owner_ref'));
        sort($pets);
        $this->assertSame(['Rex', 'Tom'], $pets);
        $this->assertContains(ColRbOwner::find($owner->id)->hasOne(ColRbPet::class, 'owner_ref')->name, ['Rex', 'Tom']);

        $pet = ColRbPet::find(['name' => 'Rex'])[0];
        $this->assertEquals($owner->id, $pet->ownerId);
        $this->assertSame('Ada', $pet->belongsTo(ColRbOwner::class, 'owner_ref')->name);
    }

    #[DataProvider('engines')]
    public function testPrimaryKeyFieldColumnRoundTrips(string $engine): void
    {
        $this->useEngine($engine, 'colrp_keyed');
        $first = ColRbKeyed::create(['name' => 'Ada']);
        $second = ColRbKeyed::create(['name' => 'Grace']);
        $this->assertNotNull($first->id);
        $this->assertNotNull($second->id);
        $this->assertNotEquals($first->id, $second->id);
        $this->assertEquals(
            ['person_id' => $first->id, 'full_name' => 'Ada'],
            $this->rawRow('SELECT person_id, full_name FROM colrp_keyed WHERE person_id = ?', [$first->id])
        );
        $found = ColRbKeyed::find($first->id);
        $this->assertEquals([$first->id, 'Ada'], [$found->id, $found->name]);
        $this->assertNoStrayColumnProperty($found, 'person_id');

        $found->name = 'Ada Lovelace';
        $this->assertNotFalse($found->save());
        $this->assertSame('Ada Lovelace', ColRbKeyed::find($first->id)->name);
        $this->assertSame('Grace', ColRbKeyed::find($second->id)->name, 'an update must address only its own row');

        $this->assertTrue(ColRbKeyed::find($second->id)->delete());
        $this->assertNull(ColRbKeyed::find($second->id));
        $this->assertSame(['Ada Lovelace'], self::names((new ColRbKeyed())->all()));
    }

    #[DataProvider('engines')]
    public function testNonIdAutoIncrementKeyIsSetAfterSave(string $engine): void
    {
        $this->useEngine($engine, 'colrp_named_key');
        $first = ColRbNamedKey::create(['name' => 'Ada']);
        $second = ColRbNamedKey::create(['name' => 'Grace']);
        $this->assertNotNull($first->person_id);
        $this->assertNotNull($second->person_id);
        $this->assertNotEquals($first->person_id, $second->person_id);
        $this->assertSame('Grace', ColRbNamedKey::find($second->person_id)->name);
    }
}
