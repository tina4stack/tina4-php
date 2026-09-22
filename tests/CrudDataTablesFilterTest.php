<?php

use PHPUnit\Framework\TestCase;
use Tina4\Crud;
use Tina4\DataSQLite3;
use Tina4\ORM;

/**
 * Covers Crud::getDataTablesFilter().
 *
 * The method returns SQL fragments that a caller concatenates into a statement,
 * and every part of them is built from $_REQUEST, so the tests below fix three
 * things in place:
 *
 *   - search values are quoted as literals rather than concatenated raw,
 *   - column names must resolve to fields the ORM knows about,
 *   - the order direction can only be asc or desc.
 *
 * The case-insensitivity test is the portability one: the search term is
 * uppercased, so the column has to be too, or the comparison silently matches
 * nothing on any engine whose LIKE is case-sensitive.
 */
class CrudDataTablesFilterTest extends TestCase
{
    private ORM $orm;

    protected function setUp(): void
    {
        $this->orm = new CrudFilterTestModel();
        $this->orm->DBA = new DataSQLite3(":memory:");
    }

    protected function tearDown(): void
    {
        $_REQUEST = [];
    }

    private function filter(array $request): array
    {
        $_REQUEST = $request;

        return Crud::getDataTablesFilter("t.", $this->orm);
    }

    public function testSearchIsMatchedCaseInsensitively(): void
    {
        $result = $this->filter([
            "columns" => [["data" => "firstName", "searchable" => "true"]],
            "search" => ["value" => "ann"],
        ]);

        // Both sides uppercased, or a case-sensitive LIKE never matches.
        $this->assertStringContainsString("upper(coalesce(t.first_Name, ''))", $result["where"]);
        $this->assertStringContainsString("like '%ANN%'", $result["where"]);
    }

    public function testGeneratedWhereIsValidSqlAndMatchesRegardlessOfCase(): void
    {
        $result = $this->filter([
            "columns" => [["data" => "firstName", "searchable" => "true"]],
            "search" => ["value" => "bob"],
        ]);

        $database = new SQLite3(":memory:");
        $database->exec("create table t (id integer, first_Name text)");
        $database->exec("insert into t values (1, 'Bob'), (2, 'Ann')");

        $this->assertSame(
            1,
            (int)$database->querySingle("select count(*) from t where {$result["where"]}")
        );
    }

    public function testSearchValueIsQuotedRatherThanConcatenatedRaw(): void
    {
        $result = $this->filter([
            "columns" => [["data" => "firstName", "searchable" => "true"]],
            "search" => ["value" => "o'brien"],
        ]);

        $this->assertStringContainsString("like '%O''BRIEN%'", $result["where"]);
    }

    public function testUnknownColumnIsIgnored(): void
    {
        $result = $this->filter([
            "columns" => [["data" => "notAFieldOnTheModel", "searchable" => "true"]],
            "search" => ["value" => "ann"],
        ]);

        $this->assertSame("", $result["where"]);
    }

    public function testColumnNameThatIsNotAPlainIdentifierIsIgnored(): void
    {
        $result = $this->filter([
            "columns" => [["data" => "first_Name) or (1=1", "searchable" => "true"]],
            "search" => ["value" => "ann"],
        ]);

        $this->assertSame("", $result["where"]);
    }

    public function testOrderDirectionIsLimitedToAscOrDesc(): void
    {
        $result = $this->filter([
            "columns" => [["data" => "firstName", "searchable" => "true"]],
            "order" => [["column" => 0, "dir" => "DESC"]],
        ]);

        $this->assertSame("t.first_Name desc", $result["orderBy"]);

        $result = $this->filter([
            "columns" => [["data" => "firstName", "searchable" => "true"]],
            "order" => [["column" => 0, "dir" => "asc, (select 1)"]],
        ]);

        $this->assertSame("t.first_Name asc", $result["orderBy"]);
    }

    public function testStartAndLengthAreIntegers(): void
    {
        $result = $this->filter([
            "columns" => [["data" => "firstName", "searchable" => "true"]],
            "start" => "10 union select",
            "length" => "25",
        ]);

        $this->assertSame(10, $result["start"]);
        $this->assertSame(25, $result["length"]);
    }

    public function testDefaultsWhenTheRequestIsEmpty(): void
    {
        $result = $this->filter([]);

        $this->assertSame(["length" => 10, "start" => 0, "orderBy" => "", "where" => ""], $result);
    }
}

class CrudFilterTestModel extends ORM
{
    public $id;
    public $firstName;
    public $emailAddress;
}
