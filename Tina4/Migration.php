<?php
namespace Tina4;
/**
 * Description of Tina4 Migration
 * There are some "rules" that Maggy prescribes for the migrations and it would be in your best interests to follow them.
 * 1.) File formats are in the following format:
 *     YYYYMMDDHHMMSS Description of migration followed by dot sql
 *     Example: 01012015101525 The first migration.sql
 * 2.) Do not put commit statements in the SQL, this will make it impossible for a migration to fail or roll back. Instead make more files.
 *     A create table statement would be in its own file.
 * 3.) Stored procedures, triggers and views should be in their own files, they are run as individual statements and are commited so, delimter is not needed on the end of these.
 *     You do not need to change the delimiter either before running these statements.
 *
 */
class Migration extends \Tina4\Data {
    /**
     * The migration path
     * @var String
     */
    private $migrationPath = "migration";
    /**
     * The delimiter
     * @var String
     */
    private $delim = ";";
    /**
     * The database connection
     * @var DataBase
     */
    private $DBA = null;

    /**
     * Constructor for Migrations
     * The path is relative to your web folder.
     *
     * @param String $migrationPath relative path to your web folder
     * @param String $delim A delimiter to say how your SQL is run
     */
    function __construct($migrationPath = "./migration", $delim = ";", $runMigrations=false) {
        $this->delim = $delim;
        $this->migrationPath = $migrationPath;

        if ( !$this->DBA->tableExists("tina4_maggy")) {
            $this->DBA->exec("create table tina4_migration ("
                . "migration_id varchar (14) not null,"
                . "description varchar (1000) default '',"
                . "content blob,"
                . "passed integer default 0,"
                . "primary key (migration_id))");
            $this->DBA->commit();
        }

        if ($runMigrations && file_exists ($this->migrationPath)) {
            //Run the migration
            $this->doMigration();
        }
    }

    /**
     * Do Migration
     *
     * Do Migration finds the last possible migration based on what is read from the database on the constructor
     * It then opens the migration file, imports it into the database and tries to run each statement.
     * The migration files must run in sequence and migrations will stop if it hits an error!
     *
     * DO NOT USE COMMIT STATEMENTS IN YOUR MIGRATIONS , RATHER BREAK THINGS UP INTO SMALLER LOGICAL PIECES
     */
    function doMigration() {
        $dirHandle = opendir($this->migrationPath);
        $error = false;
        set_time_limit ( 0 );

        echo "<pre>";
        echo "STARTING Maggy ....\n";

        $error = false;
        error_reporting(0);
        $fileArray = [];
        while (false !== ($entry = readdir($dirHandle)) && !$error) {
            if ($entry != "." && $entry != ".." && stripos($entry, ".sql")) {
                $fileParts = explode(".", $entry);
                $fileParts = explode(" ", $fileParts[0]);
                $fileArray[$fileParts[0]] = $entry;
            }
        }


        asort ($fileArray);

        foreach ( $fileArray as $fid => $entry ) {
            $fileParts = explode(".", $entry);
            $fileParts = explode(" ", $fileParts[0]);
            $sqlCheck = "select * from tina4_migration where migration_id = '{$fileParts[0]}'";
            $record = $this->DBA->fetch($sqlCheck)->AsArray();

            $migrationId = $fileParts[0];
            unset($fileParts[0]);
            $description = join(" ", $fileParts);

            $content = file_get_contents ( $this->migrationPath . "/" . $entry);

            $runsql = false;
            if (empty($record)) {
                echo "<span style=\"color:orange;\">RUNNING:\"{$migrationId} {$description}\" ...</span>\n";
                $transId = $this->DBA->startTransaction();

                $sqlInsert = "insert into tina4_migration (migration_id, description, content, passed)
                                values ('{$migrationId}', '{$description}', ?, 0)";


                $this->DBA->exec($sqlInsert, $transId, substr($content, 0, 10000));
                $this->DBA->commit($transId);
                $runsql = true;
            } else {

                if ($record->PASSED === "0" || $record->PASSED === "" || $record->PASSED == 0) {
                    echo "<span style=\"color:orange;\">RETRY: \"{$migrationId} {$description}\" ... </span> \n";
                    $runsql = true;
                } else
                    if ($record->PASSED === "1" || $record->PASSED == 1 ) {
                        echo "<span style=\"color:green;\">PASSED:\"{$migrationId} {$description}\"</span>\n";
                        $runsql = false;
                    }
            }

            if ($runsql) {
                $transId = $this->DBA->startTransaction();
                //before exploding the content, see if it is a stored procedure, trigger or view.
                if (stripos ($content, "create trigger") === false && stripos ($content, "create procedure") === false && stripos ($content, "create view") === false ) {
                    $content = explode($this->delim, $content);
                }
                else {
                    $sql = $content;
                    $content = [];
                    $content[] = $sql;
                }

                $error = false;
                foreach ($content as $cid => $sql) {
                    if (!empty(trim($sql))) {
                        $success = $this->DBA->exec($sql, $transId);

                        if (!$success) {
                            echo "<span style=\"color:red;\">FAILED: \"{$migrationId} {$description}\"</span>\nQUERY:{$sql}\nERROR:".$this->DBA->getError()."\n";
                            $error = true;
                            break;
                        } else {
                            echo "<span style=\"color:green;\">PASSED:</span> ";
                        }
                        echo $sql . " ...\n";
                    }
                }

                if ($error) {
                    echo "<span style=\"color:red;\">FAILED: \"{$migrationId} {$description}\"</span>\nAll Transactions Rolled Back ...\n";
                    $this->DBA->rollbackTransaction($transId);
                } else {

                    $this->DBA->commit( $transId );

                    //we need to make sure the commit resulted in no errors
                    if ($this->DBA->getError() !== "No Error") {
                        echo "<span style=\"color:red;\">FAILED COMMIT: \"{$migrationId} {$description}\"</span>\nERROR:".$this->DBA->getError()."\n";
                        $this->DBA->rollbackTransaction($transId);
                        $error = true;
                        break;
                    }
                    else {
                        $transId = $this->DBA->startTransaction();
                        $this->DBA->exec ("update tina4_migration set passed = 1 where migration_id = '{$migrationId}'", $transId);
                        $this->DBA->commit($transId);
                        echo "<span style=\"color:green;\">PASSED: \"{$migrationId} {$description}\"</span>\n";
                    }
                }
            }
        }

        if (!$error) echo "FINISHED!";
        error_reporting(E_ALL);
        echo "</pre>";
    }


    function createMigration($description,$content) {
        $fileName = $this->migrationPath."/".date("Ymdhis")." ".$description.".sql";
        file_put_contents($fileName, $content );
    }

}
