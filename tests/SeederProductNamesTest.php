<?php

/**
 * Tina4 — The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Product-name seeding — a generic `name` column on a product-ish table/model
 * seeds product names ("Wireless Keyboard"), not person names ("John Smith").
 *
 * Mirrors the Python master's tests/test_seeder_product_names.py.
 *
 * No mocks: the integration tests seed a REAL SQLite table/model and read the
 * rows back. The heuristic tests call the real seeder functions over real
 * strings (pure — no dependency). Product and person vocabularies are disjoint,
 * so the first word of a generated value tells which generator ran.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Database\DatabaseAdapter;
use Tina4\DevAdmin;
use Tina4\FakeData;

// ── Integration ORM models (real SQLite) ────────────────────────────
// The seedOrm heuristic keys on the MODEL CLASS name (parity with Python's
// table=orm_class.__name__): "PnProduct" contains the "product" hint, so its
// generic `name` column seeds a product name; "PnUser" contains no hint, so it
// stays a person name. Distinct prefix (Pn = product-name test) so the classes
// never collide with the framework's other test models.

/** Product-ish model — a generic `name` column should get product names. */
class PnProduct extends \Tina4\ORM
{
    public string $tableName = 'pn_products';
    public string $primaryKey = 'id';
    public int $id = 0;
    public string $name = '';
}

/** Person-ish model — a generic `name` column stays a person name. */
class PnUser extends \Tina4\ORM
{
    public string $tableName = 'pn_users';
    public string $primaryKey = 'id';
    public int $id = 0;
    public string $name = '';
}

/** Second product-ish model used by the reproducibility test. */
class PnCatalogItem extends \Tina4\ORM
{
    public string $tableName = 'pn_catalog_items';
    public string $primaryKey = 'id';
    public int $id = 0;
    public string $name = '';
}

class SeederProductNamesTest extends TestCase
{
    private static ?DatabaseAdapter $savedGlobalDb = null;

    protected function setUp(): void
    {
        self::$savedGlobalDb = \Tina4\ORM::getGlobalDb();
        $this->resetBindings();
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

    /** First whitespace-delimited word of a generated value. */
    private static function firstWord(string $value): string
    {
        return explode(' ', $value, 2)[0];
    }

    // ── The product() generator ─────────────────────────────────────

    public function testProductShapeAndVocab(): void
    {
        $fake = new FakeData(1);
        $p = $fake->product();
        $this->assertContains(self::firstWord($p), FakeData::PRODUCT_ADJECTIVES, "$p");
        $this->assertStringContainsString(' ', $p);
        $this->assertGreaterThanOrEqual(2, count(explode(' ', $p)));
    }

    public function testProductDeterministicUnderSeed(): void
    {
        $a = [];
        $b = [];
        $fa = new FakeData(7);
        $fb = new FakeData(7);
        for ($i = 0; $i < 3; $i++) {
            $a[] = $fa->product();
            $b[] = $fb->product();
        }
        $this->assertSame($a, $b);

        // ...and it varies across draws, not one constant string.
        $fake = new FakeData(3);
        $seen = [];
        for ($i = 0; $i < 30; $i++) {
            $seen[$fake->product()] = true;
        }
        $this->assertGreaterThan(1, count($seen));
    }

    public function testProductDisjointFromPersonNames(): void
    {
        // A product's first word (an adjective) is never a person first-name and
        // vice versa — this is what makes the table-aware assertions unambiguous.
        $overlap = array_intersect(FakeData::PRODUCT_ADJECTIVES, FakeData::FIRST_NAMES);
        $this->assertSame([], array_values($overlap));
    }

    // ── isProductTable() helper ─────────────────────────────────────

    #[DataProvider('productIshTables')]
    public function testIsProductTableTrue(string $table): void
    {
        $this->assertTrue(FakeData::isProductTable($table), $table);
    }

    public static function productIshTables(): array
    {
        return [
            ['products'], ['Product'], ['order_items'], ['catalog'],
            ['inventory'], ['sku_table'], ['listings'], ['warehouse_goods'],
            ['merchandise'], ['stock_levels'],
        ];
    }

    #[DataProvider('nonProductTables')]
    public function testIsProductTableFalse(?string $table): void
    {
        $this->assertFalse(FakeData::isProductTable($table), var_export($table, true));
    }

    public static function nonProductTables(): array
    {
        return [
            ['users'], ['people'], ['customers'], ['employees'],
            [null], [''], ['orders'],
        ];
    }

    // ── The three heuristic sites are table-aware (pure) ────────────

    /**
     * Site 3 — the dev-admin / MCP column-generator (via its public builder).
     * A generic `name` column returns product() on a product-ish table, name()
     * otherwise, and name() with no table context (back-compat).
     */
    public function testSeedGeneratorForColumnIsTableAware(): void
    {
        $columns = [['name' => 'name', 'type' => 'TEXT', 'primaryKey' => false]];

        $prod = DevAdmin::buildSeedFieldMapFromColumns($columns, new FakeData(1), 'products');
        $this->assertContains(self::firstWord($prod['name']()), FakeData::PRODUCT_ADJECTIVES);

        $person = DevAdmin::buildSeedFieldMapFromColumns($columns, new FakeData(1), 'users');
        $this->assertContains(self::firstWord($person['name']()), FakeData::FIRST_NAMES);

        $noTable = DevAdmin::buildSeedFieldMapFromColumns($columns, new FakeData(1));
        $this->assertContains(self::firstWord($noTable['name']()), FakeData::FIRST_NAMES);
    }

    /**
     * Site 2 — the ORM per-field generator (private, reached via reflection so
     * the REAL method runs; no mock).
     */
    public function testGenerateForFieldIsTableAware(): void
    {
        // Private static: reflection runs the REAL method (no mock). Since PHP
        // 8.1 reflection is accessible by default, so no setAccessible() call
        // (which is deprecated from 8.5) is needed.
        $method = new \ReflectionMethod(FakeData::class, 'generateForField');
        $meta = ['type' => 'string'];

        $prod = $method->invoke(null, new FakeData(1), $meta, 'name', 'products');
        $this->assertContains(self::firstWord($prod), FakeData::PRODUCT_ADJECTIVES);

        $person = $method->invoke(null, new FakeData(1), $meta, 'name', 'users');
        $this->assertContains(self::firstWord($person), FakeData::FIRST_NAMES);

        $noTable = $method->invoke(null, new FakeData(1), $meta, 'name', null);
        $this->assertContains(self::firstWord($noTable), FakeData::FIRST_NAMES);
    }

    /** Site 1 — the public forField() method. */
    public function testForFieldMethodIsTableAware(): void
    {
        $meta = ['type' => 'string'];

        $prod = (new FakeData(1))->forField($meta, 'name', 'products');
        $this->assertContains(self::firstWord($prod), FakeData::PRODUCT_ADJECTIVES);

        $person = (new FakeData(1))->forField($meta, 'name', 'users');
        $this->assertContains(self::firstWord($person), FakeData::FIRST_NAMES);

        $noTable = (new FakeData(1))->forField($meta, 'name');
        $this->assertContains(self::firstWord($noTable), FakeData::FIRST_NAMES);
    }

    /**
     * Person sub-columns stay person even on a product-ish table (the generic
     * `name` catch-all must not swallow first_name/last_name/user_name).
     */
    public function testPersonSubColumnsStayPersonOnProductTable(): void
    {
        $columns = [
            ['name' => 'first_name', 'type' => 'TEXT', 'primaryKey' => false],
            ['name' => 'last_name', 'type' => 'TEXT', 'primaryKey' => false],
            ['name' => 'user_name', 'type' => 'TEXT', 'primaryKey' => false],
        ];
        $map = DevAdmin::buildSeedFieldMapFromColumns($columns, new FakeData(1), 'products');

        // first_name -> a single person first-name.
        $this->assertContains(self::firstWord($map['first_name']()), FakeData::FIRST_NAMES);
        // last_name -> a single surname: one word, and never a product adjective
        // (a product name is two words starting with an adjective).
        $lastName = $map['last_name']();
        $this->assertStringNotContainsString(' ', $lastName);
        $this->assertNotContains($lastName, FakeData::PRODUCT_ADJECTIVES);
        // user_name -> the person full-name generator (str_contains name && user).
        $this->assertStringContainsString(' ', $map['user_name']());
        $this->assertContains(self::firstWord($map['user_name']()), FakeData::FIRST_NAMES);
    }

    // ── Real SQLite seeding ─────────────────────────────────────────

    public function testSeedOrmProductModelGetsProductNames(): void
    {
        $db = Database::create('sqlite::memory:');
        \Tina4\ORM::bindDatabase($db);
        $db->execute('CREATE TABLE pn_products (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');

        $summary = FakeData::seedOrm(PnProduct::class, 8, [], false, 42);
        $this->assertSame(8, $summary->seeded);

        $rows = $db->query('SELECT name FROM pn_products');
        $this->assertCount(8, $rows);
        foreach ($rows as $row) {
            $this->assertContains(self::firstWord($row['name']), FakeData::PRODUCT_ADJECTIVES, $row['name']);
        }
    }

    public function testSeedOrmUserModelGetsPersonNames(): void
    {
        $db = Database::create('sqlite::memory:');
        \Tina4\ORM::bindDatabase($db);
        $db->execute('CREATE TABLE pn_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');

        $summary = FakeData::seedOrm(PnUser::class, 8, [], false, 42);
        $this->assertSame(8, $summary->seeded);

        $rows = $db->query('SELECT name FROM pn_users');
        $this->assertCount(8, $rows);
        foreach ($rows as $row) {
            $this->assertContains(self::firstWord($row['name']), FakeData::FIRST_NAMES, $row['name']);
        }
    }

    public function testAutoFieldMapSeedsProductsTableWithProductNames(): void
    {
        $db = Database::create('sqlite::memory:');
        $db->execute('CREATE TABLE pn_auto_products (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, price REAL)');

        $fake = new FakeData(5);
        $columns = $db->getColumns('pn_auto_products');
        $fieldMap = DevAdmin::buildSeedFieldMapFromColumns($columns, $fake, 'pn_auto_products');
        $summary = FakeData::seedTable($db, 'pn_auto_products', 8, $fieldMap);
        $this->assertSame(8, $summary->seeded);

        $rows = $db->query('SELECT name FROM pn_auto_products');
        $this->assertCount(8, $rows);
        foreach ($rows as $row) {
            $this->assertContains(self::firstWord($row['name']), FakeData::PRODUCT_ADJECTIVES, $row['name']);
        }
    }

    public function testSeedOrmReproducibleProductNames(): void
    {
        $run = function (): array {
            $db = Database::create('sqlite::memory:');
            \Tina4\ORM::bindDatabase($db);
            $db->execute('CREATE TABLE pn_catalog_items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
            FakeData::seedOrm(PnCatalogItem::class, 6, [], false, 99);
            $rows = $db->query('SELECT name FROM pn_catalog_items ORDER BY id');
            return array_map(static fn($r) => $r['name'], $rows);
        };

        $a = $run();
        $b = $run();
        $this->assertSame($a, $b);
        // And they really are product names, not person names.
        foreach ($a as $name) {
            $this->assertContains(self::firstWord($name), FakeData::PRODUCT_ADJECTIVES, $name);
        }
    }
}
