<?php

namespace dokuwiki\plugin\sqlite\test;

use DokuWikiTest;
/**
 * Tests all the same things as the abstract test but skips the PDO driver
 *
 * @group plugin_sqlite
 * @group plugins
 */
class Sqlite2Test extends AbstractTest {

    public function setup(): void {
        if(!function_exists('sqlite_open')){
            $this->markTestSkipped('The sqlite2 extension is not available.');
        }else{
            $_ENV['SQLITE_SKIP_PDO'] = true;
        }
        parent::setup();
    }

    public function tearDown():void {
        $_ENV['SQLITE_SKIP_PDO'] = false;
        parent::tearDown();
    }

}
