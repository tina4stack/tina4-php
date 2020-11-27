<?php
namespace Helper;

// here you can define custom actions
// all public methods declared in helper class will be available in $I

class Acceptance extends \Codeception\Module
{
    /**
     * Define custom actions here
     */
    public  $DBA;

    // HOOK: before each suite
    public function _beforeSuite($settings = array())
    {
        if (empty($this->DBA)) {
            if (file_exists("./test.db")) {
                unlink("./test.db");
            }
            $this->DBA = new \Tina4\DataSQLite3("test.db");
        }
    }
}
