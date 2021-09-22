<?php 
class DataBaseMySQLTest extends \Codeception\Test\Unit
{
    /**
     * @var \UnitTester
     */
    protected $tester;
    protected $DBA;
    protected $testORM;
    protected $defaultError = "0 ";

    protected function _before()
    {
       global $DBA;
       $this->DBA = $this->getModule("\Helper\Unit")->MYSQL;
       $DBA = $this->getModule("\Helper\Unit")->MYSQL;
       $this->testORM = new \Helper\Test();

    }

    protected function _after()
    {
    }

    // tests
    public function testMySQLCreateTable()
    {
        //create a table
        $result = $this->DBA->exec("create table test (the_key integer  auto_increment, first_name varchar(100), surname varchar(100), current_age integer, current_salary numeric (10,2), birth_date date, time time, primary key(the_key))");

        $this->assertEquals($this->defaultError, $result->getErrorText(), "Failed to create the table test");
    }

    public function testMySQLInsert()
    {
        //create a record in the table
        $result = $this->DBA->exec("insert into test (first_name, surname, current_age, current_salary, birth_date, time) values ('First Name', 'Last Name', 10, 1000.00, '2005-03-29', '10:10')");

        $this->assertEquals($this->defaultError, $result->getErrorText(), "Failed to insert the test record");
    }

    public function testSqliteFetch () {
        $result = $this->DBA->fetch("select * from test");

        $result = $this->DBA->fetch("select * from test")->asArray();

        $this->assertIsArray( $result[0], "Failed to fetch an array");

        $result = $this->DBA->fetch("select * from test")->asObject(true);

        $this->assertIsObject( $result[0], "Failed to fetch an object");

        $result = $result = $this->DBA->fetch("select * from test");

        $this->assertIsObject( $result , "Result object not correct");

        $this->assertEquals( 1 , $result->noOfRecords, "No of records is not correct");
        $this->assertEquals( 0 , $result->offSet, "Offset should be 0");
    }

    public function testORMFetchAndSave () {
        $this->testORM = new \Helper\Test();
        $this->testORM->load ("the_key = 1");
        $this->assertEquals("First Name", $this->testORM->firstName);
        $this->assertEquals("Last Name", $this->testORM->lastName);

        $this->testORM->firstName = "Save Test";
        $this->testORM->lastName = "Save Surname";
        $this->testORM->save();

        $this->testORM = new \Helper\Test();
        $this->testORM->load ("the_key = 1");

        $this->assertEquals("Save Test", $this->testORM->firstName);
        $this->assertEquals("Save Surname", $this->testORM->lastName);

        $this->testORM = new \Helper\Test();
        $this->testORM->theKey = 1;
        $this->testORM->load();

        $this->assertEquals("Save Test", $this->testORM->firstName);
        $this->assertEquals("Save Surname", $this->testORM->lastName);

    }

    public function testMySQLOrmCreate () {

        $this->testORM = new \Helper\Test();
        $this->testORM->theKey = 2;
        $this->testORM->firstName = "New ORM";
        $this->testORM->lastName = "New ORM";
        $this->testORM->currentAge = 10;
        $result = $this->testORM->save();

        $this->testORM->load("the_key = 2");



        $this->assertEquals("New ORM", $this->testORM->firstName);
        $this->assertEquals("New ORM", $this->testORM->lastName);


    }


}