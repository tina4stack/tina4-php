#!/usr/bin/env php
<?php
/*
 * Tina4 : This Is Not Another Framework
 * Created with : PHPStorm
 * User : andrevanzuydam
 * Copyright (C)
 * Contact : andre@codeinfinity.co.za
*/
set_time_limit(0);

global $rootPath;
$rootPath = str_replace("\n", "", `cd`);
$rootPath = str_replace("\r", "", $rootPath);

$binFile = str_replace("/tina4", "", $argv[0]);
$rootPath = str_replace($binFile, "", $rootPath) . DIRECTORY_SEPARATOR;
global $settings;
$settings = [];
define("TINA4_SUPPRESS", 1);
if (file_exists($rootPath.DIRECTORY_SEPARATOR."index.php")) {
    include $rootPath.DIRECTORY_SEPARATOR."index.php";
}

$stopFileName = "{$rootPath}stop";

if (file_exists($stopFileName)) {
    unlink($stopFileName);
}

\Tina4\DebugLog::message("Running from folder {$rootPath}");
\Tina4\DebugLog::message("Running Tina4 service");
while (TRUE && !file_exists($stopFileName)) {
    $service = new \Tina4\Service();
    $processes = $service->getProcesses();

    if (!empty($processes)) {
        foreach ($processes as $id => $process) {

            try {
                if (get_class($process) !== "__PHP_Incomplete_Class") {
                    \Tina4\DebugLog::message("Running {$process->name}");
                    if ($process->canRun()) {
                        $process->run();
                    }
                } else {
                    \Tina4\DebugLog::message("Could not load registered process, make sure it is in one of the TINA4_INCLUDE_LOCATIONS");
                }
            } catch (Exception $exception) {
                \Tina4\DebugLog::message("Could not run ".$exception->getMessage());
            }

        }
    } else {
        \Tina4\DebugLog::message("Nothing found to run");
    }
    sleep($service->getSleepTime());
}