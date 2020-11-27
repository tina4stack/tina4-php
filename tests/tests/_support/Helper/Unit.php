<?php
namespace Helper;

// here you can define custom actions
// all public methods declared in helper class will be available in $I

class Test extends \Tina4\ORM
{
    public $fieldMapping = ["lastName" => "SURNAME"];
    public $primaryKey = "theKey";

    public $theKey;
    public $firstName;
    public $lastName;
    public $currentAge;
    public $currentSalary;
    public $birthDate;
    public $time;

    public $virtualField; // A field that doesn't exist in the database
}

class Unit extends \Codeception\Module
{
    /**
     * Define custom actions here
     */
    public  $SQLITE;
    public  $MYSQL;
    public  $FIREBIRD;

    public $testORM;

    // HOOK: before each suite
    public function _beforeSuite($settings = array())
    {

        require_once "./vendor/autoload.php";
        if (empty($this->DBA)) {
            $this->SQLITE = new \Tina4\DataSQLite3(SQLITE_DATABASE);
            $this->MYSQL = new \Tina4\DataMySQL(MYSQL_DATABASE, MYSQL_USERNAME, MYSQL_PASSWORD);
            $this->MYSQL->exec("drop table if exists test");
            $this->FIREBIRD = new \Tina4\DataFirebird(FIREBIRD_DATABASE, FIREBIRD_USERNAME, FIREBIRD_PASSWORD);
            $this->FIREBIRD->exec ("drop table test");
            $this->FIREBIRD->commit();
            $this->testORM = new Test();
        }
    }

}
