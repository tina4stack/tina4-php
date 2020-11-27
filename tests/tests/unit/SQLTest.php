<?php 
class SQLTest extends \Codeception\Test\Unit
{
    /**
     * @var \UnitTester
     */
    protected $tester;
    protected $DBA;
    protected $testORM;
    
    protected function _before()
    {
        global $DBA;
        $this->DBA = $this->getModule("\Helper\Unit")->SQLITE;
        $DBA = $this->DBA;
        $this->testORM = new \Helper\Test();
    }

    protected function _after()
    {
    }

    // tests
    public function testSQLFetch()
    {
        $array = (new \Tina4\SQL())->select()->from("test")->where("the_key = 1")->asArray();

        $this->assertIsArray($array[0], "Result is not an array");
        $object = (new \Tina4\SQL($this->testORM))->select()->from("test")->where("the_key = 1")->asObject();




        $this->assertIsObject($object[0], "Result is not an object");
        $array = (new \Tina4\SQL())->select()->from("test")->where("the_key = 2")->asArray();

        $this->assertIsArray($array[0], "Result is not an array");
    }
}