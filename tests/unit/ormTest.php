<?php

namespace Tina4;

class ormTest extends \Codeception\Test\Unit
{

    protected function _before()
    {
    }

    protected function _after()
    {
    }

    // tests
    public function testCreateObject()
    {
       $testTable = new Tina4\ORM('{"name": "Andre", "email": "test@test.com"}', "user_table", ["name" => "user_name", "email" => "email_address"]);

       debugger_print($testTable->getTableData());



    }

}
