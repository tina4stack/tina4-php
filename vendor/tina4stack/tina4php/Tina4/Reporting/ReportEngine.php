<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

class ReportEngine extends \Tina4\Data
{
    /**
     * @const string
     */
    const TINA4_REPORT_ENGINE = "." . DIRECTORY_SEPARATOR . "bin" . DIRECTORY_SEPARATOR . "reportengine";

    /**
     * @param $reportName
     * @param $outputName
     * @param string $sql
     * @param string $debug
     * @return mixed
     */
    function generate($reportName, $outputName, $sql, string $debug = "")
    {
        if (file_exists($reportName)) {
            $reportName = realpath($reportName);
        }

        $reportEngine = realpath(self::TINA4_REPORT_ENGINE);

        if (strpos(php_uname(), "Windows") !== false) {
            $reportEngine .= ".exe";
        }

        $sql = str_replace("\n", " ", $sql);

        $sql = str_replace("\r", " ", $sql);

        $sql = str_replace("\"", "\\\"", $sql);

        $result = `{$reportEngine} "{$reportName}" "{$outputName}" "{$sql}" {$debug}`;

        return json_decode($result);
    }
}
