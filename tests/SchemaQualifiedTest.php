<?php

/**
 * Tina4 PHP — v3.13.14 (#48) schema-qualified table introspection.
 *
 * A model whose table name is schema/catalog-qualified was invisible to
 * tableExists()/getColumns()/getTables() — they hardcoded the default
 * namespace and matched the dotted string as one flat name. SQLite's
 * "schema" is an ATTACH alias, which needs no external server, so it gives
 * the schema-qualified path real coverage here. PostgreSQL/MySQL/MSSQL use
 * the same splitSchema() helper (unit-tested below); their live behaviour
 * is covered when a container is present.
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\Database\SQLite3Adapter;

class SchemaQualifiedTest extends TestCase
{
    private string $main;
    private string $att;

    protected function setUp(): void
    {
        $this->main = tempnam(sys_get_temp_dir(), 'tina4_sq_main_') . '.db';
        $this->att = tempnam(sys_get_temp_dir(), 'tina4_sq_att_') . '.db';
    }

    protected function tearDown(): void
    {
        foreach ([$this->main, $this->att] as $f) {
            @unlink($f);
        }
    }

    private function db(): Database
    {
        $db = Database::create('sqlite:///' . $this->main);
        $db->execute("ATTACH DATABASE '{$this->att}' AS extra");
        $db->execute("CREATE TABLE extra.widget (id INTEGER PRIMARY KEY, name TEXT, is_deleted INTEGER DEFAULT 0)");
        $db->execute("CREATE TABLE local_only (id INTEGER PRIMARY KEY)");
        return $db;
    }

    // ── splitSchema unit (the shared helper) ─────────────────────────

    private function splitSchema(string $name): array
    {
        // Protected static on SqlNormalizerTrait, mixed into every adapter.
        // Reflection methods are accessible by default since PHP 8.1, so no
        // setAccessible() call (deprecated in 8.5, no-op since 8.1).
        $ref = new ReflectionMethod(SQLite3Adapter::class, 'splitSchema');
        return $ref->invoke(null, $name);
    }

    public function testSplitSchemaBare(): void
    {
        $this->assertSame([null, 'users'], $this->splitSchema('users'));
    }

    public function testSplitSchemaQualified(): void
    {
        $this->assertSame(['gift_cards', 'gift_card'], $this->splitSchema('gift_cards.gift_card'));
    }

    // ── SQLite attached-database integration (no server needed) ──────

    public function testTableExistsAttached(): void
    {
        $this->assertTrue($this->db()->tableExists('extra.widget'));
    }

    public function testTableExistsAttachedAbsent(): void
    {
        $this->assertFalse($this->db()->tableExists('extra.nope'));
    }

    public function testTableExistsBareStillWorks(): void
    {
        $this->assertTrue($this->db()->tableExists('local_only'));
    }

    public function testGetColumnsAttached(): void
    {
        $cols = $this->db()->getColumns('extra.widget');
        $names = array_column($cols, 'name');
        $this->assertSame(['id', 'name', 'is_deleted'], $names);
        $idCol = array_values(array_filter($cols, fn($c) => $c['name'] === 'id'))[0];
        $this->assertTrue((bool) $idCol['primary']);
    }

    // ── Every SQL adapter must carry the normalizer trait (server-free) ──
    //
    // Regression guard: PostgresAdapter once called self::stripTrailingSemicolons()
    // (v3.13.12) and self::splitSchema() (#48) without mixing in SqlNormalizerTrait,
    // so every PG fetch/getColumns fatalled — invisible to the live PG tests, which
    // skip without a server. These reflection checks need no database, so they run
    // in every CI build and pin the contract for all five SQL adapters.

    public static function sqlAdapterClasses(): array
    {
        return [
            'sqlite'   => [\Tina4\Database\SQLite3Adapter::class],
            'postgres' => [\Tina4\Database\PostgresAdapter::class],
            'mysql'    => [\Tina4\Database\MySQLAdapter::class],
            'mssql'    => [\Tina4\Database\MSSQLAdapter::class],
            'firebird' => [\Tina4\Database\FirebirdAdapter::class],
        ];
    }

    #[DataProvider('sqlAdapterClasses')]
    public function testAdapterExposesStripTrailingSemicolons(string $adapter): void
    {
        $this->assertTrue(
            method_exists($adapter, 'stripTrailingSemicolons'),
            "$adapter must mix in SqlNormalizerTrait (stripTrailingSemicolons missing)"
        );
    }

    #[DataProvider('sqlAdapterClasses')]
    public function testAdapterExposesSplitSchema(string $adapter): void
    {
        $this->assertTrue(
            method_exists($adapter, 'splitSchema'),
            "$adapter must mix in SqlNormalizerTrait (splitSchema missing)"
        );
    }
}
