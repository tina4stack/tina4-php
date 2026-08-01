<?php

namespace Tina4\Tests;

use PHPUnit\Framework\TestCase;
use Tina4\Database\Database;
use Tina4\ORM;
use Tina4\Response;

/**
 * A REAL Tina4 ORM model, bound to a REAL SQLite database and loaded from a
 * REAL row. This is NOT a double: toDict() is the framework's own
 * implementation, so whatever shape it really emits is what Response really
 * receives. The previous version of this file passed an anonymous
 * "duck-typed" object exposing only toDict(), which could only ever prove
 * that Response calls toDict() on anything that has one.
 */
class AutoSerializeWidget extends ORM
{
    public string $tableName  = 'autoserialize_widgets';
    public string $primaryKey = 'id';
    public bool   $autoMap    = true;

    public ?int    $id     = null;
    public ?string $name   = null;
    public ?int    $active = null;
}

/**
 * Response auto-serialization: $response($model) / $response->json($model)
 * serialize an ORM model (toDict), a list of models, and a DatabaseResult
 * (toArray) without the caller calling ->toDict() by hand. Plain arrays /
 * strings must behave exactly as before.
 *
 * NO DOUBLES. Every object handed to Response here is the real framework
 * class, produced by a real query against a real SQLite engine on disk:
 *  - the models are real Tina4\ORM instances loaded back through the ORM;
 *  - the DatabaseResult is the real object $db->fetch() returns;
 *  - the write-path DatabaseResult is the real object $db->insert() returns
 *    (insert/update/delete began returning DatabaseResult in 3.13.86, and no
 *    test covered feeding that straight into Response).
 *
 * A real file database (not :memory:) is used so a row genuinely round-trips
 * through the driver's storage rather than living in one process-local handle.
 */
class ResponseAutoSerializeTest extends TestCase
{
    private Database $db;
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/tina4_autoserialize_' . bin2hex(random_bytes(6)) . '.db';
        // Database::create() is the documented application entry point; it is the
        // facade that yields a real DatabaseResult from reads AND writes. (A raw
        // `new SQLite3Adapter(...)` returns bare array/bool instead — using the
        // adapter directly here would have quietly weakened the assertions.)
        // Four slashes = absolute path, per the SQLAlchemy convention Tina4 follows.
        $this->db = Database::create('sqlite:///' . $this->dbFile);

        $this->db->execute(
            'CREATE TABLE autoserialize_widgets (id INTEGER PRIMARY KEY, name TEXT, active INTEGER)'
        );
        $this->db->commit();

        ORM::bindDatabase($this->db);
    }

    protected function tearDown(): void
    {
        @unlink($this->dbFile);
    }

    /** Insert a REAL row and load it back through the REAL ORM. */
    private function realModel(int $id, string $name, int $active): AutoSerializeWidget
    {
        $this->db->insert('autoserialize_widgets', ['id' => $id, 'name' => $name, 'active' => $active]);
        $this->db->commit();

        $model = new AutoSerializeWidget();
        $found = $model->load('id = ?', [$id]);
        $this->assertTrue($found, "Fixture row $id must really load back from the real database.");

        return $model;
    }

    public function testModelSerialized(): void
    {
        $model = $this->realModel(1, 'alpha', 1);

        $r = (new Response())($model);
        $this->assertSame('application/json', $r->getContentType());

        $decoded = json_decode($r->getBody(), true);
        // Assert against the REAL toDict() shape: Response must emit exactly
        // what the real ORM produces, with no hand-massaging by the caller.
        $this->assertSame($model->toDict(), $decoded);
        $this->assertSame(1, $decoded['id']);
        $this->assertSame('alpha', $decoded['name']);
    }

    public function testListOfModelsSerialized(): void
    {
        $a = $this->realModel(1, 'alpha', 1);
        $b = $this->realModel(2, 'beta', 0);

        $r       = (new Response())([$a, $b]);
        $decoded = json_decode($r->getBody(), true);

        $this->assertSame([$a->toDict(), $b->toDict()], $decoded);
        $this->assertSame([1, 2], array_column($decoded, 'id'));
    }

    public function testDatabaseResultSerialized(): void
    {
        $this->realModel(1, 'alpha', 1);
        $this->realModel(2, 'beta', 0);

        // The REAL DatabaseResult returned by a REAL query.
        $result = $this->db->fetch('SELECT id FROM autoserialize_widgets ORDER BY id');
        $this->assertInstanceOf(\Tina4\Database\DatabaseResult::class, $result);

        $r = (new Response())($result);
        $this->assertSame([['id' => 1], ['id' => 2]], json_decode($r->getBody(), true));
    }

    /**
     * insert/update/delete return a DatabaseResult since 3.13.86. Handing that
     * straight to Response is a path a real application takes, and no test
     * covered it with a real object.
     */
    public function testWriteResultSerialized(): void
    {
        $writeResult = $this->db->insert('autoserialize_widgets', ['id' => 7, 'name' => 'gamma', 'active' => 1]);
        $this->db->commit();

        $this->assertInstanceOf(\Tina4\Database\DatabaseResult::class, $writeResult);

        $r = (new Response())($writeResult);
        $this->assertSame('application/json', $r->getContentType());
        // A write result carries no rows, so it serialises as an empty JSON array
        // rather than fataling or leaking the object's internals.
        $this->assertSame($writeResult->toArray(), json_decode($r->getBody(), true));
    }

    public function testJsonMethodSerializesModel(): void
    {
        $model = $this->realModel(9, 'delta', 1);

        $r = (new Response())->json($model);
        $this->assertSame('application/json', $r->getContentType());
        $this->assertSame($model->toDict(), json_decode($r->getBody(), true));
    }

    public function testPlainArrayUnchanged(): void
    {
        $r = (new Response())(['ok' => true]);
        $this->assertSame('application/json', $r->getContentType());
        $this->assertSame(['ok' => true], json_decode($r->getBody(), true));
    }

    public function testStringUnchanged(): void
    {
        $r = (new Response())('<h1>hi</h1>');
        $this->assertStringStartsWith('text/html', $r->getContentType());
        $this->assertSame('<h1>hi</h1>', $r->getBody());
    }
}
