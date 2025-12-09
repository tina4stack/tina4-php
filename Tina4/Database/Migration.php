<?php
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

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
    private $delimit = ";";

    /**
     * Flag this to run the root migrations after the database specific ones have run
     * @var bool
     */
    private $runRootMigrations = false;

    /**
     * Output format: 'html' or 'terminal'
     * @var string
     */
    private $outputFormat = 'html';

    /**
     * Constructor for Migrations
     * The path is relative to your web folder.
     *
     * @param String $migrationPath relative path to your web folder
     * @param String $delim A delimiter to say how your SQL is run
     * @param string $outputFormat 'html' or 'terminal'
     */
    public function __construct(string $migrationPath = "./migrations", string $delim = ";", string $outputFormat = 'html')
    {
        parent::__construct();
        $this->delimit = $delim;
        $this->migrationPath = $migrationPath;
        $this->outputFormat = $outputFormat;

        if ($this->DBA === null) {
            echo "Please make sure you have a global \$DBA connection in your index.php\n";
        }

        \Tina4\Debug::message("Migration path: ".$this->migrationPath, TINA4_LOG_DEBUG);

        //Only run this if the database connection exists
        if ($this->DBA !== null) {
            //check to see if there are database specific migrations
            if (method_exists($this->DBA, "getShortName") && file_exists($this->migrationPath . "/" . $this->DBA->getShortName())) {
                $this->migrationPath .= "/" . $this->DBA->getShortName();
                $this->runRootMigrations = true;
            } else {
                if (!method_exists($this->DBA, "getShortName")) {
                    \Tina4\Debug::message("Please upgrade the database driver to include getShortName method for migrations", TINA4_LOG_DEBUG);
                }
            }

            //Turn off auto commits so we can roll back
            $this->DBA->autoCommit(false);

            if (!$this->DBA->tableExists("tina4_migration")) {

                $sql = "create table tina4_migration ("
                    . "migration_id varchar (14) not null,"
                    . "description varchar (1000) default '',"
                    . "content blob,"
                    . "passed integer default 0,"
                    . "primary key (migration_id))";

                if (get_class($this->DBA) === "Tina4\DataPostgresql") {
                    $sql = str_replace("blob", "bytea", $sql);
                }
                $this->DBA->exec($sql);
                $this->DBA->commit();
            }

            //Table to set and determine the version of the database
            if (!$this->DBA->tableExists("tina4_version")) {
                $sql = "create table tina4_version (version varchar (100) not null, software varchar(100) default 'tina4', notes blob, primary key (version, software))";
                if (get_class($this->DBA) === "Tina4\DataPostgresql") {
                    $sql = str_replace("blob", "bytea", $sql);
                }
                $this->DBA->exec($sql);
                $this->DBA->commit();
                $this->DBA->exec("insert into tina4_version(version, notes) values ('1.0.0', 'Initial version')");
                $this->DBA->commit();
            }
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
    final public function doMigration(): string
    {
        $result = "";
        if (!file_exists($this->migrationPath)) {
            return "Migration path {$this->migrationPath} does not exist";
        }
        $dirHandle = opendir($this->migrationPath);
        $error = false;
        set_time_limit(0);


        if ($this->outputFormat === 'html') {
            $result .= "<pre>";
        }
        $result .= $this->formatText("STARTING Migrations ($this->migrationPath)....", 'green') . "\n";

        restore_error_handler();
        error_reporting(0);

        //Reads all the sql files into a fileArray
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
            //Get first 14 characters (length of migration id column)
            $migrationId = substr($fileParts[0], 0, 14);

            //Get the rest of the string
            $leftOverFileParts = substr($fileParts[0], 14, strlen($fileParts[0]));
            unset($fileParts[0]);
            /*  Check that first 14 characters do not contain '_' (word separator)
             *  If so, only get first part for id
             */
            if (strpos($migrationId, "_") !== false) {
                $migrationText = explode("_", $migrationId);
                $migrationId = $migrationText[0];
                unset($migrationText[0]);
                $migrationText = implode(" ", $migrationText) . $leftOverFileParts . implode(" ", $fileParts);
            } else {
                $migrationText = $leftOverFileParts . implode(" ", $fileParts);
            }

            //Fix if we end up with weird migrations which do not conform to the spec
            if (empty($migrationId)) {
                $migrationId = substr($entry, 0, 14);
            }

            $sqlCheck = "select * from tina4_migration where migration_id = '{$migrationId}'";
            $record = $this->DBA->fetch($sqlCheck)->AsObject();
            if (!empty($record)) {
                $record = $record[0];
            }

            //Re-attach left over migration text just in case of there being a '_' seperator
            $description = trim(str_replace("_", " ", $migrationText));

            $content = file_get_contents($this->migrationPath . "/" . $entry);

            $runSql = false;
            if (empty($record)) {
                $result .= $this->formatText("RUNNING:\"{$migrationId} {$description}\" ...", 'orange') . "\n";
                $transId = $this->DBA->startTransaction();

                $sqlInsert = "insert into tina4_migration (migration_id, description, content, passed)
                                values ('{$migrationId}', '{$description}', ".$this->DBA->getQueryParam("1", 1).", 0)";


                $this->DBA->exec($sqlInsert, substr($content, 0, 10000));
                $this->DBA->commit($transId);
                $runSql = true;
            } else if ($record->passed === "0" || $record->passed === "" || $record->passed == 0) {
                $result .= $this->formatText("RETRY: \"{$migrationId} {$description}\" ... ", 'orange') . "\n";
                $runSql = true;
            } elseif ($record->passed === "1" || $record->passed == 1) {
                //Update the migration with the latest copy
                $sqlUpdate = "update tina4_migration set content = ".$this->DBA->getQueryParam("1", 1)." where migration_id = '{$migrationId}'";
                $this->DBA->exec($sqlUpdate, substr($content, 0, 10000));
                $result .= $this->formatText("PASSED:\"{$migrationId} {$description}\"", 'green') . "\n";
                $runSql = false;
            }

            if ($runSql) {
                $transId = $this->DBA->startTransaction();
                //before exploding the content, see if it is a stored procedure, trigger or view.
                if (stripos($content, "create trigger") === false && stripos($content, "create procedure") === false && stripos($content, "create view") === false) {
                    $content = explode($this->delimit, $content);
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
                            $result .= $this->formatText("FAILED: \"{$migrationId} {$description}\"", 'red') . "\nQUERY:{$sql}\nERROR:" . $success->getError()["errorMessage"] . "\n";
                            $error = true;
                            break;
                        } else {
                            $result .= $this->formatText("PASSED:", 'green') . " ";
                        }
                        $result .= $sql . " ...\n";
                    }
                }

                if ($error) {
                    $result .= $this->formatText("FAILED: \"{$migrationId} {$description}\"", 'red') . "\nAll Transactions Rolled Back ...\n";
                    $this->DBA->rollback($transId);
                    break;
                } else {
                    $this->DBA->commit($transId);

                    //we need to make sure the commit resulted in no errors
                    if ($this->DBA->error()->getError()["errorMessage"] !== "" && $this->DBA->error()->getError()["errorMessage"] !== "not an error" && $success->getError()["errorMessage"] !== false) {
                        $result .= $this->formatText("FAILED COMMIT: \"{$migrationId} {$description}\"", 'red') . "\nERROR:" . $this->DBA->error()->getError()["errorMessage"] . "\n";
                        $this->DBA->rollback($transId);
                        $error = true;
                        break;
                    } else {
                        $transId = $this->DBA->startTransaction();
                        $this->DBA->exec("update tina4_migration set passed = 1 where migration_id = '{$migrationId}'");
                        $this->DBA->commit($transId);
                        $result .= $this->formatText("PASSED: \"{$migrationId} {$description}\"", 'green') . "\n";
                    }
                }
            }
        }

        if (!$error) {
            $result .= $this->formatText("FINISHED! ....", 'green') . "\n";
        }
        error_reporting(E_ALL);
        if ($this->outputFormat === 'html') {
            $result .= "</pre>";
        }


        if ($this->runRootMigrations) {
            //get rid of the database path
            $this->migrationPath = str_replace("/".$this->DBA->getShortName(), "", $this->migrationPath);
            $this->runRootMigrations = false;
            $result .= $this->doMigration();
            file_put_contents("./log/migrations.html", $result, FILE_APPEND);
        }

        return $result;
    }

    /**
     * Format text based on output format
     * @param string $text
     * @param string $color 'green', 'orange', 'red'
     * @return string
     */
    private function formatText(string $text, string $color): string
    {
        if ($this->outputFormat === 'terminal') {
            $colors = [
                'green' => "\033[32m",
                'orange' => "\033[33m",
                'red' => "\033[31m",
                'reset' => "\033[0m"
            ];
            return $colors[$color] . $text . $colors['reset'];
        } else { // html
            $htmlColors = [
                'green' => '<span style="color:green;">',
                'orange' => '<span style="color:orange;">',
                'red' => '<span style="color:red;">'
            ];
            return $htmlColors[$color] . $text . '</span>';
        }
    }


    /**
     *
     * @param string $description
     * @param string $content
     * @param bool $noDateStamp
     * @return string
     */
    public function createMigration(string $description, string $content, bool $noDateStamp = false): string
    {
        if (!empty($description) && !empty($content)) {
            if (!file_exists($this->migrationPath) && !mkdir($concurrentDirectory = $this->migrationPath, 0755, true) && !is_dir($concurrentDirectory)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
            }

            $description = str_replace(" ", "_", $description);

            if ($noDateStamp) {
                $fileName = $this->migrationPath . DIRECTORY_SEPARATOR . $description . ".sql";
            } else {
                $fileName = $this->migrationPath . DIRECTORY_SEPARATOR . date("YmdHis") . "_" . $description . ".sql";
            }

            if (!file_exists($fileName)) {
                file_put_contents($fileName, $content);
                return "Migration created {$fileName}";
            } else {
                return "Migration exists already in {$fileName}";
            }
        } else {
            return "Failed to create a migration, needs description & content";
        }
    }

    /**
     * Sets the version of a migration to be used in deciding where to migrate a database
     * @param $version
     * @param $notes
     * @param $software
     * @return void
     */
    public function setVersion($version, $notes, $software='tina4')
    {
        if (!empty($version) && !empty($notes)) {
            $versionInfo = $this->DBA->fetchOne("select * from tina4_version where version = '{$version}' and software = '{$software}'");

            if (empty($versionInfo)) {
                $this->DBA->exec("insert into tina4_version(version, notes, software) values (?,?,?)", $version, $notes, $software);

            } else {
                $this->DBA->exec("update tina4_version set notes = ? where version = ? and software = ?", $notes, $version, $software);
            }
            $this->DBA->commit();
        }
    }

    /**
     * Gets the current version of the database
     * @param $software
     * @return mixed|null
     */
    public function getVersionInfo($software='tina4')
    {
        return $this->DBA->fetchOne("select version as \"version\" from tina4_version where software = '{$software}' order by version desc");
    }

    /**
     * Gets the current version of the database
     * @param $software
     * @return mixed|null
     */
    public function getVersion($software='tina4')
    {
        $version = $this->getVersionInfo($software);
        if(empty($version)) {
            $version = (object)["version" => "1.0.0"];
        }
        return $version->version;
    }
}