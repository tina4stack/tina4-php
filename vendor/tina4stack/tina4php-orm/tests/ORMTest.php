<?php
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

use PHPUnit\Framework\TestCase;

require_once "./vendor/autoload.php";
require_once "./src/orm/Person.php";


class ORMTest extends TestCase
{
    final public function SetUp(): void
    {
        global $DBA;
        $DBA = new \Tina4\DataFirebird("localhost/33050:/firebird/data/TINA4.FDB", "sysdba", "pass1234");
        $this->DBA = $DBA;
    }

    final public function testClassExists() : void
    {
        $this->assertEquals(true, class_exists("Tina4\ORM"), "Class does not exist");
    }

    final public function testPerson(): void
    {
        define("TINA4_DEBUG", true);
        $person = new Person();

        if ($this->DBA->tableExists("person")) {
            $this->DBA->exec("drop table person");
            $this->DBA->commit();
        }
        $sql = $person->generateCreateSQL();

        $this->assertStringContainsString("first_name varchar(100),", $sql, "Generate SQL is not correct");

        $error = $this->DBA->exec ($sql);


        //print_r ($error);
        //die();
        $this->DBA->commit();
        $person->lastName = "Test Lastname";
        $person->save();

        $this->assertEquals(1, $person->id, "Id should be 1");

        $person->firstName = "Test FirstName";

        $person->save();



        $person = new Person();
        $person->save();

        //print_r ($person->asArray());

        $person->delete();

        $person->load();

        //$person->hoola = 'test';
        //$person->save();

        $person->select("*")->asArray();

        $person = new Person(["firstName" => "Test Another", "lastName" => "Test Another"]);
        $person->save();

        $this->assertEquals("Test Another", $person->firstName, "Person didn't save");
        $this->assertEquals(3, $person->id);


    }

    final function testGenerateCrud() : void
    {
        $content = (new Person())->generateCRUD("/api/person", true);
        $this->assertStringContainsString('\Tina4\Crud::route ("/api/person", new Person(),', $content, "Not found");
    }

}