<?php

/**
 * Tina4 - The Intelligent Native Application 4ramework
 * Copyright 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 *
 * Lock-in: a property named EXACTLY like its database column is populated on read.
 *
 * THE BUG (pre-fix): with autoMap on (the default), fill() auto-generated
 * fieldMapping['firstName'] => 'first_name' for every incoming snake_case
 * column. The reverse map then sent that column's value to $firstName -- even
 * when the model declared $first_name and had no $firstName at all. So a model
 * whose properties mirror the DB verbatim WROTE correctly and READ BACK NULL:
 *
 *     $m->first_name = 'Ada'; $m->save();   // row lands as first_name = 'Ada'
 *     $m = (new M)->findById(1);
 *     $m->first_name;                        // NULL  <-- silently
 *
 * Write worked, read returned nothing, no error either way. That is the exact
 * mistake the owner's naming rule exists to prevent: "keep the column naming as
 * in the database so as not to create mistakes."
 *
 * THE FIX: autoMap does NOT invent a camelCase mapping for a column when the
 * model already declares a property of that exact name. The verbatim property
 * wins; autoMap keeps handling every camelCase model exactly as before.
 *
 * autoMap itself is CORRECT and unchanged (owner ruling, 2026-07-30). It maps a
 * camelCase PROPERTY onto the database's real column name -- it never rewrites
 * the column name in the SQL. This fix only stops it from hijacking a column
 * that already has a matching property. Node already behaved this way, which is
 * how we know both can hold at once.
 *
 * Engine-agnostic: in-memory SQLite, so it runs with no DB server.
 */

use PHPUnit\Framework\TestCase;
use Tina4\Database\SQLite3Adapter;
use Tina4\ORM;

/** Properties named EXACTLY as the DB columns. */
class CaseSnakeModel extends ORM
{
    public string $tableName  = 'case_probe';
    public string $primaryKey = 'id';

    public ?int    $id         = null;
    public ?string $first_name = null;
}

/** camelCase properties -- the historical PHP style autoMap exists to serve. */
class CaseCamelModel extends ORM
{
    public string $tableName  = 'case_probe';
    public string $primaryKey = 'id';

    public ?int    $id        = null;
    public ?string $firstName = null;
}

/** An EXPLICIT fieldMapping must still beat both. */
class CaseExplicitModel extends ORM
{
    public string $tableName  = 'case_probe';
    public string $primaryKey = 'id';
    public array  $fieldMapping = ['givenName' => 'first_name'];

    public ?int    $id        = null;
    public ?string $givenName = null;
}

class OrmColumnCaseTest extends TestCase
{
    private SQLite3Adapter $db;

    protected function setUp(): void
    {
        $this->db = new SQLite3Adapter(':memory:');
        $this->db->exec(
            'CREATE TABLE case_probe (id INTEGER PRIMARY KEY AUTOINCREMENT, first_name TEXT)'
        );
        ORM::bindDatabase($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    /** The column name reaching the DB is the DATABASE's name, never rewritten. */
    public function testTheColumnNameInTheDatabaseIsVerbatim(): void
    {
        $m = new CaseSnakeModel();
        $m->first_name = 'Ada';
        $this->assertNotFalse($m->save(), 'save failed: ' . var_export($this->db->error(), true));

        $row = $this->db->fetch('SELECT id, first_name FROM case_probe', [], 10)['data'][0];
        $this->assertSame('Ada', $row['first_name'], 'the value must land in the verbatim column');
    }

    /** THE BUG: this returned NULL before the fix. */
    public function testASnakeCaseDeclaredPropertyIsPopulatedOnRead(): void
    {
        $w = new CaseSnakeModel();
        $w->first_name = 'Ada';
        $w->save();

        $read = (new CaseSnakeModel())->findById(1);
        $this->assertNotNull($read, 'findById found no row');
        $this->assertSame(
            'Ada',
            $read->first_name,
            'a property named exactly like its column must be populated on read'
        );
    }

    /**
     * The other half: autoMap is UNCHANGED and still serves camelCase models.
     * Without this, "fixing" the bug by disabling autoMap would look green.
     */
    public function testAutoMapStillPopulatesACamelCaseProperty(): void
    {
        $w = new CaseCamelModel();
        $w->firstName = 'Grace';
        $this->assertNotFalse($w->save(), 'camel save failed: ' . var_export($this->db->error(), true));

        $row = $this->db->fetch('SELECT first_name FROM case_probe', [], 10)['data'][0];
        $this->assertSame('Grace', $row['first_name'], 'autoMap must still write the snake_case column');

        $read = (new CaseCamelModel())->findById(1);
        $this->assertSame('Grace', $read->firstName, 'autoMap must still populate the camelCase property');
    }

    /** autoMap is on by default -- pin it, since the fix must not have flipped it. */
    public function testAutoMapRemainsOnByDefault(): void
    {
        $this->assertTrue((new CaseSnakeModel())->autoMap, 'autoMap must stay on by default');
        $this->assertTrue((new CaseCamelModel())->autoMap);
    }

    /** An explicit fieldMapping still wins over both conventions. */
    public function testAnExplicitFieldMappingStillWins(): void
    {
        $w = new CaseExplicitModel();
        $w->givenName = 'Hedy';
        $this->assertNotFalse($w->save(), 'explicit save failed: ' . var_export($this->db->error(), true));

        $row = $this->db->fetch('SELECT first_name FROM case_probe', [], 10)['data'][0];
        $this->assertSame('Hedy', $row['first_name']);

        $read = (new CaseExplicitModel())->findById(1);
        $this->assertSame('Hedy', $read->givenName, 'an explicit mapping must be honoured on read');
    }
}
