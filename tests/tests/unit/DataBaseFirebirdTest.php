<?php 
class DataBaseFirebirdTest extends \Codeception\Test\Unit
{
    /**
     * @var \UnitTester
     */
    protected $tester;
    protected $DBA;
    protected $testORM;
    protected $defaultError = " ";

    protected function _before()
    {
       global $DBA;
       $this->DBA = $this->getModule("\Helper\Unit")->FIREBIRD;
       $DBA = $this->getModule("\Helper\Unit")->FIREBIRD;
       $this->testORM = new \Helper\Test();
       $this->testORM->fieldMapping = ["lastName" => "surname", "time" => "the_time"];

    }

    protected function _after()
    {
    }

    // tests
    public function testFirebirdCreateTable()
    {
        //create a table
        $this->DBA->exec("create table test (the_key integer not null, first_name varchar(100), surname varchar(100), current_age integer, current_salary numeric (10,2), birth_date date, the_time time, primary key(the_key))");
        $this->DBA->exec ("DROP GENERATOR GEN_TEST_ID");
        $this->DBA->exec ("CREATE GENERATOR GEN_TEST_ID");
        $result = $this->DBA->exec ("CREATE TRIGGER TEST_BI FOR TEST
                                        ACTIVE BEFORE INSERT POSITION 0
                                        AS
                                        DECLARE VARIABLE tmp DECIMAL(18,0);
                                        BEGIN
                                          IF (NEW.THE_KEY IS NULL) THEN
                                            NEW.THE_KEY = GEN_ID(GEN_TEST_ID, 1);
                                          ELSE
                                          BEGIN
                                            tmp = GEN_ID(GEN_TEST_ID, 0);
                                            if (tmp < new.THE_KEY) then
                                              tmp = GEN_ID(GEN_TEST_ID, new.THE_KEY-tmp);
                                          END
                                        END");
        $this->DBA->commit();

        $this->assertEquals($this->defaultError, $result->getErrorText(), "Failed to create the table test");
    }

    public function testFirebirdInsert()
    {
        //create a record in the table
        $result = $this->DBA->exec("insert into test (the_key, first_name, surname, current_age, current_salary, birth_date, the_time) values (1, 'First Name', 'Last Name', 10, 1000.00, '2005-03-29', '10:10')");
        $this->DBA->commit();

        $this->assertEquals($this->defaultError, $result->getErrorText(), "Failed to insert the test record");
    }

    public function testFirebirdFetch () {
        $this->DBA->fetch("select * from test");

        $result = $this->DBA->fetch("select * from test")->asArray();


        $this->assertIsArray( $result[0], "Failed to fetch an array");

        $result = $this->DBA->fetch("select * from test")->asObject(true);

        $this->assertIsObject( $result[0], "Failed to fetch an object");

        $result = $result = $this->DBA->fetch("select * from test");

        $this->assertIsObject( $result , "Result object not correct");

        $this->assertEquals( 1 , $result->noOfRecords, "No of records is not correct");
        $this->assertEquals( 0 , $result->offSet, "Offset should be 0");
    }

    public function testFirebirdORMFetchAndSave () {
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

    public function testFirebirdOrmCreate () {
        $this->testORM = new \Helper\Test();
        $this->testORM->theKey = 2;
        $this->testORM->firstName = "New ORM";
        $this->testORM->lastName = "New ORM";
        $this->testORM->currentAge = 10;
        $this->testORM->save();

        $this->testORM->load("key_value = 2");
        $this->assertEquals("New ORM", $this->testORM->firstName);
        $this->assertEquals("New ORM", $this->testORM->lastName);


        $this->testORM = new \Helper\Test();
        $this->testORM->firstName = "New ORM New ID";
        $this->testORM->lastName = "New ORM New ID";
        $this->testORM->currentAge = 10;
        $this->testORM->birthDate = date("m/d/Y");
        $this->testORM->save();

    }

    function testDateFormatting () {
        $this->DBA->dateFormat = "d/m/Y";

        $record = $this->DBA->fetch("select * from test")->asArray();



    }

    public function testTransaction()
    {
        $count = $this->DBA->fetch("select count(*) from test")->asArray();




        $tranId = $this->DBA->startTransaction();
        $this->DBA->exec ("delete from test", $tranId);
        $this->DBA->rollback($tranId);



        $count2 = $this->DBA->fetch("select count(*) from test")->asArray();


        $this->assertEquals($count2[0]["count"], 3);

        $this->DBA->commit();

        $tranId = $this->DBA->startTransaction();


        $this->DBA->exec ("delete from test", $tranId);
        $this->DBA->commit($tranId);

        $count = $this->DBA->fetch("select count(*) from test")->asArray();

        $this->assertEquals($count[0]["count"], 0);

        $this->DBA->commit();


    }



}