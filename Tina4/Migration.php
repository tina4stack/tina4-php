<?php

namespace Tina4;
/**
 * Description of Tina4 Migration
 * There are some "rules" that Tina4 prescribes for the migrations and it would be in your best interests to follow them.
 * 1.) File formats are in the following format:
 *     YYYYMMDDHHMMSS Description of migration followed by dot sql
 *     Example: 01012015101525 The first migration.sql
 * 2.) Do not put commit statements in the SQL, this will make it impossible for a migration to fail or roll back. Instead make more files.
 *     A create table statement would be in its own file.
 * 3.) Stored procedures, triggers and views should be in their own files, they are run as individual statements and are commited so, delimter is not needed on the end of these.
 *     You do not need to change the delimiter either before running these statements.
 *
 */
class Migration extends Data
{
    /**
     * The database connection
     * @var DataBase
     */
    public $DBA = null;
    /**
     * The migration path
     * @var String
     */
    private $migrationPath = "migrations";
    /**
     * The delimiter
     * @var String
     */
    private $delim = ";";

    /**
     * Constructor for Migrations
     * The path is relative to your web folder.
     *
     * @param String $migrationPath relative path to your web folder
     * @param String $delim A delimiter to say how your SQL is run
     */
    public function __construct($migrationPath = "./migrations", $delim = ";")
    {
        parent::__construct();
        $this->delim = $delim;
        $this->migrationPath = $migrationPath;

        if ($this->DBA === null)
        {
            die("Please make sure you have a global \$DBA connection in your index.php");
        }
        //Turn off auto commits so we can roll back
        $this->DBA->autoCommit(false);

        if (!$this->DBA->tableExists("tina4_migration")) {
            $this->DBA->exec("create table tina4_migration ("
                . "migration_id varchar (14) not null,"
                . "description varchar (1000) default '',"
                . "content blob,"
                . "passed integer default 0,"
                . "primary key (migration_id))");
            $this->DBA->commit();
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
    public function doMigration()
    {
        $result = "";
        echo "<pre>";
        if (!file_exists($this->migrationPath)) return;
        $dirHandle = opendir($this->migrationPath);
        $error = false;
        set_time_limit(0);


        $result .= "<pre>";
        $result .= "<span style=\"color:green;\">STARTING Migrations ....</span>\n";

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
        asort($fileArray);

        foreach ($fileArray as $fid => $entry) {
            $fileParts = explode(".", $entry);
            $fileParts = explode(" ", $fileParts[0]);
            $sqlCheck = "select * from tina4_migration where migration_id = '{$fileParts[0]}'";
            $record = $this->DBA->fetch($sqlCheck)->AsObject();
            if (!empty($record)) {
                $record = $record[0];
            }

            $migrationId = $fileParts[0];
            unset($fileParts[0]);
            $description = implode(" ", $fileParts);

            $content = file_get_contents($this->migrationPath . "/" . $entry);

            $runsql = false;
            if (empty($record)) {
                $result .= "<span style=\"color:orange;\">RUNNING:\"{$migrationId} {$description}\" ...</span>\n";
                $transId = $this->DBA->startTransaction();

                $sqlInsert = "insert into tina4_migration (migration_id, description, content, passed)
                                values ('{$migrationId}', '{$description}', ?, 0)";


                $this->DBA->exec($sqlInsert, substr($content, 0, 10000));
                $this->DBA->commit($transId);
                $runsql = true;
            } else {

                if ($record->passed === "0" || $record->passed === "" || $record->passed == 0) {
                    $result .= "<span style=\"color:orange;\">RETRY: \"{$migrationId} {$description}\" ... </span> \n";
                    $runsql = true;
                } else
                    if ($record->passed === "1" || $record->passed == 1) {
                        //Update the migration with the latest copy
                        $sqlUpdate = "update tina4_migration set content =? where migration_id = '{$migrationId}'";
                        $this->DBA->exec($sqlUpdate, substr($content, 0, 10000));
                        $result .= "<span style=\"color:green;\">PASSED:\"{$migrationId} {$description}\"</span>\n";
                        $runsql = false;
                    }
            }

            if ($runsql) {
                $transId = $this->DBA->startTransaction();
                //before exploding the content, see if it is a stored procedure, trigger or view.
                if (stripos($content, "create trigger") === false && stripos($content, "create procedure") === false && stripos($content, "create view") === false) {
                    $content = explode($this->delim, $content);
                } else {
                    $sql = $content;
                    $content = [];
                    $content[] = $sql;
                }

                $error = false;
                foreach ($content as $cid => $sql) {
                    if (!empty(trim($sql))) {



                        $success = $this->DBA->exec($sql, $transId);
                        if ($success->getError()["errorMessage"] !== "" && $success->getError()["errorMessage"] !== "not an error" && $success->getError()["errorMessage"] !== false) {
                            $result .= "<span style=\"color:red;\">FAILED: \"{$migrationId} {$description}\"</span>\nQUERY:{$sql}\nERROR:" . $success->getError()["errorMessage"] . "\n";
                            $error = true;
                            break;
                        } else {
                            $result .= "<span style=\"color:green;\">PASSED:</span> ";
                        }
                        $result .= $sql . " ...\n";
                    }
                }

                if ($error) {
                    $result .= "<span style=\"color:red;\">FAILED: \"{$migrationId} {$description}\"</span>\nAll Transactions Rolled Back ...\n";
                    $this->DBA->rollback($transId);
                } else {

                    $this->DBA->commit($transId);

                    //we need to make sure the commit resulted in no errors
                    if ($this->DBA->error()->getError()["errorMessage"] !== "" && $this->DBA->error()->getError()["errorMessage"] !== "not an error" && $success->getError()["errorMessage"] !== false) {
                        $result .= "<span style=\"color:red;\">FAILED COMMIT: \"{$migrationId} {$description}\"</span>\nERROR:" . $this->DBA->error()->getError()["errorMessage"] . "\n";
                        $this->DBA->rollback($transId);
                        $error = true;
                        break;
                    } else {
                        $transId = $this->DBA->startTransaction();
                        $this->DBA->exec("update tina4_migration set passed = 1 where migration_id = '{$migrationId}'");
                        $this->DBA->commit($transId);
                        $result .= "<span style=\"color:green;\">PASSED: \"{$migrationId} {$description}\"</span>\n";
                    }
                }
            }
        }

        if (!$error) $result .= "<span style=\"color:green;\">FINISHED! ....</span>\n";
        error_reporting(E_ALL);
        $result .= "</pre>";

        return $result;
    }


    /**
     *
     * @param $description
     * @param $content
     * @return string
     */
    public function createMigration($description, $content)
    {
        if (!empty($description) && !empty($content)) {
            if (!file_exists($this->migrationPath)) {
                if (!mkdir($concurrentDirectory = $this->migrationPath, 0755, true) && !is_dir($concurrentDirectory)) {
                    throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
                }
            }
            $fileName = $this->migrationPath . DIRECTORY_SEPARATOR . date("Ymdhis") . " " . $description . ".sql";
            file_put_contents($fileName, $content);
            return "Migration created {$fileName}";
        } else {
            return "Failed to create a migration, needs description & content";
        }
    }

}